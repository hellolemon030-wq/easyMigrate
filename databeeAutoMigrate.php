<?php

use Zjk\DbInterface\PdoDb;
use Zjk\EasyMigrate\AutoMigrateException;
use Zjk\EasyMigrate\initInstance;
use Zjk\EasyMigrate\migrate;


include __DIR__ . "/vendor/autoload.php";

$dbHost = "localhost";
$dbPort = 3306;
$dbUser = "root";
$dbPassword = "";
$dbName = "easy_migrate";

$projectDir = __DIR__ . "/demo/"; // プロジェクトのパス 
$projectId = "databee"; // プロジェクトID
$projectDbTag = "admin"; // プロジェクトに、いくつかのデータベースを識別するタグを指定する；今後getDbInstanceByProjectId()で使用するのは可能です。

$init = new initInstance();
$init->projectDir = $projectDir;
$init->projectId = $projectId;
$init->dbTag = $projectDbTag;

$pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName}", $dbUser, $dbPassword);
$pdoDb = new PdoDb($pdo);

$migrater = new migrate($pdoDb);  
$migrater->migrateInit();
$init->_db = $pdoDb; 

$migrater->addInit($init);

try{
    $migrater->autoMigrate($init->projectId);
} catch (AutoMigrateException $e) {
    echo "repair cmd: \n php databeeRepair.php {$e->lastErrorFile}\n";
}
