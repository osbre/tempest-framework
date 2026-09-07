<?php

declare(strict_types=1);

namespace Tempest\CommandBus;

use DateTimeImmutable;
use Deprecated;
use Symfony\Component\Process\Process;
use Tempest\CommandBus\AsyncCommandRepositories\RedisCommandRepository;
use Tempest\Console\Console;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\HasConsole;
use Tempest\Console\Input\ConsoleArgumentBag;

use function Tempest\Support\arr;

if (class_exists(ConsoleCommand::class)) {
    final class MonitorAsyncCommands
    {
        use HasConsole;

        /**
         * How often abandoned reservations are looked for. Checking on every tick would mean
         * scanning for stale commands twice a second for no benefit.
         */
        private const float RELEASE_INTERVAL_SECONDS = 10.0;

        /**
         * How much the interval is varied per sweep. Without it, monitors started by the same
         * deploy sweep in lockstep forever, and every sweep contends with every other one.
         */
        private const float RELEASE_INTERVAL_JITTER = 0.25;

        private float $releaseStaleReservationsAt = 0.0;

        public function __construct(
            private readonly CommandRepository $repository,
            private readonly CommandBusConfig $commandBusConfig,
            private readonly ConsoleArgumentBag $argumentBag,
            private readonly Console $console,
        ) {}

        #[ConsoleCommand(name: 'command:monitor', description: 'Monitors and executes pending async commands')]
        public function __invoke(): void
        {
            $this->migrateStoredCommands();

            $this->info('Monitoring for new commands. Press <em>Ctrl+C</em> to stop.');

            if (! $this->repository instanceof ClaimsPendingCommands) {
                $this->writeln();
                $this->writeln("<style='fg-yellow'>This command repository cannot reserve commands, so only one monitor may run at a time.</style>");
            }

            $this->writeln();

            /** @var \Symfony\Component\Process\Process[] $processes */
            $processes = [];

            while (true) { // @phpstan-ignore-line
                foreach ($processes as $uuid => $process) {
                    if (! $process->isTerminated()) {
                        continue;
                    }

                    $this->reportResult($uuid, $process);

                    unset($processes[$uuid]);
                }

                $capacity = $this->commandBusConfig->concurrency - count($processes);

                if ($capacity <= 0) {
                    $this->sleep(0.5);

                    continue;
                }

                $uuids = $this->nextCommands($processes, $capacity);

                if ($uuids === []) {
                    $this->releaseStaleReservations();
                    $this->sleep(0.5);

                    continue;
                }

                foreach ($uuids as $uuid) {
                    $time = new DateTimeImmutable();
                    $this->console->keyValue(
                        key: $uuid,
                        value: "<style='fg-gray'>{$time->format('Y-m-d H:i:s')}</style>",
                    );

                    $process = new Process([
                        $this->argumentBag->getBinaryPath(),
                        $this->argumentBag->getCliName(),
                        'command:handle',
                        $uuid,
                    ], getcwd());

                    $process->start();

                    $processes[$uuid] = $process;
                }
            }
        }

        /**
         * Reserves as many commands as there is room to run, or returns an empty array when there
         * is nothing to do. Claiming the whole batch at once is what lets a monitor reach its
         * concurrency in one round trip instead of one command per poll.
         *
         * @param \Symfony\Component\Process\Process[] $processes
         * @return string[]
         */
        private function nextCommands(array $processes, int $limit): array
        {
            if ($this->repository instanceof ClaimsPendingCommands) {
                // The reservation is what keeps two monitors off the same command, so nothing here
                // needs to look at what this monitor is already running.
                return array_map(
                    static fn (ClaimedCommand $claimed) => $claimed->uuid,
                    $this->repository->claimPendingCommands($limit),
                );
            }

            // Without reservations, the best that can be done is to skip what this monitor already
            // started. A second monitor would pick up the same commands.
            return arr($this->repository->getPendingCommands())
                ->filter(fn (object $_, string $uuid) => ! array_key_exists($uuid, $processes))
                ->keys()
                ->slice(0, $limit)
                ->toArray();
        }

        /**
         * Hands abandoned commands back to the queue. A command is abandoned when the process that
         * reserved it died without reporting success or failure, which leaves the reservation in
         * place with nothing working on it.
         */
        private function releaseStaleReservations(): void
        {
            if (! $this->repository instanceof ClaimsPendingCommands) {
                return;
            }

            if (microtime(true) < $this->releaseStaleReservationsAt) {
                return;
            }

            $this->releaseStaleReservationsAt = microtime(true) + $this->nextReleaseInterval();

            $released = $this->repository->releaseStaleReservations($this->commandBusConfig->reservationTimeout);

            if ($released === 0) {
                return;
            }

            $this->writeln("<style='fg-yellow'>Released <em>{$released}</em> abandoned command(s) back to the queue.</style>");
        }

        private function nextReleaseInterval(): float
        {
            $spread = self::RELEASE_INTERVAL_SECONDS * self::RELEASE_INTERVAL_JITTER;

            return self::RELEASE_INTERVAL_SECONDS + ((((mt_rand(0, 1_000) / 1_000) * 2) - 1) * $spread);
        }

        private function reportResult(string $uuid, Process $process): void
        {
            if ($process->isSuccessful()) {
                $this->console->keyValue(
                    key: "<style='fg-gray'>{$uuid}</style>",
                    value: "<style='fg-green bold'>SUCCESS</style>",
                );
            } else {
                $this->console->keyValue(
                    key: "<style='fg-gray'>{$uuid}</style>",
                    value: "<style='fg-red bold'>FAILED</style>",
                );

                // A command that reported failure cleared its own reservation, so this only does
                // anything when the process was killed outright — an out of memory kill, or a
                // deploy — and never got to say so. Handing the command back here recovers it
                // immediately, instead of leaving it reserved until the timeout expires.
                if ($this->repository instanceof ClaimsPendingCommands) {
                    $this->repository->releaseReservation($uuid);
                }
            }

            $output = trim($process->getOutput());

            if ($output !== '' && $output !== '0') {
                $this->writeln($output);
            }

            $errorOutput = trim($process->getErrorOutput());

            if ($errorOutput !== '' && $errorOutput !== '0') {
                $this->writeln($errorOutput);
            }
        }

        /**
         * Moves Redis commands stored by an older version over, on the first start after the upgrade.
         */
        #[Deprecated(message: 'Remove in 4.0.')]
        private function migrateStoredCommands(): void
        {
            if (! $this->repository instanceof RedisCommandRepository) {
                return;
            }

            $migrated = $this->repository->migrateStoredCommands();

            if ($migrated === 0) {
                return;
            }

            $this->info("Migrated <em>{$migrated}</em> stored commands to the current storage format.");
        }

        private function sleep(float $seconds): void
        {
            usleep((int) ($seconds * 1_000_000));
        }
    }
}
