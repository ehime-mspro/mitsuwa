<?php

/*
|--------------------------------------------------------------------------
| 夜間バックアップ（要件定義書 14.6 / 手順書 docs/運用_バックアップとメール.md）
|--------------------------------------------------------------------------
|
| 毎晩、データベース全体と storage/app 配下のファイルを暗号化して、
| さくらのオブジェクトストレージへ送る。秘密情報は本番の .env にだけ書く。
|
*/

return [

    // 保管先: s3（本番） / local（手元での通し確認用）
    'storage' => env('BACKUP_STORAGE', 's3'),

    // local のときの保管フォルダ（deploy.sh は storage/ を本番へ送るため、既定はリポジトリの外）
    'local_root' => env('BACKUP_LOCAL_ROOT', sys_get_temp_dir().'/manage-backup-local'),

    // 暗号化キー（base64 の 32 バイト）。`php artisan ops:backup-key` で作り、紙にも控える
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

    // 失敗を知らせる宛先（カンマ区切りで複数可）
    'notify_to' => env('BACKUP_NOTIFY_TO'),

    // データベースのバックアップを残す日数（最新の 1 件は日数に関係なく残す）
    'retention_days' => 30,

    // 平文のダンプを一時的に置く作業フォルダ。名前は backup-work 以外を受け付けない
    'work_dir' => env('BACKUP_WORK_DIR', storage_path('app/backup-work')),

    // storage/app からの相対パスで、バックアップするフォルダ
    'file_roots' => ['public', 'private'],

    'mysqldump' => [
        'binary' => env('BACKUP_MYSQLDUMP_BINARY', 'mysqldump'),
        // 環境に合わせて足すオプション（空白区切り）
        'extra_options' => env('BACKUP_MYSQLDUMP_EXTRA_OPTIONS', ''),
        'timeout' => 1800,
    ],

    // さくらのクラウド オブジェクトストレージ（S3 互換・石狩第1）
    's3' => [
        'endpoint' => env('BACKUP_S3_ENDPOINT', 'https://s3.isk01.sakurastorage.jp'),
        'region' => env('BACKUP_S3_REGION', 'jp-north-1'),
        'bucket' => env('BACKUP_S3_BUCKET'),
        'key' => env('BACKUP_S3_ACCESS_KEY'),
        'secret' => env('BACKUP_S3_SECRET_KEY'),
    ],

];
