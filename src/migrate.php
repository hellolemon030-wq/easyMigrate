<?php
namespace Zjk\EasyMigrate;

use Exception;
use Throwable;
use Zjk\DbInterface\DbInterface;


class migrate
{
    CONST INI_FILE_NAME = "easyMigrate.ini";

    /** @var DbInterface $db */
    protected $db;
    public $_migrateInitTable = "easy_migrate";

    public function __construct(DbInterface $db){
        $this->db = $db;
    }

    /**
     * ```
     * TODO; 
     *      差分更新のサポート
     *      （実際には数万個のファイルであってもそれほど時間はかからないため、現時点ではこのままで問題ない）。
     * ```
     * @param string $projectId
     * @throws AutoMigrateException 自動迁移失敗時，拋げられる例外、具体的なproject情報とfile情報が含まれる
     */
    public function autoMigrate($projectId){
        $init = $this->getInitByProjectId($projectId);
        $files = $this->scanFilesAndSort($init->getProjectDir());
        $projectId = $init->getProjectId();
        foreach ($files as $file) {
            $exists = $this->isExecuted($projectId, $file);
            if ($exists) {
                self::log(" {$projectId} {$file} already executed skip",'yellow');
                continue;
            }
            $sql = file_get_contents($file);
            self::log(" {$projectId} {$file} start",'green');

            $tagetDb = $this->getRunSqlDb($init);
            if($this->containsDDL($sql)){
                try{
                    $this->runSql($tagetDb,$sql);    // TODO; DDL問題の説明；
                    $this->markExecuted($projectId, $file);
                } catch (Exception $e) {
                    self::log(" {$projectId} {$file} failed",'red');
                    throw AutoMigrateException::generateInstance($e,$init, $file);
                }
            } else {
                try {
                    $tagetDb->begin();
                    $this->runSql(
                        $tagetDb,
                        $sql
                    );
                    $tagetDb->commit();
                    $this->markExecuted($projectId, $file);
                } catch (Throwable $e) {
                    try {
                        $tagetDb->rollback();
                    } catch (Throwable $ignore) {
                        // DDL問題によりtransaction already closed が発生する
                    }
                    self::log(" {$projectId} {$file} failed",'red');
                    throw AutoMigrateException::generateInstance($e,$init, $file);
                }

            }
            self::log(" {$projectId} {$file} end");
        }
    }

    /**
     * マイグレーションファイルが DDL 文で始まっているかどうかを判定します。
     *
     * 背景：
     * MySQL では、CREATE / ALTER / DROP などの DDL 文が実行されると暗黙的なコミット（implicit commit）が発生します。
     * そのため、外側でトランザクションを開始していても途中で終了してしまう可能性があり、こうしたファイルはトランザクション処理には適していません。
     *
     * ルール：
     * - 1つのマイグレーションファイルには、同じ種別の SQL（DDL または DML）のみを含めること
     * - 最初に実行される有効な SQL 文のみをチェックすること
     * - 空行、および -- や # で始まるコメント行は無視すること
     *
     * 処理概要：
     * 1. コメント行を除去
     * 2. ファイル先頭の空白文字をスキップ
     * 3. 最初の SQL の先頭のキーワードを取得
     * 4. そのキーワードが DDL（CREATE/ALTER/DROP/TRUNCATE/RENAME）に該当するか判定
     *
     * 初めのキーワードを判断　だけ、十分に判断にはできない
     * @param string $sql 
     * @return bool true: DDL；
     */
    protected function containsDDL($sql) {
        $sql = preg_replace('/^\s*(--|#).*$/m', '', $sql);
        $sql = ltrim($sql);
        if ($sql === '') {
            return false;
        }
        $firstWord = strtolower(strtok($sql, " \t\r\n("));
        return in_array($firstWord, [
            'create',
            'alter',
            'drop',
            'truncate',
            'rename',
        ], true);
    }

    /**
     * @param string $projectId 
     * @param string $fileName 
     * @return string
     */
    public function getFileUniqueId($projectId,$fileName){
        return md5($projectId . '::' . $fileName);
    }

    /**
     * ディレクトリ内の SQL ファイルを再帰的にスキャンしてソートします。
     *
     * @param string $dir ルートディレクトリ
     * @param int $maxDepth 最大スキャン階層数（デフォルト: 2層まで）
     * @return array 相対パスの配列
     */
    protected function scanFilesAndSort($dir, $maxDepth = 2)
    {
        $files = [];

        // 最大階層数を超えた場合は処理を終了（0未満でストップ）
        if ($maxDepth < 0) {
            return $files;
        }

        // パス区切り文字をスラッシュに統一し、末尾にスラッシュを付与
        $dir = rtrim(str_replace('\\', '/', $dir), '/') . '/';

        if (!is_dir($dir)) {
            return $files;
        }

        if ($dh = opendir($dir)) {
            while (($file = readdir($dh)) !== false) {
                if ($file === '.' || $file === '..') {
                    continue;
                }

                $fullPath = $dir . $file;

                if (is_dir($fullPath)) {
                    // ディレクトリの場合は、階層数をマイナス1して再帰呼び出し
                    $subFiles = $this->scanFilesAndSort($fullPath, $maxDepth - 1);
                    $files = array_merge($files, $subFiles);
                } else {
                    $pathInfo = pathinfo($fullPath);
                    if (isset($pathInfo['extension']) && strtolower($pathInfo['extension']) === 'sql') {
                        $files[] = $fullPath;
                    }
                }
            }
            closedir($dh);
        }

        // ファイル名で昇順ソートを行い、実行順序を統一
        sort($files);
        return $files;
    }




    /**
     * @param string $projectId
     * @param string $fileName
     * @return bool
     */
    protected function isExecuted($projectId, $fileName) {
        $mid = $this->getFileUniqueId($projectId,$fileName);
        $sql = "SELECT status FROM {$this->getTableName()} WHERE project_id=? AND mid=?;";

        $row = $this->db->fetch(
            $sql,
            [$projectId, $mid]
        );
        return $row && $row['status'] == 1;
    }

    /**
     * @param string $projectId
     * @param string $fileName
     */
    public function markExecuted($projectId, $fileName)
    {
        $mid = $this->getFileUniqueId($projectId,$fileName);
        $this->db->execute(
            "INSERT INTO {$this->getTableName()}
            (project_id, mid, file_name, status, executed_at)
            VALUES (?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE status=1, executed_at=NOW()",
            [$projectId, $mid, $fileName]
        );
        $this->log(" {$projectId} {$fileName} executed");
    }

    /**
     * @param DbInterface $db
     * @param string $sql
     */
    protected function runSql($db,$sql){
        $db->execute($sql);
    }

    /**
     * @param initInstance $init
     * @return DbInterface
     */
    public function getRunSqlDb($init){
        return $init->_db ? $init->_db : $this->db;
    }

    
    /**
     * 手作業で、問題を修復する
     * @param string $projectId
     * @param string $fileName
     */
    public function repair($projectId, $fileName){
        $this->markExecuted($projectId, $fileName);
    }

    // 機能を運用するため、核心表を生成する
    public function migrateInit(){
        $this->db->execute(
            "create table if not exists {$this->getTableName()} (
                id int primary key auto_increment,
                project_id varchar(30), 
                mid varchar(128), 
                file_name varchar(255), 
                status int default 0, 
                executed_at datetime,
                unique key uk_project_id_mid (project_id, mid)
            ) engine=InnoDB;"
        );
    }

    public function getTableName(){
        return $this->_migrateInitTable;
    }

    /**
     * @param string $tableName
     */
    public function setTableName($tableName){
        $this->_migrateInitTable = $tableName;
    }

    /**
     * @return initInstance[]
     */
    public $initCache = [];
    /**
     * @param string $projectDir
     * @return initInstance
     */
    public function getInitByProject($projectDir){
        $fileName = $projectDir . '/' . self::INI_FILE_NAME;
        if(!file_exists($fileName)){
            throw new Exception("文件{$fileName}不存在");
        }
        $ini = parse_ini_file($fileName);
        $init = new initInstance();
        $init->projectId = $ini['project_id'];
        $init->dbTag = $ini['db_tag'];
        $init->projectDir = $projectDir;
        $this->initCache[$init->projectId] = $init;
        return $init;
    }

    /**
     * @param string $projectId
     * @return initInstance
     */
    public function getInitByProjectId($projectId){
        if(!isset($this->initCache[$projectId])){
            throw new Exception("project {$projectId} not initialized");
        }
        return $this->initCache[$projectId];
    }

    /**
     * @param initInstance $init
     */
    public function addInit($init){
        $this->initCache[$init->projectId] = $init;
    }

    /**
     * @param string $projectId
     * @param string $projectDir
     * @param string $dbTag
     * @return string
     */
    static public function makeConfigJson($projectId,$projectDir,$dbTag){
        return initInstance::makeConfigJson($projectId,$dbTag,$projectDir);
    }

    /**
     * @param string $msg
     * @param string $color
     */
    static public function log($msg,$color = 'green'){
        $echo = $msg . "\n";
        switch($color){
            case 'green':
                $echo = "\033[32m{$echo}\033[0m";
                break;
            case 'red':
                $echo = "\033[31m{$echo}\033[0m";
                break;
            case 'yellow':
                $echo = "\033[33m{$echo}\033[0m";
                break;
            default:
                break;
        }
        echo $echo;
    }
}


