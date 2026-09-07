<?php

declare(strict_types=1);

namespace Tempest\CommandBus\Installer;

use Tempest\Database\MigratesUp;
use Tempest\Database\QueryStatement;
use Tempest\Database\QueryStatements\AlterTableStatement;
use Tempest\Database\QueryStatements\CompoundStatement;
use Tempest\Database\QueryStatements\DatabaseIntegerSize;
use Tempest\Database\QueryStatements\IndexStatement;
use Tempest\Database\QueryStatements\IntegerStatement;
use Tempest\Database\QueryStatements\VarcharStatement;
use Tempest\Discovery\SkipDiscovery;

/**
 * Adds the columns a consumer needs to reserve a command for itself.
 *
 * This is kept separate from `create_commands_table` rather than folded into it, so that
 * installations created before reservations existed can run it on top of the table they already
 * have. New installations simply run both, in order.
 */
#[SkipDiscovery]
final class AddReservationsToCommandsTable implements MigratesUp
{
    private(set) string $name = '0000-00-01_add_reservations_to_commands_table';

    public function up(): QueryStatement
    {
        // One column per statement, because SQLite only accepts a single `ADD` per `ALTER TABLE`.
        return new CompoundStatement(
            // A Unix timestamp, not a datetime: see `StoredCommand::$reserved_at`. `BIGINT`
            // because a signed `INTEGER` stops being able to hold one in 2038.
            new AlterTableStatement('commands')->add(
                new IntegerStatement('reserved_at', nullable: true, size: DatabaseIntegerSize::BIG),
            ),
            new AlterTableStatement('commands')->add(new VarcharStatement('reserved_by', nullable: true)),
            // Claiming filters on `reserved_at` on every poll, and releasing scans for stale ones.
            new IndexStatement(tableName: 'commands', columns: ['reserved_at']),
        );
    }
}
