{{--
    ガントの CSS（設計書 §4.2）。**ここがこの CSS の唯一の定義**で、
    現在はボード（_schedule_board）だけが @include する。
    詳細カード（_schedule_gantt）は **Task 8 で**同じ partial を @include する予定
    （本コミットの時点ではまだ min-width: 940px / flex: 0 0 262px の旧実装のまま）。

    ⚠ **`resources/css/app.css` には置かない。** ビルド済み CSS は .gitignore 済みで
       worktree に存在しないため、テストが実物を見られなくなる
       （RULES「Tailwind 監査の落とし穴 1」）。先行例は housing/contracts/index.blade.php の
       .co-sticky と tenant/area-buildings/_map_style.blade.php の AREA_MAP_STYLES。

    ⚠ **@once で囲む。** 現状ボードとカードが同一ページに同居することは無いが、
       将来同居したときに <style> が 2 回出るのを防ぐ。

    ⚠ **ラベル欄の幅（320 / 262 / 140px）はここだけが持つ。** PHP は
       `calc(var(--gantt-label-w) + <月数×150>px)` としか書かない（設計書 §4.2）。
--}}
@once
    @push('styles')
        <style>
            .gantt-scroll       { --gantt-label-w: 320px; }
            .gantt-scroll--card { --gantt-label-w: 262px; }

            /* 案件名（カードは工程名）の列を左端に貼り付ける。
               ⚠ 影は ::after でなく box-shadow で出す。ラベルのセルは overflow: hidden を
                  持っており（Bug #29 対策で外せない）、::after は right: -6px でクリップされる。
                  overflow は子孫を切るが、その要素自身の box-shadow は切らない。
               ⚠ 値そのものに実測の裏付けは無い（設計書 §4.2 の値をそのまま使っている）。
                  既存の固定列 .co-sticky（housing/contracts/index.blade.php）は
                  4px 0 6px -4px rgba(0,0,0,.15) と別の値で、揃えていない。 */
            .gantt-label        { position: sticky; left: 0; z-index: 5; background: #fff;
                                  box-shadow: 6px 0 6px -6px rgba(0, 0, 0, 0.18); }
            .gantt-label--head  { z-index: 6; background: #F9FAFB; }

            /* ⚠ **この @media は .gantt-scroll--card より後ろでなければならない。**
                  詳細度はどちらも (0,1,0) なので後勝ち。前に置くとカードだけ 262px のまま残り、
                  375px で軸が 81px しか見えなくなる（PHP もテストも素通りする型。Bug #29）。
               ⚠ 640px は app.css の既存ユーティリティ（.grid-stack-sm 等）と同じ境目に揃えている。 */
            @media (max-width: 640px) {
                .gantt-scroll   { --gantt-label-w: 140px; }
            }
        </style>
    @endpush
@endonce
