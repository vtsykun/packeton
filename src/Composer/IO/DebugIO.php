<?php

declare(strict_types=1);

namespace Packeton\Composer\IO;

use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Composer\Pcre\Preg;
use Packeton\Model\ConsoleAwareInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * To show debug more logs in UI.
 * Limit count of debug logs to prevent memory leaks.
 */
class DebugIO extends ConsoleIO implements ConsoleAwareInterface
{
    protected $output;
    protected ?OutputInterface $consoleOutput = null;

    protected $verbosityMatrixCapacity = 5;

    protected $verbosityMatrix = [
        IOInterface::VERBOSE => 12,
        IOInterface::VERY_VERBOSE => 12,
        IOInterface::DEBUG => 12,
    ];

    protected $maxLoggingMatrix = [
        LogLevel::EMERGENCY => 1000,
        LogLevel::ALERT => 1000,
        LogLevel::CRITICAL => 1000,
        LogLevel::ERROR => 1000,
        LogLevel::WARNING => 100,
        LogLevel::NOTICE => 100,
        LogLevel::INFO => 100,
    ];

    public function __construct(string $input = '', int $verbosity = StreamOutput::VERBOSITY_NORMAL, ?OutputFormatterInterface $formatter = null, protected ?int $maxBufferSize = null)
    {
        $input = new StringInput($input);
        $input->setInteractive(false);

        $this->output = $output = new BufferedOutput($verbosity, $formatter && $formatter->isDecorated(), $formatter);
        $output->setMaxSize($this->maxBufferSize);

        parent::__construct($input, $output, new HelperSet([
            new QuestionHelper(),
        ]));
    }

    /**
     * {@inheritdoc}
     */
    public function setOutput(OutputInterface $output = null): void
    {
        $this->consoleOutput = $output;
    }

    /**
     * @return string output
     */
    public function getOutput(): string
    {
        $output = $this->output->fetch();

        $output = Preg::replaceCallback("{(?<=^|\n|\x08)(.+?)(\x08+)}", static function ($matches): string {
            assert(is_string($matches[1]));
            assert(is_string($matches[2]));
            $pre = strip_tags($matches[1]);

            if (strlen($pre) === strlen($matches[2])) {
                return '';
            }

            // TODO reverse parse the string, skipping span tags and \033\[([0-9;]+)m(.*?)\033\[0m style blobs
            return rtrim($matches[1])."\n";
        }, $output);

        return $output;
    }

    public function getProgressBar(int $max = 0)
    {
        return new ProgressBar(new NullOutput(), $max);
    }

    public function overwrite($messages, bool $newline = true, ?int $size = null, int $verbosity = self::NORMAL)
    {
        $messages = $this->logAndStrip($messages, $verbosity);
        parent::overwriteError($messages, $newline, $size, $this->dynamicVerbosity($verbosity));
    }

    public function overwriteError($messages, bool $newline = true, ?int $size = null, int $verbosity = self::NORMAL)
    {
        $messages = $this->logAndStrip($messages, $verbosity);
        parent::overwriteError($messages, $newline, $size, $this->dynamicVerbosity($verbosity));
    }

    public function write($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        $messages = $this->logAndStrip($messages, $verbosity);
        parent::write($messages, $newline, $this->dynamicVerbosity($verbosity));
    }

    public function writeError($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        $messages = $this->logAndStrip($messages, $verbosity);;
        parent::writeError($messages, $newline, $this->dynamicVerbosity($verbosity));
    }

    public function writeRaw($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        $messages = $this->logAndStrip($messages, $verbosity);
        parent::writeRaw($messages, $newline, $this->dynamicVerbosity($verbosity));
    }

    public function writeErrorRaw($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        $messages = $this->logAndStrip($messages, $verbosity);
        parent::writeErrorRaw($messages, $newline, $this->dynamicVerbosity($verbosity));
    }

    public function log($level, $message, array $context = []): void
    {
        if (isset($this->maxLoggingMatrix[$level])) {
            $value = --$this->maxLoggingMatrix[$level];

            if ($value === 0) {
                parent::write("Too many [$level] logs, suppress logging...");
            }
            if ($value <= 0) {
                return;
            }
        }

        parent::log($level, strip_tags((string) $message), $context);
    }

    protected function dynamicVerbosity($verbosity)
    {
        if ($verbosity === self::NORMAL && $this->verbosityMatrixCapacity > 0) {
            foreach ($this->verbosityMatrix as $verb => $value) {
                if ($value < 12) {
                    $this->verbosityMatrix[$verb] = 12;
                    $this->verbosityMatrixCapacity--;
                }
            }
        }

        if (isset($this->verbosityMatrix[$verbosity]) && $this->verbosityMatrix[$verbosity] > 0) {
            $value = --$this->verbosityMatrix[$verbosity];
            if ($value === 0) {
                parent::write('Too many debug logs, suppress debug logging...');
            }

            return self::NORMAL;
        }

        return $verbosity;
    }

    protected function logAndStrip($messages, int $verbosity = self::NORMAL): array|string
    {
        $messages = is_array($messages) ? array_map(fn($msg) => strip_tags($msg, ['error', 'warning', 'info']), $messages) :
            strip_tags((string)$messages, ['error', 'warning', 'info']);

        if (null !== $this->consoleOutput && $this->consoleOutput->getVerbosity() >= $verbosity * 16) {
            $messages = !is_array($messages) ? [$messages] : $messages;
            foreach ($messages as $message) {
                $message = str_replace(['<warning>', '</warning>'], ['<comment>', '</comment>'], $message);
                $this->consoleOutput->writeln($message);
            }
        }

        return $messages;
    }
}
