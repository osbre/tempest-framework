<?php

declare(strict_types=1);

namespace Tempest\CommandBus;

use Tempest\DateTime\Duration;

/**
 * A command repository that can reserve commands for a single consumer.
 *
 * Without this, a consumer has to read every pending command to pick one, and two consumers
 * running at once both pick up the same commands. Repositories that implement this hand out
 * each command once, which makes running more than one monitor safe.
 */
interface ClaimsPendingCommands
{
    /**
     * Reserves up to `$limit` pending commands and returns them. Fewer than asked for, down to
     * none at all, means there was not that much work waiting.
     *
     * Order is whatever the backend finds cheapest to hand out; nothing may depend on commands
     * being handled in the order they were stored.
     *
     * Implementations must make this atomic: two consumers claiming at the same time never
     * receive the same command.
     *
     * @return \Tempest\CommandBus\ClaimedCommand[]
     */
    public function claimPendingCommands(int $limit = 1): array;

    /**
     * Makes a single reserved command pending again. Does nothing when the command is not
     * reserved, so it is safe to call for a command that already reported success or failure.
     *
     * This is how a consumer hands work back the moment it knows it was abandoned, instead of
     * waiting for the reservation to go stale.
     */
    public function releaseReservation(string $uuid): void;

    /**
     * Makes commands that have been reserved for longer than the given duration pending again,
     * and returns how many were released.
     *
     * This is the backstop for a consumer that died without anyone noticing. It is time-based, so
     * a command that merely outlives the timeout is handled a second time.
     */
    public function releaseStaleReservations(Duration $timeout): int;
}
