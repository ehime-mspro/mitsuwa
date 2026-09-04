{{--
    工程表ボードの本体（設計書 §4.2）。不動産用・住宅用の 2 画面が共有する唯一の定義。

    必要な変数: $board（App\Services\ScheduleBoardService::build() の戻り値）
                $boardRoute（'realestate.schedules.index' など）
--}}
@php($f = $board['filters'])
@php($axis = $board['axis'])

@include('_partials._schedule_gantt_style')

@if($board['unregisteredCount'] > 0)
    <div style="font-size: 12px; color: #6B7280; margin-bottom: 12px;">
        工程が未登録の案件が {{ $board['unregisteredCount'] }} 件あります（ボードには出ません）。
    </div>
@endif

{{-- 絞り込み。
     ⚠ **既存のフィルタバーとまったく同じマークアップにする**（`realestate/procurements/index.blade.php`
        から書き写した）。⚠ **`class="form-input"` を使わない** —— アプリのフィルタバーは
        どこも下のユーティリティ列を直接書いており、`.form-input` は
        `appearance: none; border-radius: 0` を含むのでセレクトから矢印が消える（Bug #18 と同型）。
     ⚠ フォーム側の `flex flex-col sm:flex-row` がモバイルでの縦積みを担っている。
        インラインの `display: flex` で置き換えない。 --}}
<form id="filter-form" method="GET" action="{{ route($boardRoute) }}"
      class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
    <select name="kind" onchange="document.getElementById('filter-form').submit()"
            class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
        <option value="all" @selected($f['kind'] === 'all')>種別: すべて</option>
        @foreach($board['kinds'] as $key => $kind)
            <option value="{{ $key }}" @selected($f['kind'] === $key)>種別: {{ $kind[1] }}</option>
        @endforeach
    </select>

    <select name="status" onchange="document.getElementById('filter-form').submit()"
            class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
        @foreach($board['statuses'] as $value => $label)
            <option value="{{ $value }}" @selected($f['status'] === $value)>ステータス: {{ $label }}</option>
        @endforeach
    </select>

    <input type="text" name="q" value="{{ $f['q'] }}" placeholder="案件名・工程名で検索"
           class="h-9 px-3 border border-gray-300 rounded-md text-sm text-gray-700 bg-white focus:border-emerald-500 focus:outline-none w-full sm:w-56">

    <button type="submit" class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-600">検索</button>
    <a href="{{ route($boardRoute) }}" class="h-9 px-3 border border-gray-200 rounded-md text-xs text-gray-400"
       style="display: inline-flex; align-items: center; justify-content: center;">クリア</a>
</form>

@if($board['rows'] === [])
    <div style="background: white; border: 1px solid #E5E7EB; border-radius: 8px; padding: 28px 16px; text-align: center; color: #9CA3AF; font-size: 13px;">
        該当する案件がありません。
    </div>
@else
    <div style="border: 1px solid #E5E7EB; border-radius: 8px; overflow: hidden; background: white;">
        <div id="schedule-board-scroller" class="gantt-scroll" style="overflow-x: auto;">
            <div style="width: calc(var(--gantt-label-w) + {{ $axis['trackWidthPx'] }}px);">

                {{-- ヘッダ --}}
                <div style="display: flex; height: 42px; background: #F9FAFB; border-bottom: 1px solid #E5E7EB;">
                    <div class="gantt-label gantt-label--head" style="flex: 0 0 var(--gantt-label-w); min-width: 0; overflow: hidden; border-right: 1px solid #E5E7EB; display: flex; align-items: center; padding: 0 12px; font-size: 11.5px; font-weight: 700; color: #6B7280;">案件</div>
                    <div style="flex: 1 1 auto; min-width: 0; position: relative; display: flex;">
                        {{-- ⚠ 年 span と月名の間に**改行も空白も入れない**（設計書 §12.4）。
                             ⚠ **見た目は変わらない** —— このセルは display: flex なので、改行込みの
                                テキスト実行は匿名ブロックの flex アイテムになり行頭の空白が除去される
                                （2026-09-04 実ブラウザ実測: 改行あり／なしとも間隔 3.000px で完全一致）。
                                変わるのは HTML の形だけで、テストの隣接チェック
                                （<span class="gantt-year">2025</span>6月）が落ちる。
                             ⚠ ただし将来このセルの display: flex を外すと本当に空白が入る
                                （同実測で block 化すると内容が 3.66px 広がる）。 --}}
                        @foreach($axis['headers'] as $h)
                            <div style="width: {{ $h['widthPct'] }}%; border-right: 1px solid {{ $h['strong'] ? '#D1D5DB' : '#E5E7EB' }}; font-size: 11px; color: #6B7280; display: flex; align-items: center; justify-content: center; box-sizing: border-box; overflow: hidden;"><span class="gantt-year">{{ $h['year'] }}</span>{{ $h['label'] }}</div>
                        @endforeach
                        @if($axis['todayPct'] !== null)
                            <div style="position: absolute; top: 2px; left: {{ $axis['todayPct'] }}%; transform: translateX(-50%); background: #EF4444; color: white; font-size: 9.5px; font-weight: 700; padding: 1px 6px; border-radius: 999px; white-space: nowrap; z-index: 4;">今日 {{ $axis['todayLabel'] }}</div>
                        @endif
                    </div>
                </div>

                {{-- 1 行 1 案件 --}}
                @foreach($board['rows'] as $row)
                    <div x-data="{ open: false }" style="border-bottom: 1px solid #F3F4F6;">
                        <div style="display: flex; height: {{ $row['rowHeight'] }}px;">
                            <div class="gantt-label" style="flex: 0 0 var(--gantt-label-w); border-right: 1px solid #E5E7EB; display: flex; align-items: center; gap: 6px; padding: 0 12px; font-size: 12.5px; min-width: 0; overflow: hidden;">
                                <button type="button" @click="open = !open" :aria-expanded="open ? 'true' : 'false'"
                                        style="border: none; background: none; cursor: pointer; color: #6B7280; font-size: 12px; padding: 0 2px;">▸</button>
                                <span style="font-size: 10px; font-weight: 700; color: #6B7280; background: #F3F4F6; border-radius: 4px; padding: 1px 6px; white-space: nowrap;">{{ $row['kindLabel'] }}</span>
                                <a href="{{ $row['url'] }}" style="color: #111827; text-decoration: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0;">{{ $row['name'] }}</a>
                                @if($row['delayDays'] > 0)
                                    <span style="margin-left: auto; font-size: 10.5px; color: #DC2626; font-weight: 700; white-space: nowrap;">+{{ $row['delayDays'] }}日</span>
                                @endif
                            </div>
                            <div style="flex: 1 1 auto; min-width: 0; position: relative;">
                                @if($axis['todayPct'] !== null)
                                    <div style="position: absolute; top: 0; bottom: 0; left: {{ $axis['todayPct'] }}%; width: 0; border-left: 2px dashed #EF4444; z-index: 3;"></div>
                                @endif
                                @foreach($row['milestones'] as $ms)
                                    <div style="position: absolute; top: 2px; left: {{ $ms['leftPct'] }}%; z-index: 2;">
                                        <span style="display: block; width: 9px; height: 9px; border-radius: 2px; transform: rotate(45deg); {{ $ms['reached'] ? 'background: #111827;' : 'background: white; border: 2px solid #111827;' }}"></span>
                                    </div>
                                @endforeach
                                @foreach($row['bars'] as $bar)
                                    <div title="{{ $bar['name'] }}"
                                         style="position: absolute; height: 13px; border-radius: 3px; box-sizing: border-box; top: {{ $bar['topPx'] }}px; left: {{ $bar['leftPct'] }}%; width: {{ $bar['widthPct'] }}%; background: {{ $bar['color'] }}; {{ $bar['future'] ? 'opacity: 0.45;' : '' }} {{ $bar['late'] ? 'border: 2px solid #DC2626;' : '' }}{{ $bar['ring'] ? ' box-shadow: 0 0 0 1.5px #111827;' : '' }}"></div>
                                @endforeach
                            </div>
                        </div>

                        {{-- 展開: その案件の工程明細。⚠ x-show と :style を同じタグに置かない（Bug #32） --}}
                        <div x-show="open" x-cloak>
                            <div style="background: #FCFCFD; border-top: 1px solid #F3F4F6; padding: 8px 12px 10px 40px;">
                                @foreach($row['steps'] as $step)
                                    <div style="display: flex; align-items: center; gap: 10px; font-size: 12px; color: #374151; padding: 2px 0;">
                                        <span style="display: inline-block; width: 18px; height: 8px; border-radius: 3px; background: {{ $step['color'] }};"></span>
                                        <span style="min-width: 180px;">{{ $step['name'] }}</span>
                                        <span style="color: #9CA3AF;">{{ $step['periodText'] }}</span>
                                        @if($step['delayDays'] > 0)
                                            <span style="color: #DC2626; font-weight: 700;">+{{ $step['delayDays'] }}日</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                @endforeach

            </div>
        </div>
    </div>

    {{-- 開いた直後に「今日の前月の 1 日」が軸の左端に来る位置まで横スクロールしておく
         （設計書 §2 D15 / §12.4）。

         ⚠ **アロー関数を属性にも <script> にも書かない。** Blade の属性内では
            `=>` の `>` が HTML 終了タグとして解釈される（Top trap #4）。
            x-init ではなく名前付き関数にしているのはこのため。

         ⚠ **位置(%) は PHP が出す。** ここが計算するのはスクロール量だけで、
            日付 → % の計算は持たない（Bug #41 の二重実装を避ける）。

         ⚠ **`--gantt-label-w` は読まない。** 案件名の列は position: sticky; left: 0 なので、
            scrollLeft = S のとき軸の見えている範囲は月エリア座標で
            [S, S + (clientWidth − labelW)] ＝ **左端はちょうど S**。
            左端に置くだけなら引き算そのものが要らない（設計書 §12.4）。
            旧実装（D9）は今日を「見えている幅の中央」に置くために引いていた。

         ⚠ **今日が軸の外でも必ずスクロールする。** initialPct は 0〜100 に
            クランプ済みで null にならないので、`pct` の null 分岐を作らない。
            §7.1 が挙げた「null だと scrollLeft = NaN」という理由は D15 で消えている。 --}}
    @push('scripts')
        <script>
            function scheduleBoardSetInitialScroll(id, pct, trackPx) {
                var el = document.getElementById(id);
                if (! el) { return; }
                el.scrollLeft = trackPx * pct / 100;
            }
            scheduleBoardSetInitialScroll('schedule-board-scroller', {{ $axis['initialPct'] }}, {{ $axis['trackWidthPx'] }});
        </script>
    @endpush
@endif
