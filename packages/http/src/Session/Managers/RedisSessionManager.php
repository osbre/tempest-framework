<?php

declare(strict_types=1);

namespace Tempest\Http\Session\Managers;

use Tempest\Clock\Clock;
use Tempest\DateTime\Duration;
use Tempest\Http\Session\Config\RedisSessionConfig;
use Tempest\Http\Session\Session;
use Tempest\Http\Session\SessionCreated;
use Tempest\Http\Session\SessionDeleted;
use Tempest\Http\Session\SessionId;
use Tempest\Http\Session\SessionManager;
use Tempest\KeyValue\Redis\Redis;
use Tempest\Support\Str;
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

        // Expired sessions are not resurrected. Cleanup is lazy, and may be disabled
        // entirely, so expiration is enforced when the session is loaded.
        if ($session instanceof Session && ! $this->isValid($session)) {
            $this->delete($session);

            $session = null;
        }

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
            expiration: $this->resolveExpiration($session),
        );
    }

    public function delete(Session $session): void
    {
        $this->redis->command('UNLINK', $this->getKey($session->id));

        event(new SessionDeleted($session->id));
    }

    public function isValid(Session $session): bool
    {
        return ! $session->hasExpired(
            now: $this->clock->now(),
            expiration: $this->config->expiration,
            absoluteExpiration: $this->config->absoluteExpiration,
        );
    }

    public function deleteExpiredSessions(): void
    {
        $cursor = '0';

        do {
            /** @var array<int,string> $keys */
            [$cursor, $keys] = $this->redis->command('SCAN', $cursor, 'MATCH', "{$this->config->prefix}*", 'COUNT', '100');

            foreach ($keys as $key) {
                $sessionId = $this->getSessionIdFromKey($key);
                $session = $this->load($sessionId);

                if (! $session instanceof Session) {
                    continue;
                }

                if ($this->isValid($session)) {
                    continue;
                }

                $this->delete($session);
            }
        } while ($cursor !== '0');
    }

    /**
     * Resolves the time-to-live of the session key, which may never outlive the absolute
     * expiration - otherwise an active session would keep pushing its own deadline back.
     */
    private function resolveExpiration(Session $session): Duration
    {
        if ($this->config->absoluteExpiration === null) {
            return $this->config->expiration;
        }

        $remaining = $session
            ->createdAt
            ->plus($this->config->absoluteExpiration)
            ->since($this->clock->now());

        if ($remaining->getTotalSeconds() < $this->config->expiration->getTotalSeconds()) {
            return $remaining;
        }

        return $this->config->expiration;
    }

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

    private function getSessionIdFromKey(string $key): SessionId
    {
        return new SessionId(Str\after_first($key, $this->config->prefix));
    }
}
