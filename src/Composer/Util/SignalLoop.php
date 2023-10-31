<?php

declare(strict_types=1);

namespace Packeton\Composer\Util;

use Composer\Util\HttpDownloader;
use Packeton\Exception\SignalException;
use React\Promise\PromiseInterface;
use Seld\Signal\SignalHandler;
use Symfony\Component\Console\Helper\ProgressBar;

class SignalLoop
{
    /** @var PromiseInterface[][] */
    protected $currentPromises = [];
    /** @var int */
    protected $waitIndex = 0;

    public function __construct(
        protected HttpDownloader $httpDownloader,
        protected ?SignalHandler $signal = null
    ) {
        $this->httpDownloader->enableAsync();
    }

    /**
     * @param  PromiseInterface[] $promises
     * @param  ?ProgressBar       $progress
     */
    public function wait(array $promises, ?ProgressBar $progress = null): void
    {
        /** @var \Exception|null $uncaught */
        $uncaught = null;

        \React\Promise\all($promises)->then(
            static function (): void {
            },
            static function ($e) use (&$uncaught): void {
                $uncaught = $e;
            }
        );

        // keep track of every group of promises that is waited on, so abortJobs can
        // cancel them all, even if wait() was called within a wait()
        $waitIndex = $this->waitIndex++;
        $this->currentPromises[$waitIndex] = $promises;

        if ($progress) {
            $totalJobs = 0;
            $totalJobs += $this->httpDownloader->countActiveJobs();
            $progress->start($totalJobs);
        }

        $lastUpdate = 0;
        while (true) {
            $activeJobs = 0;

            $activeJobs += $this->httpDownloader->countActiveJobs();

            if ($progress && microtime(true) - $lastUpdate > 0.1) {
                $lastUpdate = microtime(true);
                $progress->setProgress($progress->getMaxSteps() - $activeJobs);
            }

            if (!$activeJobs) {
                break;
            }

            if ($this->signal?->isTriggered()) {
                throw new SignalException();
            }
        }

        // as we skip progress updates if they are too quick, make sure we do one last one here at 100%
        $progress?->finish();

        unset($this->currentPromises[$waitIndex]);
        if ($uncaught) {
            throw $uncaught;
        }
    }
}
