<?php

declare(strict_types=1);

namespace Tests\Tempest\Integration\Database;

use PHPUnit\Framework\Attributes\Test;
use Tempest\Database\Config\DatabaseDialect;
use Tempest\Database\Database;
use Tempest\Database\DatabaseCapabilities;
use Tempest\Database\Query;
use Tests\Tempest\Integration\FrameworkIntegrationTestCase;

/**
 * @internal
 */
final class DatabaseCapabilitiesTest extends FrameworkIntegrationTestCase
{
    #[Test]
    public function claiming_readers_always_take_a_lock_where_there_is_one_to_take(): void
    {
        $database = $this->container->get(Database::class);
        $capabilities = $this->container->get(DatabaseCapabilities::class);

        $clause = $capabilities->rowLockClause($database);

        // A reader that claims rows for itself must lock them as it reads. Without a lock, MySQL
        // and MariaDB serve every concurrent reader the same snapshot under REPEATABLE READ, and
        // each of them goes on to claim the same rows. Only SQLite, which admits a single writer,
        // may read them unlocked.
        if ($database->dialect === DatabaseDialect::SQLITE) {
            $this->assertNull($clause);

            return;
        }

        $this->assertContains($clause, ['FOR UPDATE SKIP LOCKED', 'FOR UPDATE']);

        // `SKIP LOCKED` is only claimed where the server actually has it, since a server that does
        // not will reject the statement outright rather than ignore the hint.
        if ($clause === 'FOR UPDATE SKIP LOCKED') {
            $this->assertTrue($capabilities->supportsSkipLocked($database));
        }
    }

    #[Test]
    public function the_lock_clause_is_accepted_by_the_server(): void
    {
        $database = $this->container->get(Database::class);
        $capabilities = $this->container->get(DatabaseCapabilities::class);

        $clause = $capabilities->rowLockClause($database);

        if ($clause === null) {
            $this->markTestSkipped('This database has no row locks to take.');
        }

        // Whichever clause was chosen has to parse on the server it was chosen for: a MariaDB 10.5
        // told to `SKIP LOCKED` fails here rather than in a monitor.
        $database->execute(new Query("SELECT 1 {$clause}"));

        $this->assertTrue(true);
    }
}
