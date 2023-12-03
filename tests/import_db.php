<?php

namespace Packeton\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Symfony\Component\Yaml\Yaml;
use Doctrine\DBAL\Types\Type as DBALType;

require_once __DIR__ . '/../vendor/autoload.php';

$projectDir = dirname(__DIR__);

$initDb = __DIR__ . '/dump/test.db';
if (!file_exists($initDb)) {
    $db = gzdecode(file_get_contents($projectDir.'/tests/dump/test.db.gz'));
    file_put_contents($initDb, $db);
}

$dbalTypes = Yaml::parse(file_get_contents($projectDir.'/config/packages/doctrine.yaml'))['doctrine']['dbal']['types'] ?? [];
foreach ($dbalTypes as $type => $class) {
    if (!DBALType::hasType($type)) {
        DBALType::addType($type, $class);
    }
}

$conn0 = DriverManager::getConnection(['url' => 'sqlite:///'.$initDb]);
$sm0 = $conn0->createSchemaManager();
$tab0 = $sm0->listTables();

if (!$url = getenv('DATABASE_URL')) {
    echo "DATABASE_URL is empty";
    exit(1);
}

$conn1 = DriverManager::getConnection(['url' => $url]);
$sm1 = $conn1->createSchemaManager();
$tab1 = $sm1->listTables();

$dependMap = [];
foreach ($tab1 as $table) {
    $depends = array_map(fn(ForeignKeyConstraint $fk) => $fk->getForeignTableName(), $table->getForeignKeys());
    $dependMap[$table->getName()] = array_flip($depends);
}

$commitOfOrders = [];
$proc1 = static function($dependMap) use (&$commitOfOrders, &$proc1) {
    $resolved = false;
    foreach ($dependMap as $table => $deps) {
        $deps = $dependMap[$table];
        if (isset($deps[$table])) {
            unset($deps[$table]);
            $dependMap[$table] = $deps;
        }

        if (empty($deps)) {
            $commitOfOrders[] = $table;
            unset($dependMap[$table]);

            foreach ($dependMap as $t1 => $deps1) {
                if (isset($deps1[$table])) {
                    $resolved = true;
                    unset($deps1[$table]);
                    $dependMap[$t1] = $deps1;
                }
            }
        }
    }

    if ($resolved) {
        return $proc1($dependMap);
    }

    return $dependMap;
};

$proc1($dependMap);

$tableMap0 = [];
$tableMapColumn1 = [];
foreach ($tab0 as $table) {
    $tableMap0[$table->getName()] = $table;
}

foreach ($tab1 as $table) {
    $table->getColumns();
    $tableMap0[$table->getName()] = $table;
}

print_r($commitOfOrders);
