<?php

declare(strict_types=1);

namespace Tests\Tempest\Integration\CommandBus;

use PHPUnit\Framework\Attributes\PostCondition;
use PHPUnit\Framework\Attributes\PreCondition;
use PHPUnit\Framework\Attributes\Test;
use Tempest\CommandBus\Installer\CommandRepositoryInstaller;
use Tempest\Support\Namespace\Psr4Namespace;
use Tests\Tempest\Integration\FrameworkIntegrationTestCase;

/**
 * @internal
 */
final class CommandRepositoryInstallerTest extends FrameworkIntegrationTestCase
{
    #[PreCondition]
    protected function configure(): void
    {
        $this->installer
            ->configure(__DIR__ . '/install', new Psr4Namespace('App\\', __DIR__ . '/install/App'))
            ->setRoot(__DIR__ . '/install');
    }

    #[PostCondition]
    protected function cleanup(): void
    {
        $this->installer->clean();
    }

    #[Test]
    public function installs_database_command_bus_config_and_migration(): void
    {
        $this->console
            ->call(sprintf('install %s', CommandRepositoryInstaller::class))
            ->input(0)
            ->confirm()
            ->input('App/CommandBus/command-bus.config.php')
            ->deny()
            ->assertSuccess();

        $this->installer
            ->assertFileExists('App/CommandBus/CreateCommandsTable.php')
            ->assertFileExists('App/CommandBus/AddReservationsToCommandsTable.php')
            ->assertFileExists('App/CommandBus/command-bus.config.php');

        $this->installer->assertFileContains('App/CommandBus/command-bus.config.php', 'CommandBusConfig');
    }

    #[Test]
    public function installs_redis_command_bus_config(): void
    {
        $this->console
            ->call(sprintf('install %s', CommandRepositoryInstaller::class))
            ->input(1)
            ->input('App/CommandBus/command-bus.config.php')
            ->assertSuccess();

        $this->installer
            ->assertFileExists('App/CommandBus/command-bus.config.php');

        $this->installer->assertFileContains('App/CommandBus/command-bus.config.php', 'CommandBusConfig');
    }
}
