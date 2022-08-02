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

namespace Drift\CommandBus\Async;

use Bunny\Channel;
use Bunny\Exception\ClientException;
use Bunny\Message;
use Drift\CommandBus\Bus\CommandBus;
use Drift\CommandBus\Bus\NonRecoverableCommand;
use Drift\CommandBus\Console\CommandBusHeaderMessage;
use Drift\CommandBus\Console\CommandBusLineMessage;
use Drift\CommandBus\Exception\InvalidCommandException;
use Drift\Console\OutputPrinter;
use Drift\EventLoop\EventLoopUtils;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

/**
 * Class AMQPAdapter.
 */
class AMQPAdapter extends AsyncAdapter
{
    /**
     * @var Channel
     */
    private $channel;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var string
     */
    private $queueName;

    /**
     * RedisAdapter constructor.
     *
     * @param Channel       $channel
     * @param LoopInterface $loop
     * @param string        $queueName
     */
    public function __construct(
        Channel $channel,
        LoopInterface $loop,
        string $queueName
    ) {
        $this->channel = $channel;
        $this->loop = $loop;
        $this->queueName = $queueName;
    }

    /**
     * Get adapter name.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'AMQP';
    }

    /**
     * Create infrastructure.
     *
     * @param OutputPrinter $outputPrinter
     *
     * @return PromiseInterface
     */
    public function createInfrastructure(OutputPrinter $outputPrinter): PromiseInterface
    {
        return $this
            ->channel
            ->queueDeclare($this->queueName, false, true)
            ->then(function () use ($outputPrinter) {
                (new CommandBusLineMessage(sprintf('Queue with name %s created properly', $this->queueName)))->print($outputPrinter);
            }, function (\Exception $exception) use ($outputPrinter) {
                (new CommandBusLineMessage(sprintf(
                    'Queue with name %s could not be created. Reason - %s',
                    $this->queueName,
                    $exception->getMessage()
                )))->print($outputPrinter);
            });
    }

    /**
     * Drop infrastructure.
     *
     * @param OutputPrinter $outputPrinter
     *
     * @return PromiseInterface
     */
    public function dropInfrastructure(OutputPrinter $outputPrinter): PromiseInterface
    {
        return $this
            ->channel
            ->queueDelete($this->queueName, false, false)
            ->then(function () use ($outputPrinter) {
                (new CommandBusLineMessage(sprintf('Queue with name %s deleted properly', $this->queueName)))->print($outputPrinter);
            }, function (ClientException $exception) use ($outputPrinter) {
                (new CommandBusLineMessage(sprintf(
                    'Queue with name %s was impossible to be deleted. Reason - %s',
                    $this->queueName,
                    $exception->getMessage()
                )))->print($outputPrinter);
            });
    }

    /**
     * Check infrastructure.
     *
     * @param OutputPrinter $outputPrinter
     *
     * @return PromiseInterface
     */
    public function checkInfrastructure(OutputPrinter $outputPrinter): PromiseInterface
    {
        return $this
            ->channel
            ->queueDeclare($this->queueName, true, true)
            ->then(function ($_) use ($outputPrinter) {
                (new CommandBusLineMessage(sprintf('Queue with name %s exists', $this->queueName)))->print($outputPrinter);
            }, function (ClientException $exception) use ($outputPrinter) {
                (new CommandBusLineMessage(sprintf(
                    'Queue with name %s does not exist. Reason - %s',
                    $this->queueName,
                    $exception->getMessage()
                )))->print($outputPrinter);
            });
    }

    /**
     * {@inheritdoc}
     */
    public function enqueue($command): PromiseInterface
    {
        return $this
            ->channel
            ->publish(serialize($command), [
                'delivery_mode' => 2,
            ], '', $this->queueName);
    }

    /**
     * Consume.
     *
     * @param CommandBus    $bus
     * @param int           $limit
     * @param OutputPrinter $outputPrinter
     * @param Prefetch      $prefetch
     *
     * @throws InvalidCommandException
     */
    public function consume(
        CommandBus $bus,
        int $limit,
        OutputPrinter $outputPrinter,
        Prefetch $prefetch
    ) {
        $this->resetIterations($limit);
        $forced = false;

        $this
            ->channel
            ->qos($prefetch->getPrefetchSize(), $prefetch->getPrefetchCount(), $prefetch->isGlobal())
            ->then(function () use ($bus, $outputPrinter, &$forced) {
                return $this
                    ->channel
                    ->consume(function (Message $message, Channel $channel) use ($bus, $outputPrinter, &$forced) {
                        $command = unserialize($message->content);

                        return $this
                            ->executeCommand(
                                $bus,
                                $command,
                                $outputPrinter,
                                function () use ($channel, $message) {
                                    return $channel->ack($message);
                                },
                                function () use ($command, $channel, $message) {
                                    return $command instanceof NonRecoverableCommand
                                        ? $channel->ack($message)
                                        : $channel->nack($message);
                                },
                                function () use (&$forced) {
                                    $forced = true;
                                    $this
                                        ->loop
                                        ->stop();

                                    return true;
                                }
                            );
                    }, $this->queueName);
            });

        EventLoopUtils::runLoop($this->loop, 2, function ($iterationsMissing) use ($outputPrinter) {
            (new CommandBusHeaderMessage('', 'EventLoop stopped. This consumer will run it '.$iterationsMissing.' more times.'))->print($outputPrinter);
        }, $forced);
    }
}
