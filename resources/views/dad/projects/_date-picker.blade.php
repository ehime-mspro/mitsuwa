{{-- DAD 工事案件 共通 datePicker パーシャル（静的フィールド用） --}}
{{-- 期待される変数:
     - $name : input の name 属性（例: "estimate_date"）
     - $value: 初期値（YYYY-MM-DD 形式 または 空文字）
--}}
<div class="date-picker-wrap" x-data="datePicker('{{ $value }}')" @click.outside="open = false">
    <button type="button" class="date-input-trigger" @click="open = !open">
        <span x-show="selected" x-text="selectedLabel"></span>
        <span x-show="!selected" class="placeholder">日付を選択</span>
        <span class="cal-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        </span>
    </button>
    <input type="hidden" name="{{ $name }}" :value="isoValue">

    <div class="picker-popup" x-show="open" x-transition style="display:none;">
        <div class="cal-info">
            <span class="pill">選択中</span>
            <span class="sel-date" x-show="selected"><b x-text="selectedLong"></b></span>
            <span class="sel-date" x-show="!selected" style="color:#9CA3AF;">未選択</span>
        </div>

        <div class="cal-nav">
            <button type="button" class="arrow-btn" :class="{ hidden: mode !== 'calendar' }" @click="prevMonth">‹</button>
            <div class="month-btns">
                <button type="button" class="ym-btn" :class="{ active: mode === 'year' }" @click="toggleYearMode">
                    <span x-text="viewYear + '年'"></span>
                    <span class="chev">▾</span>
                </button>
                <button type="button" class="ym-btn" :class="{ active: mode === 'month' }" @click="toggleMonthMode">
                    <span x-text="(viewMonth + 1) + '月'"></span>
                    <span class="chev">▾</span>
                </button>
            </div>
            <button type="button" class="arrow-btn" :class="{ hidden: mode !== 'calendar' }" @click="nextMonth">›</button>
        </div>

        <div class="cal-grid" x-show="mode === 'calendar'">
            <div class="cal-dow sun">日</div>
            <div class="cal-dow">月</div>
            <div class="cal-dow">火</div>
            <div class="cal-dow">水</div>
            <div class="cal-dow">木</div>
            <div class="cal-dow">金</div>
            <div class="cal-dow sat">土</div>
            <template x-for="(cell, idx) in calendarCells" :key="idx">
                <button type="button" class="cal-cell"
                        :class="{ muted: cell.muted, sun: cell.dow === 0, sat: cell.dow === 6, today: isToday(cell), selected: isSelected(cell) }"
                        @click="pick(cell)"
                        x-text="cell.day"></button>
            </template>
        </div>

        <div class="ym-picker" x-show="mode === 'year'" style="display:none;">
            <template x-for="year in yearRange" :key="year">
                <button type="button"
                        :class="{ today: year === todayYear, selected: year === viewYear }"
                        @click="pickYear(year)"
                        x-text="year"></button>
            </template>
        </div>
        <div class="range-hint" x-show="mode === 'year'" x-text="yearRangeHint" style="display:none;"></div>

        <div class="ym-picker" x-show="mode === 'month'" style="display:none;">
            <template x-for="m in 12" :key="m">
                <button type="button"
                        :class="{ today: (m - 1) === todayMonth && viewYear === todayYear, selected: (m - 1) === viewMonth }"
                        @click="pickMonth(m - 1)"
                        x-text="m + '月'"></button>
            </template>
        </div>
        <div class="range-hint" x-show="mode === 'month'" x-text="viewYear + '年'" style="display:none;"></div>

        <div class="cal-foot">
            <button type="button" class="shortcut" @click="setToday">今日</button>
            <button type="button" class="shortcut" @click="setWeekAgo">1週間前</button>
            <button type="button" class="shortcut" @click="setMonthAgo">1ヶ月前</button>
        </div>
    </div>
</div>
