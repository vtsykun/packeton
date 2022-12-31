<?php declare(strict_types=1);

namespace Packeton\Service;

use Psr\Log\LoggerInterface;

class LogResetter
{
    private $handlers = [];

    public function __construct(LoggerInterface $logger)
    {
        $this->initLogger($logger);
    }

    private function initLogger(LoggerInterface $logger)
    {
    }

    public function reset()
    {
        foreach ($this->handlers as $handler) {
            if (method_exists($handler, 'clear')) {
                $handler->clear();
            }
        }
    }
}
