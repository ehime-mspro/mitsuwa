@extends('layouts.app')

@section('title', $building->name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.properties.index') }}" class="hover:text-emerald-600 transition-colors">テナント管理</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('tenant.area-buildings.index') }}" class="hover:text-emerald-600 transition-colors">周辺ビル調査</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $building->name }}</span>
@endsection

@section('content')
<div x-data="{ showDeleteModal: false }">

    {{-- ヘッダー
         ⚠ 「戻る」はヘッダーのボタン列に置く（show 画面の流儀。realestate/procurements/show.blade.php
           23-25 行等を参照。create/edit は独立リンクで正しい。上に単独で置いていたのは create の
           流儀を show に誤って適用したもの）。
         ⚠ Task 8 がこのボタン列に編集・削除ボタンを追加する。既存の列に足すだけで済むよう、
           先にこの div を用意しておく。 --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <h1 class="text-lg max-lg:text-base font-bold text-gray-900">{{ $building->name }}</h1>
        <div style="display: flex; gap: 8px; align-items: center;">
            <a href="{{ route('tenant.area-buildings.index') }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; background: #fff;">周辺ビル調査に戻る</a>
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('tenant.area-buildings.edit', $building) }}"
                   style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #047857; border: 1px solid #a7f3d0; border-radius: 6px; text-decoration: none; background: #ecfdf5;">編集</a>
            @endif
            @if(auth()->user()->role->isExecutive())
                <button @click="showDeleteModal = true"
                        style="padding: 6px 16px; font-size: 13px; font-weight: 600; color: #b91c1c; border: 1px solid #fecaca; border-radius: 6px; background: #fef2f2; cursor: pointer;">削除</button>
            @endif
        </div>
    </div>

    {{-- ヘッダ --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <div class="text-xs font-semibold text-gray-500 mb-1">所在地</div>
                <div class="text-sm text-gray-800">{{ $building->address ?: '—' }}</div>
            </div>
            <div>
                <div class="text-xs font-semibold text-gray-500 mb-1">総階数</div>
                <div class="text-sm text-gray-800">{{ $building->totalFloorsLabel() }}</div>
            </div>
        </div>

        @if($latestSurvey)
            <div class="mt-4 pt-4 border-t border-gray-200">
                <div class="text-xs font-semibold text-gray-500 mb-2">最新調査（{{ $latestSurvey->monthLabel() }}）</div>
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                    <span class="text-sm text-gray-700">営業 <strong class="text-gray-900">{{ $latestSurvey->operating_count }}</strong></span>
                    <span class="text-sm text-gray-700">空き <strong class="text-gray-900">{{ $latestSurvey->vacant_count }}</strong></span>
                    <span class="text-sm text-gray-700">不明 <strong class="text-gray-900">{{ $latestSurvey->unknown_count }}</strong></span>
                    <span class="text-sm text-gray-700">空室率 <strong class="text-base text-gray-900">{{ $latestSurvey->vacancyRateLabel() }}</strong></span>
                </div>
            </div>
        @else
            <div class="mt-4 pt-4 border-t border-gray-200 text-sm text-gray-400">調査データがまだありません。</div>
        @endif
    </div>

    {{-- 乖離の警告（Bug #46） --}}
    @if($divergence)
        <div class="mb-3 rounded-lg border border-amber-300 bg-amber-50 p-4">
            <div class="text-sm font-bold text-amber-800 mb-2">調査時の実測とテナント明細が一致していません</div>
            <p class="text-xs text-amber-900 mb-2">
                空室率などの数字は<strong>調査時の実測（入力値）</strong>を正として算出しています。
                テナント明細の入力が途中の可能性があります。
            </p>
            <div class="scroll-hint at-start">
                <div class="scroll-hint-inner">
                    <table class="border-collapse" style="min-width:360px;">
                        <thead>
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-bold text-amber-900"></th>
                                <th class="px-3 py-2 text-center text-xs font-bold text-amber-900">営業</th>
                                <th class="px-3 py-2 text-center text-xs font-bold text-amber-900">空き</th>
                                <th class="px-3 py-2 text-center text-xs font-bold text-amber-900">不明</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="px-3 py-2 text-xs font-semibold text-amber-900 whitespace-nowrap">調査時の実測（入力値）</td>
                                <td class="px-3 py-2 text-center text-sm text-amber-900">{{ $divergence['input']['operating'] }}</td>
                                <td class="px-3 py-2 text-center text-sm text-amber-900">{{ $divergence['input']['vacant'] }}</td>
                                <td class="px-3 py-2 text-center text-sm text-amber-900">{{ $divergence['input']['unknown'] }}</td>
                            </tr>
                            <tr>
                                <td class="px-3 py-2 text-xs font-semibold text-amber-900 whitespace-nowrap">テナント明細からの集計</td>
                                <td class="px-3 py-2 text-center text-sm text-amber-900">{{ $divergence['counted']['operating'] }}</td>
                                <td class="px-3 py-2 text-center text-sm text-amber-900">{{ $divergence['counted']['vacant'] }}</td>
                                <td class="px-3 py-2 text-center text-sm text-amber-900">{{ $divergence['counted']['unknown'] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- 位置（埋め込み地図は置かない。Google マップへのリンクのみ。設計 §6.0） --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">位置</div>
        @if($building->googleMapsUrl())
            <a href="{{ $building->googleMapsUrl() }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1.5 text-sm font-semibold text-emerald-600 hover:text-emerald-700 hover:underline transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                Google マップで開く
            </a>
            <div class="mt-2 text-xs text-gray-500">緯度 {{ $building->latitude }} / 経度 {{ $building->longitude }}</div>
        @else
            <div class="text-sm text-gray-400">位置未登録です。編集画面の「マップで確認」から座標を登録できます。</div>
        @endif
    </div>

    {{-- 調査履歴 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="flex items-center justify-between pb-2 mb-3.5 border-b border-gray-200">
            <div class="text-sm font-bold text-gray-800">調査履歴</div>
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('tenant.area-buildings.surveys.create', $building) }}"
                   class="text-xs font-semibold text-emerald-700 px-3 py-1.5 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100 transition-colors">
                    調査を追加
                </a>
            @endif
        </div>
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                {{-- ⚠ min-width は 760 → 900px。「操作」列を足したので、狭い画面で編集/削除ボタン
                     （実測で約 106px 必要）が隣のセルへはみ出さないだけの幅を確保する --}}
                <table class="w-full border-collapse" style="table-layout:fixed; min-width:900px;">
                    {{-- ⚠ 下の入居テナントのテーブルと構造がほぼ同じなので、置換するときは必ずこのコメントを目印にすること
                         （下の入居テナントの表にも同じ「操作」列がある）。 --}}
                    <colgroup>
                        <col style="width:13%"><col style="width:8%"><col style="width:8%"><col style="width:8%">
                        <col style="width:11%"><col style="width:14%"><col style="width:22%"><col style="width:16%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">調査年月</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">営業</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">空き</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">不明</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">空室率</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">調査者</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">所見</th>
                            <th class="px-2 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($surveys as $survey)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 border-b border-gray-200 text-sm font-semibold text-gray-900 whitespace-nowrap">{{ $survey->monthLabel() }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-center text-gray-700">{{ $survey->operating_count }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-center text-gray-700">{{ $survey->vacant_count }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-center text-gray-700">{{ $survey->unknown_count }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-center font-bold text-gray-900">{{ $survey->vacancyRateLabel() }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700">{{ $survey->surveyor?->name ?? '—' }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700">{{ $survey->notes ?: '—' }}</td>
                                <td class="px-2 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                                    <div class="flex gap-1.5 justify-center">
                                        @if(auth()->user()->role->isManagerOrAbove())
                                            <a href="{{ route('tenant.area-buildings.surveys.edit', [$building, $survey]) }}"
                                               class="text-xs font-semibold text-emerald-700 px-3 py-1 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100 transition-colors">編集</a>
                                        @endif
                                        @if(auth()->user()->role->isExecutive())
                                            {{-- ⚠ 行ごとの削除なので <x-delete-confirm-modal> は使えない（このページ末尾のコメント参照）。
                                                 ⚠ JS 文字列への差し込みは Js::from()。生の {{ }} だと `'` を含む値で壊れる。
                                                    前例: zeal/plans/index.blade.php:143 --}}
                                            <form method="POST" action="{{ route('tenant.area-buildings.surveys.destroy', [$building, $survey]) }}"
                                                  onsubmit="return confirm({{ \Illuminate\Support\Js::from($survey->monthLabel()) }} + ' の調査を削除します。よろしいですか？');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="text-xs font-semibold text-red-700 px-3 py-1 border border-red-200 rounded bg-red-50 hover:bg-red-100 transition-colors">削除</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-5 py-8 text-center text-sm text-gray-400">調査履歴がありません。</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>
    </div>

    {{-- 入居テナント --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="flex items-center justify-between pb-2 mb-3.5 border-b border-gray-200">
            <div class="text-sm font-bold text-gray-800">入居テナント（現況 {{ $activeTenants->count() }} 件）</div>
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('tenant.area-buildings.tenants.create', $building) }}"
                   class="text-xs font-semibold text-emerald-700 px-3 py-1.5 border border-emerald-200 rounded bg-emerald-50 hover:bg-emerald-100 transition-colors">
                    テナントを追加
                </a>
            @endif
        </div>
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
                {{-- ⚠ min-width は 720 → 900px。「操作」列を足したので、狭い画面で編集/削除ボタン
                     （実測で約 106px 必要）が隣のセルへはみ出さないだけの幅を確保する --}}
                <table class="w-full border-collapse" style="table-layout:fixed; min-width:900px;">
                    {{-- ⚠ 上の調査履歴のテーブルと構造がほぼ同じなので、置換するときは必ずこのコメントを目印にすること。
                         ⚠ 幅の合計は 100% にする（AreaBuildingTenantCrudTest が固定している）。 --}}
                    <colgroup>
                        <col style="width:8%"><col style="width:12%"><col style="width:26%">
                        <col style="width:14%"><col style="width:10%"><col style="width:14%"><col style="width:16%">
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">階</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">部屋番号</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">テナント名</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">業種</th>
                            <th class="px-4 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">状態</th>
                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">最終確認日</th>
                            <th class="px-2 py-3 text-center text-xs font-bold text-gray-600 bg-gray-50 border-b border-gray-200 whitespace-nowrap">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($activeTenants as $tenant)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-center text-gray-700 whitespace-nowrap">{{ $tenant->floorLabel() }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700">{{ $tenant->room_number ?: '—' }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm font-semibold text-gray-900">{{ $tenant->name ?: '—' }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700">{{ $tenant->industry ?: '—' }}</td>
                                <td class="px-4 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                                    <span class="badge" style="{{ $tenant->status->badgeStyle() }}">{{ $tenant->status->label() }}</span>
                                </td>
                                <td class="px-4 py-3 border-b border-gray-200 text-sm text-gray-700 whitespace-nowrap">{{ $tenant->confirmed_on?->format('Y/m/d') ?? '—' }}</td>
                                <td class="px-2 py-3 border-b border-gray-200 text-center whitespace-nowrap">
                                    <div class="flex gap-1.5 justify-center">
                                        @include('tenant.area-buildings.tenants._row_actions', ['building' => $building, 'tenant' => $tenant])
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-5 py-8 text-center text-sm text-gray-400">入居テナントの明細がありません。</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>

        @if($movedOutTenants->isNotEmpty())
            <details class="mt-4">
                <summary class="text-xs font-semibold text-gray-500 cursor-pointer hover:text-gray-700">
                    退去済み {{ $movedOutTenants->count() }} 件を表示
                </summary>
                {{-- ⚠ 退去済みにも操作を出す。出さないと退去日の打ち間違いが二度と直せない
                     （行は現況リストから消えるので、編集画面への入口がここしか無い） --}}
                <ul class="mt-2 space-y-1.5">
                    @foreach($movedOutTenants as $tenant)
                        <li class="flex flex-wrap items-center gap-2 text-xs text-gray-500">
                            <span>
                                {{ $tenant->floorLabel() }} {{ $tenant->room_number }} {{ $tenant->name ?: '（名称なし）' }}
                                <span class="text-gray-400">— {{ $tenant->moved_out_on?->format('Y/m/d') }} 退去</span>
                            </span>
                            @include('tenant.area-buildings.tenants._row_actions', ['building' => $building, 'tenant' => $tenant])
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>

    {{-- ⚠ このページ（ビル本体）の削除は 1 画面 1 対象なのでモーダル（<x-delete-confirm-modal>）を使う
         （tenant モジュールの他の詳細画面 5/5 と同じ流儀。Minor M-1 2026-08-17）。
         Task 9 / 10 が実装する調査回・入居テナントの削除は同じ画面内で行ごとに複数あり、
         このコンポーネントは showDeleteModal という単一の Alpine 変数でしか開閉できず
         1 対象しか持てないため、行ごとの削除には引き続き confirm() を使う。 --}}
    @if(auth()->user()->role->isExecutive())
        <x-delete-confirm-modal
            title="このビルを削除しますか？"
            :action="route('tenant.area-buildings.destroy', $building)"
            :target="$building->name"
            message="調査履歴とテナント明細も画面から見えなくなります。"
        />
    @endif

</div>
@endsection
