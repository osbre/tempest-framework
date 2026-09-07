<?php

declare(strict_types=1);

namespace Tempest\CommandBus\AsyncCommandRepositories;

use Throwable;

/**
 * Turns a stored payload back into a command, whatever the backend it was read from.
 */
trait UnserializesCommands
{
    private function unserializeCommand(string $payload): ?object
    {
        try {
            // A corrupted payload warns and returns `false` instead of throwing, but a custom
            // `__wakeup()` or `__unserialize()` can still throw.
            $command = @unserialize($payload);
        } catch (Throwable) {
            return null;
        }

        return is_object($command) ? $command : null;
    }
}
