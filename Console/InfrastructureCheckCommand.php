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

namespace Drift\CommandBus\Console;

use Clue\React\Block;
use Drift\CommandBus\Async\AsyncAdapter;
use Drift\Console\OutputPrinter;
use Drift\Server\Console\Style\Muted;
use Drift\Server\Console\Style\Purple;
use React\EventLoop\LoopInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class InfrastructureCheckCommand.
 */
class InfrastructureCheckCommand extends Command
{
    /**
     * @var AsyncAdapter
     */
    private $asyncAdapter;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * ConsumeCommand constructor.
     *
     * @param AsyncAdapter  $asyncAdapter
     * @param LoopInterface $loop
     */
    public function __construct(
        AsyncAdapter $asyncAdapter,
        LoopInterface $loop
    ) {
        parent::__construct();

        $this->asyncAdapter = $asyncAdapter;
        $this->loop = $loop;
    }

    /**
     * Configures the current command.
     */
    protected function configure()
    {
        $this->setDescription('Checks the infrastructure that makes command bus work');
    }

    /**
     * Executes the current command.
     *
     * This method is not abstract because you can use this class
     * as a concrete class. In this case, instead of defining the
     * execute() method, you set the code to execute by passing
     * a Closure to the setCode() method.
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $outputFormatter = $output->getFormatter();
        $outputFormatter->setStyle('performance', new OutputFormatterStyle('gray'));
        $outputPrinter = new OutputPrinter($output, false, false);
        $adapterName = $this->asyncAdapter->getName();
        (new CommandBusHeaderMessage('Started checking infrastructure...'))->print($outputPrinter);
        (new CommandBusHeaderMessage('Using adapter '.$adapterName))->print($outputPrinter);

        $promise = $this
            ->asyncAdapter
            ->checkInfrastructure($outputPrinter);

        Block\await($promise, $this->loop);
        (new CommandBusHeaderMessage('Infrastructure checked'))->print($outputPrinter);

        return 0;
    }
}
