<?php

declare(strict_types=1);

namespace Tempest\Database;

use Tempest\Container\Singleton;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Exceptions\QueryWasInvalid;
use UnitEnum;

/**
 * Answers what the database being talked to is capable of, where the dialect alone does not say.
 *
 * Answers are resolved once per database and remembered, since they cannot change while the
 * connection is open.
 */
#[Singleton]
final class DatabaseCapabilities
{
    /** @var array<string, bool> */
    private array $skipLocked = [];

    /**
     * Whether `SELECT ... FOR UPDATE SKIP LOCKED` is available, which lets a reader walk past rows
     * another connection has locked instead of queueing behind them.
     *
     * Postgres has had it since 9.5 and SQLite has no row locks at all, so only MySQL and MariaDB
     * have to be asked: MySQL gained it in 8.0, MariaDB in 10.6.
     */
    /**
     * The clause that locks the rows a `SELECT` reads, so a concurrent reader cannot claim them
     * too, or `null` where the database has no row locks to take.
     *
     * `SKIP LOCKED` is preferred, since it lets each reader walk past the rows another one holds.
     * Without it the readers have to queue behind each other, which is slower but still correct —
     * a plain `SELECT` would not be, because MySQL and MariaDB read a snapshot under REPEATABLE
     * READ and would hand the same rows to every reader at once.
     */
    public function rowLockClause(Database $database): ?string
    {
        if ($this->supportsSkipLocked($database)) {
            return 'FOR UPDATE SKIP LOCKED';
        }

        return match ($database->dialect) {
            // SQLite serialises writers by itself and has nothing to lock at row level.
            DatabaseDialect::SQLITE => null,
            default => 'FOR UPDATE',
        };
    }

    public function supportsSkipLocked(Database $database): bool
    {
        return $this->skipLocked[$this->key($database)] ??= match ($database->dialect) {
            DatabaseDialect::SQLITE => false,
            DatabaseDialect::POSTGRESQL => true,
            DatabaseDialect::MYSQL => $this->mysqlSupportsSkipLocked($database),
        };
    }

    private function mysqlSupportsSkipLocked(Database $database): bool
    {
        try {
            $version = (string) ($database->fetchFirst(new Query('SELECT VERSION() AS version'))['version'] ?? '');
        } catch (QueryWasInvalid) {
            // Nothing is known about the server, so the caller is left with the option that works
            // everywhere rather than one that may not parse.
            return false;
        }

        // MariaDB may still prefix its version with the `5.5.5-` it once used to keep clients that
        // could not read a two-digit major version working.
        $version = str_starts_with($version, '5.5.5-') ? substr($version, strlen('5.5.5-')) : $version;

        // `10.5.2-MariaDB` against `8.4.0`: the suffix is what tells the two products apart.
        $isMariaDb = str_contains(strtolower($version), 'mariadb');

        return version_compare($version, $isMariaDb ? '10.6' : '8.0', '>=');
    }

    private function key(Database $database): string
    {
        $tag = match (true) {
            $database->tag instanceof UnitEnum => $database->tag::class . '::' . $database->tag->name,
            default => (string) $database->tag,
        };

        return $database->dialect->value . ':' . $tag;
    }
}
