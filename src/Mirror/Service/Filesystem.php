<?php

declare(strict_types=1);

namespace Packeton\Mirror\Service;

use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

class Filesystem extends SymfonyFilesystem
{
    public function glob(string $pattern):array
    {
        $return = \glob($pattern, GLOB_NOSORT);
        return $return ?: [];
    }

    public function globLast(string $pattern): string
    {
        $dir = \rtrim(\dirname($pattern), '/') . '/';
        $list = $this->glob($pattern);

        $old = 0;
        $selected = null;
        foreach ($list as $file) {
            $filename = \str_contains($file, '/') ? $file : $dir . $file;
            $unix = \filemtime($filename);
            if ($unix > $old) {
                $selected = $filename;
                $old = $unix;
            }
        }

        return $selected;
    }
}
