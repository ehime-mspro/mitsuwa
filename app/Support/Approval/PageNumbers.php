<?php

namespace App\Support\Approval;

/**
 * ページ送りに出す番号（段階2 の ⑩「決裁済み・否決」から寄せた。⑥ のお知らせ一覧も使う。段階3 設計書 §5.6）。
 * 画面の部品は approvals._pager（->links() は使わない。Bug #24）。
 */
final class PageNumbers
{
    /**
     * 出す番号（null は「…」）。先頭・最後・今のページの前後 1 つだけにする（全部を並べるとスマホの幅からはみ出す。
     * 2b の Task 9 の B1・利用者の決定 C5）。1 ページだけの間は「…」にせず、その番号を出す。
     * 番号と「…」は多くて 7 つ（前後の「<」「>」を足して 9 個）
     *
     * @return list<int|null>
     */
    public static function around(int $current, int $last): array
    {
        $shown = array_unique(array_filter([1, $current - 1, $current, $current + 1, $last], fn (int $page) => $page >= 1 && $page <= $last));
        sort($shown);

        $numbers = [];
        foreach ($shown as $i => $page) {
            $between = $i === 0 ? 0 : $page - $shown[$i - 1] - 1;   // 前に出した番号との間のページ数
            if ($between === 1) {
                $numbers[] = $page - 1;
            } elseif ($between > 1) {
                $numbers[] = null;
            }
            $numbers[] = $page;
        }

        return $numbers;
    }
}
