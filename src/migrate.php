<?php
namespace Zjk\EasyMigrate;

use Exception;
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
     * TODO; 増量更新のサポート；
     * @throws AutoMigrateException 自動迁移失敗時，拋げられる例外、具体的なproject情報とfile情報が含まれる
     */
    public function autoMigrate($projectId){
        $init = $this->getInitByProjectId($projectId);
        $files = $this->scanFiles($init->getProjectDir());
        $projectId = $init->getProjectId();
        foreach ($files as $file) {
            $exists = $this->isExecuted($projectId, $file);
            if ($exists) {
                self::log("MIGRATE SKIP {$file} already executed",'yellow');
                continue;
            }
            $sql = file_get_contents($file);
            self::log("MIGRATE START {$file}",'green');

            $tagetDb = $this->getRunSqlDb($init);
            if($this->containsDDL($sql)){
                try{
                    $this->runSql($tagetDb,$sql);    // TODO; DDL問題の説明；
                    $this->markExecuted($projectId, $file);
                } catch (Exception $e) {
                    self::log("MIGRATE STOP {$file} failed",'red');
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
                } catch (Exception $e) {
                    try {
                        $tagetDb->rollback();
                    } catch (Exception $ignore) {
                        // DDL問題によりtransaction already closed が発生する
                    }
                    self::log("MIGRATE STOP {$file} failed",'red');
                    throw AutoMigrateException::generateInstance($e,$init, $file);
                }

            }
            self::log("MIGRATE END {$file}");
        }
    }

    /**
     * 判断 migration 文件是否以 DDL 语句开头。
     *
     * 背景：
     * MySQL 中 CREATE / ALTER / DROP 等 DDL 语句会触发隐式提交（implicit commit），
     * 即使外层开启了事务，也可能导致事务提前结束，因此这类文件不适合按事务方式执行。
     *
     * 约定：
     * - 一个 migration 文件只包含同类型 SQL（DDL 或 DML）
     * - 只检查第一条有效 SQL 语句
     * - 忽略空行和以 -- / # 开头的注释
     *
     * 实现原理：
     * 1. 去除注释行
     * 2. 跳过文件开头的空白字符
     * 3. 获取第一条 SQL 的第一个关键字
     * 4. 判断该关键字是否属于 DDL（CREATE/ALTER/DROP/TRUNCATE/RENAME）
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


    protected function scanFiles($dir){
        $files = glob($dir . '/*.sql');
        sort($files);
        return $files;
    }


    protected function isExecuted($projectId, $fileName) {
        $mid = $this->getFileUniqueId($projectId,$fileName);
        $sql = "SELECT status FROM {$this->getTableName()} WHERE project_id=? AND mid=?;";

        $row = $this->db->fetch(
            $sql,
            [$projectId, $mid]
        );
        return $row && $row['status'] == 1;
    }

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
        $this->log("MIGRATE MARK {$projectId} {$fileName} executed");
    }

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

    // 手作業で、問題を修復する
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

    public function setTableName($tableName){
        $this->_migrateInitTable = $tableName;
    }

    /**
     * @return initInstance[]
     */
    public $initCache = [];
    /**
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
     * @return initInstance
     */
    public function getInitByProjectId($projectId){
        if(!isset($this->initCache[$projectId])){
            throw new Exception("project {$projectId} not initialized");
        }
        return $this->initCache[$projectId];
    }

    public function addInit($init){
        $this->initCache[$init->projectId] = $init;
    }

    static public function makeConfigJson($projectId,$projectDir,$dbTag){
        return initInstance::makeConfigJson($projectId,$dbTag,$projectDir);
    }

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


