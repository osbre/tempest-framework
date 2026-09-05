<?php

declare(strict_types=1);

namespace Tempest\Http\Session\Config;

use Tempest\Container\Container;
use Tempest\DateTime\Duration;
use Tempest\Http\Session\CleanupStrategy;
use Tempest\Http\Session\Managers\DatabaseSessionManager;
use Tempest\Http\Session\SessionConfig;

final class DatabaseSessionConfig implements SessionConfig
{
    /**
     * @param Duration $expiration Time required for a session to expire.
     * @param CleanupStrategy $cleanupStrategy Strategy for cleaning up expired sessions. Defaults to `RANDOM_REQUESTS`, which provides a good balance between performance and cleanup frequency.
     * @param int $cleanupBatchSize Number of expired sessions removed per query during cleanup. Each session in a batch binds one query parameter, so this must stay below the parameter limit of the underlying database. Larger batches use fewer queries, but hold locks on the table for longer.
     */
    public function __construct(
        private(set) Duration $expiration,
        private(set) CleanupStrategy $cleanupStrategy = CleanupStrategy::RANDOM_REQUESTS,
        private(set) int $cleanupBatchSize = 500,
    ) {}

    public function createManager(Container $container): DatabaseSessionManager
    {
        return $container->get(DatabaseSessionManager::class);
    }
}
