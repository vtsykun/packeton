<?php

declare(strict_types=1);

namespace Packeton\Webhook;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class WebhookLogger extends AbstractLogger
{
    private static $levels = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /** @var LoggerInterface|null */
    private $wrapperLogger;
    private $logs = [];
    private $logLevel;

    public function __construct(string $logLevel = LogLevel::NOTICE)
    {
        $this->logLevel = self::$levels[$logLevel];
    }

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context = []): void
    {
        $this->wrapperLogger?->log($level, $message, $context);

        if (self::$levels[$level] >= $this->logLevel) {
            $this->logs[] = [$level, $message];
        }
    }

    /**
     * @param LoggerInterface|null $logger
     */
    public function setWrapperLogger(LoggerInterface $logger = null)
    {
        $this->wrapperLogger = $logger;
    }

    public function getLogs(): array
    {
        return $this->logs;
    }

    public function clearLogs(): array
    {
        $logs = $this->logs;
        $this->logs = [];

        return $logs;
    }
}
