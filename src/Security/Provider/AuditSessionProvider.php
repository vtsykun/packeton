<?php

declare(strict_types=1);

namespace Packeton\Security\Provider;

use Packeton\Model\AuditSession;
use Packeton\Util\RedisTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

class AuditSessionProvider
{
    use RedisTrait;
    public const KEEP_COUNT = 50;

    public function __construct(protected \Redis $redis)
    {
    }

    public function logDownload(Request $request, string|UserInterface $user, array $downloads): void
    {
        $current = [
            'ua' => $request->headers->get('user-agent'),
            'ip' =>  $request->getClientIp(),
            'last_usage' => time(),
            'usage' => 1,
            'downloads' => implode("\n", $downloads) ?: 'na'
        ];

        $this->log($current, $user instanceof UserInterface ? $user->getUserIdentifier() : $user);
    }

    public function allSessions(array $excludeUsers = []): array
    {
        $allKeys = $this->redis->hGetAll("user_session");
        $allSessions = [];
        foreach ($allKeys as $name => $sessions) {
            if (in_array($name, $excludeUsers)) {
                continue;
            }

            $sessions = $sessions ? json_decode($sessions, true) : [];
            $sessions = array_map(fn($s) => $s + ['uid' => $name], $sessions);

            if (count($sessions) > 50) {
                usort($sessions, fn($s1, $s2) => -1 * ($s1['last_usage'] <=> $s2['last_usage']));
                $sessions = array_slice($sessions, 0, 50);
            }

            $allSessions = array_merge($allSessions, $sessions);
        }

        usort($allSessions, fn($s1, $s2) => -1 * ($s1['last_usage'] <=> $s2['last_usage']));
        return array_map(AuditSession::create(...), $allSessions);
    }

    /**
     * @param string $userIdentity
     * @return AuditSession[]
     */
    public function getSessions(string $userIdentity): array
    {
        $sessions = $this->redis->hGet("user_session", $userIdentity);
        $sessions = $sessions ? json_decode($sessions, true) : [];
        $sessions = array_map(fn($s) => $s + ['uid' => $userIdentity], $sessions);

        usort($sessions, fn($s1, $s2) => -1 * ($s1['last_usage'] <=> $s2['last_usage']));

        return array_map(AuditSession::create(...), $sessions);
    }

    public function logWebLogin(Request $request, string|UserInterface $user, bool $rememberMe = false, string $error = null): void
    {
        $current = [
            'ua' => $request->headers->get('user-agent'),
            'ip' =>  $request->getClientIp(),
            'last_usage' => time(),
            'usage' => 1,
            'web' => true,
            'remember_me' => $rememberMe,
            'error' => $error,
        ];

        $this->log($current, $user instanceof UserInterface ? $user->getUserIdentifier() : $user);
    }

    public function logApi(Request $request, string|UserInterface $user, #[\SensitiveParameter] string $apiToken, string $error = null): void
    {
        $user = $user instanceof UserInterface ? $user->getUserIdentifier() : $user;

        $current = [
            'ua' => $request->headers->get('user-agent'),
            'ip' =>  $request->getClientIp(),
            'last_usage' => time(),
            'api_token' => (str_contains($apiToken, '_') ? substr($apiToken, 0, 5) : substr($apiToken, 0, 2)) . '***' .  substr($apiToken, -1),
            'usage' => 1,
            'error' => $error,
        ];

        $this->log($current, $user);
    }

    private function log(array $session, string $identity): void
    {
        try {
            $sessions = $this->redis->hGet("user_session", $identity);
        } catch (\Exception $e) {
            return;
        }

        $sessions = $sessions ? json_decode($sessions, true) : [];

        if (is_string($session['ua'] ?? null)) {
            $session['ua'] = substr($session['ua'], 512);
        }
        if (is_string($session['error'] ?? null)) {
            $session['error'] = substr($session['error'], 512);
        }

        $unix = time();
        $len = count($sessions);
        $probe = $session;
        unset($probe['usage'], $probe['last_usage'], $probe['error'], $probe['ua']);

        $exists = false;
        for ($i = $len - 4; $i < $len; $i++) {
            $probe1 = $sessions[$i] ?? null;
            unset($probe1['usage'], $probe1['last_usage'], $probe1['error'], $probe1['ua']);

            if ($probe1 === $probe) {
                $probe = $sessions[$i];
                if ($unix - $probe['last_usage'] < 10) {
                    return;
                }

                $probe['last_usage'] = $unix;
                $probe['usage'] = ($probe['usage'] ?? 1) + 1;
                $sessions[$i] = $probe;
                $exists = true;
                break;
            }
        }

        if (false === $exists) {
            $sessions[] = $session;
        }

        if (count($sessions) > self::KEEP_COUNT + 5) {
            usort($sessions, fn($s1, $s2) => $s1['last_usage'] <=> $s2['last_usage']);
            $sessions = array_slice($sessions, count($sessions) - 5);
        }

        $this->hSet("user_session", $identity, json_encode($sessions));
    }
}
