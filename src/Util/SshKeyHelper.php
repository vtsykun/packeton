<?php

declare(strict_types=1);

namespace Packeton\Util;

class SshKeyHelper
{
    private function __construct()
    {
    }

    public static function isSshEd25519Key(string $key): bool
    {
        return str_contains($key, 'OPENSSH PRIVATE');
    }

    public static function trimKey(string $key): string
    {
        $key = str_replace("\r\n", "\n", trim($key));
        return rtrim($key, "\n") . "\n";
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

        $key = self::trimKey($key);
        $tmpName = sys_get_temp_dir() . '/sshtmp_' . time();
        file_put_contents($tmpName, $key);
        @chmod($tmpName, 0600);

        [$regex, $cmd] = self::isSshEd25519Key($key) ?
            ['#(SHA256:.+)#i', "ssh-keygen -E sha256 -lf '$tmpName'"] :
            ['#MD5:([0-9a-f:]+)#', "ssh-keygen -E md5 -lf '$tmpName'"];

        try {
            if (!$output = @shell_exec($cmd)) {
                return null;
            }
            preg_match($regex, $output, $match);
            return $match[1] ?? null;
        } finally {
            @unlink($tmpName);
        }
    }
}
