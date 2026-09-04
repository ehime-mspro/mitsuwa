{{--
    工程表のガント本体（設計書 §4.1 / §5）。

    ⚠ Ajax の保存後もこの partial を**サーバでレンダリングし直して**差し替える。
       位置(%) の計算を JS 側に持たせないため（Bug #41）。差し替えは outerHTML なので
       この partial の**最外殻は id="schedule-gantt" の 1 要素**でなければならない。

    ⚠ 行は grid ではなく flex にしている。素の 1fr トラックは最小値が auto で中身の
       min-content 幅がカードを押し広げるため（Bug #29）。track 側の min-width: 0 が要。
--}}
<div id="schedule-gantt">
@include('_partials._schedule_gantt_style')
@if($schedule['gantt'] === null)
    <div style="padding: 28px 16px; text-align: center; color: #9CA3AF; font-size: 13px;">
        工程が登録されていません。「＋ 工程を追加」から登録してください。
    </div>
@else
    @php($g = $schedule['gantt'])
    {{-- 状態チップの見た目（案B′。モック docs/mockups/housing/schedule-current-state.html で確定）。
         ⚠ CSS はここに置く。サービスは真偽値と語だけを返す。 --}}
    @php($chipStyle = [
        'upcoming' => 'background: #fff; color: #6B7280; border: 1px solid #D1D5DB;',
        'running'  => 'background: #111827; color: #fff; border: 1px solid #111827;',
        'done'     => 'background: #F3F4F6; color: #9CA3AF; border: 1px solid #F3F4F6;',
        'undated'  => 'background: #fff; color: #9CA3AF; border: 1px dashed #D1D5DB;',
    ])
    {{-- ⚠ **「済」チップのコントラスト比は 2.31:1（#9CA3AF / #F3F4F6）で、10px bold は
         「通常テキスト」扱いのため AA の 4.5:1 に届かない（`undated` も #9CA3AF / #fff で
         2.54:1 で同様に届かない）。承知のうえでモックの意匠を採用している
         （upcoming 4.83:1 / running 17.74:1 は AA を満たす）。次に直す人向けに実測値を残す:
         前景を `#6B7280` にすると 4.39:1、`#4B5563` にすると 6.87:1 で AA を満たす
         （周辺ビル調査で「点線は 2.43:1 で 3:1 に届かないのは承知のうえ」と明記した前例に倣う）。 --}}
    <div style="border: 1px solid #E5E7EB; border-radius: 8px; overflow: hidden; background: white;">
        <div class="gantt-scroll gantt-scroll--card" style="overflow-x: auto;">
            <div style="width: calc(var(--gantt-label-w) + {{ $g['trackWidthPx'] }}px);">

                {{-- 月ヘッダ --}}
                <div style="display: flex; height: 42px; background: #F9FAFB; border-bottom: 1px solid #E5E7EB;">
                    <div class="gantt-label gantt-label--head" style="flex: 0 0 var(--gantt-label-w); min-width: 0; overflow: hidden; border-right: 1px solid #E5E7EB; display: flex; align-items: center; padding: 0 12px; font-size: 11.5px; font-weight: 700; color: #6B7280;">工程</div>
                    <div style="flex: 1 1 auto; min-width: 0; position: relative; display: flex;">
                        {{-- ⚠ **形の正本はボード**（_schedule_board.blade.php の月セル）。同じ形にすること（設計書 §12.2 D14）。
                             年 span と月名の間に改行も空白も入れない。flex と改行まわりの実測は
                             ボード側のコメントに 1 箇所だけ置いてある（2 箇所に書くと食い違う）。
                             ⚠ `overflow: hidden` は D14（ボードと同じ形）のために置いてある。
                                ⚠ **カードでは現状 load-bearing ではない** —— months() は daysInMonth を
                                   クランプせず使うので、部分月があっても収縮後のセルは常に約 138〜153px で
                                   min-content の床（実測 40.6px）に届かない（2026-09-04 実測）。
                                   床に当たり得るのはクランプ済みの headers() を持つボードのほう（同実測 7.5px）。
                                ⚠ カードで部分月が来たときの本当の症状は「widthPct の合計が 100% を超えて
                                   月グリッドが棒とズレる」ほうで、これは overflow では直らない。 --}}
                        @foreach($g['months'] as $m)
                            <div style="width: {{ $m['widthPct'] }}%; border-right: 1px solid #E5E7EB; {{ $m['quarterStart'] ? 'border-left: 1px solid #D1D5DB;' : '' }} font-size: 11px; color: #6B7280; display: flex; align-items: center; justify-content: center; box-sizing: border-box; overflow: hidden;"><span class="gantt-year">{{ $m['year'] }}</span>{{ $m['label'] }}</div>
                        @endforeach
                        @if($g['todayPct'] !== null)
                            <div style="position: absolute; top: 2px; left: {{ $g['todayPct'] }}%; transform: translateX(-50%); background: #EF4444; color: white; font-size: 9.5px; font-weight: 700; padding: 1px 6px; border-radius: 999px; white-space: nowrap; z-index: 4;">今日 {{ $g['todayLabel'] }}</div>
                        @endif
                    </div>
                </div>

                {{-- 自動マイルストーン（既存の日付列から描く ◆。読み取り専用） --}}
                @if($g['milestones'] !== [])
                    <div class="schedule-gantt-track" style="display: flex; height: 34px; border-bottom: 1px solid #F3F4F6;">
                        <div class="gantt-label" style="flex: 0 0 var(--gantt-label-w); min-width: 0; overflow: hidden; border-right: 1px solid #E5E7EB; display: flex; align-items: center; padding: 0 12px; font-size: 12.5px; color: #6B7280;">節目</div>
                        <div style="flex: 1 1 auto; min-width: 0; position: relative;">
                            @if($g['todayPct'] !== null)
                                <div style="position: absolute; top: 0; bottom: 0; left: {{ $g['todayPct'] }}%; width: 0; border-left: 2px dashed #EF4444; z-index: 3;"></div>
                            @endif
                            @foreach($g['milestones'] as $ms)
                                <div style="position: absolute; top: 11px; left: {{ $ms['leftPct'] }}%; z-index: 2;">
                                    <span style="display: block; width: 11px; height: 11px; border-radius: 2px; transform: rotate(45deg); {{ $ms['reached'] ? 'background: #111827;' : 'background: white; border: 2px solid #111827;' }}"></span>
                                    <span style="position: absolute; left: 15px; top: -3px; font-size: 10.5px; font-weight: 600; color: #374151; white-space: nowrap;">{{ $ms['label'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- 工程の行（1 行 1 本。設計書 §5.2） --}}
                @foreach($g['rows'] as $row)
                    <div class="schedule-gantt-track" style="display: flex; height: 34px; border-bottom: 1px solid #F3F4F6; {{ $loop->odd ? 'background: #FCFCFD;' : '' }}">
                        {{-- ⚠ **min-width: 0; overflow: hidden; を落とさないこと。**
                             flex の min-width は既定が auto なので、チップを足した行が
                             ラベル欄の幅（`--gantt-label-w`。2026-09-03 に 262px 固定から
                             CSS 変数へ移行。既定値はカードで 262px）を超えて広がり、
                             **その行の棒だけ最大 31.1px（軸 275 日で約 12.6 日）右へずれる**
                             （モックで実測。月ヘッダも同じ `--gantt-label-w` を参照するので、
                             揃わなくなると月境界ともずれる）。Bug #29 と同型。 --}}
                        {{-- ⚠ **縞模様を末尾にインラインで足す。** `.gantt-label` は共有 CSS で
                             `background: #fff` を持つので、行の `$loop->odd` の縞模様は
                             ラベル欄には自動で届かない。`background: inherit` にしないこと——
                             行の div の背景は既定 transparent なので、ラベル欄が透けて
                             スクロール時に棒が透けて見える（sticky の意味が消える）。
                             インライン style はクラスより強いので、この宣言が
                             `.gantt-label` の `background: #fff` に勝つ。 --}}
                        <div class="gantt-label" style="flex: 0 0 var(--gantt-label-w); min-width: 0; overflow: hidden; border-right: 1px solid #E5E7EB; display: flex; align-items: center; gap: 6px; padding: 0 12px; font-size: 12.5px; color: #111827;{{ $loop->odd ? ' background: #FCFCFD;' : '' }}">
                            <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0;">{{ $row['name'] }}</span>
                            @if($g['tracksActuals'])
                                @if($row['delayDays'] > 0)
                                    <span style="margin-left: auto; font-size: 10.5px; color: #DC2626; font-weight: 700; white-space: nowrap;">+{{ $row['delayDays'] }}日</span>
                                @else
                                    <span style="margin-left: auto; font-size: 10.5px; color: #9CA3AF; white-space: nowrap;">{{ $row['periodText'] }}</span>
                                @endif
                            @else
                                {{-- ⚠ **`margin-left: auto` の span が 2 つあるのは意図どおり。**
                                     flex の auto マージンが 2 つあると余白がほぼ等分されるので、
                                     チップは右端ではなく**工程名と期間テキストの中間**に来る
                                     （モックの採寸で確定した意匠。実測で前後 51.1px / 52.4px）。
                                     空の `<span>` を「冗長」と判断して消さないこと——消すと
                                     チップが期間テキストの直左に張り付き、意匠が変わる。 --}}
                                <span style="margin-left: auto;"></span>
                                <span style="flex: 0 0 auto; font-size: 10px; font-weight: 700; line-height: 1.5; padding: 0 5px; border-radius: 3px; white-space: nowrap; {{ $chipStyle[$row['state']] }}">{{ $row['stateLabel'] }}</span>
                                <span style="margin-left: auto; font-size: 10.5px; color: #9CA3AF; white-space: nowrap;">{{ $row['periodText'] }}</span>
                            @endif
                        </div>
                        <div style="flex: 1 1 auto; min-width: 0; position: relative;">
                            @if($g['todayPct'] !== null)
                                <div style="position: absolute; top: 0; bottom: 0; left: {{ $g['todayPct'] }}%; width: 0; border-left: 2px dashed #EF4444; z-index: 3;"></div>
                            @endif
                            @if($row['kind'] === 'bar')
                                <div style="position: absolute; top: 11px; height: 12px; border-radius: 4px; box-sizing: border-box; left: {{ $row['leftPct'] }}%; width: {{ $row['widthPct'] }}%; background: {{ $row['color'] }};{{ $row['ring'] ? ' box-shadow: 0 0 0 1.5px #111827;' : '' }}"></div>
                            @elseif($row['kind'] === 'milestone')
                                <div style="position: absolute; top: 11px; left: {{ $row['leftPct'] }}%; z-index: 2;">
                                    <span style="display: block; width: 11px; height: 11px; border-radius: 2px; transform: rotate(45deg); background: {{ $row['color'] }};"></span>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach

            </div>
        </div>
    </div>

    {{-- 凡例 --}}
    <div style="display: flex; flex-wrap: wrap; gap: 14px; margin-top: 12px; font-size: 11.5px; color: #6B7280;">
        @foreach($schedule['categories'] as $c)
            <span><i style="display: inline-block; width: 22px; height: 9px; border-radius: 3px; margin-right: 5px; vertical-align: -1px; background: {{ $c['color'] }};"></i>{{ $c['label'] }}</span>
        @endforeach
        <span><span style="display: inline-block; width: 9px; height: 9px; background: #111827; transform: rotate(45deg); margin-right: 7px; vertical-align: -1px;"></span>節目（塗り＝到達済み / 白抜き＝これから）</span>
    </div>
    @if(! $g['tracksActuals'])
        <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 7px; font-size: 11.5px; color: #6B7280; align-items: center;">
            <span style="color: #9CA3AF; font-weight: 700;">状態</span>
            @foreach(['upcoming', 'running', 'done'] as $s)
                <span style="font-size: 10px; font-weight: 700; line-height: 1.5; padding: 0 5px; border-radius: 3px; {{ $chipStyle[$s] }}">{{ $g['stateLabels'][$s] }}</span>
            @endforeach
            <span><i style="display: inline-block; width: 22px; height: 9px; border-radius: 3px; margin-right: 5px; vertical-align: -1px; background: #059669; box-shadow: 0 0 0 1.5px #111827;"></i>進行中は棒にも輪郭</span>
        </div>
    @endif
@endif
</div>
