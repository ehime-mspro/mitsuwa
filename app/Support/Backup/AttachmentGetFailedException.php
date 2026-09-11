<?php

namespace App\Support\Backup;

use RuntimeException;

/**
 * restoreOneFile() が保管先からの取得（$storage->get()）で失敗したことの印。
 * 復号やフォルダ作成など、それ以外の失敗と区別して「連続失敗による打ち切り」の対象にするために使う
 * （復号の失敗はローカル側の問題で、保管先の不調とは別物のため数えない）。
 */
final class AttachmentGetFailedException extends RuntimeException {}
