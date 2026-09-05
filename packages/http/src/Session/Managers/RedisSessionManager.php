<?php

declare(strict_types=1);

namespace Tempest\Http\Session\Managers;

use Tempest\Clock\Clock;
use Tempest\Http\Session\Config\RedisSessionConfig;
use Tempest\Http\Session\Session;
use Tempest\Http\Session\SessionCreated;
use Tempest\Http\Session\SessionDeleted;
use Tempest\Http\Session\SessionId;
use Tempest\Http\Session\SessionManager;
use Tempest\KeyValue\Redis\Redis;
use Throwable;

use function Tempest\EventBus\event;

final readonly class RedisSessionManager implements SessionManager
{
    public function __construct(
        private Clock $clock,
        private Redis $redis,
        private RedisSessionConfig $config,
    ) {}

    public function getOrCreate(SessionId $id): Session
    {
        $now = $this->clock->now();
        $session = $this->load($id);

        if (! $session instanceof Session) {
            $session = new Session(
                id: $id,
                createdAt: $now,
                lastActiveAt: $now,
            );

            event(new SessionCreated($session));
        }

        return $session;
    }

    public function save(Session $session): void
    {
        $session->lastActiveAt = $this->clock->now();

        $this->redis->set(
            key: $this->getKey($session->id),
            value: serialize($session),
            expiration: $this->config->expiration,
        );
    }

    public function delete(Session $session): void
    {
        $this->redis->command('UNLINK', $this->getKey($session->id));

        event(new SessionDeleted($session->id));
    }

    public function isValid(Session $session): bool
    {
        return $this->clock->now()->before(
            other: $session->lastActiveAt->plus($this->config->expiration),
        );
    }

    /**
     * Expired sessions are removed by Redis itself, as {@see self::save()} writes every session
     * with a time-to-live equal to the configured expiration. There is nothing left to collect,
     * so this is a no-op.
     *
     * As a consequence, {@see SessionDeleted} is only dispatched when a session is explicitly
     * deleted, never when it expires.
     */
    public function deleteExpiredSessions(): void {}

    private function load(SessionId $id): ?Session
    {
        try {
            return unserialize(
                data: $this->redis->get($this->getKey($id)),
                options: ['allowed_classes' => true],
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function getKey(SessionId $id): string
    {
        return sprintf('%s%s', $this->config->prefix, $id);
    }
}
