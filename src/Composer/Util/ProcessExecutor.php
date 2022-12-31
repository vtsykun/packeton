<?php

namespace Packeton\Composer\Util;

use Composer\IO\IOInterface;
use Composer\Pcre\Preg;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor as ComposerProcessExecutor;
use Seld\Signal\SignalHandler;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

class ProcessExecutor extends ComposerProcessExecutor
{
    /**
     * @var array
     */
    protected static $inheritEnv = [];

    /**
     * @var int
     */
    protected static $timeout = 600;

    /**
     * {@inheritdoc}
     */
    public function execute($command, &$output = null, ?string $cwd = null): int
    {
        if (func_num_args() > 1) {
            return $this->doExecute($command, $cwd, false, $output);
        }

        return $this->doExecute($command, $cwd, false);
    }

    /**
     * {@inheritdoc}
     */
    public function executeTty($command, ?string $cwd = null): int
    {
        if (Platform::isTty()) {
            return $this->doExecute($command, $cwd, true);
        }

        return $this->doExecute($command, $cwd, false);
    }

    /**
     * @param  string|list<string> $command
     * @param  mixed   $output
     */
    private function doExecute($command, ?string $cwd, bool $tty, &$output = null): int
    {
        $this->outputCommandRun($command, $cwd, false);

        $this->captureOutput = func_num_args() > 3;
        $this->errorOutput = '';

        $env = $this->getInheritedEnv();

        if (is_string($command)) {
            $process = Process::fromShellCommandline($command, $cwd, $env, null, static::getTimeout());
        } else {
            $process = new Process($command, $cwd, $env, null, static::getTimeout());
        }

        if (!Platform::isWindows() && $tty) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                // ignore TTY enabling errors
            }
        }

        $callback = is_callable($output) ? $output : function (string $type, string $buffer): void {
            $this->outputHandler($type, $buffer);
        };

        $signalHandler = SignalHandler::create([SignalHandler::SIGINT, SignalHandler::SIGTERM, SignalHandler::SIGHUP], function (string $signal) {
            $this->io?->writeError('Received ' . $signal . ', aborting when child process is done', true, IOInterface::DEBUG);
        });

        try {
            $process->run($callback);

            if ($this->captureOutput && !is_callable($output)) {
                $output = $process->getOutput();
            }

            $this->errorOutput = $process->getErrorOutput();
        } catch (ProcessSignaledException $e) {
            if ($signalHandler->isTriggered()) {
                // exiting as we were signaled and the child process exited too due to the signal
                $signalHandler->exitWithLastSignal();
            }
        } finally {
            $signalHandler->unregister();
        }

        return $process->getExitCode();
    }

    /**
     * @param string|list<string> $command
     */
    private function outputCommandRun($command, ?string $cwd, bool $async): void
    {
        if (null === $this->io || !$this->io->isDebug()) {
            return;
        }

        $commandString = is_string($command) ? $command : implode(' ', array_map(self::class.'::escape', $command));
        $safeCommand = Preg::replaceCallback('{://(?P<user>[^:/\s]+):(?P<password>[^@\s/]+)@}i', static function ($m): string {
            assert(is_string($m['user']));

            // if the username looks like a long (12char+) hex string, or a modern github token (e.g. ghp_xxx) we obfuscate that
            if (Preg::isMatch('{^([a-f0-9]{12,}|gh[a-z]_[a-zA-Z0-9_]+)$}', $m['user'])) {
                return '://***:***@';
            }
            if (Preg::isMatch('{^[a-f0-9]{12,}$}', $m['user'])) {
                return '://***:***@';
            }

            return '://'.$m['user'].':***@';
        }, $commandString);
        $safeCommand = Preg::replace("{--password (.*[^\\\\]\') }", '--password \'***\' ', $safeCommand);
        $this->io->writeError('Executing'.($async ? ' async' : '').' command ('.($cwd ?: 'CWD').'): '.$safeCommand);
    }

    /**
     * Sets the environment variables for child process.
     *
     * @param array $variables
     */
    public static function inheritEnv(?array $variables): void
    {
        static::$inheritEnv = $variables;
    }

    /**
     *  Sets the environment variables.
     */
    protected function getInheritedEnv(): ?array
    {
        $env = [];
        foreach (static::$inheritEnv as $name) {
            if (getenv($name)) {
                $env[$name] = getenv($name);
            }
        }

        return $env ?: null;
    }
}
