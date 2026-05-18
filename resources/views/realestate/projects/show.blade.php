@extends('layouts.app')

@section('title', $project->project_code)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>不動産管理</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('realestate.projects.index') }}" class="hover:text-emerald-600 transition-colors">分譲地一覧</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $project->project_code }}</span>
@endsection

@section('content')
<div x-data="projectDetail()">

    {{-- ヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <div class="flex items-center gap-3">
            <h1 class="text-lg font-bold text-gray-900">{{ $project->project_code }}</h1>
            <span class="badge {{ $project->status->badgeClass() }}">{{ $project->status->label() }}</span>
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
            <a href="{{ route('realestate.projects.index') }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; background: #fff;">分譲地一覧に戻る</a>
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('realestate.projects.edit', $project) }}"
                   style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 6px; text-decoration: none; background: #fff;">編集</a>
            @endif
            @if(auth()->user()->role->isExecutive())
                <form method="POST" action="{{ route('realestate.projects.destroy', $project) }}"
                      onsubmit="return confirm('この分譲地を削除しますか？ 原価・区画・図面データも全て削除されます。')">
                    @csrf @method('DELETE')
                    <button type="submit"
                            style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 6px; background: #fff; cursor: pointer;">削除</button>
                </form>
            @endif
        </div>
    </div>


    {{-- 基本情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-4">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">基本情報</h2>
        </div>
        <div class="border border-gray-200 rounded-md overflow-hidden" style="display: grid; grid-template-columns: 130px 1fr 130px 1fr;">
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">PJ番号</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200 font-semibold">{{ $project->project_code }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">ステータス</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200"><span class="badge {{ $project->status->badgeClass() }}">{{ $project->status->label() }}</span></dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">プロジェクト名</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200 font-medium">{{ $project->project_name }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">所在地</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">
                <span style="display: inline-flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <span>{{ $project->address ?: '—' }}</span>
                    @if($project->latitude && $project->longitude)
                        <button type="button" onclick="openMapModal('{{ addslashes($project->project_name) }}', '{{ addslashes($project->address) }}', {{ $project->latitude }}, {{ $project->longitude }})"
                                style="background: #fff; color: #2563eb; padding: 3px 10px; border-radius: 5px; font-size: 11px; font-weight: 600; border: 1px solid #2563eb; cursor: pointer; white-space: nowrap;">マップで確認</button>
                    @endif
                </span>
            </dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">土地面積</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">
                @if($project->land_area_sqm)
                    {{ $project->land_area_sqm }} ㎡（{{ $project->getLandAreaTsubo() }} 坪）
                @else
                    <span class="text-gray-400">—</span>
                @endif
            </dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">用途地域</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $project->zoning ?? '—' }}</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">建ぺい率</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">
                @if($project->building_coverage)
                    {{ $project->building_coverage }}%
                @else
                    —
                @endif
            </dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">容積率</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">
                @if($project->floor_area_ratio)
                    {{ $project->floor_area_ratio }}%
                @else
                    —
                @endif
            </dd>
        </div>
    </div>

    {{-- 所在地マップ（閲覧専用） --}}
    @if($project->latitude && $project->longitude)
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-4">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">所在地マップ</h2>
        </div>
        <div style="border: 1px solid #d1d5db; border-radius: 8px; overflow: hidden;">
            <div id="detail-map" style="height: 350px;"></div>
        </div>
        <div class="flex gap-3" style="margin-top: 8px;">
            <span class="text-xs text-gray-500">緯度: <strong class="text-gray-800">{{ $project->latitude }}</strong></span>
            <span class="text-xs text-gray-500">経度: <strong class="text-gray-800">{{ $project->longitude }}</strong></span>
        </div>
    </div>
    @endif

    {{-- 仕入れ情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-4">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">仕入れ情報</h2>
        </div>
        <div class="border border-gray-200 rounded-md overflow-hidden" style="display: grid; grid-template-columns: 130px 1fr 130px 1fr;">
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">仕入れ先</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">
                @if($project->supplier)
                    <a href="{{ route('realestate.suppliers.show', $project->supplier) }}" class="text-emerald-600 font-medium hover:underline">{{ $project->supplier->name }}</a>
                @else
                    <span class="text-gray-400">—</span>
                @endif
            </dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">情報入手日</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $project->info_obtained_date?->format('Y/m/d') ?? '—' }}</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">査定価格</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">
                @if($project->assessment_price)
                    {{ number_format($project->assessment_price) }}円
                @else
                    —
                @endif
            </dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">購入価格</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">
                @if($project->purchase_price)
                    {{ number_format($project->purchase_price) }}円
                @else
                    —
                @endif
            </dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">想定総販売価格</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">
                @if($project->target_selling_price)
                    {{ number_format($project->target_selling_price) }}円
                @else
                    —
                @endif
            </dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200"></dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200"></dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">契約日</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $project->contract_date?->format('Y/m/d') ?? '—' }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">決済日</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $project->settlement_date?->format('Y/m/d') ?? '—' }}</dd>
        </div>
    </div>

    {{-- 原価管理 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
            <div class="flex items-center gap-2">
                <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
                <h2 class="text-base font-bold text-gray-900">原価管理</h2>
            </div>
            @if(auth()->user()->role->isManagerOrAbove())
                <div style="display: flex; gap: 8px;">
                    <button @click="openCostExcelImport()" x-show="!showAddCost && !costExcelImport.open"
                            class="px-3.5 py-1.5 bg-white text-emerald-700 text-sm font-semibold rounded-md hover:bg-emerald-50 cursor-pointer"
                            style="font-size: 13px; border: 1px solid #a7f3d0;">📂 試算表 Excel 取込</button>
                    <button @click="showAddCost = true" x-show="!showAddCost && !costExcelImport.open"
                            class="px-3.5 py-1.5 bg-emerald-600 text-white text-sm font-semibold rounded-md hover:bg-emerald-700 transition-colors cursor-pointer"
                            style="font-size: 13px;">＋ 費用追加</button>
                </div>
            @endif
        </div>

        {{-- 試算表 Excel/CSV 取込パネル（仕入れ案件・分譲地PJ 共用 partial） --}}
        @include('realestate._partials._cost_excel_import', ['costItems' => $costItemsForJs])

        {{-- 費用追加フォーム --}}
        <div x-show="showAddCost" x-transition class="mb-4 p-4 bg-emerald-50 border border-emerald-200 rounded-lg">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3" style="align-items: end;">
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">費用項目</label>
                    <select x-model="newCost.cost_item_id" class="w-full h-9 px-2 border border-gray-300 rounded-md text-sm bg-white focus:border-emerald-500 focus:outline-none">
                        <option value="">選択</option>
                        <template x-for="ci in costItems" :key="ci.id">
                            <option :value="ci.id" x-text="ci.name"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">見込み額</label>
                    <input type="number" x-model.number="newCost.estimated_amount" placeholder=""
                           class="w-full h-9 px-2 border border-gray-300 rounded-md text-sm focus:border-emerald-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">確定額</label>
                    <input type="number" x-model.number="newCost.actual_amount" placeholder="未定"
                           class="w-full h-9 px-2 border border-gray-300 rounded-md text-sm focus:border-emerald-500 focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1">備考</label>
                    <input type="text" x-model="newCost.notes" placeholder="備考"
                           class="w-full h-9 px-2 border border-gray-300 rounded-md text-sm focus:border-emerald-500 focus:outline-none">
                </div>
            </div>
            <div class="flex gap-2 mt-3">
                <button @click="addCost()" class="px-4 py-1.5 bg-emerald-600 text-white text-xs font-semibold rounded hover:bg-emerald-700 cursor-pointer">追加</button>
                <button @click="showAddCost = false" class="px-4 py-1.5 bg-gray-100 text-gray-600 text-xs font-semibold rounded hover:bg-gray-200 cursor-pointer">取消</button>
            </div>
        </div>

        {{-- Ajax メッセージ --}}
        <div x-show="costMessage" x-transition class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 p-2">
            <p class="text-sm text-emerald-800" x-text="costMessage"></p>
        </div>

        {{-- 原価テーブル --}}
        <div class="border border-gray-200 rounded-md overflow-hidden">
            <table class="w-full border-collapse" style="table-layout: fixed;">
                <colgroup>
                    <col style="width: 160px;">
                    <col style="width: 250px;">
                    <col style="width: 250px;">
                    <col>
                    <col style="width: 110px;">
                </colgroup>
                <thead>
                    <tr>
                        <th class="text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding: 9px 16px;">費用項目</th>
                        <th class="text-right text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding: 9px 16px;">見込み額</th>
                        <th class="text-right text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding: 9px 16px;">確定額</th>
                        <th class="text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding: 9px 28px;">備考</th>
                        <th class="text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding: 9px 8px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(cost, idx) in costs" :key="cost.id">
                        <tr class="hover:bg-gray-50 border-b border-gray-100">
                            <td :style="editingCostId === cost.id ? 'font-size: 13px; padding: 8px 16px;' : 'font-size: 13px; padding: 11px 16px;'">
                                <span x-text="cost.cost_item_name"></span>
                                <span x-show="cost.is_property_purchase"
                                      style="display: inline-block; margin-left: 8px; padding: 1px 6px; font-size: 10px; font-weight: 600; color: #92400e; background: #fef3c7; border-radius: 4px; vertical-align: middle;">自動</span>
                            </td>
                            <td :style="editingCostId === cost.id ? 'padding: 8px 8px;' : 'padding: 11px 16px; font-size: 13px; text-align: right;'">
                                <div x-show="editingCostId === cost.id">
                                    <input type="number" x-model.number="editCost.estimated_amount" class="w-full h-8 px-2 border border-gray-300 rounded text-sm text-right focus:border-emerald-500 focus:outline-none">
                                </div>
                                <span x-show="editingCostId !== cost.id" x-text="formatMoney(cost.estimated_amount) + '円'"></span>
                            </td>
                            <td :style="editingCostId === cost.id ? 'padding: 8px 8px;' : 'padding: 11px 16px; font-size: 13px; text-align: right;'">
                                <div x-show="editingCostId === cost.id">
                                    <input type="number" x-model.number="editCost.actual_amount" placeholder="未定" class="w-full h-8 px-2 border border-gray-300 rounded text-sm text-right focus:border-emerald-500 focus:outline-none">
                                </div>
                                <div x-show="editingCostId !== cost.id">
                                    <span x-show="cost.actual_amount !== null && cost.actual_amount !== ''" style="font-weight: 600;" x-text="formatMoney(cost.actual_amount) + '円'"></span>
                                    <span x-show="cost.actual_amount === null || cost.actual_amount === ''" class="text-gray-400">—</span>
                                </div>
                            </td>
                            <td :style="editingCostId === cost.id ? 'padding: 8px 8px;' : 'padding: 11px 28px; font-size: 13px; color: #4b5563;'">
                                <div x-show="editingCostId === cost.id">
                                    <input type="text" x-model="editCost.notes" class="w-full h-8 px-2 border border-gray-300 rounded text-sm focus:border-emerald-500 focus:outline-none">
                                </div>
                                <span x-show="editingCostId !== cost.id" x-text="cost.notes"></span>
                            </td>
                            <td class="text-center" style="padding: 8px 4px;">
                                <div x-show="editingCostId === cost.id">
                                    <button @click="saveCost(cost)" class="bg-emerald-600 text-white text-xs font-semibold rounded hover:bg-emerald-700 cursor-pointer" style="padding: 2px 8px;">保存</button>
                                    <button @click="cancelEditCost()" class="bg-gray-100 text-gray-600 text-xs font-semibold rounded hover:bg-gray-200 cursor-pointer ml-1" style="padding: 2px 8px;">取消</button>
                                </div>
                                <div x-show="editingCostId !== cost.id && !cost.is_property_purchase">
                                    @if(auth()->user()->role->isManagerOrAbove())
                                        <button @click="startEditCost(cost)" class="inline-block text-xs font-semibold text-emerald-600 rounded bg-white hover:bg-emerald-50 cursor-pointer" style="border: 1px solid #059669; padding: 2px 10px;">編集</button>
                                    @endif
                                    @if(auth()->user()->role->isExecutive())
                                        <button @click="deleteCost(cost)" class="inline-block text-xs font-semibold text-red-600 rounded bg-white cursor-pointer ml-1" style="border: 1px solid #dc2626; padding: 2px 10px;">削除</button>
                                    @endif
                                </div>
                                <div x-show="editingCostId !== cost.id && cost.is_property_purchase" class="text-xs text-gray-400">
                                    自動同期
                                </div>
                            </td>
                        </tr>
                    </template>
                    <tr x-show="costs.length === 0">
                        <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-400">原価データがありません。「＋ 費用追加」から追加してください。</td>
                    </tr>
                </tbody>
                <tfoot x-show="costs.length > 0">
                    <tr style="background: #f0fdf4;">
                        <td style="padding: 11px 16px; font-size: 13px; font-weight: 700; border-bottom: none;">合計</td>
                        <td style="padding: 11px 16px; font-size: 13px; font-weight: 700; text-align: right; border-bottom: none;" x-text="formatMoney(estimatedTotal) + '円'"></td>
                        <td style="padding: 11px 16px; font-size: 13px; font-weight: 700; text-align: right; border-bottom: none;" x-text="formatMoney(actualTotal) + '円'"></td>
                        <td colspan="2" style="padding: 11px 28px; font-size: 13px; color: #4b5563; border-bottom: none;">
                            採用額合計（確定優先）: <strong style="color: #111827;" x-text="formatMoney(effectiveTotal) + '円'"></strong>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- 収支シミュレーション --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-4">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">収支シミュレーション</h2>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            {{-- パターンA --}}
            <div class="flex flex-col rounded-lg p-5" style="background: #f8fffe; border: 1px solid #d1fae5;">
                <div class="text-sm font-bold mb-3.5" style="padding-bottom: 10px; border-bottom: 1px solid #d1fae5;">パターンA: 販売価格仮置き</div>
                <div class="mb-3">
                    <div class="text-xs text-gray-600 font-medium mb-1">販売価格（税抜）</div>
                    <input type="number" x-model.number="simA.sellingPrice"
                           class="w-full px-3 border border-gray-300 rounded-md text-right font-semibold focus:border-emerald-500 focus:outline-none"
                           style="font-size: 16px; height: 42px;">
                </div>
                <div class="mb-3.5">
                    <div class="text-xs text-gray-600 font-medium mb-0.5">原価合計（採用額）</div>
                    <div class="text-base font-semibold" x-text="formatMoney(effectiveTotal) + '円'"></div>
                </div>
                <div class="flex justify-between" style="align-items: flex-end; margin-top: auto; border-top: 2px solid #86efac; padding-top: 14px;">
                    <div>
                        <div class="text-xs text-gray-600 font-medium mb-0.5">粗利額</div>
                        <div class="font-bold text-emerald-600" style="font-size: 22px;" x-text="formatMoney(simA.sellingPrice - effectiveTotal) + '円'"></div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-gray-600 font-medium mb-0.5">粗利率</div>
                        <div class="font-bold text-emerald-600" style="font-size: 22px;" x-text="simARate"></div>
                    </div>
                </div>
            </div>

            {{-- パターンB --}}
            <div class="flex flex-col rounded-lg p-5" style="background: #f8fffe; border: 1px solid #d1fae5;">
                <div class="text-sm font-bold mb-3.5" style="padding-bottom: 10px; border-bottom: 1px solid #d1fae5;">パターンB: 目標粗利率から逆算</div>
                <div class="mb-3">
                    <div class="text-xs text-gray-600 font-medium mb-1">目標粗利率（%）</div>
                    <input type="number" x-model.number="simB.targetRate" step="0.1"
                           class="w-full px-3 border border-gray-300 rounded-md text-right font-semibold focus:border-emerald-500 focus:outline-none"
                           style="font-size: 16px; height: 42px;">
                </div>
                <div class="mb-3.5">
                    <div class="text-xs text-gray-600 font-medium mb-0.5">原価合計（採用額）</div>
                    <div class="text-base font-semibold" x-text="formatMoney(effectiveTotal) + '円'"></div>
                </div>
                <div class="flex justify-between" style="align-items: flex-end; margin-top: auto; border-top: 2px solid #86efac; padding-top: 14px;">
                    <div>
                        <div class="text-xs text-gray-600 font-medium mb-0.5">必要販売価格</div>
                        <div class="font-bold text-emerald-600" style="font-size: 22px;" x-text="formatMoney(simBPrice) + '円'"></div>
                    </div>
                    <div class="text-right">
                        <div class="text-xs text-gray-600 font-medium mb-0.5">粗利額</div>
                        <div class="font-bold text-emerald-600" style="font-size: 22px;" x-text="formatMoney(simBPrice - effectiveTotal) + '円'"></div>
                    </div>
                </div>
            </div>
        </div>
        <div style="margin-top: 10px; font-size: 12px; color: #6b7280;">※ シミュレーションは保存されません。入力値に応じてリアルタイムで計算されます。</div>
    </div>

    {{-- 区画管理（サマリー + 簡易テーブル + リンク） --}}
    @php
        $lotCount = $project->lots->count();
        $soldCount = $project->getSoldLotCount();
        $lotSellingTotal = $project->getLotSellingPriceTotal();
        $allHave = $project->allLotsHaveSellingPrice();
    @endphp
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
            <div class="flex items-center gap-2">
                <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
                <h2 class="text-base font-bold text-gray-900">区画管理</h2>
            </div>
            <a href="{{ route('realestate.projects.lots', $project) }}"
               class="px-3.5 py-1.5 bg-emerald-600 text-white text-sm font-semibold rounded-md hover:bg-emerald-700 transition-colors"
               style="font-size: 13px; display: inline-flex; align-items: center; gap: 6px;">
                区画管理を開く
                <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
        </div>
        <div class="border border-gray-200 rounded-md overflow-hidden" style="display: grid; grid-template-columns: repeat(6, 1fr);">
            <div style="padding: 14px 16px; text-align: center; border-right: 1px solid #e5e7eb;">
                <div style="font-size: 11px; color: #6b7280; font-weight: 500; margin-bottom: 4px;">区画数</div>
                <div style="font-size: 16px; font-weight: 700;">{{ $lotCount }}</div>
            </div>
            <div style="padding: 14px 16px; text-align: center; border-right: 1px solid #e5e7eb;">
                <div style="font-size: 11px; color: #6b7280; font-weight: 500; margin-bottom: 4px;">成約</div>
                <div style="font-size: 16px; font-weight: 700; color: #059669;">{{ $soldCount }}</div>
            </div>
            <div style="padding: 14px 16px; text-align: center; border-right: 1px solid #e5e7eb;">
                <div style="font-size: 11px; color: #6b7280; font-weight: 500; margin-bottom: 4px;">販売価格合計</div>
                <div style="font-size: 16px; font-weight: 700;">{{ $lotSellingTotal ? number_format($lotSellingTotal) . '円' : '—' }}</div>
            </div>
            <div style="padding: 14px 16px; text-align: center; border-right: 1px solid #e5e7eb;">
                <div style="font-size: 11px; color: #6b7280; font-weight: 500; margin-bottom: 4px;">原価合計</div>
                <div style="font-size: 16px; font-weight: 700;" x-text="formatMoney(effectiveTotal) + '円'"></div>
            </div>
            <div style="padding: 14px 16px; text-align: center; border-right: 1px solid #e5e7eb;">
                <div style="font-size: 11px; color: #6b7280; font-weight: 500; margin-bottom: 4px;">粗利合計</div>
                <div style="font-size: 16px; font-weight: 700; color: #059669;" x-text="lotProfitDisplay"></div>
            </div>
            <div style="padding: 14px 16px; text-align: center;">
                <div style="font-size: 11px; color: #6b7280; font-weight: 500; margin-bottom: 4px;">粗利率</div>
                <div style="font-size: 16px; font-weight: 700; color: #059669;" x-text="lotProfitRateDisplay"></div>
            </div>
        </div>
        @if($lotCount > 0)
        <div class="border border-gray-200 rounded-md overflow-hidden" style="margin-top: 12px;">
            <table class="w-full border-collapse">
                <thead>
                    <tr>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">号地</th>
                        <th class="py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="text-align: right; padding-right: 16px;">面積</th>
                        <th class="py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="text-align: right; padding-right: 16px;">坪数</th>
                        <th class="py-2.5 text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="text-align: right; padding-right: 16px;">販売価格</th>
                        <th class="px-3 py-2.5 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">ステータス</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($project->lots as $lot)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2.5 border-b border-gray-100 text-sm text-center">{{ $lot->lot_number }}</td>
                        <td class="py-2.5 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">{{ $lot->area_sqm }} ㎡</td>
                        <td class="py-2.5 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">{{ $lot->area_tsubo }} 坪</td>
                        <td class="py-2.5 border-b border-gray-100 text-sm whitespace-nowrap" style="text-align: right; padding-right: 16px;">
                            @if($lot->selling_price)
                                {{ number_format($lot->selling_price) }}円
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-3 py-2.5 border-b border-gray-100 text-center whitespace-nowrap"><span class="badge {{ $lot->status->badgeClass() }}">{{ $lot->status->label() }}</span></td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- 添付ファイル --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        @include('components.attachment-section', [
            'attachments' => $attachments,
            'deletedAttachments' => $deletedAttachments,
            'attachableType' => 'projects',
            'attachableId' => $project->id,
        ])
    </div>

    {{-- 備考 --}}
    @if($project->notes)
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-4">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">備考</h2>
        </div>
        <div class="text-sm text-gray-800" style="line-height: 1.8;">{!! nl2br(e($project->notes)) !!}</div>
    </div>
    @endif

</div>

{{-- SheetJS（試算表 Excel 取込用）— CLAUDE.md ルール: cdn.jsdelivr.net のみ許可 --}}
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

{{-- 試算表 Excel/CSV 取込の Alpine factory（仕入れ案件・分譲地PJ 共用） --}}
@include('realestate._partials._cost_excel_import_script')

<script>
function projectDetail() {
    var costExcelEi = costExcelImporterFactory({
        baseUrl:         '{{ url("/realestate/projects/" . $project->id . "/costs/bulk-import") }}',
        csrf:            document.querySelector('meta[name="csrf-token"]').content,
        costItems:       @json($costItemsForJs),
        costAliasMap:    @json($costAliasMap),
        costSkipList:    @json($costSkipList),
        costSubtotalKws: @json($costSubtotalKws)
    });
    return Object.assign({
        costs: @json($costsForJs),
        costItems: @json($costItemsForJs),
        showAddCost: false,
        costMessage: '',
        editingCostId: null,
        editCost: { estimated_amount: '', actual_amount: null, notes: '' },
        newCost: { cost_item_id: '', estimated_amount: '', actual_amount: null, notes: '' },
        simA: { sellingPrice: {{ $project->target_selling_price ?? 0 }} },
        simB: { targetRate: 20 },
        // 区画管理セクション連動用
        lotSellingTotal: {{ $lotSellingTotal }},
        allLotsHavePrice: {{ $allHave ? 'true' : 'false' }},

        get lotProfitDisplay() {
            if (!this.allLotsHavePrice) return '—';
            return this.formatMoney(this.lotSellingTotal - this.effectiveTotal) + '円';
        },
        get lotProfitRateDisplay() {
            if (!this.allLotsHavePrice || !this.lotSellingTotal) return '—';
            var rate = ((this.lotSellingTotal - this.effectiveTotal) / this.lotSellingTotal * 100);
            return rate.toFixed(1) + '%';
        },
        token: document.querySelector('meta[name="csrf-token"]').content,
        baseUrl: '{{ url("/realestate/projects/" . $project->id . "/costs") }}',

        get estimatedTotal() {
            var t = 0;
            for (var i = 0; i < this.costs.length; i++) { t += (this.costs[i].estimated_amount || 0); }
            return t;
        },
        get actualTotal() {
            var t = 0;
            for (var i = 0; i < this.costs.length; i++) {
                if (this.costs[i].actual_amount !== null && this.costs[i].actual_amount !== '') {
                    t += Number(this.costs[i].actual_amount);
                }
            }
            return t;
        },
        get effectiveTotal() {
            var t = 0;
            for (var i = 0; i < this.costs.length; i++) {
                var c = this.costs[i];
                t += (c.actual_amount !== null && c.actual_amount !== '') ? Number(c.actual_amount) : (c.estimated_amount || 0);
            }
            return t;
        },
        get simARate() {
            if (!this.simA.sellingPrice || this.simA.sellingPrice === 0) return '—';
            var rate = ((this.simA.sellingPrice - this.effectiveTotal) / this.simA.sellingPrice * 100);
            return rate.toFixed(1) + '%';
        },
        get simBPrice() {
            if (!this.simB.targetRate || this.simB.targetRate >= 100) return 0;
            return Math.ceil(this.effectiveTotal / (1 - this.simB.targetRate / 100));
        },

        formatMoney: function(val) {
            if (val === null || val === undefined || isNaN(val)) return '0';
            return Number(val).toLocaleString('ja-JP');
        },

        addCost: function() {
            if (!this.newCost.cost_item_id || !this.newCost.estimated_amount) return;
            var self = this;
            var body = {
                cost_item_id: Number(self.newCost.cost_item_id),
                estimated_amount: Number(self.newCost.estimated_amount),
                actual_amount: self.newCost.actual_amount ? Number(self.newCost.actual_amount) : null,
                notes: self.newCost.notes || null
            };
            fetch(self.baseUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': self.token, 'Accept': 'application/json' },
                body: JSON.stringify(body)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    self.costs.push(data.cost);
                    self.newCost = { cost_item_id: '', estimated_amount: '', actual_amount: null, notes: '' };
                    self.showAddCost = false;
                    self.showMessage('費用を追加しました。');
                }
            })
            .catch(function() { alert('費用の追加に失敗しました。'); });
        },

        startEditCost: function(cost) {
            this.editingCostId = cost.id;
            this.editCost = {
                estimated_amount: cost.estimated_amount,
                actual_amount: cost.actual_amount,
                notes: cost.notes || ''
            };
        },
        cancelEditCost: function() {
            this.editingCostId = null;
        },
        saveCost: function(cost) {
            var self = this;
            var body = {
                estimated_amount: Number(self.editCost.estimated_amount),
                actual_amount: self.editCost.actual_amount !== null && self.editCost.actual_amount !== '' ? Number(self.editCost.actual_amount) : null,
                notes: self.editCost.notes || null
            };
            fetch(self.baseUrl + '/' + cost.id, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': self.token, 'Accept': 'application/json' },
                body: JSON.stringify(body)
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    for (var i = 0; i < self.costs.length; i++) {
                        if (self.costs[i].id === cost.id) {
                            self.costs[i] = data.cost;
                            break;
                        }
                    }
                    self.editingCostId = null;
                    self.showMessage('費用を更新しました。');
                }
            })
            .catch(function() { alert('費用の更新に失敗しました。'); });
        },

        deleteCost: function(cost) {
            if (!confirm('「' + cost.cost_item_name + '」を削除しますか？')) return;
            var self = this;
            fetch(self.baseUrl + '/' + cost.id, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': self.token, 'Accept': 'application/json' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    self.costs = self.costs.filter(function(c) { return c.id !== cost.id; });
                    self.showMessage('費用を削除しました。');
                }
            })
            .catch(function() { alert('費用の削除に失敗しました。'); });
        },

        showMessage: function(msg) {
            var self = this;
            self.costMessage = msg;
            setTimeout(function() { self.costMessage = ''; }, 3000);
        }
    }, costExcelEi);
}
</script>

@if($project->latitude && $project->longitude)
<script>
function initDetailMap() {
    var lat = {{ $project->latitude }};
    var lng = {{ $project->longitude }};

    var map = new google.maps.Map(document.getElementById('detail-map'), {
        center: { lat: lat, lng: lng },
        zoom: 17,
        mapTypeControl: true,
        streetViewControl: true,
        fullscreenControl: false
    });

    var marker = new google.maps.Marker({
        position: { lat: lat, lng: lng },
        map: map,
        title: '{{ $project->project_name }}'
    });

    var infoWindow = new google.maps.InfoWindow({
        content: '<div style="font-size:13px;"><strong>{{ $project->project_name }}</strong><br>{{ $project->address }}</div>'
    });
    infoWindow.open(map, marker);
}
</script>

{{-- マップモーダル（所在地行のボタンから開く） --}}
<div id="map-modal-overlay" onclick="if(event.target===this)closeMapModal()"
     style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 10000; align-items: center; justify-content: center;">
    <div style="background: #fff; border-radius: 10px; width: 90%; max-width: 700px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid #e5e7eb;">
            <div>
                <div id="modal-map-title" style="font-size: 15px; font-weight: 700;"></div>
                <div id="modal-map-address" style="font-size: 12px; color: #6b7280; margin-top: 2px;"></div>
            </div>
            <button onclick="closeMapModal()" style="background: none; border: none; font-size: 22px; color: #6b7280; cursor: pointer; padding: 0 4px; line-height: 1;">&times;</button>
        </div>
        <div id="modal-map-container" style="height: 400px;"></div>
    </div>
</div>

<script>
var modalMap = null;
var modalMarker = null;
var modalInfoWindow = null;

function openMapModal(name, address, lat, lng) {
    document.getElementById('modal-map-title').textContent = name;
    document.getElementById('modal-map-address').textContent = address;
    var overlay = document.getElementById('map-modal-overlay');
    overlay.style.display = 'flex';

    setTimeout(function() {
        if (!modalMap) {
            modalMap = new google.maps.Map(document.getElementById('modal-map-container'), {
                center: { lat: lat, lng: lng },
                zoom: 16,
                mapTypeControl: true,
                streetViewControl: true,
                fullscreenControl: false
            });
            modalMarker = new google.maps.Marker({ position: { lat: lat, lng: lng }, map: modalMap });
            modalInfoWindow = new google.maps.InfoWindow();
        } else {
            modalMap.setCenter({ lat: lat, lng: lng });
            modalMarker.setPosition({ lat: lat, lng: lng });
        }
        modalInfoWindow.setContent('<div style="font-size:13px;"><strong>' + name + '</strong><br>' + address + '</div>');
        modalInfoWindow.open(modalMap, modalMarker);
        google.maps.event.trigger(modalMap, 'resize');
    }, 150);
}

function closeMapModal() {
    document.getElementById('map-modal-overlay').style.display = 'none';
}
</script>
<script src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.api_key') }}&callback=initDetailMap&language=ja&region=JP" async defer></script>
@endif

{{-- ステータスバッジCSS --}}
<style>
.badge-prj-info { background: #dbeafe; color: #1e40af; }
.badge-prj-assess { background: #fce7f3; color: #9d174d; }
.badge-prj-negotiate { background: #fed7aa; color: #9a3412; }
.badge-prj-contracted { background: #fef3c7; color: #92400e; }
.badge-prj-settled { background: #a7f3d0; color: #064e3b; }
.badge-prj-selling { background: #c7d2fe; color: #3730a3; }
.badge-prj-soldout { background: #86efac; color: #14532d; }
.badge-prj-lost { background: #e5e7eb; color: #374151; }
.badge-lot-unsold { background: #f3f4f6; color: #374151; }
.badge-lot-onsale { background: #dbeafe; color: #1e40af; }
.badge-lot-negotiating { background: #fed7aa; color: #9a3412; }
.badge-lot-sold { background: #a7f3d0; color: #064e3b; }
</style>

@endsection
