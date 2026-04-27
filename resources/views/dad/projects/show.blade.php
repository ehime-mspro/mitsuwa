@extends('layouts.app')

@section('title', $project->project_name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>DAD（土木事業）</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('dad.projects.index') }}" class="text-emerald-600 hover:text-emerald-700">工事案件</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $project->project_code }}</span>
@endsection

@section('content')

<div x-data="projectShow()">

    {{-- 成功メッセージ --}}
    @if(session('success'))
        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-3"><p class="text-sm text-emerald-800">{{ session('success') }}</p></div>
    @endif

    {{-- ヘッダー --}}
    <div class="flex items-start justify-between gap-3 mb-4">
        <div>
            <div class="flex items-center gap-2 mb-2">
                <span style="display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; {{ $project->project_type->badgeStyle() }}">{{ $project->project_type->label() }}</span>
                <span style="display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; {{ $project->status->badgeStyle() }}">{{ $project->status->label() }}</span>
                <span class="text-xs text-gray-400" style="font-variant-numeric: tabular-nums;">{{ $project->project_code }}</span>
            </div>
            <h1 class="text-lg max-lg:text-base font-bold text-gray-900">{{ $project->project_name }}</h1>
            @if($project->client)
                <div class="text-sm text-gray-600 mt-1">発注者: {{ $project->client->name }}</div>
            @endif
        </div>
        <div class="flex gap-2">
            <a href="{{ route('dad.projects.edit', $project) }}" class="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-gray-300 text-gray-700 rounded-md text-sm hover:bg-gray-50">編集</a>
            <a href="{{ route('dad.projects.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-gray-300 text-gray-700 rounded-md text-sm hover:bg-gray-50">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>一覧へ
            </a>
        </div>
    </div>

    {{-- 金額サマリー4カード（3モードハイブリッド） --}}
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 20px;">
        {{-- 受注額 --}}
        <div class="stat-card">
            <div class="stat-label">受注額</div>
            <div class="stat-value">{{ $project->contract_amount !== null ? number_format($project->contract_amount) . '円' : '—' }}</div>
        </div>
        {{-- 原価合計（モード自動切替） --}}
        <div class="stat-card">
            <div class="stat-label" x-text="costLabel"></div>
            <div class="stat-value" x-text="costDisplay"></div>
        </div>
        {{-- 粗利額（モード自動切替） --}}
        <div class="stat-card">
            <div class="stat-label" x-text="grossProfitLabel"></div>
            <div class="stat-value" :style="{ color: grossProfitColor }" x-text="grossProfitDisplay"></div>
        </div>
        {{-- 粗利率（モード自動切替） --}}
        <div class="stat-card">
            <div class="stat-label" x-text="grossProfitRateLabel"></div>
            <div class="stat-value" :style="{ color: grossProfitColor }" x-text="grossProfitRateDisplay"></div>
        </div>
    </div>

    {{-- タブ --}}
    <div class="tabs">
        <button type="button" class="tab" :class="{ active: activeTab === 'basic' }" @click="activeTab = 'basic'">基本情報</button>
        <button type="button" class="tab" :class="{ active: activeTab === 'cost' }" @click="activeTab = 'cost'">原価管理</button>
        <button type="button" class="tab" :class="{ active: activeTab === 'assignment' }" @click="activeTab = 'assignment'">人員配置</button>
    </div>

    {{-- タブ: 基本情報 --}}
    <div x-show="activeTab === 'basic'" x-cloak class="bg-white border border-gray-200 rounded-b-lg" style="border-top: none; padding: 20px;">
        <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px 30px;">
            <div>
                <div class="info-label">案件番号</div>
                <div class="info-value" style="font-variant-numeric: tabular-nums;">{{ $project->project_code }}</div>
            </div>
            <div>
                <div class="info-label">担当者</div>
                <div class="info-value">{{ optional($project->staffUser)->name ?: '—' }}</div>
            </div>
            <div>
                <div class="info-label">見積日</div>
                <div class="info-value">{{ optional($project->estimate_date)->format('Y年n月j日') ?: '—' }}</div>
            </div>
            <div>
                <div class="info-label">受注日</div>
                <div class="info-value">{{ optional($project->order_date)->format('Y年n月j日') ?: '—' }}</div>
            </div>
            <div>
                <div class="info-label">着工日</div>
                <div class="info-value">{{ optional($project->start_date)->format('Y年n月j日') ?: '—' }}</div>
            </div>
            <div>
                <div class="info-label">完工日</div>
                <div class="info-value">{{ optional($project->completion_date)->format('Y年n月j日') ?: '—' }}</div>
            </div>
            <div>
                <div class="info-label">工期</div>
                <div class="info-value">
                    {{ optional($project->period_start)->format('Y/n/j') ?: '—' }}
                    〜
                    {{ optional($project->period_end)->format('Y/n/j') ?: '—' }}
                </div>
            </div>
            <div>
                <div class="info-label">入金日</div>
                <div class="info-value">{{ optional($project->payment_date)->format('Y年n月j日') ?: '—' }}</div>
            </div>
            <div>
                <div class="info-label">見積金額（税抜）</div>
                <div class="info-value" style="font-variant-numeric: tabular-nums;">{{ $project->estimate_amount !== null ? number_format($project->estimate_amount) . '円' : '—' }}</div>
            </div>
            <div>
                <div class="info-label">受注金額（税抜）</div>
                <div class="info-value" style="font-variant-numeric: tabular-nums;">{{ $project->contract_amount !== null ? number_format($project->contract_amount) . '円' : '—' }}</div>
            </div>
            <div style="grid-column: 1 / -1;">
                <div class="info-label">工事現場住所</div>
                <div class="info-value">{{ $project->site_address ?: '—' }}</div>
            </div>
            <div style="grid-column: 1 / -1;">
                <div class="info-label">備考</div>
                <div class="info-value" style="white-space: pre-wrap;">{{ $project->memo ?: '—' }}</div>
            </div>
        </div>
    </div>

    {{-- タブ: 原価管理 --}}
    <div x-show="activeTab === 'cost'" x-cloak class="bg-white border border-gray-200 rounded-b-lg" style="border-top: none; padding: 20px;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
            <div class="card-title" style="margin-bottom: 0;">原価明細</div>
            <a href="{{ route('dad.projects.edit', $project) }}" class="text-xs font-semibold text-emerald-700 px-3 py-1 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100">編集画面で追加・修正</a>
        </div>

        <table class="w-full" style="border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="padding: 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12px; font-weight: 700; color: #374151; text-align: left; width: 14%;">費用カテゴリ</th>
                    <th style="padding: 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12px; font-weight: 700; color: #374151; text-align: left;">内容</th>
                    <th style="padding: 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12px; font-weight: 700; color: #374151; text-align: right; width: 14%;">見積額</th>
                    <th style="padding: 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12px; font-weight: 700; color: #374151; text-align: right; width: 14%;">実績額</th>
                    <th style="padding: 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12px; font-weight: 700; color: #374151; text-align: right; width: 14%;">差額</th>
                    <th style="padding: 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12px; font-weight: 700; color: #374151; text-align: left; width: 18%;">協力業者</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(row, idx) in costRows" :key="idx">
                    <tr>
                        <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px;" x-text="row.cost_category_label"></td>
                        <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px;" x-text="row.description || '—'"></td>
                        <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px; text-align: right; font-variant-numeric: tabular-nums;" x-text="formatYen(row.estimateAmount)"></td>
                        <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px; text-align: right; font-weight: 600; font-variant-numeric: tabular-nums;" x-text="rowActualDisplay(row) || '—'"></td>
                        <td :style="`padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px; text-align: right; color: ${rowDiffColor(row)}; font-weight: 600; font-variant-numeric: tabular-nums;`" x-text="rowDiffDisplay(row) || '—'"></td>
                        <td :style="`padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px; color: ${row.subcontractor_name ? '#374151' : '#9ca3af'};`" x-text="row.subcontractor_name || '—'"></td>
                    </tr>
                </template>
                <tr x-show="costRows.length === 0">
                    <td colspan="6" style="padding: 24px; text-align: center; font-size: 13px; color: #9ca3af;">原価明細がまだ登録されていません。編集画面から追加してください。</td>
                </tr>
            </tbody>
            <tfoot x-show="costRows.length > 0">
                <tr style="background: #f9fafb;">
                    <td colspan="2" style="padding: 12px 10px; border-top: 2px solid #e5e7eb; font-size: 13px; font-weight: 700; color: #374151;">合計</td>
                    <td style="padding: 12px 10px; border-top: 2px solid #e5e7eb; font-size: 13px; font-weight: 700; text-align: right; font-variant-numeric: tabular-nums;" x-text="formatYen(estimateTotal)"></td>
                    <td style="padding: 12px 10px; border-top: 2px solid #e5e7eb; font-size: 13px; font-weight: 700; text-align: right; font-variant-numeric: tabular-nums;" x-text="actualTotal > 0 ? formatYen(actualTotal) : '—'"></td>
                    <td :style="`padding: 12px 10px; border-top: 2px solid #e5e7eb; font-size: 13px; font-weight: 700; text-align: right; color: ${diffTotalColor}; font-variant-numeric: tabular-nums;`" x-text="diffTotalDisplay"></td>
                    <td style="padding: 12px 10px; border-top: 2px solid #e5e7eb;"></td>
                </tr>
            </tfoot>
        </table>

        {{-- 凡例 --}}
        <div style="margin-top: 12px; padding: 10px 14px; background: #f9fafb; border-radius: 6px; font-size: 11px; color: #6b7280;">
            <strong style="color: #374151;">差額の見方:</strong> 実績 − 見積。<span style="color: #dc2626; font-weight: 600;">赤</span>は超過、<span style="color: #047857; font-weight: 600;">緑</span>は以内・同額、<span style="color: #9ca3af; font-weight: 600;">灰</span>は実績未入力。
        </div>
    </div>

    {{-- タブ: 人員配置 --}}
    <div x-show="activeTab === 'assignment'" x-cloak class="bg-white border border-gray-200 rounded-b-lg" style="border-top: none; padding: 20px;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
            <div class="card-title" style="margin-bottom: 0;">人員配置</div>
            <a href="{{ route('dad.projects.edit', $project) }}" class="text-xs font-semibold text-emerald-700 px-3 py-1 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100">編集画面で追加・修正</a>
        </div>

        @if($project->assignments->isEmpty())
            <div style="padding: 24px; text-align: center; font-size: 13px; color: #9ca3af; background: #f9fafb; border-radius: 6px;">
                人員配置がまだ登録されていません。編集画面から追加してください。
            </div>
        @else
            <table class="w-full" style="border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="padding: 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12px; font-weight: 700; color: #374151; text-align: left;">社員番号</th>
                        <th style="padding: 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12px; font-weight: 700; color: #374151; text-align: left;">氏名</th>
                        <th style="padding: 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12px; font-weight: 700; color: #374151; text-align: left;">役割</th>
                        <th style="padding: 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12px; font-weight: 700; color: #374151; text-align: left;">配置期間</th>
                        <th style="padding: 10px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 12px; font-weight: 700; color: #374151; text-align: left;">備考</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($project->assignments as $a)
                        <tr>
                            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px; font-variant-numeric: tabular-nums;">{{ optional($a->employee)->employee_code }}</td>
                            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px; font-weight: 600;">{{ optional($a->employee)->name }}</td>
                            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px;">{{ $a->role ?: '—' }}</td>
                            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px;">
                                {{ optional($a->start_date)->format('Y/n/j') ?: '—' }}
                                〜
                                {{ optional($a->end_date)->format('Y/n/j') ?: '配置中' }}
                            </td>
                            <td style="padding: 10px; border-bottom: 1px solid #e5e7eb; font-size: 13px; color: #6b7280;">{{ $a->notes ?: '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

</div>

<style>
.stat-card { background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 16px; }
.stat-label { font-size: 11px; color: #6b7280; margin-bottom: 6px; font-weight: 600; }
.stat-value { font-size: 20px; font-weight: 700; color: #111827; font-variant-numeric: tabular-nums; }
.tabs { display: flex; gap: 0; background: white; border: 1px solid #e5e7eb; border-bottom: none; border-radius: 8px 8px 0 0; padding: 0 14px; }
.tab { padding: 12px 20px; font-size: 13px; font-weight: 600; color: #6b7280; cursor: pointer; border: none; background: none; border-bottom: 3px solid transparent; transition: all 0.15s; }
.tab:hover { color: #047857; }
.tab.active { color: #047857; border-bottom-color: #10b981; }
.card-title { font-size: 14px; font-weight: 700; color: #111827; margin-bottom: 14px; }
.info-label { font-size: 11px; color: #6b7280; font-weight: 600; margin-bottom: 4px; }
.info-value { font-size: 14px; color: #111827; font-weight: 500; }
</style>

<script>
function projectShow() {
    return {
        activeTab: 'basic',
        costRows: @json($costRowsForJs),
        contractAmount: {{ $project->contract_amount ?? 'null' }},

        // ===== 共通ヘルパー =====
        formatYen: function (n) {
            if (n === null || n === undefined || n === '') return '';
            const num = Number(n);
            if (isNaN(num)) return '';
            return num.toLocaleString('ja-JP') + '円';
        },
        hasActual: function (row) {
            const a = row.actualAmount;
            return a !== null && a !== undefined && a !== '';
        },
        rowEffective: function (row) {
            if (this.hasActual(row)) return Number(row.actualAmount) || 0;
            return Number(row.estimateAmount) || 0;
        },
        rowDiff: function (row) {
            if (!this.hasActual(row)) return null;
            return Number(row.actualAmount) - (Number(row.estimateAmount) || 0);
        },
        rowDiffDisplay: function (row) {
            const d = this.rowDiff(row);
            if (d === null) return '';
            if (d === 0) return '±0円';
            return (d > 0 ? '+' : '') + Number(d).toLocaleString('ja-JP') + '円';
        },
        rowDiffColor: function (row) {
            const d = this.rowDiff(row);
            if (d === null) return '#9ca3af';
            if (d > 0) return '#dc2626';
            return '#047857';
        },
        rowActualDisplay: function (row) {
            if (!this.hasActual(row)) return '';
            return this.formatYen(row.actualAmount);
        },

        // ===== 3 モード判定 + 集計 getter =====
        get costMode() {
            const rows = this.costRows || [];
            if (rows.length === 0) return 'empty';
            const self = this;
            const filled = rows.filter(function (r) { return self.hasActual(r); }).length;
            if (filled === 0) return 'estimate';
            if (filled === rows.length) return 'actual';
            return 'hybrid';
        },
        get estimateTotal() {
            return (this.costRows || []).reduce(function (sum, r) {
                return sum + (Number(r.estimateAmount) || 0);
            }, 0);
        },
        get actualTotal() {
            const self = this;
            return (this.costRows || []).reduce(function (sum, r) {
                if (!self.hasActual(r)) return sum;
                return sum + (Number(r.actualAmount) || 0);
            }, 0);
        },
        get diffTotal() {
            const self = this;
            return (this.costRows || []).reduce(function (sum, r) {
                if (!self.hasActual(r)) return sum;
                return sum + (Number(r.actualAmount) - (Number(r.estimateAmount) || 0));
            }, 0);
        },
        get diffTotalDisplay() {
            const d = this.diffTotal;
            if (this.actualTotal === 0) return '—';
            if (d === 0) return '±0円';
            return (d > 0 ? '+' : '') + Number(d).toLocaleString('ja-JP') + '円';
        },
        get diffTotalColor() {
            if (this.actualTotal === 0) return '#9ca3af';
            const d = this.diffTotal;
            if (d > 0) return '#dc2626';
            return '#047857';
        },
        get costTotal() {
            const m = this.costMode;
            if (m === 'empty') return null;
            if (m === 'estimate') return this.estimateTotal;
            if (m === 'actual') return this.actualTotal;
            const self = this;
            return (this.costRows || []).reduce(function (sum, r) {
                return sum + self.rowEffective(r);
            }, 0);
        },
        get costLabel() {
            const m = this.costMode;
            if (m === 'empty') return '原価合計';
            if (m === 'estimate') return '原価合計（見積）';
            if (m === 'hybrid') return '原価合計（見込）';
            return '原価合計（実績）';
        },
        get costDisplay() {
            if (this.costMode === 'empty') return '—';
            return this.formatYen(this.costTotal);
        },
        get grossProfit() {
            if (this.costMode === 'empty') return null;
            const contract = Number(this.contractAmount) || 0;
            return contract - this.costTotal;
        },
        get grossProfitLabel() {
            const m = this.costMode;
            if (m === 'empty') return '粗利額';
            if (m === 'estimate') return '粗利額（見積）';
            if (m === 'hybrid') return '粗利額（見込）';
            return '粗利額（実績）';
        },
        get grossProfitDisplay() {
            if (this.costMode === 'empty') return '—';
            return this.formatYen(this.grossProfit);
        },
        get grossProfitColor() {
            if (this.costMode === 'empty') return '#111827';
            const gp = this.grossProfit;
            if (gp >= 0) return '#047857';
            return '#dc2626';
        },
        get grossProfitRateValue() {
            if (this.costMode === 'empty') return null;
            const contract = Number(this.contractAmount) || 0;
            if (contract === 0) return null;
            return (this.grossProfit / contract) * 100;
        },
        get grossProfitRateLabel() {
            const m = this.costMode;
            if (m === 'empty') return '粗利率';
            if (m === 'estimate') return '粗利率（見積）';
            if (m === 'hybrid') return '粗利率（見込）';
            return '粗利率（実績）';
        },
        get grossProfitRateDisplay() {
            const v = this.grossProfitRateValue;
            if (v === null) return '—';
            return v.toFixed(1) + '%';
        }
    };
}
</script>

@endsection
