#!/usr/bin/env php
<?php

/**
 * A small supervisor to replace Python dependency.
 */
declare(strict_types=1);

namespace Supervisord;

use Symfony\Component\Process\Process;

require_once __DIR__ . '/../vendor/autoload.php';

class Task
{
    public string $name = '';
    public string $command;
    public ?string $owner = null;
    public int $priority = 0;
    public ?Process $process = null;
    public int $numprocs = 1;
    public bool $enabled = false;
    public ?string $directory = null;
    public ?array $env = null;
    public ?string $stdout = null;
    public ?string $stderr = null;
    public int $err0 = 0;
    public int $err1 = 0;

    public function isOut(): bool
    {
        return $this->stdout && (str_starts_with($this->stdout, '/proc/self') || str_starts_with($this->stdout, '/dev/stdout'));
    }

    public function isErr(): bool
    {
        return $this->stderr && (str_starts_with($this->stderr, '/proc/self') || str_starts_with($this->stderr, '/dev/stderr'));
    }
}

class TaskManager
{
    protected $isRunning = false;
    protected $currentUid = null;
    protected $wait4;

    public function __construct(private readonly string $configPath)
    {
        $this->currentUid = posix_getuid();
    }

    public function run()
    {
        $this->isRunning = true;
        $tasks = $this->prepareTasks();

        $signalHandler = function (int $signal) use (&$tasks) {
            $this->writeln("[signal-handler] receive signal $signal");
            $this->isRunning = false;

            $taskRev = array_reverse($tasks);
            $timeout = microtime(true) + 6;
            /** @var Task[] $taskRev */
            foreach ($taskRev as $i => $task) {
                if (!$process = ($task->process ?? null)) {
                    unset($taskRev[$i]);
                    continue;
                }

                try {
                    $start = microtime(true);
                    $process->stop(2.5);
                    $end = microtime(true) - $start;
                    $this->writeln(sprintf("[$task->name] interrupt %.2f sec. and exit with code {$process->getExitCode()}", $end));
                    unset($taskRev[$i]);
                } catch (\Throwable $e) {
                }
                if (microtime(true) > $timeout) {
                    break;
                }
            }

            if ($taskRev) {
                foreach ($taskRev as $task) {
                    try {
                        $this->writeln("Force interrupt [{$task->name}]");
                        $task->process->signal(15);
                    } catch (\Throwable $e) {
                    }
                }
                sleep(1);
            }
        };

        pcntl_async_signals(true);

        pcntl_signal(SIGINT, $signalHandler);
        pcntl_signal(SIGQUIT, $signalHandler);
        pcntl_signal(SIGTERM, $signalHandler);

        pcntl_signal(SIGHUP, function () use (&$tasks) {
            $this->writeln("SIGHUP reload config");
            $this->reloadTasks($tasks);
        });

        pcntl_signal(SIGCHLD, function () {
            $this->writeln("[signal-handler] receive signal SIGCHLD");
            $p = pcntl_waitpid(-1, $status, WNOHANG);
            $this->writeln("Child process with pid $p exit with status $status");
            $this->wait4 = 0;
        });

        $priority = 0;
        foreach ($tasks as $task) {
            if ($priority !== $task->priority) {
                usleep(250000);
            }
            $this->writeln("start task [{$task->name}]");
            $task->process = $this->startProcess($task);
            usleep(50000);
        }

        while ($this->isRunning) {
            try {
                $this->waitTasks($tasks);
            } catch (\Throwable $e) {
                $this->writeln((string) $e);
            }

            $this->wait4 = 9;
            while (--$this->wait4 > 0) {
                usleep(300000);
            }
        }
    }

    protected function waitTasks(array &$tasks): void
    {
        foreach ($tasks as $task) {
            if (!$this->isRunning) {
                break;
            }

            $process = ($task->process ?? null);
            if (null !== $process && !$task->enabled) {
                try {
                    $this->writeln("[{$task->name}] stopping... it is disabled by user");
                    $process->stop();
                } catch (\Throwable $e) {
                }
                $process = $task->process = null;
            }

            if (!$task->enabled) {
                continue;
            }

            if (null === $process) {
                try {
                    $this->writeln("[{$task->name}] restart task");
                    $process = $task->process = $this->startProcess($task);
                    usleep(100000);
                } catch (\Throwable $e) {
                    $this->writeln("ERROR: " . $e->getMessage());
                    usleep(500000);
                }
            }

            if (null === $process || !$this->isRunning) {
                continue;
            }

            try {
                if (!$process->isRunning()) {
                    $this->writeln("[{$task->name}] task exit with status code: " . $process->getExitCode());
                    $task->process = null;
                    $task->err0 += $process->getExitCode() > 0;
                    unset($process, $task->process);
                    $task->process = null;
                }
            } catch (\Throwable $e) {
                $this->writeln("[{$task->name}] unexpected exception when check task status:\n" . $e->__toString());

                try {
                    $process->stop();
                } catch (\Throwable $e) {}
                $task->process = null;
            }
            usleep(100000);
        }
    }

    /**
     * @param Task[] $tasks
     * @return void
     */
    protected function reloadTasks(array &$tasks): void
    {
        $newTasks = $this->prepareTasks();
        foreach ($tasks as $task) {
            $task->enabled = isset($newTasks[$task->name]) && $newTasks[$task->name]->enabled;
        }
        $tasks += $newTasks;
    }

    protected function startProcess(Task $task): Process
    {
        $cmd = $task->command;
        if ($task->owner && 0 === $this->currentUid) {
            $cmd = "chpst -u {$task->owner} $cmd";
        }

        $process = $task->process = Process::fromShellCommandline($cmd, $task->directory, $task->env);
        $process->setTimeout(null);

        $process->start(function ($type, $buffer) use ($task): void {
            if (Process::ERR === $type) {
                $this->writeln("[{$task->name}]+ $buffer");
            } else if ($task->isOut()) {
                $this->writeln("[{$task->name}] $buffer");
            }
        });

        return $process;
    }

    protected function writeln(string $msg): void
    {
        echo (new \DateTime('now'))->format('Y-m-d H:i:s.u') . " $msg\n";
    }

    protected function loadConfiguration(?string $file = null): array
    {
        $file ??= $this->configPath;
        if (!file_exists($file) || !is_readable($file)) {
            return [];
        }

        $configs = [];
        $lines = explode(PHP_EOL, file_get_contents($file));
        $lines = array_filter(array_map('rtrim', $lines));

        $section = null;
        $sectionIndentation = 0;
        $buffer = [];
        foreach ($lines as $line) {
            $indentation = \strlen($line) - \strlen(ltrim($line, ' '));

            if (preg_match('/^\[(.+)\]$/', $line, $match)) {
                $newSection = $match[1];
                if ($buffer && $section) {
                    $configs[$section] = $buffer;
                }

                $section = $newSection;
                $sectionIndentation = $indentation;
                $buffer = [];
                continue;
            }

            $line = explode(';', $line)[0];
            if ($indentation > $sectionIndentation) {
                if ($key = array_key_last($buffer)) {
                    $buffer[$key] .= "\n".$line;
                }

                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }

            [$param, $value] = explode("=", $line, 2);
            $buffer[trim($param)] = trim($value);
        }

        if ($buffer && $section) {
            $configs[$section] = $buffer;
        }

        if ($configs['include']['files'] ?? null) {
            if ($matches = glob($configs['include']['files'])) {
                foreach ($matches as $file) {
                    $configs += $this->loadConfiguration($file);
                }
            }
        }
        return $configs;
    }

    /**
     * @param array|null $configs
     * @return Task[]
     */
    protected function loadTasks(?array $configs = null): array
    {
        $tasks = [];
        $configs ??= $this->loadConfiguration();

        foreach ($configs as $name => $config) {
            if (!str_starts_with($name, 'program:') || empty($config['command'] ?? null)) {
                continue;
            }
            $tasks[] = $task = new Task();
            $task->name = str_replace('program:', '', $name);
            $task->command = $config['command'];
            $task->owner = $config['user'] ?? null;
            $task->enabled = ($config['autostart'] ?? false) === 'true';
            $task->priority = (int)($config['priority'] ?? 0);
            $task->numprocs = (int)($config['numprocs'] ?? 1);
            $task->directory = ($config['directory'] ?? null);
            $task->stderr = ($config['stderr_logfile'] ?? null);
            $task->stdout = ($config['stdout_logfile'] ?? null);

            if ($config['environment'] ?? null) {
                $env = [];
                $lines = preg_split('/[,\n]/', $config['environment']);
                foreach ($lines as $var) {
                    if (count($var = explode("=", $var, 2)) !== 2) {
                        continue;
                    }
                    $env[trim($var[0])] = trim($var[1], '"');
                }
                if ($env) {
                    $task->env = $env;
                }
            }
        }

        return $tasks;
    }

    /**
     * @param array|null $tasks
     * @return Task[]
     */
    protected function prepareTasks(?array $tasks = null): array
    {
        $tasks ??= $this->loadTasks();

        $tasks = array_filter($tasks, fn($t) => $t->enabled);
        $multiply = [];
        foreach ($tasks as $i => $task) {
            if ($task->numprocs > 1 && $task->numprocs < 10) {
                while ($task->numprocs--) {
                    $name = $task->name . '_'. $task->numprocs;
                    $clone = $multiply[] = clone $task;
                    $clone->name = $name;
                    $clone->numprocs = 1;
                }
                unset($tasks[$i]);
            }
        }

        $tasks = array_merge(array_values($tasks), $multiply);

        usort($tasks, fn(Task $t1, Task $t2) => $t1->priority <=> $t2->priority);
        $tasks = array_filter($tasks, fn($t) => $t->enabled);
        $map = [];
        foreach ($tasks as $task) {
            $map[$task->name] = $task;
        }

        return $map;
    }
}

$options = getopt('c:h');
if (($options['h'] ?? false) || !isset($options['c'])) {
    echo "Example usage:\n supervisord -c /etc/supervisord.conf";
    return 0;
}

$file = $options['c'] ?? 'na';
if (!file_exists($file)) {
    echo "File $file does not exists\n";
    exit(1);
}

$tm = new TaskManager($file);
$tm->run();
