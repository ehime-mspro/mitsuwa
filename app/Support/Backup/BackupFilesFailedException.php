<?php

namespace App\Support\Backup;

use RuntimeException;

/**
 * BackupRunner::backupFiles() が自ら組み立てた集約メッセージ（「データベースは保存済みです…」の
 * 文脈をすでに含む）であることの印。run() はこれを素通りさせ、それ以外の例外にだけ同じ文脈を
 * 付けて包み直す（一覧取得の失敗など、ループの外で起きたものは文脈が付いていないため）。
 */
final class BackupFilesFailedException extends RuntimeException {}
