<?php

declare(strict_types=1);

namespace Tempest\CommandBus\AsyncCommandRepositories;

use Tempest\Clock\Clock;
use Tempest\Clock\GenericClock;
use Tempest\CommandBus\ClaimedCommand;
use Tempest\CommandBus\ClaimsPendingCommands;
use Tempest\CommandBus\CommandRepository;
use Tempest\DateTime\Duration;

final class MemoryRepository implements CommandRepository, ClaimsPendingCommands
{
    private array $commands = [];

    /** @var array<string, object> */
    private array $reserved = [];

    /** @var array<string, int> */
    private array $reservedAt = [];

    private readonly Clock $clock;

    public function __construct(?Clock $clock = null)
    {
        // Defaulted rather than required, so this stays usable as a plain `new MemoryRepository()`
        // in tests that have no container to resolve a clock from.
        $this->clock = $clock ?? new GenericClock();
    }

    public function store(string $uuid, object $command): void
    {
        $this->commands[$uuid] = $command;
    }

    public function getPendingCommands(): array
    {
        return $this->commands;
    }

    public function findPendingCommand(string $uuid): object
    {
        return $this->commands[$uuid] ?? $this->reserved[$uuid];
    }

    public function markAsDone(string $uuid): void
    {
        unset($this->commands[$uuid], $this->reserved[$uuid], $this->reservedAt[$uuid]);
    }

    public function markAsFailed(string $uuid): void
    {
        $this->markAsDone($uuid);
    }

    public function claimPendingCommands(int $limit = 1): array
    {
        $claimed = [];

        foreach (array_slice($this->commands, 0, max($limit, 0), preserve_keys: true) as $uuid => $command) {
            unset($this->commands[$uuid]);

            $this->reserved[$uuid] = $command;
            $this->reservedAt[$uuid] = $this->clock->now()->getTimestamp()->getSeconds();

            $claimed[] = new ClaimedCommand($uuid, $command);
        }

        return $claimed;
    }

    public function releaseReservation(string $uuid): void
    {
        if (! array_key_exists($uuid, $this->reserved)) {
            return;
        }

        $this->commands[$uuid] = $this->reserved[$uuid];

        unset($this->reserved[$uuid], $this->reservedAt[$uuid]);
    }

    public function releaseStaleReservations(Duration $timeout): int
    {
        $threshold = $this->clock->now()->getTimestamp()->getSeconds() - (int) $timeout->getTotalSeconds();
        $released = 0;

        foreach ($this->reservedAt as $uuid => $reservedAt) {
            if ($reservedAt > $threshold) {
                continue;
            }

            $this->commands[$uuid] = $this->reserved[$uuid];

            unset($this->reserved[$uuid], $this->reservedAt[$uuid]);

            $released++;
        }

        return $released;
    }
}
