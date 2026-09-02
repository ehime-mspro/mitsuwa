{{--
    工程表のガント本体（設計書 §4.1 / §5）。

    ⚠ Ajax の保存後もこの partial を**サーバでレンダリングし直して**差し替える。
       位置(%) の計算を JS 側に持たせないため（Bug #41）。差し替えは outerHTML なので
       この partial の**最外殻は id="schedule-gantt" の 1 要素**でなければならない。

    ⚠ 行は grid ではなく flex にしている。素の 1fr トラックは最小値が auto で中身の
       min-content 幅がカードを押し広げるため（Bug #29）。track 側の min-width: 0 が要。
--}}
<div id="schedule-gantt">
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
    <div style="border: 1px solid #E5E7EB; border-radius: 8px; overflow: hidden; background: white;">
        <div style="overflow-x: auto;">
            <div style="min-width: 940px;">

                {{-- 月ヘッダ --}}
                <div style="display: flex; height: 42px; background: #F9FAFB; border-bottom: 1px solid #E5E7EB;">
                    <div style="flex: 0 0 262px; min-width: 0; overflow: hidden; border-right: 1px solid #E5E7EB; display: flex; align-items: center; padding: 0 12px; font-size: 11.5px; font-weight: 700; color: #6B7280;">工程</div>
                    <div style="flex: 1 1 auto; min-width: 0; position: relative; display: flex;">
                        @foreach($g['months'] as $m)
                            <div style="width: {{ $m['widthPct'] }}%; border-right: 1px solid #E5E7EB; {{ $m['quarterStart'] ? 'border-left: 1px solid #D1D5DB;' : '' }} font-size: 11px; color: #6B7280; display: flex; flex-direction: column; align-items: center; justify-content: center; line-height: 1.35; box-sizing: border-box;">
                                <span style="font-size: 9.5px; color: #9CA3AF;">{{ $m['year'] }}</span>
                                <span>{{ $m['label'] }}</span>
                            </div>
                        @endforeach
                        @if($g['todayPct'] !== null)
                            <div style="position: absolute; top: 2px; left: {{ $g['todayPct'] }}%; transform: translateX(-50%); background: #EF4444; color: white; font-size: 9.5px; font-weight: 700; padding: 1px 6px; border-radius: 999px; white-space: nowrap; z-index: 4;">今日 {{ $g['todayLabel'] }}</div>
                        @endif
                    </div>
                </div>

                {{-- 自動マイルストーン（既存の日付列から描く ◆。読み取り専用） --}}
                @if($g['milestones'] !== [])
                    <div class="schedule-gantt-track" style="display: flex; height: 34px; border-bottom: 1px solid #F3F4F6;">
                        <div style="flex: 0 0 262px; min-width: 0; overflow: hidden; border-right: 1px solid #E5E7EB; display: flex; align-items: center; padding: 0 12px; font-size: 12.5px; color: #6B7280;">節目</div>
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
                             flex の min-width は既定が auto なので、チップを足した行が 262px を
                             超えて広がり、**その行の棒だけ最大 31.1px（軸 275 日で約 12.6 日）
                             右へずれる**（モックで実測。月ヘッダは 262px のままなので月境界とも
                             合わなくなる）。Bug #29 と同型。 --}}
                        <div style="flex: 0 0 262px; min-width: 0; overflow: hidden; border-right: 1px solid #E5E7EB; display: flex; align-items: center; gap: 6px; padding: 0 12px; font-size: 12.5px; color: #111827;">
                            <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0;">{{ $row['name'] }}</span>
                            @if($g['tracksActuals'])
                                @if($row['delayDays'] > 0)
                                    <span style="margin-left: auto; font-size: 10.5px; color: #DC2626; font-weight: 700; white-space: nowrap;">+{{ $row['delayDays'] }}日</span>
                                @else
                                    <span style="margin-left: auto; font-size: 10.5px; color: #9CA3AF; white-space: nowrap;">{{ $row['periodText'] }}</span>
                                @endif
                            @else
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
