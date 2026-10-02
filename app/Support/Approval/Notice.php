<?php

namespace App\Support\Approval;

/**
 * 1 人に出す 1 つの知らせの中身（段階3 設計書 §5.5）。作るのは NoticeText だけ（文言を 1 か所に置く）。
 *
 * ⚠ メールに持たせてキューに積むので、中身は文字と真偽だけにする（モデルを持たせない。キューの中で読み直さない）。
 */
final class Notice
{
    /**
     * @param string       $scene       場面（お知らせの data に残す。turn・changed・returned・decided・condition・confirmed・withdrawn・undone）
     * @param string       $headline    見出し（メールの件名の「【決裁】」の後ろ・お知らせの文の頭）
     * @param string       $action      必要な対応
     * @param list<string> $lead        メールの本文の前置き（宛名の次の行から）
     * @param bool         $mailed      メールも送るか（場面 6 はお知らせだけ）
     * @param bool         $handlerTurn 担当の番の知らせか（申請者本人には出さない。§5.4 の決まり 5）
     */
    public function __construct(
        public readonly string $scene,
        public readonly string $headline,
        public readonly string $action,
        public readonly array $lead,
        public readonly bool $mailed = true,
        public readonly bool $handlerTurn = false,
    ) {}
}
