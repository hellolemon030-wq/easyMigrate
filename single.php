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

$initPath = __DIR__ . "/demo/";
$initProjectId = "demo";
$initDbTag = "demo";

$init = new initInstance();
$init->projectDir = $initPath;
$init->projectId = $initProjectId;
$init->dbTag = $initDbTag;

$pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName}", $dbUser, $dbPassword);
$pdoDb = new PdoDb($pdo);

$tables = $pdoDb->fetchAll("SHOW TABLES");
foreach($tables as $table){
    $ctable = current($table);
    $sql = "DROP TABLE IF EXISTS {$ctable}";
    $pdoDb->execute($sql);
}

$migrater = new migrate($pdoDb);    

// この機能は当初、ディレクトリを入力して設定ファイルを取得する仕様を予定していましたが、
// その後、手動注入（インジェクション）する方式に変更しました。
// ディレクトリ方式にする場合は、dbTagを利用して異なるデータベースを識別する、
// つまり getDbInstanceByProjectId() のような形を想定しています。
// 現在は、この方法で特定の dbInstance インスタンスを指定することができます。
//ローカル環境で実行する場合は指定しなくても問題なく、デフォルトのデータベース接続は、この機能が使用する接続と一致します。
$init->_db = $pdoDb;    

$migrater->addInit($init);


// 機能のinitialize
$migrater->migrateInit();

try{
    // 自动迁移
    $migrater->autoMigrate($init->projectId);
} catch(AutoMigrateException $e){
    // 例の中に、わざとエラーを発生させる場合がある
    $errorInitInstance = $e->lastErrorInitInstall;
    $errorFile = $e->lastErrorFile;

    // 手作業で、問題を修復のを模擬する
    $migrater->repair($errorInitInstance->projectId, $errorFile);

}



// スムーズに、自動迁移を実行する
$migrater->autoMigrate($init->projectId);




