<?php

declare(strict_types=1);

namespace Tempest\CommandBus;

/**
 * A pending command that has been reserved by a single consumer.
 */
final readonly class ClaimedCommand
{
    public function __construct(
        public string $uuid,
        public object $command,
    ) {}
}
