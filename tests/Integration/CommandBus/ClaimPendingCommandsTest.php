<?php

declare(strict_types=1);

namespace Tests\Tempest\Integration\CommandBus;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PostCondition;
use PHPUnit\Framework\Attributes\PreCondition;
use PHPUnit\Framework\Attributes\Test;
use Tempest\Clock\Clock;
use Tempest\CommandBus\AsyncCommandRepositories\DatabaseCommandRepository;
use Tempest\CommandBus\AsyncCommandRepositories\FileCommandRepository;
use Tempest\CommandBus\AsyncCommandRepositories\MemoryRepository;
use Tempest\CommandBus\AsyncCommandRepositories\RedisCommandRepository;
use Tempest\CommandBus\ClaimedCommand;
use Tempest\CommandBus\ClaimsPendingCommands;
use Tempest\CommandBus\CommandRepository;
use Tempest\CommandBus\Installer\AddReservationsToCommandsTable;
use Tempest\CommandBus\Installer\CreateCommandsTable;
use Tempest\Database\Migrations\CreateMigrationsTable;
use Tempest\DateTime\Duration;
use Tempest\KeyValue\Redis\Config\RedisConfig;
use Tempest\KeyValue\Redis\Redis;
use Tests\Tempest\Fixtures\Commands\MyCommand;
use Tests\Tempest\Integration\FrameworkIntegrationTestCase;
use Throwable;

use function Tempest\Support\Random\uuid;

/**
 * Every repository has to hand out a command exactly once, and hand it back after the consumer
 * holding it goes away. The behaviour is identical across backends, so it is asserted against all
 * of them rather than against whichever one is convenient.
 *
 * @internal
 */
final class ClaimPendingCommandsTest extends FrameworkIntegrationTestCase
{
    private ?Redis $redis = null;

    private ?string $storageDirectory = null;

    #[PreCondition]
    protected function configure(): void
    {
        $this->database->migrate(CreateMigrationsTable::class, CreateCommandsTable::class, AddReservationsToCommandsTable::class);
    }

    #[PostCondition]
    protected function cleanup(): void
    {
        try {
            $this->redis?->flush();
        } catch (Throwable) { // @mago-expect lint:no-empty-catch-clause
        }

        if ($this->storageDirectory !== null) {
            foreach (glob($this->storageDirectory . '/*.txt') as $path) {
                @unlink($path);
            }
        }
    }

    public static function repositories(): iterable
    {
        yield 'memory' => [MemoryRepository::class];
        yield 'file' => [FileCommandRepository::class];
        yield 'database' => [DatabaseCommandRepository::class];
        yield 'redis' => [RedisCommandRepository::class];
    }

    #[Test]
    #[DataProvider('repositories')]
    public function claims_a_stored_command(string $repositoryClass): void
    {
        $repository = $this->repository($repositoryClass);
        $command = new MyCommand();

        $repository->store($uuid = uuid(), $command);

        $claimed = $this->claim($repository);

        $this->assertNotNull($claimed);
        $this->assertSame($uuid, $claimed->uuid);
        $this->assertEquals($command, $claimed->command);
    }

    #[Test]
    #[DataProvider('repositories')]
    public function claims_nothing_when_there_is_nothing_pending(string $repositoryClass): void
    {
        $this->assertNull($this->claim($this->repository($repositoryClass)));
    }

    #[Test]
    #[DataProvider('repositories')]
    public function a_command_is_only_claimed_once(string $repositoryClass): void
    {
        $repository = $this->repository($repositoryClass);

        $repository->store(uuid(), new MyCommand());

        $this->assertNotNull($this->claim($repository));
        $this->assertNull($this->claim($repository), 'A claimed command was handed out a second time.');
    }

    #[Test]
    #[DataProvider('repositories')]
    public function every_stored_command_is_claimed_exactly_once(string $repositoryClass): void
    {
        $repository = $this->repository($repositoryClass);

        $stored = [];

        for ($i = 0; $i < 5; $i++) {
            $repository->store($uuid = uuid(), new MyCommand());
            $stored[] = $uuid;
        }

        $claimed = [];

        while (($command = $this->claim($repository)) !== null) {
            $claimed[] = $command->uuid;
        }

        sort($stored);
        sort($claimed);

        $this->assertSame($stored, $claimed);
    }

    #[Test]
    #[DataProvider('repositories')]
    public function a_claimed_command_is_hidden_from_pending_commands(string $repositoryClass): void
    {
        $repository = $this->repository($repositoryClass);

        $repository->store($uuid = uuid(), new MyCommand());
        $this->claim($repository);

        $this->assertArrayNotHasKey($uuid, $repository->getPendingCommands());
    }

    #[Test]
    #[DataProvider('repositories')]
    public function a_claimed_command_can_still_be_resolved_by_uuid(string $repositoryClass): void
    {
        // This is what `command:handle <uuid>` relies on: the monitor reserves a command and then
        // hands the uuid to a separate process, which has to be able to load it.
        $repository = $this->repository($repositoryClass);

        $repository->store($uuid = uuid(), $command = new MyCommand());
        $this->claim($repository);

        $this->assertEquals($command, $repository->findPendingCommand($uuid));
    }

    #[Test]
    #[DataProvider('repositories')]
    public function finishing_a_claimed_command_removes_it(string $repositoryClass): void
    {
        $repository = $this->repository($repositoryClass);

        $repository->store($uuid = uuid(), new MyCommand());
        $this->claim($repository);
        $repository->markAsDone($uuid);

        $this->assertSame([], $repository->getPendingCommands());
        $this->assertSame(0, $repository->releaseStaleReservations(Duration::seconds(0)));
    }

    #[Test]
    #[DataProvider('repositories')]
    public function failing_a_claimed_command_does_not_leave_a_reservation(string $repositoryClass): void
    {
        $repository = $this->repository($repositoryClass);

        $repository->store($uuid = uuid(), new MyCommand());
        $this->claim($repository);
        $repository->markAsFailed($uuid);

        // Without this, a failed command would be released back into the queue once its
        // reservation went stale, and would be handled again forever.
        $this->assertSame(0, $repository->releaseStaleReservations(Duration::seconds(0)));
        $this->assertNull($this->claim($repository));
    }

    #[Test]
    #[DataProvider('repositories')]
    public function an_abandoned_reservation_is_released_and_claimed_again(string $repositoryClass): void
    {
        $repository = $this->repository($repositoryClass);

        $repository->store($uuid = uuid(), new MyCommand());
        $this->claim($repository);

        // A zero timeout treats the reservation as abandoned right away, which is what would
        // happen to a consumer that died without reporting back.
        $this->assertSame(1, $repository->releaseStaleReservations(Duration::seconds(0)));

        $reclaimed = $this->claim($repository);

        $this->assertNotNull($reclaimed);
        $this->assertSame($uuid, $reclaimed->uuid);
    }

    #[Test]
    #[DataProvider('repositories')]
    public function a_reservation_within_the_timeout_is_left_alone(string $repositoryClass): void
    {
        $repository = $this->repository($repositoryClass);

        $repository->store(uuid(), new MyCommand());
        $this->claim($repository);

        $this->assertSame(0, $repository->releaseStaleReservations(Duration::hours(1)));
        $this->assertNull($this->claim($repository));
    }

    #[Test]
    #[DataProvider('repositories')]
    public function claims_a_whole_batch_at_once(string $repositoryClass): void
    {
        // This is what lets a monitor reach its concurrency in one round trip rather than one
        // command per poll, so it is asserted rather than left to the caller to notice.
        $repository = $this->repository($repositoryClass);

        $stored = [];

        for ($i = 0; $i < 5; $i++) {
            $repository->store($uuid = uuid(), new MyCommand());
            $stored[] = $uuid;
        }

        $claimed = $repository->claimPendingCommands(limit: 3);

        $this->assertCount(3, $claimed);
        $this->assertCount(3, array_unique(array_map(fn (ClaimedCommand $c) => $c->uuid, $claimed)));
        $this->assertCount(2, $repository->getPendingCommands());
    }

    #[Test]
    #[DataProvider('repositories')]
    public function claims_no_more_than_is_pending(string $repositoryClass): void
    {
        $repository = $this->repository($repositoryClass);

        $repository->store(uuid(), new MyCommand());

        $this->assertCount(1, $repository->claimPendingCommands(limit: 10));
        $this->assertSame([], $repository->claimPendingCommands(limit: 10));
    }

    #[Test]
    #[DataProvider('repositories')]
    public function a_reservation_can_be_released_immediately(string $repositoryClass): void
    {
        // How the monitor hands work back when a command's process was killed without reporting
        // anything, instead of leaving it reserved until the timeout expires.
        $repository = $this->repository($repositoryClass);

        $repository->store($uuid = uuid(), new MyCommand());
        $repository->claimPendingCommands();

        $repository->releaseReservation($uuid);

        $reclaimed = $this->claim($repository);

        $this->assertNotNull($reclaimed);
        $this->assertSame($uuid, $reclaimed->uuid);
    }

    #[Test]
    #[DataProvider('repositories')]
    public function releasing_a_command_that_is_not_reserved_does_nothing(string $repositoryClass): void
    {
        // The monitor calls this for every failed process, and a command that reported its own
        // failure has already cleared its reservation. It must not come back from the dead.
        $repository = $this->repository($repositoryClass);

        $repository->store($uuid = uuid(), new MyCommand());
        $repository->claimPendingCommands();
        $repository->markAsFailed($uuid);

        $repository->releaseReservation($uuid);

        $this->assertNull($this->claim($repository));
        $this->assertSame([], $repository->getPendingCommands());
    }

    /**
     * Claims a single command, which is what most of these assertions are about. Batching has
     * tests of its own.
     */
    private function claim(ClaimsPendingCommands $repository): ?ClaimedCommand
    {
        return $repository->claimPendingCommands(limit: 1)[0] ?? null;
    }

    private function repository(string $repositoryClass): CommandRepository&ClaimsPendingCommands
    {
        if ($repositoryClass === RedisCommandRepository::class) {
            $this->configureRedis();
        }

        if ($repositoryClass === FileCommandRepository::class) {
            // Given a directory of its own rather than the one shared by every test in the run,
            // so nothing here depends on what another test left behind.
            return new FileCommandRepository($this->container->get(Clock::class), $this->storageDirectory());
        }

        return $this->container->get($repositoryClass);
    }

    private function storageDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/tempest-claim-test-' . getmypid();

        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        $this->storageDirectory = $directory;

        return $directory;
    }

    private function configureRedis(): void
    {
        $this->eventBus->preventEventHandling();

        $this->container->config(new RedisConfig(
            prefix: 'tempest_test:',
            database: 6,
            connectionTimeOut: .2,
        ));

        $this->redis = $this->container->get(Redis::class);

        try {
            $this->redis->connect();
            $this->redis->flush();
        } catch (Throwable) {
            $this->markTestSkipped('Could not connect to Redis.');
        }
    }
}
