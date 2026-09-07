<?php

declare(strict_types=1);

namespace Tempest\CommandBus\AsyncCommandRepositories;

use Tempest\Clock\Clock;
use Tempest\CommandBus\ClaimedCommand;
use Tempest\CommandBus\ClaimsPendingCommands;
use Tempest\CommandBus\CommandRepository;
use Tempest\CommandBus\Exceptions\PendingCommandCouldNotBeResolved;
use Tempest\DateTime\Duration;
use Tempest\Support\Filesystem;
use Tempest\Support\Random;

use function Tempest\Support\arr;

final readonly class FileCommandRepository implements CommandRepository, ClaimsPendingCommands
{
    use UnserializesCommands;

    public function __construct(
        private Clock $clock,

        /**
         * Where stored commands live. Defaulted rather than required so the container can build
         * this without configuration; tests pass a directory of their own to stay isolated.
         */
        private ?string $directory = null,
    ) {}

    public function directory(): string
    {
        return $this->directory ?? __DIR__ . '/../stored-commands';
    }

    public function store(string $uuid, object $command): void
    {
        $payload = serialize($command);

        Filesystem\write_file($this->pendingPath($uuid), $payload);
    }

    public function findPendingCommand(string $uuid): object
    {
        // Reserved commands stay resolvable: a monitor claims a command and then hands the uuid to
        // `command:handle`, which has to be able to load it.
        $path = Filesystem\is_file($this->pendingPath($uuid))
            ? $this->pendingPath($uuid)
            : $this->reservedPath($uuid);

        if ($path === null || ! Filesystem\is_file($path)) {
            throw new PendingCommandCouldNotBeResolved($uuid);
        }

        return $this->unserializeCommand(Filesystem\read_file($path)) ?? throw new PendingCommandCouldNotBeResolved($uuid);
    }

    public function markAsDone(string $uuid): void
    {
        foreach ($this->pathsFor($uuid) as $path) {
            Filesystem\delete_file($path);
        }
    }

    public function markAsFailed(string $uuid): void
    {
        $paths = $this->pathsFor($uuid);

        if ($paths === []) {
            return;
        }

        rename(from: $paths[0], to: $this->path("{$uuid}.failed.txt"));

        // A command is only ever in one place, but clean up any leftovers rather than leaving a
        // reservation behind that would later be released back into the queue.
        foreach (array_slice($paths, 1) as $path) {
            Filesystem\delete_file($path);
        }
    }

    public function getPendingCommands(): array
    {
        return arr(glob($this->path('*.pending.txt')))
            ->mapWithKeys(function (string $path) {
                if (! Filesystem\is_file($path)) {
                    return;
                }

                $uuid = str_replace('.pending.txt', '', pathinfo($path, PATHINFO_BASENAME));
                $command = $this->unserializeCommand(Filesystem\read_file($path));

                if ($command === null) {
                    return;
                }

                yield $uuid => $command;
            })
            ->toArray();
    }

    public function claimPendingCommands(int $limit = 1): array
    {
        if ($limit < 1) {
            return [];
        }

        $claimed = [];

        foreach (glob($this->path('*.pending.txt')) as $path) {
            $uuid = str_replace('.pending.txt', '', pathinfo($path, PATHINFO_BASENAME));

            // The destination carries a token unique to this attempt, so two consumers renaming
            // the same file never both succeed: the loser's source no longer exists.
            $destination = $this->path("{$uuid}." . Random\uuid() . '.reserved.txt');

            if (! @rename($path, $destination)) {
                continue;
            }

            $command = $this->unserializeCommand(Filesystem\read_file($destination));

            if ($command === null) {
                // The payload can never be handled, so it is moved out of the way rather than
                // reserved forever or handed to the next consumer.
                $this->markAsFailed($uuid);

                continue;
            }

            $claimed[] = new ClaimedCommand($uuid, $command);

            if (count($claimed) === $limit) {
                break;
            }
        }

        return $claimed;
    }

    public function releaseReservation(string $uuid): void
    {
        $reserved = $this->reservedPath($uuid);

        if ($reserved === null) {
            return;
        }

        @rename($reserved, $this->pendingPath($uuid));
    }

    public function releaseStaleReservations(Duration $timeout): int
    {
        $threshold = $this->clock->now()->getTimestamp()->getSeconds() - (int) $timeout->getTotalSeconds();
        $released = 0;

        foreach (glob($this->path('*.reserved.txt')) as $path) {
            $reservedAt = @filemtime($path);

            if ($reservedAt === false || $reservedAt > $threshold) {
                continue;
            }

            $uuid = explode('.', pathinfo($path, PATHINFO_BASENAME))[0];

            if (@rename($path, $this->pendingPath($uuid))) {
                $released++;
            }
        }

        return $released;
    }

    /**
     * Every file currently holding this command, pending or reserved.
     *
     * @return string[]
     */
    private function pathsFor(string $uuid): array
    {
        $paths = [];

        if (Filesystem\is_file($this->pendingPath($uuid))) {
            $paths[] = $this->pendingPath($uuid);
        }

        return [...$paths, ...glob($this->path("{$uuid}.*.reserved.txt"))];
    }

    private function reservedPath(string $uuid): ?string
    {
        return glob($this->path("{$uuid}.*.reserved.txt"))[0] ?? null;
    }

    private function pendingPath(string $uuid): string
    {
        return $this->path("{$uuid}.pending.txt");
    }

    private function path(string $file): string
    {
        return $this->directory() . '/' . $file;
    }
}
