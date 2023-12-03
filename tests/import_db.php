<?php

namespace Packeton\Tests;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Symfony\Component\Yaml\Yaml;
use Doctrine\DBAL\Types\Type as DBALType;

require_once __DIR__ . '/../vendor/autoload.php';

$in = $argv[1] ?? null;
$out = $argv[2] ?? null;

if (empty($in) || empty($out)) {
    echo "Usage:\n";
    echo "    php import_db.php 'sqlite:////var/www/app/test.db' 'postgresql://postgres:pass123@127.0.0.1:5433/py1'\n";
    return 0;
}

$dbalTypes = [];
if (file_exists($conf_file = __DIR__.'/config/packages/doctrine.yaml')
    || file_exists($conf_file = dirname(__DIR__).'/config/packages/doctrine.yaml')
) {
    $dbalTypes = Yaml::parse(file_get_contents($conf_file))['doctrine']['dbal']['types'] ?? [];
}

foreach ($dbalTypes as $type => $class) {
    if (!DBALType::hasType($type)) {
        DBALType::addType($type, $class);
    }
}

$conn0 = DriverManager::getConnection(['url' => $in]);
$sm0 = $conn0->createSchemaManager();
$tab0 = $sm0->listTables();

$conn1 = DriverManager::getConnection(['url' => $out]);
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
    $columns = array_map(fn(Column $column) => $column->getName(), $table->getColumns());
    $tableMapColumn1[$table->getName()] = $columns;
}

$persister = static function(string $table) use ($conn0, $conn1, $tableMapColumn1, $tableMap0) {
    $data = $conn0->executeQuery("SELECT * FROM $table")->fetchAllAssociative();
    $columns = $tableMapColumn1[$table];
    foreach ($data as $item) {
        foreach ($item as $key => $value) {
            if (!isset($columns[$key])) {
                unset($item[$key]);
            }
        }
        $conn1->insert($table, $item);
    }
    return count($data);
};

foreach ($commitOfOrders as $table) {
    if (!isset($tableMap0[$table])) {
        continue;
    }

    $result = $persister($table);
    echo "Import $table. Rows $result\n";
    if (str_starts_with($out, 'postgresql')) {
        try {
            $max = $conn1->executeQuery("SELECT max(id) FROM $table")->fetchOne();
            if ($max > 0) {
                $max = $max+1;
                $conn1->executeQuery("ALTER SEQUENCE {$table}_id_seq RESTART WITH $max")->fetchOne();
                echo "ALTER SEQUENCE {$table}_id_seq RESTART WITH $max\n";
            }
        } catch (\Throwable $e) {
        }
    }
}
