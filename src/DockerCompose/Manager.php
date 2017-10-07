<?php

namespace DockerCompose;

use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;

class Manager
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var bool
     */
    protected $logCommands = true;

    /**
     * @var array<string, string>
     */
    protected $outToLogMap = [
        Process::OUT => LogLevel::INFO,
        Process::ERR => LogLevel::ERROR,
    ];

    /**
     * @var callable
     */
    protected $outputHandler;

    /**
     * @param null|LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger ? $logger : new NullLogger();

        $this->outputHandler = function ($type, $output) {
            $this->logger->log(
                isset($this->outToLogMap[$type])
                    ? $this->outToLogMap[$type]
                    : LogLevel::CRITICAL,
                $output
            );
        };
    }

    /**
     * @param null|string $file
     * @param bool        $stopOnShutDown
     *
     * @return Process
     */
    public function up($file = null, $stopOnShutDown = true)
    {
        $builder = $this->createDockerComposeBuilder('up', $file);

        $process = $builder->getProcess();

        // The purpose of the following code is twofold: stop the process on
        // shutdown, but also ensures it is not stopped prematurely by process
        // destructor (by keeping $process in use by the callback).
        register_shutdown_function(function () use ($file, $process, $stopOnShutDown) {
            if ($stopOnShutDown) {
                $this->remove($file, true, true);
                $process->stop();
            }
        });

        $this->logger->debug('> '.$process->getCommandLine());

        $process->start($this->outputHandler);

        return $process;
    }

    /**
     * @param null|string $file
     * @param null|string $removeImages  'local' or 'all', see `docker-compose down --help` for more info
     * @param bool        $removeVolumes
     *
     * @throws RuntimeException|ProcessFailedException
     */
    public function down($file = null, $removeImages = null, $removeVolumes = false)
    {
        $builder = $this->createDockerComposeBuilder('down', $file);

        if ($removeImages) {
            $builder->add('--rmi')->add($removeImages);
        }

        if ($removeVolumes) {
            $builder->add('--volumes');
        }

        $process = $builder->getProcess();

        $this->logger->debug('> '.$process->getCommandLine());

        $process->run($this->outputHandler);
    }

    /**
     * @param null $file
     * @param bool $noCache
     * @param bool $forceRemove
     * @param bool $forcePull
     */
    public function build($file = null, $noCache = false, $forceRemove = false, $forcePull = false)
    {
        $builder = $this->createDockerComposeBuilder('build', $file);

        if ($noCache) {
            $builder->add('--no-cache');
        }

        if ($forceRemove) {
            $builder->add('--force-rm');
        }

        if ($forcePull) {
            $builder->add('--pull');
        }

        $process = $builder->getProcess();

        $this->logger->debug('> '.$process->getCommandLine());

        $process->run($this->outputHandler);
    }

    public function remove($file = null, $stopContainers = false, $removeVolumes = false)
    {
        $builder = $this->createDockerComposeBuilder('rm', $file);

        // doesn't make sense to prompt
        $builder->add('--force');

        if ($stopContainers) {
            $builder->add('--stop');
        }

        if ($removeVolumes) {
            $builder->add('-v');
        }

        $process = $builder->getProcess();

        $this->logger->debug('> '.$process->getCommandLine());

        $process->run($this->outputHandler);
    }

    /**
     * @param null|string $file
     * @param string      $service
     * @param string      $command
     * @param bool        $background
     *
     * @todo support --privileged
     * @todo support --user <user>
     * @todo support -T (no tty)
     * @todo support --index=<index>
     *
     * @return Process
     */
    public function execute($file = null, $service = '', $command = '', $background = false)
    {
        $builder = $this->createDockerComposeBuilder('exec', $file);

        if (!$service) {
            throw new \InvalidArgumentException('Argument $service is required and cannot be empty.');
        }

        if (!$command) {
            throw new \InvalidArgumentException('Argument $command is required and cannot be empty.');
        }

        $builder->add($service)->add($command);

        $process = $builder->getProcess();

        $this->logger->debug('> '.$process->getCommandLine());

        $background ? $process->start($this->outputHandler) : $process->run($this->outputHandler);

        return $process;
    }

    /**
     * @param string      $cmd
     * @param null|string $file
     *
     * @return ProcessBuilder
     */
    protected function createDockerComposeBuilder($cmd, $file = null)
    {
        $builder = ProcessBuilder::create(['docker-compose'])
            ->setTimeout(null);

        if ($file) {
            $builder->add('--file')->add($file);
        }

        return $builder->add($cmd);
    }
}
