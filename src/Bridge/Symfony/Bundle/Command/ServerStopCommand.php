<?php

declare(strict_types=1);

namespace K911\Swoole\Bridge\Symfony\Bundle\Command;

use Assert\Assertion;
use K911\Swoole\Server\HttpServer;
use K911\Swoole\Server\HttpServerConfiguration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class ServerStopCommand extends Command
{
    use ParametersHelperTrait;

    private $server;
    private $serverConfiguration;
    private $parameterBag;

    public function __construct(
        HttpServer $server,
        HttpServerConfiguration $serverConfiguration,
        ParameterBagInterface $parameterBag
    ) {
        $this->server = $server;
        $this->serverConfiguration = $serverConfiguration;
        $this->parameterBag = $parameterBag;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->setDescription('Stop Swoole HTTP server running in the background.')
            ->addOption('pid-file', null, InputOption::VALUE_REQUIRED, 'Pid file', $this->getProjectDirectory().'/var/swoole.pid')
        ;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Assert\AssertionFailedException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pidFile = $input->getOption('pid-file');
        Assertion::nullOrString($pidFile);

        $this->serverConfiguration->daemonize($pidFile);

        try {
            $this->server->shutdown();
        } catch (\Throwable $ex) {
            $io->error($ex->getMessage());
            exit(1);
        }

        $io->success('Swoole server shutdown successfully');

        return 0;
    }
}
