<?php

declare(strict_types=1);

namespace Tempest\CommandBus;

use Tempest\CommandBus\AsyncCommandRepositories\FileCommandRepository;
use Tempest\Core\Middleware;
use Tempest\DateTime\Duration;
use Tempest\Reflection\MethodReflector;

final class CommandBusConfig
{
    /**
     * How long a command may stay reserved before it is considered abandoned and is handed out
     * again. Set this above the runtime of your slowest command, otherwise a command that is
     * merely slow is handled a second time while the first one is still running.
     *
     * Only applies to repositories that implement {@see \Tempest\CommandBus\ClaimsPendingCommands}.
     */
    public Duration $reservationTimeout;

    public function __construct(
        /** @var \Tempest\CommandBus\CommandHandler[] */
        public array $handlers = [],

        /** @var Middleware<\Tempest\CommandBus\CommandBusMiddleware> */
        public Middleware $middleware = new Middleware(),

        /** @var class-string<\Tempest\CommandBus\CommandRepository> $commandRepositoryClass */
        public string $commandRepositoryClass = FileCommandRepository::class,

        /**
         * How many async commands a single monitor may handle at the same time.
         */
        public int $concurrency = 5,

        ?Duration $reservationTimeout = null,
    ) {
        // `Duration` has a private constructor, so this cannot be a parameter default.
        $this->reservationTimeout = $reservationTimeout ?? Duration::minutes(5);
    }

    /**
     * @throws CommandHandlerWasAlreadyRegistered
     */
    public function addHandler(CommandHandler $commandHandler, string $commandName, MethodReflector $handler): self
    {
        if (array_key_exists($commandName, $this->handlers)) {
            throw new CommandHandlerWasAlreadyRegistered($commandName, new: $handler, existing: $this->handlers[$commandName]->handler);
        }

        $this->handlers[$commandName] = $commandHandler
            ->setCommandName($commandName)
            ->setHandler($handler);

        return $this;
    }
}
