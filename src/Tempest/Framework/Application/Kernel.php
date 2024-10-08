<?php

declare(strict_types=1);

namespace Tempest\Framework\Application;

use Tempest\Container\Container;
use Tempest\Container\GenericContainer;
use Tempest\Framework\Bootstraps\ConfigBootstrap;
use Tempest\Framework\Bootstraps\DiscoveryBootstrap;
use Tempest\Framework\Bootstraps\DiscoveryLocationBootstrap;

final readonly class Kernel
{
    public function __construct(
        private AppConfig $appConfig,
    ) {
    }

    public function init(): Container
    {
        register_shutdown_function(function () {
            $error = error_get_last();

            $message = $error['message'] ?? '';

            if (str_contains($message, 'Cannot declare class')) {
                echo "Does this class have the right namespace?" . PHP_EOL;
            }
        });

        $container = $this->createContainer();

        $bootstraps = [
            DiscoveryLocationBootstrap::class,
            ConfigBootstrap::class,
            DiscoveryBootstrap::class,
        ];

        foreach ($bootstraps as $bootstrap) {
            $container->get(
                $bootstrap,
                kernel: $this,
                appConfig: $this->appConfig,
            )->boot();
        }

        return $container;
    }

    private function createContainer(): Container
    {
        $container = new GenericContainer();

        GenericContainer::setInstance($container);

        $container
            ->config($this->appConfig)
            ->singleton(self::class, fn () => $this)
            ->singleton(Container::class, fn () => $container);

        return $container;
    }
}
