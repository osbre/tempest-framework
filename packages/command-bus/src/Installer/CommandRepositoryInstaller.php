<?php

declare(strict_types=1);

namespace Tempest\CommandBus\Installer;

use Tempest\Core\Installer;
use Tempest\Core\PublishesFiles;
use Tempest\Database\Migrations\MigrationManager;

use function Tempest\src_path;

final class CommandRepositoryInstaller
{
    use PublishesFiles;

    public function __construct(
        private readonly MigrationManager $migrationManager,
    ) {}

    #[Installer('Command bus', alias: 'command-bus')]
    public function install(?CommandBusStorage $storage = null, ?bool $migrate = null): void
    {
        $storage ??= $this->console->ask(
            question: 'Which command bus storage do you want to use?',
            options: CommandBusStorage::class,
            default: CommandBusStorage::DATABASE,
        );

        if (! $storage instanceof CommandBusStorage) {
            $this->console->error('Invalid command bus storage selected.');
            return;
        }

        $migration = null;

        if ($storage === CommandBusStorage::DATABASE) {
            $migration = $this->publish(
                source: __DIR__ . '/CreateCommandsTable.php',
                destination: src_path('CommandBus/CreateCommandsTable.php'),
            );

            // Not confirmed separately: the repository does not work without these columns, so
            // there is nothing useful to install without it.
            $this->publish(
                source: __DIR__ . '/AddReservationsToCommandsTable.php',
                destination: src_path('CommandBus/AddReservationsToCommandsTable.php'),
                confirm: false,
            );
        }

        $this->publish(
            source: match ($storage) {
                CommandBusStorage::DATABASE => __DIR__ . '/command-bus.database.config.stub.php',
                CommandBusStorage::REDIS => __DIR__ . '/command-bus.redis.config.stub.php',
            },
            destination: $this->promptTargetPath(src_path('CommandBus/commandBus.config.php')),
            confirm: false,
        );

        $this->publishImports();

        if ($migration && $this->shouldMigrate($migrate)) {
            $this->migrationManager->up();
        }
    }

    private function shouldMigrate(?bool $migrate): bool
    {
        if (is_bool($migrate)) {
            return $migrate;
        }

        return $this->console->confirm('Do you want to execute migrations?', default: false);
    }
}
