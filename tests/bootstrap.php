<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (file_exists(dirname(__DIR__).'/config/bootstrap.php')) {
    require dirname(__DIR__).'/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

/**
 * Executes a given command.
 *
 * @param string $command a command to execute
 */
function executeCommand(string $command): void {
    $output = [];
    $returnCode = null;
    exec($command, $output, $returnCode);
    if ($returnCode !== 0) {
        throw new \Exception(sprintf('Error executing command "%s", return code was "%s".', $command, $returnCode));
    }
}

$projectDir = dirname(__DIR__);

$db = gzdecode(file_get_contents($projectDir.'/tests/dump/test.db.gz'));
file_put_contents($projectDir.'/var/test.db', $db);

executeCommand('php ./bin/console doctrine:schema:update --force --complete --env=test -q');
executeCommand('php ./bin/console redis:query flushall --env=test -n -q');
