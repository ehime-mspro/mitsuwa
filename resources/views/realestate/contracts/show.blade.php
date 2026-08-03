@extends('layouts.app')

@section('title', $contract->property_name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>不動産管理</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('realestate.contracts.index') }}" class="hover:text-emerald-600 transition-colors">契約管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $contract->property_name }}</span>
@endsection

@section('content')
<div x-data="contractShow()">

    {{-- ヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <div class="flex items-center gap-3">
            <h1 class="text-lg font-bold text-gray-900">{{ $contract->property_name }}</h1>
            <span style="{{ $contract->contract_type->badgeStyle() }} display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">{{ $contract->contract_type->shortLabel() }}</span>
            @if($contract->contract_type->isBrokerage())
                <span style="{{ $contract->status->badgeStyle() }} display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">{{ $contract->status->label() }}</span>
            @endif
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
            <a href="{{ route('realestate.contracts.index') }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; background: #fff;">契約一覧に戻る</a>
            @if(auth()->user()->role->isManagerOrAbove())
                @if($contract->contract_type->isBrokerage() && $contract->status === \App\Enums\ReContractStatus::Listing)
                    <button @click="showCloseModal = true"
                            class="px-3.5 py-1.5 bg-emerald-600 text-white font-semibold rounded-md hover:bg-emerald-700 transition-colors cursor-pointer"
                            style="font-size: 13px;">成約にする</button>
                    <form method="POST" action="{{ route('realestate.contracts.lost', $contract) }}"
                          onsubmit="return confirm('この仲介案件を不成約にしますか？')">
                        @csrf @method('PATCH')
                        <button type="submit"
                                class="px-3.5 py-1.5 bg-white border border-gray-400 text-gray-600 font-semibold rounded-md hover:bg-gray-50 transition-colors cursor-pointer"
                                style="font-size: 13px;">不成約にする</button>
                    </form>
                @endif
                <a href="{{ route('realestate.contracts.edit', $contract) }}"
                   style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 6px; text-decoration: none; background: #fff;">編集</a>
            @endif
            @if(auth()->user()->role->isExecutive())
                <form method="POST" action="{{ route('realestate.contracts.destroy', $contract) }}"
                      onsubmit="return confirm('この契約を削除しますか？')">
                    @csrf @method('DELETE')
                    <button type="submit"
                            style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 6px; background: #fff; cursor: pointer;">削除</button>
                </form>
            @endif
        </div>
    </div>


    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
            <ul class="text-sm text-red-800">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 金額カード --}}
    @if(!$contract->contract_type->isBrokerage())
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px;">
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <div class="text-xs text-gray-500">契約額（税抜）</div>
                <div class="text-lg font-bold text-gray-900">
                    @if($contract->getContractAmountTotal() !== null)
                        {{ number_format($contract->getContractAmountTotal()) }}円
                    @else
                        —
                    @endif
                </div>
                @if($contract->hasBuilding())
                    <div class="text-xs text-gray-500">
                        土地 {{ number_format((int) $contract->contract_amount_land) }}円 ／ 建物 {{ number_format((int) $contract->contract_amount_building) }}円
                    </div>
                    <div class="text-xs text-gray-500">
                        消費税 {{ number_format((int) $contract->getBuildingTax()) }}円@if($contract->tax_amount !== null)（手入力）@endif
                    </div>
                    <div class="text-xs text-gray-500">
                        税込 {{ number_format((int) $contract->getContractAmountTotalWithTax()) }}円
                    </div>
                @endif
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <div class="text-xs text-gray-500">原価（契約時点）</div>
                <div class="text-lg font-bold text-gray-900">
                    @if($contract->cost_amount)
                        {{ number_format($contract->cost_amount) }}円
                    @else
                        —
                    @endif
                </div>
                @if($costDivergence !== null)
                    <div class="text-xs" style="color: #b45309;">
                        ⚠ 現在の原価と {{ number_format(abs($costDivergence)) }}円の差
                    </div>
                @endif
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <div class="text-xs text-gray-500">粗利額</div>
                <div class="text-lg font-bold" style="color: #047857; font-weight: 700;">
                    @if($contract->gross_profit !== null)
                        {{ number_format($contract->gross_profit) }}円
                    @else
                        —
                    @endif
                </div>
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <div class="text-xs text-gray-500">粗利率</div>
                <div class="text-lg font-bold text-gray-900">
                    @if($contract->gross_profit_rate !== null)
                        {{ $contract->gross_profit_rate }}%
                    @else
                        —
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- 仲介成約情報カード --}}
    @if($contract->contract_type->isBrokerage() && $contract->status === \App\Enums\ReContractStatus::Closed)
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 20px;">
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <div class="text-xs text-gray-500">成約日</div>
                <div class="text-lg font-bold text-gray-900">{{ $contract->contract_date?->format('Y/m/d') ?? '—' }}</div>
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <div class="text-xs text-gray-500">買主</div>
                <div class="text-lg font-bold text-gray-900">{{ $contract->buyer_name ?? '—' }}</div>
            </div>
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <div class="text-xs text-gray-500">仲介手数料</div>
                <div class="text-lg font-bold" style="color: #047857; font-weight: 700;">
                    @if($contract->brokerage_fee)
                        {{ number_format($contract->brokerage_fee) }}円
                    @else
                        —
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- 基本情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-4">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">基本情報</h2>
        </div>
        <div class="border border-gray-200 rounded-md overflow-hidden" style="display: grid; grid-template-columns: 120px 1fr 120px 1fr;">
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">契約種別</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $contract->contract_type->label() }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">契約日</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $contract->contract_date?->format('Y/m/d') ?? '—' }}</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">物件名</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200 font-medium">{{ $contract->property_name }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">所在地</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $contract->address ?? '—' }}</dd>

            @if(!$contract->contract_type->isBrokerage())
                <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">買主</dt>
                <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $contract->buyer_display_name ?? '—' }}</dd>
            @else
                <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">販売金額</dt>
                <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">
                    @if($contract->brokerage_selling_price)
                        {{ number_format($contract->brokerage_selling_price) }}円
                    @else
                        —
                    @endif
                </dd>
            @endif

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-r border-gray-200">担当者</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900">{{ $contract->staff->name ?? '—' }}</dd>

            {{-- 仕入れ系: 案件リンク --}}
            @if($contract->contract_type->isProcurement() && $contract->procurement)
                <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-t border-r border-gray-200">仕入れ案件</dt>
                <dd class="px-3.5 py-2.5 text-sm border-t border-gray-200" style="grid-column: span 3;">
                    <a href="{{ route('realestate.procurements.show', $contract->procurement) }}" class="text-emerald-600 hover:underline">{{ $contract->procurement->procurement_code }} — {{ $contract->procurement->property_name }}</a>
                </dd>
            @endif

            {{-- 分譲地: PJ・区画リンク --}}
            @if($contract->contract_type->isSubdivision() && $contract->project)
                <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-t border-r border-gray-200">分譲地</dt>
                <dd class="px-3.5 py-2.5 text-sm border-t border-gray-200" style="grid-column: span 3;">
                    <a href="{{ route('realestate.projects.show', $contract->project) }}" class="text-emerald-600 hover:underline">{{ $contract->project->project_code }} — {{ $contract->project->project_name }}</a>
                    @if($contract->lot)
                        <span class="text-gray-500">/ 区画{{ $contract->lot->lot_number }}</span>
                    @endif
                </dd>
            @endif
        </div>
    </div>

    {{-- 原価内訳（仕入れ系） --}}
    @if($contract->contract_type->isProcurement() && $costBreakdown)
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
            <div class="flex items-center gap-2 mb-4">
                <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
                <h2 class="text-base font-bold text-gray-900">原価内訳</h2>
            </div>
            <div style="background: #fffbeb; border: 1px solid #fcd34d; border-radius: 8px; padding: 16px;">
                <div class="text-sm text-gray-700" style="margin-bottom: 8px;">
                    <strong>購入価格:</strong> {{ number_format($costBreakdown['purchase_price']) }}円
                </div>
                @foreach($costBreakdown['costs'] as $c)
                    <div class="text-sm text-gray-600" style="margin-bottom: 4px;">
                        {{ $c['name'] }}: {{ number_format($c['amount']) }}円
                        @if(!$c['is_actual'])
                            <span class="text-xs text-orange-500">（見込み）</span>
                        @endif
                    </div>
                @endforeach
                {{-- ⚠ 上の内訳は仕入れ案件からのライブ値、下の「契約時点の原価」は契約の保存カラム。
                     別ソースなので「原価合計」と1つだけ出すと無音で食い違う（Bug #46）。必ず両方出す。 --}}
                <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #fcd34d;">
                    <div class="text-sm font-bold text-gray-900">
                        契約時点の原価: {{ $contract->cost_amount !== null ? number_format($contract->cost_amount) . '円' : '—' }}
                    </div>
                    <div class="text-sm text-gray-600" style="margin-top: 4px;">
                        現在の仕入れ原価: {{ number_format($liveCost) }}円
                    </div>
                </div>
                @if($costDivergence !== null)
                    <div class="text-xs" style="margin-top: 8px; color: #b45309;">
                        ⚠ 契約後に仕入れ案件の原価が {{ number_format(abs($costDivergence)) }}円{{ $costDivergence > 0 ? '増えて' : '減って' }}います。粗利は契約時点の原価で計算しています。
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- 原価内訳（分譲地） --}}
    @if($contract->contract_type->isSubdivision() && $subdivisionCostInfo)
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
            <div class="flex items-center gap-2 mb-4">
                <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
                <h2 class="text-base font-bold text-gray-900">原価内訳（按分計算）</h2>
            </div>
            <div style="background: #fffbeb; border: 1px solid #fcd34d; border-radius: 8px; padding: 16px;">
                <div class="text-sm text-gray-700">PJ原価合計: {{ number_format($subdivisionCostInfo['total_cost']) }}円</div>
                <div class="text-sm text-gray-700">区画数: {{ $subdivisionCostInfo['lot_count'] }}区画</div>
                <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #fcd34d;">
                    <div class="text-sm font-bold text-gray-900">
                        契約時点の原価: {{ $contract->cost_amount !== null ? number_format($contract->cost_amount) . '円' : '—' }}
                    </div>
                    <div class="text-sm text-gray-600" style="margin-top: 4px;">
                        現在の区画あたり原価: {{ number_format($subdivisionCostInfo['per_lot_cost']) }}円
                    </div>
                </div>
                @if($costDivergence !== null)
                    <div class="text-xs" style="margin-top: 8px; color: #b45309;">
                        ⚠ 契約後に分譲地の原価が {{ number_format(abs($costDivergence)) }}円{{ $costDivergence > 0 ? '増えて' : '減って' }}います。粗利は契約時点の原価で計算しています。
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- 備考 --}}
    @if($contract->memo)
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
            <div class="flex items-center gap-2 mb-4">
                <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
                <h2 class="text-base font-bold text-gray-900">備考</h2>
            </div>
            <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ $contract->memo }}</p>
        </div>
    @endif

    {{-- 登録情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-4">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">登録情報</h2>
        </div>
        <div class="border border-gray-200 rounded-md overflow-hidden" style="display: grid; grid-template-columns: 120px 1fr 120px 1fr;">
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">登録者</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $contract->createdBy->name ?? '—' }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">登録日時</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $contract->created_at?->format('Y/m/d H:i') }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-r border-gray-200">更新者</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900">{{ $contract->updatedBy->name ?? '—' }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-r border-gray-200">更新日時</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900">{{ $contract->updated_at?->format('Y/m/d H:i') }}</dd>
        </div>
    </div>

    {{-- 成約モーダル --}}
    @if($contract->contract_type->isBrokerage() && $contract->status === \App\Enums\ReContractStatus::Listing)
        <div x-show="showCloseModal" style="display: none;" class="fixed inset-0 z-50 flex items-center justify-center">
            <div class="fixed inset-0 bg-black/50" @click="showCloseModal = false"></div>
            <div class="modal-box" style="position: relative; z-index: 10;">
                <h3 style="font-size: 16px; font-weight: 700; margin: 0 0 20px; color: #111827;">仲介案件を成約にする</h3>
                <form method="POST" action="{{ route('realestate.contracts.close', $contract) }}">
                    @csrf @method('PATCH')

                    <div class="fg" style="margin-bottom: 20px;">
                        <label>成約日 <span class="req">*</span></label>
                        <input type="date" name="contract_date" value="{{ old('contract_date', date('Y-m-d')) }}" required>
                    </div>

                    <div class="fg" style="margin-bottom: 20px;">
                        <label>買主名</label>
                        <input type="text" name="buyer_name" value="{{ old('buyer_name') }}" placeholder="買主の氏名">
                    </div>

                    <div class="fg" style="margin-bottom: 24px;">
                        <label>仲介手数料（税抜） <span class="req">*</span></label>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <input type="number" name="brokerage_fee" value="{{ old('brokerage_fee', $contract->brokerage_fee) }}" style="text-align: right; max-width: 200px;" min="0" required>
                            <span style="font-size: 13px;">円</span>
                        </div>
                        <div style="font-size: 11px; color: #6b7280; margin-top: 3px;">※ 登録時の値を引き継ぎ。変更可能です。</div>
                    </div>

                    <div style="display: flex; justify-content: flex-end; gap: 8px;">
                        <button type="button" @click="showCloseModal = false" class="btn-form-cancel" style="padding: 8px 16px;">キャンセル</button>
                        <button type="submit" class="btn-form-submit" style="padding: 8px 24px; font-size: 14px;">成約にする</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

</div>

<style>
.modal-box { background: #fff; border-radius: 12px; padding: 28px; max-width: 480px; width: 90%; box-shadow: 0 12px 40px rgba(0,0,0,0.15); }
.fg label { display: block; font-size: 13px; font-weight: 600; color: #1f2937; margin-bottom: 5px; }
.req { color: #dc2626; margin-left: 2px; }
.modal-box input[type="text"], .modal-box input[type="number"], .modal-box input[type="date"], .modal-box select {
    border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px; height: 38px; outline: none; color: #1f2937; background: #fff; width: 100%; box-sizing: border-box;
}
.modal-box input:focus, .modal-box select:focus { border-color: #059669; box-shadow: 0 0 0 2px rgba(5,150,105,0.12); }
.btn-form-cancel { background: #fff; color: #374151; padding: 10px 20px; border-radius: 6px; font-size: 14px; font-weight: 600; border: 2px solid #9ca3af; cursor: pointer; text-decoration: none; }
.btn-form-cancel:hover { background: #f9fafb; }
.btn-form-submit { background: #059669; color: #fff; padding: 10px 32px; border-radius: 6px; font-size: 15px; font-weight: 600; border: none; cursor: pointer; }
.btn-form-submit:hover { background: #047857; }
</style>

<script>
function contractShow() {
    return {
        showCloseModal: {{ ($errors->any() && $contract->contract_type->isBrokerage() && $contract->status === \App\Enums\ReContractStatus::Listing) ? 'true' : 'false' }}
    };
}
</script>
@endsection
