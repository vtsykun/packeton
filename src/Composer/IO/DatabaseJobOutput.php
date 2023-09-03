<?php

declare(strict_types=1);

namespace Packeton\Composer\IO;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
class DatabaseJobOutput extends BufferedOutput
{
}
