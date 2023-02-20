<?php

declare(strict_types=1);

namespace Packeton\Composer\IO;

use Composer\IO\ConsoleIO;
use Composer\IO\IOInterface;
use Composer\Pcre\Preg;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * To show more logs in UI, allow to out limit count of debugs logs.
 */
class BufferIO extends ConsoleIO
{
    protected $output;

    protected $verbosityMatrixCapacity = 3;

    protected $verbosityMatrix = [
        IOInterface::VERBOSE => 3,
        IOInterface::VERY_VERBOSE => 2,
        IOInterface::DEBUG => 2,
    ];

    public function __construct(string $input = '', int $verbosity = StreamOutput::VERBOSITY_NORMAL, ?OutputFormatterInterface $formatter = null)
    {
        $input = new StringInput($input);
        $input->setInteractive(false);

        $this->output = $output = new BufferedOutput($verbosity, $formatter && $formatter->isDecorated(), $formatter);

        parent::__construct($input, $output, new HelperSet([
            new QuestionHelper(),
        ]));
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
        $messages = strip_tags($messages, ['error', 'warning', 'info']);
        parent::overwriteError($messages, $newline, $size, $this->dynamicVerbosity($verbosity));
    }

    public function overwriteError($messages, bool $newline = true, ?int $size = null, int $verbosity = self::NORMAL)
    {
        $messages = strip_tags($messages, ['error', 'warning', 'info']);
        parent::overwriteError($messages, $newline, $size, $this->dynamicVerbosity($verbosity));
    }

    public function write($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        $messages = strip_tags($messages, ['error', 'warning', 'info']);
        parent::write($messages, $newline, $this->dynamicVerbosity($verbosity));
    }

    public function writeError($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        $messages = strip_tags($messages, ['error', 'warning', 'info']);
        parent::writeError($messages, $newline, $this->dynamicVerbosity($verbosity));
    }

    public function writeRaw($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        $messages = strip_tags($messages, ['error', 'warning', 'info']);
        parent::writeRaw($messages, $newline, $this->dynamicVerbosity($verbosity));
    }

    public function writeErrorRaw($messages, bool $newline = true, int $verbosity = self::NORMAL)
    {
        $messages = strip_tags($messages, ['error', 'warning', 'info']);
        parent::writeErrorRaw($messages, $newline, $this->dynamicVerbosity($verbosity));
    }

    public function log($level, $message, array $context = []): void
    {
        parent::log($level, \htmlspecialchars((string) $message), $context);
    }

    protected function dynamicVerbosity($verbosity)
    {
        if ($verbosity === self::NORMAL && $this->verbosityMatrixCapacity > 0) {
            foreach ($this->verbosityMatrix as $verb => $value) {
                if ($value <= 0) {
                    $this->verbosityMatrix[$verb] = 2;
                    $this->verbosityMatrixCapacity--;
                }
            }
        }

        if (isset($this->verbosityMatrix[$verbosity]) && $this->verbosityMatrix[$verbosity] > 0) {
            return self::NORMAL;
        }

        return $verbosity;
    }
}
