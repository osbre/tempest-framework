<?php

declare(strict_types=1);

namespace Tempest\CommandBus\AsyncCommandRepositories;

use Tempest\Clock\Clock;
use Tempest\CommandBus\ClaimedCommand;
use Tempest\CommandBus\ClaimsPendingCommands;
use Tempest\CommandBus\CommandRepository;
use Tempest\CommandBus\Exceptions\PendingCommandCouldNotBeResolved;
use Tempest\Database\Builder\QueryBuilders\SelectQueryBuilder;
use Tempest\Database\Builder\WhereOperator;
use Tempest\Database\Database;
use Tempest\Database\DatabaseCapabilities;
use Tempest\DateTime\Duration;

use function Tempest\Database\query;

final readonly class DatabaseCommandRepository implements CommandRepository, ClaimsPendingCommands
{
    use UnserializesCommands;

    public function __construct(
        private Clock $clock,
        private Database $database,
        private DatabaseCapabilities $capabilities,
    ) {}

    public function store(string $uuid, object $command): void
    {
        query(StoredCommand::class)
            ->insert(
                id: $uuid,
                payload: serialize($command),
            )
            ->execute();
    }

    public function getPendingCommands(): array
    {
        $commands = [];

        $rows = query(StoredCommand::class)
            ->select()
            ->whereNull('failed_at')
            ->whereNull('reserved_at')
            ->all();

        foreach ($rows as $row) {
            $command = $this->unserializeCommand($row->payload);

            if ($command === null) {
                continue;
            }

            $commands[(string) $row->id] = $command;
        }

        return $commands;
    }

    public function findPendingCommand(string $uuid): object
    {
        // Reserved commands are deliberately still resolvable here: a monitor claims a command
        // and then hands the uuid to `command:handle`, which has to be able to load it.
        $row = query(StoredCommand::class)
            ->select()
            ->whereField('id', $uuid)
            ->whereNull('failed_at')
            ->first();

        if ($row === null) {
            throw new PendingCommandCouldNotBeResolved($uuid);
        }

        return $this->unserializeCommand($row->payload) ?? throw new PendingCommandCouldNotBeResolved($uuid);
    }

    public function markAsDone(string $uuid): void
    {
        query(StoredCommand::class)
            ->delete()
            ->whereField('id', $uuid)
            ->whereNull('failed_at')
            ->execute();
    }

    public function markAsFailed(string $uuid): void
    {
        query(StoredCommand::class)
            ->update(failed_at: $this->clock->now(), reserved_at: null, reserved_by: null)
            ->whereField('id', $uuid)
            ->execute();
    }

    public function claimPendingCommands(int $limit = 1): array
    {
        if ($limit < 1) {
            return [];
        }

        $claimed = [];

        foreach ($this->reserve($limit) as $row) {
            $uuid = (string) $row->id;
            $command = $this->unserializeCommand($row->payload);

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
        query(StoredCommand::class)
            ->update(reserved_at: null, reserved_by: null)
            ->whereField('id', $uuid)
            ->whereNotNull('reserved_at')
            ->whereNull('failed_at')
            ->execute();
    }

    public function releaseStaleReservations(Duration $timeout): int
    {
        $released = 0;

        // The whole sweep runs in one transaction, and the candidates are locked as they are read.
        // Without that, a reservation could be handed to another consumer between being seen as
        // stale and being cleared, and the sweep would then release a command that is actively
        // being handled — the recovery mechanism causing the duplicate it exists to recover from.
        $committed = $this->database->withinTransaction(function () use ($timeout, &$released): void {
            $threshold = $this->now() - (int) $timeout->getTotalSeconds();

            $rows = $this->lock(
                query(StoredCommand::class)
                    ->select('id')
                    ->whereNotNull('reserved_at')
                    ->whereNull('failed_at')
                    // Inclusive, because timestamps are stored with second precision: a command
                    // reserved within the same second as the threshold has still outlived it.
                    ->whereField('reserved_at', $threshold, WhereOperator::LESS_THAN_OR_EQUAL),
            )->all();

            $released = $this->clearReservations($rows);
        });

        return $committed ? $released : 0;
    }

    /**
     * Reserves up to `$limit` claimable commands, in a single transaction.
     *
     * Where the database has `FOR UPDATE SKIP LOCKED`, each consumer walks past the rows another
     * one is already reserving. On MySQL before 8.0 and MariaDB before 10.6 the rows are locked
     * with a plain `FOR UPDATE`, so consumers queue behind each other instead. Only SQLite, which
     * has no row locks and admits one writer at a time, takes no lock at all.
     *
     * @return StoredCommand[]
     */
    private function reserve(int $limit): array
    {
        $rows = [];

        $committed = $this->database->withinTransaction(function () use ($limit, &$rows): void {
            $rows = $this->lock(
                query(StoredCommand::class)
                    ->select('id', 'payload')
                    ->whereNull('reserved_at')
                    ->whereNull('failed_at')
                    ->orderBy('id')
                    ->limit($limit),
            )->all();

            if ($rows === []) {
                return;
            }

            query(StoredCommand::class)
                ->update(reserved_at: $this->now(), reserved_by: $this->consumerId())
                ->whereIn('id', $this->ids($rows))
                ->execute();
        });

        return $committed ? $rows : [];
    }

    /**
     * @param StoredCommand[] $rows
     */
    private function clearReservations(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        query(StoredCommand::class)
            ->update(reserved_at: null, reserved_by: null)
            ->whereIn('id', $this->ids($rows))
            ->execute();

        return count($rows);
    }

    /**
     * @param SelectQueryBuilder<StoredCommand> $query
     *
     * @return SelectQueryBuilder<StoredCommand>
     */
    private function lock(SelectQueryBuilder $query): SelectQueryBuilder
    {
        $clause = $this->capabilities->rowLockClause($this->database);

        return $query->when($clause !== null, fn (SelectQueryBuilder $query) => $query->raw($clause));
    }

    /**
     * @param StoredCommand[] $rows
     *
     * @return string[]
     */
    private function ids(array $rows): array
    {
        return array_map(fn (StoredCommand $row) => (string) $row->id, $rows);
    }

    /**
     * The moment a reservation is taken or measured against, as seconds since the epoch.
     *
     * Deliberately the application's clock rather than the database's. Asking the database costs a
     * round trip on every poll, and buys less than it looks: its answer is a datetime in whichever
     * timezone that server happens to be configured for, which is not necessarily the one the
     * application writes its own datetimes in. Seconds since the epoch mean the same thing on every
     * host, so the only thing consumers have to agree on is the time itself — which is what NTP is
     * for. Consumers whose clocks genuinely drift further apart than the timeout can still release
     * each other's live reservations.
     */
    private function now(): int
    {
        return $this->clock->now()->getTimestamp()->getSeconds();
    }

    /**
     * Identifies the consumer holding a reservation, so a stuck queue can be traced back to a box
     * and a process rather than to an opaque token.
     */
    private function consumerId(): string
    {
        return gethostname() . ':' . getmypid();
    }
}
