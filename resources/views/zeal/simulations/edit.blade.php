@extends('layouts.app')

@section('title', $simulation->fiscal_year . '年度 経営試算表 編集 — ZEAL')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>ZEAL</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.simulations.index', ['list' => 1]) }}" class="text-gray-500 hover:text-emerald-600">経営試算表</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.simulations.show', $simulation) }}" class="text-gray-500 hover:text-emerald-600">{{ $simulation->fiscal_year }}年度</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">編集</span>
@endsection

@section('content')
    @php
        $isBudgetMode = ($mode ?? 'actual') === 'budget';
        $actualUrl = route('zeal.simulations.edit', ['simulation' => $simulation, 'mode' => 'actual']);
        $budgetUrl = route('zeal.simulations.edit', ['simulation' => $simulation, 'mode' => 'budget']);
    @endphp

    <div class="flex items-center justify-between mb-4">
        <h1 class="text-lg font-bold text-gray-900">{{ $simulation->fiscal_year }}年度 経営試算表 編集</h1>
    </div>

    {{-- 編集モードタブ切替 --}}
    <div style="display: flex; gap: 0; margin-bottom: 14px; border-bottom: 2px solid #e5e7eb;">
        <a href="{{ $actualUrl }}"
           style="padding: 8px 20px; font-size: 13px; font-weight: 600; text-decoration: none;
                  {{ !$isBudgetMode ? 'color: #047857; border-bottom: 3px solid #047857; background: #ecfdf5;' : 'color: #6b7280; background: white;' }}
                  margin-bottom: -2px; border-top-left-radius: 4px; border-top-right-radius: 4px;">
            📊 実績編集
            @if(!$isBudgetMode)
                <span style="display: inline-block; padding: 1px 6px; margin-left: 6px; font-size: 10px; font-weight: 600; background: #d1fae5; color: #065f46; border-radius: 3px;">編集中</span>
            @endif
        </a>
        <a href="{{ $budgetUrl }}"
           style="padding: 8px 20px; font-size: 13px; font-weight: 600; text-decoration: none;
                  {{ $isBudgetMode ? 'color: #2563eb; border-bottom: 3px solid #2563eb; background: #eff6ff;' : 'color: #6b7280; background: white;' }}
                  margin-bottom: -2px; border-top-left-radius: 4px; border-top-right-radius: 4px;">
            💰 予算編集
            @if($isBudgetMode)
                <span style="display: inline-block; padding: 1px 6px; margin-left: 6px; font-size: 10px; font-weight: 600; background: #dbeafe; color: #1d4ed8; border-radius: 3px;">編集中</span>
            @endif
        </a>
        <div style="margin-left: auto; align-self: center; padding-right: 8px; font-size: 11px; color: #6b7280;">
            @if($isBudgetMode)
                予算編集: <strong style="color: #1d4ed8;">budget_amount</strong> 列にバインド。実績と独立管理
            @else
                実績編集: <strong style="color: #065f46;">amount</strong> 列にバインド。実績反映と同じデータ
            @endif
        </div>
    </div>

    <form action="{{ route('zeal.simulations.update', $simulation) }}" method="POST">
        @csrf
        @method('PUT')
        <input type="hidden" name="mode" value="{{ $isBudgetMode ? 'budget' : 'actual' }}">

        {{-- 名称・備考（実績モード時のみ表示。予算モードは編集対象外） --}}
        @if(!$isBudgetMode)
            <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px 22px; margin-bottom: 16px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">名称</label>
                        <input type="text" name="name" value="{{ old('name', $simulation->name) }}"
                               placeholder="例: 2025年度 経営試算表"
                               style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">備考</label>
                        <input type="text" name="notes" value="{{ old('notes', $simulation->notes) }}"
                               style="width: 100%; padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                    </div>
                </div>
            </div>
        @else
            {{-- 予算モードでは名称・備考は送らないが、Controller 側で null 上書きしないように --}}
            <input type="hidden" name="name" value="{{ $simulation->name }}">
            <input type="hidden" name="notes" value="{{ $simulation->notes }}">
        @endif

        {{-- 試算表マトリクス（編集モード、mode は actual/budget） --}}
        @include('zeal.simulations._table', ['editable' => true, 'mode' => $isBudgetMode ? 'budget' : 'actual'])

        <div style="margin-top: 14px; padding: 10px 14px; background: {{ $isBudgetMode ? '#eff6ff' : '#f0f9ff' }}; border: 1px solid {{ $isBudgetMode ? '#bfdbfe' : '#bae6fd' }}; border-radius: 6px; font-size: 12px; color: {{ $isBudgetMode ? '#1d4ed8' : '#075985' }}; line-height: 1.7;">
            <strong>編集について:</strong> 売上連動行・集計行は自動算出のため入力欄なし。手入力・固定額タイプの項目のみ編集可能です。<br>
            @if($isBudgetMode)
                <strong>予算編集:</strong> 予算は実績とは独立管理。タブを切替えても入力中の値は保持されます（保存後）。詳細画面の「予算ベース」表示モードで参照できます。
            @else
                <strong>売上・会員数の手動上書き:</strong> 売上・会員数のセルに値を入力して保存すると「手動上書き」状態 (<span style="display:inline-block; width:6px; height:6px; background:#f97316; border-radius:50%; vertical-align: middle;"></span>マーカー) になり、詳細画面の「実績を反映」ボタンを押してもそのセルは上書きされません。再度実績で上書きしたい場合は、該当セルを空欄にして保存してください。
            @endif
        </div>

        <x-form-actions
            :submit-label="$isBudgetMode ? '予算を保存する' : '実績を保存する'"
            :cancel-url="route('zeal.simulations.show', $simulation)" />
    </form>

    {{-- Phase 5: 売上連動・経費計・営業利益・累計利益のリアルタイム計算 --}}
    <script>
        // PHP の SimulationController::buildMatrix() を JS に移植
        // CLAUDE.md ルール: アロー関数禁止のため function() 構文 + var self = this を使用
        function zealSimulationMatrix(config) {
            return {
                categories: config.categories,           // [{id, code, group_type, calc_type, rate_percent}]
                months: config.months,                   // ['YYYY-MM', ...]
                values: config.initialValues || {},      // 手入力/固定額セル: {catId: {ym: amount}}
                matrix: {},                              // 全行 × 全月 + 集計列の派生値キャッシュ

                init: function () {
                    var self = this;
                    self.recompute();
                    // values の深い変更（任意セル）で matrix を再計算
                    self.$watch('values', function () { self.recompute(); }, { deep: true });
                },

                // PHP buildMatrix と同じロジックで matrix を再構築
                recompute: function () {
                    var cats = this.categories;
                    var ms   = this.months;
                    var m    = {};

                    // 1) 入力値から初期マトリクス（売上連動・集計行は後段で算出するため null）
                    for (var ci = 0; ci < cats.length; ci++) {
                        var cat = cats[ci];
                        m[cat.id] = {};
                        for (var mi = 0; mi < ms.length; mi++) {
                            var ym = ms[mi];
                            var raw = (this.values[cat.id] && this.values[cat.id][ym] !== undefined)
                                ? this.values[cat.id][ym]
                                : null;
                            m[cat.id][ym] = normalizeAmount(raw);
                        }
                    }

                    // 2) 売上行を抽出
                    var revenueCat = null;
                    for (var i = 0; i < cats.length; i++) {
                        if (cats[i].group_type === 'revenue') { revenueCat = cats[i]; break; }
                    }
                    var revPerMonth = {};
                    for (var k = 0; k < ms.length; k++) {
                        revPerMonth[ms[k]] = revenueCat ? m[revenueCat.id][ms[k]] : null;
                    }

                    // 3) 売上連動行を計算（売上 × rate_percent / 100、四捨五入）
                    for (var ci2 = 0; ci2 < cats.length; ci2++) {
                        var c = cats[ci2];
                        if (c.calc_type === 'revenue_linked' && c.rate_percent !== null) {
                            for (var mi2 = 0; mi2 < ms.length; mi2++) {
                                var ym2 = ms[mi2];
                                var rev = revPerMonth[ym2];
                                m[c.id][ym2] = (rev !== null) ? Math.round(rev * c.rate_percent / 100) : null;
                            }
                        }
                    }

                    // 4) 集計行（経費計・営業利益・累計利益）
                    var expenseTotalCat = null, opCat = null, cumCat = null;
                    for (var i2 = 0; i2 < cats.length; i2++) {
                        if (cats[i2].code === 'expense_total')     expenseTotalCat = cats[i2];
                        if (cats[i2].code === 'operating_profit')  opCat           = cats[i2];
                        if (cats[i2].code === 'cumulative_profit') cumCat          = cats[i2];
                    }

                    // 経費計 = expense グループの合計
                    if (expenseTotalCat) {
                        for (var mi3 = 0; mi3 < ms.length; mi3++) {
                            var ym3 = ms[mi3];
                            var sum = 0, hasValue = false;
                            for (var ci3 = 0; ci3 < cats.length; ci3++) {
                                if (cats[ci3].group_type === 'expense') {
                                    var v = m[cats[ci3].id][ym3];
                                    if (v !== null) { sum += v; hasValue = true; }
                                }
                            }
                            m[expenseTotalCat.id][ym3] = hasValue ? sum : null;
                        }
                    }

                    // 営業利益 = 売上 - 経費計
                    if (opCat) {
                        for (var mi4 = 0; mi4 < ms.length; mi4++) {
                            var ym4 = ms[mi4];
                            var revV = revPerMonth[ym4];
                            var expV = expenseTotalCat ? m[expenseTotalCat.id][ym4] : null;
                            m[opCat.id][ym4] = (revV !== null || expV !== null)
                                ? ((revV !== null ? revV : 0) - (expV !== null ? expV : 0))
                                : null;
                        }
                    }

                    // 累計利益 = 当月までの営業利益の累積
                    if (cumCat && opCat) {
                        var running = 0;
                        for (var mi5 = 0; mi5 < ms.length; mi5++) {
                            var op = m[opCat.id][ms[mi5]];
                            if (op !== null) running += op;
                            m[cumCat.id][ms[mi5]] = running;
                        }
                    }

                    // 5) 集計列 Q1/Q2/H1/Q3/Q4/H2/YEAR
                    var aggGroups = {
                        Q1:   ms.slice(0, 3),
                        Q2:   ms.slice(3, 6),
                        H1:   ms.slice(0, 6),
                        Q3:   ms.slice(6, 9),
                        Q4:   ms.slice(9, 12),
                        H2:   ms.slice(6, 12),
                        YEAR: ms.slice()
                    };
                    var aggKeys = ['Q1', 'Q2', 'H1', 'Q3', 'Q4', 'H2', 'YEAR'];

                    for (var ci4 = 0; ci4 < cats.length; ci4++) {
                        var ct = cats[ci4];
                        for (var ak = 0; ak < aggKeys.length; ak++) {
                            var aggKey = aggKeys[ak];
                            var aggMs  = aggGroups[aggKey];
                            var lastM  = aggMs[aggMs.length - 1];

                            if (cumCat && ct.id === cumCat.id) {
                                // 累計利益: 期末月の値
                                m[ct.id][aggKey] = (m[ct.id][lastM] !== undefined) ? m[ct.id][lastM] : null;
                            } else if (ct.group_type === 'member') {
                                // 会員数: 期末月の値
                                m[ct.id][aggKey] = (m[ct.id][lastM] !== undefined) ? m[ct.id][lastM] : null;
                            } else {
                                // 通常項目: 集計期間の合計
                                var aggSum = 0, aggHas = false;
                                for (var bi = 0; bi < aggMs.length; bi++) {
                                    var av = m[ct.id][aggMs[bi]];
                                    if (av !== null) { aggSum += av; aggHas = true; }
                                }
                                m[ct.id][aggKey] = aggHas ? aggSum : null;
                            }
                        }
                    }

                    this.matrix = m;
                },

                // 表示用フォーマット（null/NaN は em-dash、会員は人サフィックス、その他は円）
                formatAmount: function (v, isMember) {
                    if (v === null || v === undefined || (typeof v === 'number' && isNaN(v))) {
                        return '—';
                    }
                    var formatted = new Intl.NumberFormat('ja-JP').format(v);
                    return formatted + (isMember ? '人' : '円');
                }
            };
        }

        // 入力値を数値またはnullに正規化（x-model.number は空欄を null として扱うが念のため）
        function normalizeAmount(v) {
            if (v === null || v === undefined || v === '') return null;
            var n = Number(v);
            return isNaN(n) ? null : n;
        }
    </script>
@endsection
