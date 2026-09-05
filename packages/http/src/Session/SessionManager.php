<?php

declare(strict_types=1);

namespace Tempest\Http\Session;

interface SessionManager
{
    /**
     * Retrieves or creates a session based on its identifier.
     */
    public function getOrCreate(SessionId $id): Session;

    /**
     * Saves the session data to the server.
     */
    public function save(Session $session): void;

    /**
     * Removes the session from the server.
     */
    public function delete(Session $session): void;

    /**
     * Determines whether the session is still valid.
     */
    public function isValid(Session $session): bool;

    /**
     * Removes all expired sessions from the server, dispatching {@see SessionDeleted} for each one.
     *
     * Drivers backed by a store that expires entries on its own may implement this as a no-op. In
     * that case, {@see SessionDeleted} is only dispatched when a session is explicitly deleted.
     */
    public function deleteExpiredSessions(): void;
}
