<?php

declare(strict_types=1);

namespace Tempest\CommandBus\AsyncCommandRepositories;

use Tempest\Database\PrimaryKey;
use Tempest\Database\Table;
use Tempest\Database\Uuid;
use Tempest\DateTime\DateTime;

#[Table('commands')]
final class StoredCommand
{
    #[Uuid]
    public PrimaryKey $id;

    public string $payload;

    public ?DateTime $failed_at = null;

    /**
     * When a consumer reserved this command, as a Unix timestamp. Set while it is being handled,
     * so no other consumer picks it up, and cleared again if that consumer dies without finishing.
     *
     * Seconds since the epoch rather than a datetime, because a datetime means agreeing on a
     * timezone: SQLite's clock is always UTC, MySQL's `NOW()` follows the server's `time_zone` and
     * Postgres' follows the session's, and none of them follow the application's. Consumers on
     * different hosts have to compare these to the second, so the value must mean the same thing
     * everywhere it is written and read.
     */
    public ?int $reserved_at = null;

    /**
     * Which consumer holds the reservation, for debugging a stuck queue.
     */
    public ?string $reserved_by = null;
}
