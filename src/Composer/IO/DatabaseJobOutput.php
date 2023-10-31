<?php

declare(strict_types=1);

namespace Packeton\Composer\IO;

use Composer\Pcre\Preg;
use Doctrine\DBAL\Connection;
use Packeton\Entity\Job;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
class DatabaseJobOutput extends BufferedOutput
{
    protected $buffer = "";
    protected $maxSize = 65536;

    public function __construct(protected Job $job, protected Connection $conn)
    {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function doWrite(string $message, bool $newline): void
    {
        if (strlen($this->buffer) > $this->maxSize) {
            return;
        }

        $output = Preg::replaceCallback("{(?<=^|\n|\x08)(.+?)(\x08+)}", static function ($matches): string {;
            $pre = strip_tags($matches[1] ?? '');
            if (strlen($pre) === strlen($matches[2] ?? '')) {
                return '';
            }
            return rtrim($matches[1])."\n";
        }, $message);

        if ($output) {
            $this->buffer .= '['. date('Y-m-d H:i:s'). '] ' . $output;
            $this->job->addResult('output', $this->buffer);
            $this->updateOutput($this->job);
        }
    }

    public function updateOutput(Job $job): void
    {
        $data = ['result' => $job->getResult()];
        $types = ['result' => 'json'];
        $this->conn->update('job', $data, ['id' => $job->getId()], $types);
    }
}
