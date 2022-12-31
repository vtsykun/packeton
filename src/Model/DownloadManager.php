<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packeton\Model;

use Packeton\Entity\Package;
use Packeton\Entity\Version;

/**
 * Manages the download counts for packages.
 */
class DownloadManager
{
    protected $redis;
    protected $redisCommandSha = false;

    public function __construct(\Redis $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Gets the total, monthly, and daily download counts for an entire package or optionally a version.
     *
     * @param \Packeton\Entity\Package|int      $package
     * @param \Packeton\Entity\Version|int|null $version
     * @return array
     */
    public function getDownloads($package, $version = null)
    {
        if ($package instanceof Package) {
            $package = $package->getId();
        }

        if ($version instanceof Version) {
            $version = $version->getId();
        }

        if ($version !== null) {
            $version = '-'.$version;
        }

        $date = new \DateTime();
        $keys = ['dl:'.$package . $version];
        for ($i = 0; $i < 30; $i++) {
            $keys[] = 'dl:' . $package . $version . ':' . $date->format('Ymd');
            $date->modify('-1 day');
        }

        $vals = $this->redis->mget($keys);
        return [
            'total' => (int) array_shift($vals) ?: 0,
            'monthly' => (int) array_sum($vals) ?: 0,
            'daily' => (int) $vals[0] ?: 0,
        ];
    }

    /**
     * Gets the total download count for a package.
     *
     * @param \Packeton\Entity\Package|int $package
     * @return int
     */
    public function getTotalDownloads($package)
    {
        if ($package instanceof Package) {
            $package = $package->getId();
        }

        return (int) $this->redis->get('dl:' . $package) ?: 0;
    }

    /**
     * Gets total download counts for multiple package IDs.
     *
     * @param array $packageIds
     * @return array a map of package ID to download count
     */
    public function getPackagesDownloads(array $packageIds)
    {
        $keys = [];

        foreach ($packageIds as $id) {
            if (ctype_digit((string) $id)) {
                $keys[$id] = 'dl:'.$id;
            }
        }

        if (!$keys) {
            return [];
        }

        $res = array_map('intval', $this->redis->mget(array_values($keys)));
        return array_combine(array_keys($keys), $res);
    }

    /**
     * Tracks downloads by updating the relevant keys.
     *
     * @param $jobs array[] an array of arrays containing id (package id), vid (version id) and ip keys
     */
    public function addDownloads(array $jobs)
    {
        $day = date('Ymd');
        $month = date('Ym');

        if (!$this->redisCommandSha) {
            $this->loadScript();
        }

        $args = ['downloads'];

        foreach ($jobs as $job) {
            $package = $job['id'];
            $version = $job['vid'];

            // throttle key
            $args[] = 'throttle:'.$package.':'.$job['ip'].':'.$day;
            // stats keys
            $args[] = 'dl:'.$package;
            $args[] = 'dl:'.$package.':'.$month;
            $args[] = 'dl:'.$package.':'.$day;
            $args[] = 'dl:'.$package.'-'.$version;
            $args[] = 'dl:'.$package.'-'.$version.':'.$month;
            $args[] = 'dl:'.$package.'-'.$version.':'.$day;
        }

        $this->redis->evalSha($this->redisCommandSha, $args, count($args));
    }

    protected function loadScript()
    {
        $script = <<<LUA
local doIncr = false;
local successful = 0;
for i, key in ipairs(KEYS) do
    if i == 1 then
        -- nothing
    elseif ((i - 2) % 7) == 0 then
        local requests = redis.call("INCR", key);
        if 1 == requests then
            redis.call("EXPIRE", key, 86400);
        end

        doIncr = false;
        if requests <= 10 then
            doIncr = true;
            successful = successful + 1;
        end
    elseif doIncr then
        redis.call("INCR", key);
    end
end

if successful > 0 then
    redis.call("INCRBY", KEYS[1], successful);
end

return redis.status_reply("OK");
LUA;
        $this->redisCommandSha = $this->redis->script('load', $script);
    }
}
