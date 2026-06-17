

【開発の目的と背景】
当機能は、日々の開発プロセスにおいて、ソースコード更新時に「データベースの変更（マイグレーション）漏れ」が原因でプログラムに異常が発生する問題を解決することを目的としています。

【前提とする開発実態とリスク評価】
実際の開発現場におけるデータベースの変更要求は、「カラムの追加」「カラム型の変更」「新規テーブルの追加」が大部分を占め、「カラムの削除」や「テーブルの削除」が発生することは極めて稀です。
この実態から、データベースへ追加・変更操作を行った後にソースコード側のみがロールバック（古いバージョンへの差し戻し）されたとしても、データベース側に過去の資産が残るため、プログラム側で致命的な異常が発生するリスクは極めて低いと想定しています（後方互換性の維持）。

【導入による効果】
ソースコードの更新・開発・デバッグ中にDB起因の異常を検知した際、まずは当スクリプトを一クリックで実行するだけで、データベースが自動的に最新状態へと修復されます。上述の通り、リスクの低い追加・変更操作が大部分を占めるため、この一斉自動修復によって日常的なエラーの約8，90%をその場で即座に解決できることを見込んでいます。




```sh

## まずは、dependencyをインストールしてください。
composer install


## 具体には、以下のscriptを参考してください。
php single.php

php databeeAutoMigrate.php

php databeeRepair.php xxxxxxxxxxxx

```



```
//migrateEngineは、fileNameの順序で実行されます。
|- a
    |- yyyy-mm-dd   //dir
        |- yyyy-mm-dd_xx_カスタマイズ3.sql
    |- yyyy-mm-dd_xx_カスタマイズ1.sql
    |- yyyy-mm-dd_xx_カスタマイズ2.sql

【重要：DDL操作とマイグレーションファイル分割に関するガイドライン】

DDL（データ定義言語）操作を実行すると、現在のトランザクションが自動的にコミット（終了）されます。そのため、1回の開発で実行するDDL文は必ず専用のファイルにまとめ、非DDL文（DMLなど）と同じファイルに混在させないでください。

1回の開発・調整において、DDLと非DDLの両方が発生する場合は、以下のようにファイル名を分けて順序制御を行うことで解決してください。

例：
|- yyyy-mm-dd_01_create_table.sql  （DDL専用ファイル：テーブル作成など）
|- yyyy-mm-dd_02_insert_data.sql   （非DDLファイル：初期データ投入など）
```

```php
$initInstance = new initInstance();
$initInstance->projectId = "projectId";     
$initInstance->projectDir = "/a/";
// dbInterfaceのインスタンスを渡します。指定しない場合、Engineと同じDBが使用されます。当Engineは、現在プロジェクトと同じDBを使用しても問題ありません。
$initInstance->_db; 

$migrate->addInit($initInstance);

// Engineは同時に複数のプロジェクトに対応しており
$migrate->addInit($initInstance2); 
$migrate->addInit($initInstance3); 
// ...


$migrate->autoMigrate($projectId);  // 自動でmigrateEngineを実行します。

// もしmigrateEngineが失敗した場合、手動で問題のあるSQLファイルを確認・修正した後にrepairを実行します。Engineに修復を通知することで、再び自動マイグレーションが可能になります。
$migrate->repair($projectId, $fileName); 

$migrate->autoMigrate($projectId);  // 再び自動マイグレーションを実行します。

```