<?php

namespace App\Support\Backup;

/**
 * 夜間バックアップ 1 回分の結果。
 */
final class BackupSummary
{
    public function __construct(
        public readonly string $databaseKey,
        public readonly int $filesScanned,
        public readonly int $filesUploaded,
        public readonly int $databaseBackupsDeleted,
    ) {}
}
