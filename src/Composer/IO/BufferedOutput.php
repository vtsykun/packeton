<?php

declare(strict_types=1);

namespace Packeton\Composer\IO;

use Symfony\Component\Console\Output\BufferedOutput as SymfonyBufferedOutput;

class BufferedOutput extends SymfonyBufferedOutput
{
    private int $size = 0;
    private ?int $maxSize;

    public function setMaxSize(?int $maxSize = null): void
    {
        $this->maxSize = $maxSize;
    }

    public function fetch(): string
    {
        $this->size = 0;
        return parent::fetch();
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function write($messages, bool $newline = false, int $options = self::OUTPUT_NORMAL): void
    {
        if (!is_iterable($messages)) {
            $messages = [$messages];
        }

        if (null !== $this->maxSize && $this->size > $this->maxSize && isset($messages[0]) && !str_contains($messages[0], '<error>')) {
            return;
        }

        $this->size += array_sum(array_map('strlen', $messages)) + (int) $newline;
        parent::write($messages, $newline, $options);
    }
}
