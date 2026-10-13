<?php

namespace App\Support\Approval;

use RuntimeException;

/**
 * 紙の決裁台帳を取り込めないとき（ファイルとして読めない・行が多すぎる。段階5 設計書 §5.9）。
 * メッセージはそのまま画面に出せる文にする（取り込みの画面の赤帯）。行ごとの誤りはこれにせず、確認の画面のエラーの行に出す（D20）
 */
final class PaperImportException extends RuntimeException
{
}
