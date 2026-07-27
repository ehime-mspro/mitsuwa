@extends('layouts.app')

@section('title', '募集家賃の改定: ' . $unit->display_name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.show', $unit->property) }}" class="hover:text-emerald-600 transition-colors">{{ $unit->property->name }}</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.units.show', $unit) }}" class="hover:text-emerald-600 transition-colors">区画: {{ $unit->display_name }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">賃料改定</span>
@endsection

@section('content')

    @php
        // 坪単価計算ウィジェット用の値（数値リテラルとして Alpine へ渡すため (int)/(float) で正規化）
        $areaTsubo = (float) $unit->area_tsubo;
        $hasArea   = $areaTsubo > 0;
        $initRent    = (int) old('new_rent', $unit->rent);
        $initFee     = (int) old('new_common_fee', $unit->common_fee);
        $initDeposit = (int) old('new_deposit', $unit->deposit);
    @endphp

    {{-- 坪単価計算ウィジェット用スタイル（このページ限定。インライン）。
         ⚠ レイアウトに **styles** スタックは今も無い（2026-07-26 に追加したのは @stack('scripts') だけ）。
           @push('styles') に書くとサイレントに破棄されるので、スタイルはここに直接置く（Bug #28） --}}
    <style>
        .calc-input { width:100%; height:40px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; color:#1f2937; box-sizing:border-box; background:white; }
        .calc-input:focus { outline:none; border-color:#059669; box-shadow:0 0 0 3px rgba(5,150,105,0.12); }
        .calc-input:disabled { background:#f3f4f6; color:#9ca3af; cursor:not-allowed; }
        .calc-amount { font-size:15px; font-weight:600; }
        .calc-suffix { position:absolute; top:50%; transform:translateY(-50%); color:#6b7280; pointer-events:none; }
        .tsubo-box { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:12px 14px; }
    </style>

    {{-- 戻るリンク --}}
    <a href="{{ route('tenant.units.show', $unit) }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        区画詳細に戻る
    </a>

    {{-- ページタイトル --}}
    <h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">募集家賃の改定: {{ $unit->display_name }}</h1>

    {{-- 経営層のみの告知 --}}
    <div class="flex items-start gap-2 mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3.5">
        <svg class="w-5 h-5 text-blue-500 mt-0.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <div class="text-sm text-blue-800">この操作は<strong>経営層のみ</strong>実行できます。改定内容は履歴に記録され、区画の募集条件が更新されます。</div>
    </div>

    {{-- バリデーションエラー --}}
    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-sm font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-xs text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 対象区画情報（読み取り専用） --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-4 lg:px-5 lg:py-4 mb-4">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3 border-b border-gray-200">対象区画</div>
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-3">
            <div>
                <div class="text-xs text-gray-500 mb-0.5">物件 / 区画</div>
                <div class="text-sm font-medium text-gray-900">{{ $unit->property->name }} / {{ $unit->display_name }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">ステータス</div>
                <div class="text-sm font-medium text-gray-900">{{ $unit->status->label() }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">坪数</div>
                <div class="text-sm font-medium text-gray-900">{{ $hasArea ? number_format($areaTsubo, 2) . '坪' : '—' }}</div>
            </div>
        </div>
    </div>

    {{-- 現在の募集条件（読み取り専用） --}}
    <div class="bg-white border border-gray-200 rounded-lg px-4 py-4 lg:px-5 lg:py-4 mb-4">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3 border-b border-gray-200">現在の募集条件</div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div>
                <div class="text-xs text-gray-500 mb-0.5">募集家賃</div>
                <div class="text-sm font-medium text-gray-900">{{ number_format($unit->rent) }}円</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">共益費</div>
                <div class="text-sm font-medium text-gray-900">{{ number_format($unit->common_fee) }}円</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">ゴミ代</div>
                <div class="text-sm font-medium text-gray-900">{{ number_format($unit->garbage_fee) }}円</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">駆除代</div>
                <div class="text-sm font-medium text-gray-900">{{ number_format($unit->pest_control_fee) }}円</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-0.5">敷金</div>
                <div class="text-sm font-medium text-gray-900">{{ number_format($unit->deposit) }}円</div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('tenant.units.revise.execute', $unit) }}"
          x-data="reviseForm({{ $areaTsubo }}, {{ $initRent }}, {{ $initFee }}, {{ $initDeposit }})" x-init="init()">
        @csrf

        {{-- 改定内容 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-4 border-b border-gray-200">改定内容</div>

            {{-- 改定適用日（初期値=本日） --}}
            <div style="margin-bottom:26px;">
                <label class="block text-sm font-semibold text-gray-700 mb-1">改定適用日<span class="text-red-600 ml-0.5">*</span></label>
                <input type="date" name="revision_date" value="{{ old('revision_date', now()->format('Y-m-d')) }}"
                       class="calc-input" style="max-width:240px; padding:0 12px;">
                <p class="text-xs text-gray-500 mt-1">初期値は本日。カレンダーから変更できます。</p>
            </div>

            {{-- 新・募集家賃（坪単価 → 金額 / 双方向） --}}
            <div style="margin-bottom:26px;">
                <label class="block text-sm font-semibold text-gray-700 mb-2">新・募集家賃<span class="text-red-600 ml-0.5">*</span></label>
                <div class="tsubo-box">
                    <div style="display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap;">
                        {{-- 坪単価（非保存・計算補助） --}}
                        <div style="width:160px;">
                            <div class="text-xs text-gray-500 mb-0.5">坪単価</div>
                            <div style="position:relative;">
                                <input type="number" min="0" inputmode="numeric" class="calc-input" style="padding:0 42px 0 12px; text-align:right;"
                                       x-model.number="rentUnitPrice" @input="newRent = toAmount(rentUnitPrice); applyMonths()" @disabled(!$hasArea)>
                                <span class="calc-suffix" style="right:10px; font-size:11px;">円/坪</span>
                            </div>
                        </div>
                        {{-- 連結記号 --}}
                        <div style="height:40px; display:flex; align-items:center; font-size:13px; color:#6b7280; white-space:nowrap;">
                            × <span style="margin:0 3px; font-weight:600; color:#374151;">{{ number_format($areaTsubo, 2) }}</span>坪 =
                        </div>
                        {{-- 金額（保存値: new_rent） --}}
                        <div style="flex:1; min-width:170px;">
                            <div class="text-xs text-gray-500 mb-0.5">金額</div>
                            <div style="position:relative;">
                                <input type="number" name="new_rent" min="0" inputmode="numeric" class="calc-input calc-amount" style="padding:0 30px 0 12px; text-align:right;"
                                       x-model.number="newRent" @input="rentUnitPrice = toUnitPrice(newRent); applyMonths()">
                                <span class="calc-suffix" style="right:10px; font-size:12px;">円</span>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-1">
                    現在: {{ number_format($unit->rent) }}円
                    @if($hasArea)
                        ／ 坪単価を入れると金額へ自動反映。金額を直接入力しても可（坪単価も連動）。
                    @else
                        ／ <span class="text-amber-600 font-medium">坪数未設定のため坪単価計算は使えません。金額を直接入力してください。</span>
                    @endif
                </p>
            </div>

            {{-- 新・共益費（坪単価 → 金額 / 双方向） --}}
            <div style="margin-bottom:26px;">
                <label class="block text-sm font-semibold text-gray-700 mb-2">新・共益費</label>
                <div class="tsubo-box">
                    <div style="display:flex; align-items:flex-end; gap:10px; flex-wrap:wrap;">
                        <div style="width:160px;">
                            <div class="text-xs text-gray-500 mb-0.5">坪単価</div>
                            <div style="position:relative;">
                                <input type="number" min="0" inputmode="numeric" class="calc-input" style="padding:0 42px 0 12px; text-align:right;"
                                       x-model.number="feeUnitPrice" @input="newFee = toAmount(feeUnitPrice)" @disabled(!$hasArea)>
                                <span class="calc-suffix" style="right:10px; font-size:11px;">円/坪</span>
                            </div>
                        </div>
                        <div style="height:40px; display:flex; align-items:center; font-size:13px; color:#6b7280; white-space:nowrap;">
                            × <span style="margin:0 3px; font-weight:600; color:#374151;">{{ number_format($areaTsubo, 2) }}</span>坪 =
                        </div>
                        <div style="flex:1; min-width:170px;">
                            <div class="text-xs text-gray-500 mb-0.5">金額</div>
                            <div style="position:relative;">
                                <input type="number" name="new_common_fee" min="0" inputmode="numeric" class="calc-input calc-amount" style="padding:0 30px 0 12px; text-align:right;"
                                       x-model.number="newFee" @input="feeUnitPrice = toUnitPrice(newFee)">
                                <span class="calc-suffix" style="right:10px; font-size:12px;">円</span>
                            </div>
                        </div>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-1">
                    現在: {{ number_format($unit->common_fee) }}円
                    @if($hasArea)
                        ／ 坪単価を入れると金額へ自動反映。金額を直接入力しても可（坪単価も連動）。
                    @else
                        ／ <span class="text-amber-600 font-medium">坪数未設定のため坪単価計算は使えません。金額を直接入力してください。</span>
                    @endif
                </p>
            </div>

            {{-- 新・ゴミ代 / 新・駆除代（金額のみ・坪単価計算なし） --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-bottom:26px;">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">新・ゴミ代</label>
                    <div style="position:relative;">
                        <input type="number" name="new_garbage_fee" value="{{ old('new_garbage_fee', $unit->garbage_fee) }}" min="0" inputmode="numeric" class="calc-input" style="padding:0 30px 0 12px;">
                        <span class="calc-suffix" style="right:10px; font-size:12px;">円</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">現在: {{ number_format($unit->garbage_fee) }}円</p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">新・駆除代</label>
                    <div style="position:relative;">
                        <input type="number" name="new_pest_control_fee" value="{{ old('new_pest_control_fee', $unit->pest_control_fee) }}" min="0" inputmode="numeric" class="calc-input" style="padding:0 30px 0 12px;">
                        <span class="calc-suffix" style="right:10px; font-size:12px;">円</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">現在: {{ number_format($unit->pest_control_fee) }}円</p>
                </div>
            </div>

            {{-- 新・敷金（募集家賃 × ヶ月数で自動計算・手動修正可） --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="margin-bottom:26px;">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">新・敷金</label>
                    <div style="position:relative;">
                        <input type="number" name="new_deposit" x-model.number="newDeposit" min="0" inputmode="numeric" class="calc-input" style="padding:0 30px 0 12px;">
                        <span class="calc-suffix" style="right:10px; font-size:12px;">円</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">現在: {{ number_format($unit->deposit) }}円<span x-show="impliedMonths() !== null"> ／ 入力 ≒ <span x-text="impliedMonths()"></span>ヶ月分</span></p>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">敷金＝募集家賃 × ヶ月数</label>
                    <div style="position:relative;">
                        <input type="number" x-model.number="depositMonths" @input="applyMonths()" min="0" step="0.1" inputmode="decimal" placeholder="ヶ月数を入力" class="calc-input" style="padding:0 42px 0 12px;">
                        <span class="calc-suffix" style="right:10px; font-size:12px;">ヶ月</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">ヶ月数を入れると敷金を自動計算（金額の直接入力も可）</p>
                </div>
            </div>

            {{-- 改定理由 --}}
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">改定理由</label>
                <textarea name="reason" rows="3"
                          style="width:100%; min-height:80px; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px; color:#1f2937; resize:vertical; box-sizing:border-box;"
                          placeholder="改定の理由を入力（任意）">{{ old('reason') }}</textarea>
            </div>
        </div>

        {{-- アクションボタン --}}
        <div class="flex flex-col-reverse sm:flex-row justify-end gap-2 pt-2">
            <a href="{{ route('tenant.units.show', $unit) }}"
               class="px-4 py-2.5 bg-white text-gray-700 border border-gray-300 rounded-md text-sm text-center hover:bg-gray-50 transition-colors">
                キャンセル
            </a>
            <button type="submit"
                    class="inline-flex items-center justify-center gap-1.5 px-5 py-2.5 bg-emerald-600 text-white rounded-md text-sm font-semibold hover:bg-emerald-700 transition-colors cursor-pointer">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                賃料改定を実行する
            </button>
        </div>
    </form>

    {{-- 坪単価 ⇄ 金額 の双方向計算（Bug #1: アロー関数を避け、別 script の named function に定義。x-data へは数値リテラルのみ渡す＝Bug #23 回避） --}}
    <script>
        function reviseForm(area, initRent, initFee, initDeposit) {
            return {
                areaTsubo: area || 0,
                newRent: initRent || 0,
                newFee: initFee || 0,
                newDeposit: initDeposit || 0,
                depositMonths: '',
                rentUnitPrice: '',
                feeUnitPrice: '',
                init() {
                    // 金額から坪単価を逆算（初期表示）。坪数未設定なら坪単価は空のまま。
                    if (this.hasArea()) {
                        this.rentUnitPrice = this.toUnitPrice(this.newRent);
                        this.feeUnitPrice = this.toUnitPrice(this.newFee);
                    }
                },
                hasArea() { return this.areaTsubo > 0; },
                toAmount(unitPrice) { return this.hasArea() ? Math.round((unitPrice || 0) * this.areaTsubo) : 0; },
                toUnitPrice(amount) { return this.hasArea() ? Math.round((amount || 0) / this.areaTsubo) : ''; },
                // 敷金 = 募集家賃 × ヶ月数（ヶ月数が空なら敷金は保持）。募集家賃を変えたときも再計算。
                applyMonths() {
                    if (this.depositMonths === '' || this.depositMonths === null || isNaN(this.depositMonths)) return;
                    this.newDeposit = Math.round((this.newRent || 0) * this.depositMonths);
                },
                // 現在入力中の敷金が募集家賃の何ヶ月分か（家賃0以下なら非表示）
                impliedMonths() {
                    if (!this.newRent || this.newRent <= 0) return null;
                    return (this.newDeposit / this.newRent).toFixed(1);
                }
            };
        }
    </script>
@endsection
