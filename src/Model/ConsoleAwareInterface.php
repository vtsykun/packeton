<?php

declare(strict_types=1);

namespace Packeton\Model;

use Symfony\Component\Console\Output\OutputInterface;

interface ConsoleAwareInterface
{
    public function setOutput(?OutputInterface $output = null): void;
}
