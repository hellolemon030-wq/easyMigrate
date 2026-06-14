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

$initPath = __DIR__ . "/demo/"; // プロジェクトのパス 
$initProjectId = "databee"; // プロジェクトID
$initDbTag = "admin"; // プロジェクトに、いくつかのデータベースを識別するタグを指定する；今後getDbInstanceByProjectId()で使用するのは可能です。

$init = new initInstance();
$init->projectDir = $initPath;
$init->projectId = $initProjectId;
$init->dbTag = $initDbTag;

$pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName}", $dbUser, $dbPassword);
$pdoDb = new PdoDb($pdo);

$migrater = new migrate($pdoDb);   
$init->_db = $pdoDb; 

$migrater->addInit($init);

// 从cmd获取文件名
$fileName = $argv[1];

$migrater->repair($init->projectId,$fileName);