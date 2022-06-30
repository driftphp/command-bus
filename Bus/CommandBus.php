<?php

/*
 * This file is part of the DriftPHP Project
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * Feel free to edit as you please, and have fun.
 *
 * @author Marc Morera <yuhu@mmoreram.com>
 */

declare(strict_types=1);

namespace Drift\CommandBus\Bus;

use Drift\CommandBus\Exception\InvalidCommandException;
use React\Promise\PromiseInterface;
use function React\Promise\reject;

/**
 * Class CommandBus.
 */
class CommandBus extends Bus
{
    /**
     * Execute command.
     *
     * @param object $command
     *
     * @return PromiseInterface
     *
     * @throws InvalidCommandException
     */
    public function execute($command): PromiseInterface
    {
        try {
            return $this
                ->handle($command)
                ->then(function () {
                });
        } catch (\Throwable $exception) {
            return reject($exception);
        }
    }
}
