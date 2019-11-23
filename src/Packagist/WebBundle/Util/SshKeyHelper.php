<?php

declare(strict_types=1);

namespace Packagist\WebBundle\Util;

class SshKeyHelper
{
    private function __construct()
    {
    }

    /**
     * @param string $key
     *
     * @return string|null
     */
    public static function getFingerprint(string $key): ?string
    {
        if (!function_exists('shell_exec')) {
            return null;
        }

        $tmpName = sys_get_temp_dir() . '/sshtmp_' . time();
        file_put_contents($tmpName, $key);
        @chmod($tmpName, 0600);

        try {
            if (!$output = @shell_exec("ssh-keygen -E md5 -lf '$tmpName'")) {
                return null;
            }
            preg_match('#MD5:([0-9a-f:]+)#', $output, $match);
            return $match[1] ?? null;
        } finally {
            @unlink($tmpName);
        }
    }
}
