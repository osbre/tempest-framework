<?php

declare(strict_types=1);

namespace Tempest\Http\Session\Managers;

use Tempest\Clock\Clock;
use Tempest\DateTime\FormatPattern;
use Tempest\Http\Session\Config\DatabaseSessionConfig;
use Tempest\Http\Session\Session;
use Tempest\Http\Session\SessionCreated;
use Tempest\Http\Session\SessionDeleted;
use Tempest\Http\Session\SessionId;
use Tempest\Http\Session\SessionManager;

use function Tempest\Database\query;
use function Tempest\EventBus\event;

final readonly class DatabaseSessionManager implements SessionManager
{
    public function __construct(
        private Clock $clock,
        private DatabaseSessionConfig $config,
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

        $existing = query(DatabaseSession::class)
            ->select()
            ->where('id', (string) $session->id)
            ->first();

        if ($existing === null) {
            query(DatabaseSession::class)
                ->insert(
                    id: (string) $session->id,
                    data: serialize($session->data),
                    created_at: $session->createdAt,
                    last_active_at: $session->lastActiveAt,
                )
                ->execute();

            return;
        }

        query(DatabaseSession::class)
            ->update(
                data: serialize($session->data),
                last_active_at: $session->lastActiveAt,
            )
            ->where('id', (string) $session->id)
            ->execute();
    }

    public function delete(Session $session): void
    {
        query(DatabaseSession::class)
            ->delete()
            ->where('id', (string) $session->id)
            ->execute();

        event(new SessionDeleted($session->id));
    }

    public function isValid(Session $session): bool
    {
        return $this->clock->now()->before(
            other: $session->lastActiveAt->plus($this->config->expiration),
        );
    }

    public function deleteExpiredSessions(): void
    {
        $expired = $this->clock
            ->now()
            ->minus($this->config->expiration);

        // Expired sessions are removed in batches. A single statement would bind one parameter per
        // session, which overruns the per-statement parameter limits of PostgreSQL and SQLite once
        // a backlog builds up, and would hold locks on the table for the length of the whole delete.
        do {
            // Only the identifiers are needed, so the serialized session data is left unread.
            $expiredSessions = query(DatabaseSession::class)
                ->select('id')
                ->where('last_active_at < ?', $expired->format(FormatPattern::SQL_DATE_TIME))
                ->limit($this->config->cleanupBatchSize)
                ->all();

            if ($expiredSessions === []) {
                return;
            }

            $ids = array_map(
                callback: static fn (DatabaseSession $session) => (string) $session->id,
                array: $expiredSessions,
            );

            query(DatabaseSession::class)
                ->delete()
                ->whereIn('id', $ids)
                ->execute();

            foreach ($ids as $id) {
                event(new SessionDeleted(new SessionId($id)));
            }
        } while (count($expiredSessions) === $this->config->cleanupBatchSize);
    }

    private function load(SessionId $id): ?Session
    {
        $session = query(DatabaseSession::class)
            ->select()
            ->where('id', (string) $id)
            ->first();

        if ($session === null) {
            return null;
        }

        return new Session(
            id: $id,
            createdAt: $session->created_at,
            lastActiveAt: $session->last_active_at,
            data: unserialize($session->data),
        );
    }
}
