<?php

declare(strict_types=1);

namespace Tempest\CommandBus\AsyncCommandRepositories;

use Deprecated;
use Tempest\Clock\Clock;
use Tempest\CommandBus\ClaimedCommand;
use Tempest\CommandBus\ClaimsPendingCommands;
use Tempest\CommandBus\CommandRepository;
use Tempest\CommandBus\Exceptions\PendingCommandCouldNotBeResolved;
use Tempest\DateTime\Duration;
use Tempest\KeyValue\Redis\Redis;

final readonly class RedisCommandRepository implements CommandRepository, ClaimsPendingCommands
{
    use UnserializesCommands;

    private const string PENDING_KEY = 'command:pending';

    private const string FAILED_KEY = 'command:failed';

    /**
     * Commands currently held by a consumer, alongside when each was reserved.
     */
    private const string RESERVED_KEY = 'command:reserved';

    private const string RESERVED_AT_KEY = 'command:reserved-at';

    /**
     * The order commands were stored in. Claiming pops from here rather than scanning the pending
     * hash, because a script that writes after a `SCAN` is rejected by Redis before 7.
     */
    private const string QUEUE_KEY = 'command:queue';

    /**
     * Set once the old one key per command layout has been migrated, so the keyspace is scanned once.
     */
    #[Deprecated(message: 'Remove in 4.0, along with the key itself.')]
    private const string MIGRATION_KEY = 'command:migrated';

    /**
     * How many fields to read per `HSCAN` batch. Bigger batches mean fewer round trips but larger
     * replies; 500 measured as a good middle ground.
     */
    private const string SCAN_COUNT = '500';

    /**
     * Moves a command out of whichever hash currently holds it and into the failed one. Runs as a
     * script so it cannot end up in two hashes, or none.
     */
    private const string MARK_AS_FAILED_SCRIPT = <<<'LUA'
    local command = redis.call('HGET', KEYS[1], ARGV[1])
    local source = KEYS[1]

    if not command then
        command = redis.call('HGET', KEYS[3], ARGV[1])
        source = KEYS[3]
    end

    if not command then
        return 0
    end

    redis.call('HSET', KEYS[2], ARGV[1], command)
    redis.call('HDEL', source, ARGV[1])
    redis.call('HDEL', KEYS[4], ARGV[1])

    return 1
    LUA;

    /**
     * Reserves one command by moving it from pending to reserved. Returns nothing when the uuid is
     * no longer pending, which happens when it was handled or failed since being queued.
     */
    private const string CLAIM_SCRIPT = <<<'LUA'
    local command = redis.call('HGET', KEYS[1], ARGV[1])

    if not command then
        return false
    end

    redis.call('HSET', KEYS[2], ARGV[1], command)
    redis.call('HSET', KEYS[3], ARGV[1], ARGV[2])
    redis.call('HDEL', KEYS[1], ARGV[1])

    return command
    LUA;

    /**
     * Puts an abandoned command back at the head of the queue, so it is retried before newer work.
     */
    private const string RELEASE_SCRIPT = <<<'LUA'
    local command = redis.call('HGET', KEYS[1], ARGV[1])

    if not command then
        redis.call('HDEL', KEYS[3], ARGV[1])

        return 0
    end

    redis.call('HSET', KEYS[2], ARGV[1], command)
    redis.call('HDEL', KEYS[1], ARGV[1])
    redis.call('HDEL', KEYS[3], ARGV[1])
    redis.call('LPUSH', KEYS[4], ARGV[1])

    return 1
    LUA;

    /**
     * Moves one command from its own key into the matching hash, as a script so it ends up in exactly one.
     */
    #[Deprecated(message: 'Remove in 4.0.')]
    private const string MIGRATE_COMMAND_SCRIPT = <<<'LUA'
    local command = redis.call('GET', KEYS[1])

    if not command then
        return 0
    end

    redis.call('HSET', KEYS[2], ARGV[1], command)
    redis.call('UNLINK', KEYS[1])

    return 1
    LUA;

    public function __construct(
        private Redis $redis,
        private Clock $clock,
    ) {}

    public function store(string $uuid, object $command): void
    {
        $this->redis->command('HSET', self::PENDING_KEY, $uuid, serialize($command));
        $this->redis->command('RPUSH', self::QUEUE_KEY, $uuid);
    }

    public function getPendingCommands(): array
    {
        $commands = [];

        foreach ($this->scanHash(self::PENDING_KEY) as $uuid => $payload) {
            $command = $this->unserializeCommand($payload);

            if ($command === null) {
                continue;
            }

            $commands[$uuid] = $command;
        }

        return $commands;
    }

    public function findPendingCommand(string $uuid): object
    {
        // Reserved commands stay resolvable: a monitor claims a command and then hands the uuid to
        // `command:handle`, which has to be able to load it.
        $value = $this->redis->command('HGET', self::PENDING_KEY, $uuid);

        if (! is_string($value)) {
            $value = $this->redis->command('HGET', self::RESERVED_KEY, $uuid);
        }

        if (! is_string($value)) {
            throw new PendingCommandCouldNotBeResolved($uuid);
        }

        return $this->unserializeCommand($value) ?? throw new PendingCommandCouldNotBeResolved($uuid);
    }

    public function markAsDone(string $uuid): void
    {
        $this->redis->command('HDEL', self::PENDING_KEY, $uuid);
        $this->redis->command('HDEL', self::RESERVED_KEY, $uuid);
        $this->redis->command('HDEL', self::RESERVED_AT_KEY, $uuid);
    }

    public function markAsFailed(string $uuid): void
    {
        $this->redis->command(
            'EVAL',
            self::MARK_AS_FAILED_SCRIPT,
            '4',
            self::PENDING_KEY,
            self::FAILED_KEY,
            self::RESERVED_KEY,
            self::RESERVED_AT_KEY,
            $uuid,
        );
    }

    public function claimPendingCommands(int $limit = 1): array
    {
        $claimed = [];
        $seeded = false;

        while (count($claimed) < $limit) {
            $uuid = $this->redis->command('LPOP', self::QUEUE_KEY);

            if (! is_string($uuid)) {
                // Commands stored before the queue existed are only in the pending hash. Seed them
                // once, then try again; if that turns up nothing either, there is no work.
                if ($seeded || $this->seedQueue() === 0) {
                    break;
                }

                $seeded = true;

                continue;
            }

            $payload = $this->redis->command(
                'EVAL',
                self::CLAIM_SCRIPT,
                '3',
                self::PENDING_KEY,
                self::RESERVED_KEY,
                self::RESERVED_AT_KEY,
                $uuid,
                (string) $this->clock->now()->getTimestamp()->getSeconds(),
            );

            // The uuid was queued twice, or handled since being queued. Either way, move on.
            if (! is_string($payload)) {
                continue;
            }

            $command = $this->unserializeCommand($payload);

            if ($command === null) {
                // The payload can never be handled, so it is moved out of the way rather than
                // reserved forever or handed to the next consumer.
                $this->markAsFailed($uuid);

                continue;
            }

            $claimed[] = new ClaimedCommand($uuid, $command);
        }

        return $claimed;
    }

    public function releaseReservation(string $uuid): void
    {
        $this->release($uuid);
    }

    public function releaseStaleReservations(Duration $timeout): int
    {
        $threshold = $this->clock->now()->getTimestamp()->getSeconds() - (int) $timeout->getTotalSeconds();
        $released = 0;

        // Collected before releasing, because writing while a `HSCAN` cursor is open can make the
        // scan miss or repeat fields.
        $stale = [];

        foreach ($this->scanHash(self::RESERVED_AT_KEY) as $uuid => $reservedAt) {
            if ((int) $reservedAt > $threshold) {
                continue;
            }

            $stale[] = $uuid;
        }

        foreach ($stale as $uuid) {
            $released += $this->release($uuid);
        }

        return $released;
    }

    /**
     * Moves one command back from reserved to pending and re-queues it, and returns whether it was
     * still reserved. The script does that in one step, so it cannot end up in both hashes.
     */
    private function release(string $uuid): int
    {
        return (int) $this->redis->command(
            'EVAL',
            self::RELEASE_SCRIPT,
            '4',
            self::RESERVED_KEY,
            self::PENDING_KEY,
            self::RESERVED_AT_KEY,
            self::QUEUE_KEY,
            $uuid,
        );
    }

    /**
     * Rebuilds the queue from the pending hash, and returns how many uuids were added. Only called
     * when the queue is empty, so it cannot duplicate uuids that are already queued.
     */
    private function seedQueue(): int
    {
        $uuids = [];

        foreach ($this->scanHash(self::PENDING_KEY) as $uuid => $_) {
            $uuids[] = $uuid;
        }

        if ($uuids === []) {
            return 0;
        }

        $this->redis->command('RPUSH', self::QUEUE_KEY, ...$uuids);

        return count($uuids);
    }

    /**
     * Moves commands that earlier versions stored under their own key into the hashes, and returns how many.
     */
    #[Deprecated(message: 'Remove in 4.0.')]
    public function migrateStoredCommands(): int
    {
        if ((int) $this->redis->command('EXISTS', self::MIGRATION_KEY) === 1) {
            return 0;
        }

        $migrated = $this->migrateLegacyKeys(self::PENDING_KEY) + $this->migrateLegacyKeys(self::FAILED_KEY);

        $this->redis->command('SET', self::MIGRATION_KEY, '1');

        return $migrated;
    }

    /**
     * Scans from the client, since Redis before 7 refuses to write after a `SCAN`.
     */
    #[Deprecated(message: 'Remove in 4.0.')]
    private function migrateLegacyKeys(string $hash): int
    {
        $prefix = $hash . ':';
        $migrated = 0;
        $cursor = '0';

        do {
            $response = $this->redis->command('SCAN', $cursor, 'MATCH', $prefix . '*', 'COUNT', self::SCAN_COUNT);

            if (! is_array($response) || count($response) !== 2) {
                return $migrated;
            }

            [$cursor, $keys] = $response;

            foreach (is_array($keys) ? $keys : [] as $key) {
                $uuid = substr((string) $key, strlen($prefix));

                $migrated += (int) $this->redis->command('EVAL', self::MIGRATE_COMMAND_SCRIPT, '2', (string) $key, $hash, $uuid);
            }
        } while ((string) $cursor !== '0');

        return $migrated;
    }

    /**
     * Reads a hash in batches, instead of using `HGETALL`, which would make every other client
     * wait while Redis reads a large backlog.
     *
     * @return iterable<string, string>
     */
    private function scanHash(string $key): iterable
    {
        $cursor = '0';

        do {
            $response = $this->redis->command('HSCAN', $key, $cursor, 'COUNT', self::SCAN_COUNT);

            if (! is_array($response) || count($response) !== 2) {
                return;
            }

            [$cursor, $fields] = $response;

            yield from $this->parseHash($fields);
        } while ((string) $cursor !== '0');
    }

    /**
     * The clients pass raw commands straight through, so a hash comes back as a flat list:
     * field, value, field, value. This pairs them up again.
     *
     * @return array<string, string>
     */
    private function parseHash(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        if (! array_is_list($fields)) {
            return $fields;
        }

        $hash = [];

        for ($i = 0; $i < (count($fields) - 1); $i += 2) {
            $hash[$fields[$i]] = $fields[$i + 1];
        }

        return $hash;
    }
}
