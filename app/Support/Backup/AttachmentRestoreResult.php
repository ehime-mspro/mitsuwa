<?php

namespace App\Support\Backup;

/**
 * 添付の取り出しの結果。
 */
final class AttachmentRestoreResult
{
    /**
     * @param  int  $total  今のキーの置き場所にある件数
     * @param  int  $restored  取り出せた件数
     * @param  array<string, string>  $failures  元の相対パス（戻せなければキーそのもの） => 失敗の理由
     * @param  int  $untried  連続失敗による打ち切りで試さなかった件数
     * @param  int  $otherLocationOnly  今のキーの置き場所には無く、ほかの置き場所だけにある件数（重複なし）
     */
    public function __construct(
        public readonly int $total,
        public readonly int $restored,
        public readonly array $failures,
        public readonly int $untried,
        public readonly int $otherLocationOnly,
    ) {}
}
