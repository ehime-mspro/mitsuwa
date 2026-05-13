@extends('layouts.app')

@section('title', $simulation->fiscal_year . '年度 経営試算表 — ZEAL')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>ZEAL</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.simulations.index', ['list' => 1]) }}" class="text-gray-500 hover:text-emerald-600">経営試算表</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $simulation->fiscal_year }}年度</span>
@endsection

@section('content')
    @php
        // 権限判定（実績反映は executive / manager のみ）
        $canSyncActuals = auth()->user() && auth()->user()->role->isManagerOrAbove();
        $previewUrl     = route('zeal.simulations.sync-actuals.preview', $simulation);
        $applyUrl       = route('zeal.simulations.sync-actuals', $simulation);

        // 現在の表示モード（actual/budget/compare）
        $mode = $mode ?? 'actual';
        $isBudgetMode = $mode === 'budget';
        $isCompareMode = $mode === 'compare';

        // モード切替リンク（同一画面 + ?mode=xxx）
        $actualUrl  = route('zeal.simulations.show', ['simulation' => $simulation, 'mode' => 'actual']);
        $budgetUrl  = route('zeal.simulations.show', ['simulation' => $simulation, 'mode' => 'budget']);
        $compareUrl = route('zeal.simulations.show', ['simulation' => $simulation, 'mode' => 'compare']);

        // 基準日表示
        $today = now()->format('Y-m-d');
        $currentYm = \App\Support\ZealFiscalYear::currentMonthYm();
    @endphp

    <div x-data="zealActualsSync({{ $simulation->id }}, '{{ $previewUrl }}', '{{ $applyUrl }}', '{{ csrf_token() }}', '{{ $currentYm }}')"
         class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-5">
        <div>
            <h1 class="text-lg font-bold text-gray-900">{{ $simulation->fiscal_year }}年度 経営試算表</h1>
            <div class="flex items-center gap-2 mt-1 flex-wrap">
                <span class="text-sm text-gray-500">{{ $simulation->fiscal_year }}/06 〜 {{ $simulation->fiscal_year + 1 }}/05</span>
                @if($simulation->name)
                    <span class="text-sm text-gray-700">— {{ $simulation->name }}</span>
                @endif
                <span class="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-600">基準日: {{ $today }}</span>
            </div>
        </div>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            {{-- 表示モード切替 --}}
            <div style="display: flex; gap: 4px; margin-right: 6px; padding-right: 8px; border-right: 1px solid #d1d5db;">
                <span style="font-size: 11px; color: #6b7280; align-self: center; margin-right: 2px;">表示:</span>
                <a href="{{ $actualUrl }}"
                   style="padding: 5px 12px; font-size: 12px; font-weight: 600; text-decoration: none; border-radius: 5px; border: 1px solid #d1d5db;
                          {{ $mode === 'actual' ? 'background: #047857; color: white; border-color: #047857;' : 'background: white; color: #6b7280;' }}">実績</a>
                <a href="{{ $budgetUrl }}"
                   style="padding: 5px 12px; font-size: 12px; font-weight: 600; text-decoration: none; border-radius: 5px; border: 1px solid #d1d5db;
                          {{ $isBudgetMode ? 'background: #4f46e5; color: white; border-color: #4f46e5;' : 'background: white; color: #6b7280;' }}">予算</a>
                <a href="{{ $compareUrl }}"
                   style="padding: 5px 12px; font-size: 12px; font-weight: 600; text-decoration: none; border-radius: 5px; border: 1px solid #d1d5db;
                          {{ $isCompareMode ? 'background: #c2410c; color: white; border-color: #c2410c;' : 'background: white; color: #6b7280;' }}">比較</a>
            </div>
            <a href="{{ route('zeal.simulations.index', ['list' => 1]) }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; background: #fff;">年度一覧</a>
            @if($canSyncActuals)
                <button type="button" @click="openPreview()"
                        style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #1e40af; border: 1px solid #1e40af; border-radius: 6px; background: #fff; cursor: pointer;">
                    実績を反映
                </button>
                <a href="{{ route('zeal.simulations.edit', ['simulation' => $simulation, 'mode' => $isBudgetMode ? 'budget' : 'actual']) }}"
                   style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: {{ $isBudgetMode ? '#4f46e5' : '#059669' }}; border: 1px solid {{ $isBudgetMode ? '#4f46e5' : '#059669' }}; border-radius: 6px; text-decoration: none; background: #fff;">
                    {{ $isBudgetMode ? '予算を編集' : '実績を編集' }}
                </a>
            @endif
        </div>

        {{-- 実績反映 確認モーダル --}}
        <div x-show="modalOpen" x-cloak
             style="position: fixed; inset: 0; z-index: 50; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.5); padding: 16px;"
             @click.self="closeModal()">
            <div style="background: #fff; border-radius: 10px; max-width: 920px; width: 100%; max-height: 86vh; overflow: hidden; display: flex; flex-direction: column;">
                <div style="padding: 14px 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
                    <h2 style="font-size: 15px; font-weight: 700; color: #111827;">実績反映プレビュー</h2>
                    <button type="button" @click="closeModal()" style="background: none; border: none; font-size: 18px; color: #6b7280; cursor: pointer;">×</button>
                </div>

                <div style="padding: 16px 20px; overflow-y: auto;">
                    {{-- 読み込み中 --}}
                    <div x-show="loading" style="text-align: center; padding: 24px 0; color: #6b7280; font-size: 13px;">
                        実績を計算しています…
                    </div>

                    {{-- エラー --}}
                    <div x-show="errorMsg" x-text="errorMsg" x-cloak
                         style="background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; border-radius: 8px; padding: 10px 14px; font-size: 13px; margin-bottom: 12px;"></div>

                    {{-- 内容 --}}
                    <template x-if="!loading && rows">
                        <div>
                            <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 10px 14px; margin-bottom: 12px; font-size: 12px; color: #075985; line-height: 1.6;">
                                <strong>反映対象</strong>:
                                <span x-show="!includeCurrent">完了月のみ（<span x-text="pastMonthsCount"></span> ヶ月分）</span>
                                <span x-show="includeCurrent" x-cloak>完了月 <span x-text="pastMonthsCount"></span> ヶ月 + 当月（<span x-text="currentYm"></span>）</span>
                                <div style="font-size: 11px; margin-top: 4px;">
                                    現在月（<span x-text="currentYm"></span>）は月末まで未確定のため<span x-show="!includeCurrent">除外</span><span x-show="includeCurrent" x-cloak>含めると暫定値になります</span>
                                </div>
                            </div>

                            <label style="display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; margin-bottom: 12px;
                                          background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; cursor: pointer;">
                                <input type="checkbox" x-model="includeCurrent" style="margin-top: 2px;">
                                <div style="font-size: 12px; color: #92400e; line-height: 1.6;">
                                    <strong>当月（<span x-text="currentYm"></span>）も反映する</strong>
                                    <span style="color: #dc2626; font-weight: 600;">— 暫定値、月末まで変動あり</span>
                                </div>
                            </label>

                            <p style="font-size: 12px; color: #6b7280; margin-bottom: 12px; line-height: 1.6;">
                                <span style="display:inline-block; padding:1px 6px; background:#dbeafe; color:#1e40af; border-radius:4px; font-weight:600;">更新</span> 実績で上書きされるセル
                                ／
                                <span style="display:inline-block; padding:1px 6px; background:#fef3c7; color:#92400e; border-radius:4px; font-weight:600;">維持</span> 手動上書き済みでスキップ
                                ／
                                <span style="display:inline-block; padding:1px 6px; background:#f3f4f6; color:#6b7280; border-radius:4px; font-weight:600;">同値</span> 変更なし
                                ／
                                <span style="display:inline-block; padding:1px 6px; background:#f3f4f6; color:#9ca3af; border-radius:4px; font-weight:600;">除外</span> 未確定月（反映対象外）
                            </p>

                            <template x-for="(group, gIndex) in groups" :key="gIndex">
                                <div style="margin-bottom: 18px;">
                                    <h3 style="font-size: 13px; font-weight: 700; color: #111827; margin-bottom: 6px;" x-text="group.label"></h3>
                                    <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                                        <thead>
                                            <tr style="background: #f9fafb;">
                                                <th style="text-align: left; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; color: #6b7280; width: 90px;">月</th>
                                                <th style="text-align: right; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; color: #6b7280;">現在値</th>
                                                <th style="text-align: right; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; color: #6b7280;">実績値</th>
                                                <th style="text-align: center; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; color: #6b7280; width: 80px;">状態</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <template x-for="row in group.data" :key="row.ym">
                                                <tr :style="isRowExcluded(row) ? 'opacity: 0.45;' : ''">
                                                    <td style="padding: 5px 8px; border-bottom: 1px solid #f3f4f6; color: #374151;" x-text="formatYm(row.ym)"></td>
                                                    <td style="padding: 5px 8px; border-bottom: 1px solid #f3f4f6; text-align: right; color: #374151;" x-text="row.current === null ? '—' : row.current.toLocaleString()"></td>
                                                    <td style="padding: 5px 8px; border-bottom: 1px solid #f3f4f6; text-align: right; font-weight: 600; color: #111827;" x-text="row.actual.toLocaleString()"></td>
                                                    <td style="padding: 5px 8px; border-bottom: 1px solid #f3f4f6; text-align: center;">
                                                        <span x-show="isRowExcluded(row)"
                                                              style="display:inline-block; padding:1px 6px; background:#f3f4f6; color:#9ca3af; border-radius:4px; font-weight:600; font-size:11px;">除外</span>
                                                        <span x-show="!isRowExcluded(row) && row.override"
                                                              style="display:inline-block; padding:1px 6px; background:#fef3c7; color:#92400e; border-radius:4px; font-weight:600; font-size:11px;">維持</span>
                                                        <span x-show="!isRowExcluded(row) && !row.override && row.current === row.actual"
                                                              style="display:inline-block; padding:1px 6px; background:#f3f4f6; color:#6b7280; border-radius:4px; font-weight:600; font-size:11px;">同値</span>
                                                        <span x-show="!isRowExcluded(row) && !row.override && row.current !== row.actual"
                                                              style="display:inline-block; padding:1px 6px; background:#dbeafe; color:#1e40af; border-radius:4px; font-weight:600; font-size:11px;">更新</span>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                <div style="padding: 12px 20px; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; gap: 8px; background: #fafafa;">
                    <button type="button" @click="closeModal()"
                            style="padding: 6px 14px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; cursor: pointer;">
                        キャンセル
                    </button>
                    <form :action="applyUrl" method="POST" @submit="submitting = true">
                        @csrf
                        {{-- 当月を含める場合は include_current_month=1 をサーバーに渡す --}}
                        <input type="hidden" name="include_current_month" :value="includeCurrent ? '1' : '0'">
                        {{-- :style 単一バインディング（CLAUDE.md ルール: style= と :style= の同一要素併用禁止） --}}
                        <button type="submit" :disabled="loading || submitting || !rows"
                                :style="'padding: 6px 14px; font-size: 13px; font-weight: 600; color: #fff; border: 1px solid #1e40af; border-radius: 6px; background: #1e40af; ' + ((loading || submitting || !rows) ? 'opacity:0.5;cursor:not-allowed;' : 'cursor:pointer;')">
                            <span x-show="!submitting">実績を反映する</span>
                            <span x-show="submitting" x-cloak>処理中…</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @if($simulation->notes)
        <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 13px; color: #92400e; white-space: pre-wrap;">{{ $simulation->notes }}</div>
    @endif

    {{-- 試算表テーブル（横スクロール） --}}
    @include('zeal.simulations._table', ['editable' => false, 'mode' => $mode])

    {{-- 通期サマリー: 予算実績比較セクション（actual/compare モードで表示） --}}
    @if(!$isBudgetMode && !empty($comparisonSummary))
        @include('zeal.simulations._comparison_summary', [
            'comparisonSummary' => $comparisonSummary,
            'simulation'        => $simulation,
            'isCompareMode'     => $isCompareMode,
        ])
    @endif

    <script>
        // Alpine x-data 関数（CLAUDE.md ルール: x-data 内に > を含むアロー関数禁止）
        function zealActualsSync(simulationId, previewUrl, applyUrl, csrfToken, currentYm) {
            return {
                modalOpen: false,
                loading: false,
                submitting: false,
                errorMsg: '',
                rows: null,
                groups: [],
                previewUrl: previewUrl,
                applyUrl: applyUrl,
                currentYm: currentYm,         // 'YYYY-MM' 文字列
                includeCurrent: false,        // 「当月も反映する」チェック
                pastMonthsCount: 0,           // 過去確定月の数

                openPreview: function () {
                    // CLAUDE.md ルール: <script> 内でアロー関数禁止のため self キャプチャ
                    var self = this;
                    self.modalOpen = true;
                    self.loading   = true;
                    self.errorMsg  = '';
                    self.rows      = null;
                    self.groups    = [];

                    fetch(previewUrl, {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        credentials: 'same-origin',
                    })
                        .then(function (res) {
                            if (!res.ok) {
                                throw new Error('プレビューの取得に失敗しました（HTTP ' + res.status + '）');
                            }
                            return res.json();
                        })
                        .then(function (data) {
                            self.rows   = data.rows;
                            self.pastMonthsCount = (data.past_months || []).length;
                            if (data.current_month_ym) self.currentYm = data.current_month_ym;
                            self.groups = [
                                { label: data.rows.revenue_label || '売上', data: data.rows.revenue || [] },
                                { label: data.rows.member_label || '会員数', data: data.rows.member || [] },
                            ];
                            self.loading = false;
                        })
                        .catch(function (err) {
                            self.errorMsg = err.message || '予期しないエラーが発生しました';
                            self.loading  = false;
                        });
                },

                closeModal: function () {
                    if (this.submitting) return;
                    this.modalOpen = false;
                },

                formatYm: function (ym) {
                    if (!ym) return '';
                    var parts = ym.split('-');
                    if (parts.length !== 2) return ym;
                    return parts[0] + '年' + parseInt(parts[1], 10) + '月';
                },

                // 行が反映対象外（除外）かどうか判定
                // - period='future': 常に除外
                // - period='current' && !includeCurrent: 除外
                isRowExcluded: function (row) {
                    if (!row.period) return false;
                    if (row.period === 'future') return true;
                    if (row.period === 'current' && !this.includeCurrent) return true;
                    return false;
                },
            };
        }
    </script>
@endsection
