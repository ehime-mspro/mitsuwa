{{-- 現在の並び順バー（設計書 2026-08-28 §6 / モック 案C のバー）

使い方（表のすぐ上・フィルターバーやタブより下に置く）:
  <x-sort-bar :sort="$sort" :columns="$sortColumns" default-label="ビル名順" />

props:
  sort         … App\Support\ListSort|null（コントローラから渡す）
  columns      … その画面の SORT_COLUMNS（日本語ラベルと「向きの言い方」）
  defaultLabel … 並び替えていないときに出す既定順の説明（「ビル名順」など）

⚠ **defaultLabel は実際の既定順と必ず揃える。** 片方だけ直すと
   「既定（空室率が高い順）」と書いてあるのに名前順で並ぶ、という嘘になる（設計書 §6）。
   SortBarTest::test_the_area_building_bar_names_the_real_default_order が文言と並びを対で見ている。
⚠ **列名は columns から引く。** 見出し（x-sortable-th）と同じ表を見ることで、
   2 箇所に文字列を置く事故を防ぐ（Bug #41 / #46）。
⚠ 「解除」は**並び順だけ**を消す。フィルタごと初期化する「クリア」ボタンとは役割が違う。
⚠ ヒント文とピルは役割が違うので、テストでも別々にアサートすること（Bug #43 / #46 / #49）。
⚠ JS は 1 行も使わない。ただのリンク。
⚠ 属性式に &quot; を書かないこと。本番の view:cache でだけ 500 になる（Bug #21）。
--}}
@props([
    'sort' => null,
    'columns',
    'defaultLabel',
])
@php
    $column    = $sort === null ? null : $columns[$sort->key];
    $direction = $sort === null ? null : ($sort->isAscending() ? $column['asc'] : $column['desc']);
@endphp
<div class="flex flex-wrap items-center gap-2 mb-2.5 text-xs text-gray-500">
    @if($sort === null)
        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full border border-gray-200 bg-white text-xs font-bold text-gray-600">
            並び替え: 既定（{{ $defaultLabel }}）
        </span>
    @else
        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full border border-emerald-200 bg-emerald-50 text-xs font-bold text-emerald-700">
            並び替え: {{ $column['label'] }} {{ $direction }}
            <span style="flex-shrink: 0; width: 11px; height: 11px;">
                @if($sort->isAscending())
                    <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 15 12 8 18 15"/></svg>
                @else
                    <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 16 18 9"/></svg>
                @endif
            </span>
        </span>
        <a href="{{ \App\Support\ListSort::clearUrl(request()) }}"
           class="px-2 py-0.5 rounded border border-gray-200 bg-white text-[11px] font-semibold text-gray-500 hover:text-gray-700 hover:border-gray-300 hover:bg-gray-50 transition-colors">解除</a>
    @endif
    <span class="inline-flex items-center gap-1.5">
        <svg class="w-3.5 h-3.5 text-gray-400" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        見出しをクリックすると並び替えできます
    </span>
</div>
