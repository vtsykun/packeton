<?php

namespace Packagist\WebBundle\Composer\Util;

use Composer\Util\Platform;
use Composer\Util\ProcessExecutor as ComposerProcessExecutor;
use Symfony\Component\Process\Process;

class ProcessExecutor extends ComposerProcessExecutor
{
    /**
     * @var array
     */
    protected static $inheritEnv = [];

    /**
     * {@inheritdoc}
     */
    public function execute($command, &$output = null, $cwd = null)
    {
        if ($this->io && $this->io->isDebug()) {
            $safeCommand = preg_replace_callback('{://(?P<user>[^:/\s]+):(?P<password>[^@\s/]+)@}i', function ($m) {
                if (preg_match('{^[a-f0-9]{12,}$}', $m['user'])) {
                    return '://***:***@';
                }

                return '://'.$m['user'].':***@';
            }, $command);
            $safeCommand = preg_replace("{--password (.*[^\\\\]\') }", '--password \'***\' ', $safeCommand);
            $this->io->writeError('Executing command ('.($cwd ?: 'CWD').'): '.$safeCommand);
        }

        // make sure that null translate to the proper directory in case the dir is a symlink
        // and we call a git command, because msysgit does not handle symlinks properly
        if (null === $cwd && Platform::isWindows() && false !== strpos($command, 'git') && getcwd()) {
            $cwd = realpath(getcwd());
        }

        $this->captureOutput = func_num_args() > 1;
        $this->errorOutput = null;
        $env = $this->getInheritedEnv();

        // in v3, commands should be passed in as arrays of cmd + args
        if (method_exists('Symfony\Component\Process\Process', 'fromShellCommandline')) {
            $process = Process::fromShellCommandline($command, $cwd, $env, null, static::getTimeout());
        } else {
            $process = new Process($command, $cwd, $env, null, static::getTimeout());
        }

        $callback = is_callable($output) ? $output : array($this, 'outputHandler');
        $process->run($callback);

        if ($this->captureOutput && !is_callable($output)) {
            $output = $process->getOutput();
        }

        $this->errorOutput = $process->getErrorOutput();

        return $process->getExitCode();
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
