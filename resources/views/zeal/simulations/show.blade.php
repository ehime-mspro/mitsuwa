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
        // 実績反映ボタンを表示してよい権限か（executive / manager）
        $canSyncActuals = auth()->user() && in_array(auth()->user()->role, ['executive', 'manager'], true);
        $previewUrl     = route('zeal.simulations.sync-actuals.preview', $simulation);
        $applyUrl       = route('zeal.simulations.sync-actuals', $simulation);
    @endphp

    <div x-data="zealActualsSync({{ $simulation->id }}, '{{ $previewUrl }}', '{{ $applyUrl }}', '{{ csrf_token() }}')"
         class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-5">
        <div>
            <h1 class="text-lg font-bold text-gray-900">{{ $simulation->fiscal_year }}年度 経営試算表</h1>
            <div class="flex items-center gap-2 mt-1">
                <span class="text-sm text-gray-500">{{ $simulation->fiscal_year }}/06 〜 {{ $simulation->fiscal_year + 1 }}/05</span>
                @if($simulation->name)
                    <span class="text-sm text-gray-700">— {{ $simulation->name }}</span>
                @endif
            </div>
        </div>
        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <a href="{{ route('zeal.simulations.index', ['list' => 1]) }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; background: #fff;">年度一覧</a>
            @if($canSyncActuals)
                <button type="button" @click="openPreview()"
                        style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #1e40af; border: 1px solid #1e40af; border-radius: 6px; background: #fff; cursor: pointer;">
                    実績を反映
                </button>
                <a href="{{ route('zeal.simulations.edit', $simulation) }}"
                   style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 6px; text-decoration: none; background: #fff;">編集</a>
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
                            <p style="font-size: 12px; color: #6b7280; margin-bottom: 12px; line-height: 1.6;">
                                <span style="display:inline-block; padding:1px 6px; background:#dbeafe; color:#1e40af; border-radius:4px; font-weight:600;">更新</span> 実績で上書きされるセル
                                ／
                                <span style="display:inline-block; padding:1px 6px; background:#fef3c7; color:#92400e; border-radius:4px; font-weight:600;">維持</span> 手動上書き済みでスキップ
                                ／
                                <span style="display:inline-block; padding:1px 6px; background:#f3f4f6; color:#6b7280; border-radius:4px; font-weight:600;">同値</span> 変更なし
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
                                                <tr>
                                                    <td style="padding: 5px 8px; border-bottom: 1px solid #f3f4f6; color: #374151;" x-text="formatYm(row.ym)"></td>
                                                    <td style="padding: 5px 8px; border-bottom: 1px solid #f3f4f6; text-align: right; color: #374151;" x-text="row.current === null ? '—' : row.current.toLocaleString()"></td>
                                                    <td style="padding: 5px 8px; border-bottom: 1px solid #f3f4f6; text-align: right; font-weight: 600; color: #111827;" x-text="row.actual.toLocaleString()"></td>
                                                    <td style="padding: 5px 8px; border-bottom: 1px solid #f3f4f6; text-align: center;">
                                                        <template x-if="row.override">
                                                            <span style="display:inline-block; padding:1px 6px; background:#fef3c7; color:#92400e; border-radius:4px; font-weight:600; font-size:11px;">維持</span>
                                                        </template>
                                                        <template x-if="!row.override && row.current === row.actual">
                                                            <span style="display:inline-block; padding:1px 6px; background:#f3f4f6; color:#6b7280; border-radius:4px; font-weight:600; font-size:11px;">同値</span>
                                                        </template>
                                                        <template x-if="!row.override && row.current !== row.actual">
                                                            <span style="display:inline-block; padding:1px 6px; background:#dbeafe; color:#1e40af; border-radius:4px; font-weight:600; font-size:11px;">更新</span>
                                                        </template>
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
                        <button type="submit" :disabled="loading || submitting || !rows"
                                :style="(loading || submitting || !rows) ? 'opacity:0.5;cursor:not-allowed;' : ''"
                                style="padding: 6px 14px; font-size: 13px; font-weight: 600; color: #fff; border: 1px solid #1e40af; border-radius: 6px; background: #1e40af; cursor: pointer;">
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
    @include('zeal.simulations._table', ['editable' => false])

    <script>
        // Alpine x-data 関数（CLAUDE.md ルール: x-data 内に > を含むアロー関数禁止）
        function zealActualsSync(simulationId, previewUrl, applyUrl, csrfToken) {
            return {
                modalOpen: false,
                loading: false,
                submitting: false,
                errorMsg: '',
                rows: null,
                groups: [],
                previewUrl: previewUrl,
                applyUrl: applyUrl,

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
            };
        }
    </script>
@endsection
