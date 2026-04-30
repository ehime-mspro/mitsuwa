@extends('layouts.app')

@section('title', '経営ダッシュボード')

@section('breadcrumb')
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="text-gray-600">経営ダッシュボード</span>
@endsection

@section('content')
{{-- 経営ダッシュボード全体スタイル（モック docs/mockups/dashboard/executive.html を移植） --}}
<style>
:root {
    --gray-50:  #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-800: #1f2937;
    --gray-900: #111827;

    --green-50:  #ecfdf5;
    --green-600: #059669;
    --green-700: #047857;
    --green-800: #065f46;

    --red-50:  #fef2f2;
    --red-600: #dc2626;

    --amber-50: #fffbeb;
    --amber-600: #d97706;

    --blue-50: #eff6ff;
    --blue-600: #2563eb;
    --blue-700: #1d4ed8;

    --shadow-sm: 0 1px 3px rgba(0,0,0,.07), 0 1px 2px rgba(0,0,0,.05);
    --shadow-md: 0 4px 12px rgba(0,0,0,.08), 0 2px 4px rgba(0,0,0,.05);
}

/* ページラッパー */
.exec-dashboard { max-width: 1360px; margin: 0 auto; }

/* ヘッダー */
.exec-dashboard .page-header {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 16px; margin-bottom: 28px;
}
.exec-dashboard .page-title {
    font-size: 17px; font-weight: 600; color: var(--gray-700); letter-spacing: 0;
    margin: 0;
}

/* フィルタバー */
.exec-dashboard .filter-bar {
    display: flex; align-items: center; gap: 10px;
    background: #fff; border: 1px solid var(--gray-200);
    border-radius: 10px; padding: 8px 14px; box-shadow: var(--shadow-sm);
}
.exec-dashboard .filter-bar label {
    font-size: 12px; font-weight: 600; color: var(--gray-400); white-space: nowrap;
}
.exec-dashboard .filter-bar select {
    height: 36px; padding: 0 32px 0 12px;
    border: 1px solid var(--gray-200); border-radius: 7px;
    font-size: 13px; font-weight: 500;
    background: var(--gray-50); color: var(--gray-700);
    cursor: pointer; appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%236b7280'%3E%3Cpath fill-rule='evenodd' d='M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z' clip-rule='evenodd'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 8px center; background-size: 16px;
    transition: border-color .15s;
}
.exec-dashboard .filter-bar select:focus {
    outline: none; border-color: var(--green-600); background-color: #fff;
}
.exec-dashboard .filter-divider { width: 1px; height: 24px; background: var(--gray-200); }

/* セクション */
.exec-dashboard .section { margin-bottom: 48px; }
.exec-dashboard .section-heading { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; }
.exec-dashboard .section-accent {
    width: 4px; height: 20px; border-radius: 2px; flex-shrink: 0;
}
.exec-dashboard .section-accent.teal   { background: var(--green-600); }
.exec-dashboard .section-accent.amber  { background: #f59e0b; }
.exec-dashboard .section-accent.blue   { background: var(--blue-600); }
.exec-dashboard .section-accent.purple { background: #7c3aed; }
.exec-dashboard .section-accent.cyan   { background: #0891b2; }
.exec-dashboard .section-label {
    font-size: 14px; font-weight: 700; color: var(--gray-700); letter-spacing: .02em;
}
.exec-dashboard .section-divider { flex: 1; height: 1px; background: var(--gray-200); }

/* カードグリッド */
.exec-dashboard .card-grid   { display: grid; grid-template-columns: 1fr 1fr;     gap: 16px; }
.exec-dashboard .card-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }

/* KPI カード */
.exec-dashboard .kpi-card {
    background: #fff; border: 1px solid var(--gray-200);
    border-radius: 14px; padding: 24px;
    box-shadow: var(--shadow-sm); transition: box-shadow .2s;
}
.exec-dashboard .kpi-card:hover { box-shadow: var(--shadow-md); }
.exec-dashboard .kpi-card-header {
    display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;
}
.exec-dashboard .kpi-card-title { font-size: 14px; font-weight: 700; color: var(--gray-700); }
.exec-dashboard .kpi-card-link {
    font-size: 12px; color: var(--green-600); text-decoration: none;
    font-weight: 500; white-space: nowrap; flex-shrink: 0;
}
.exec-dashboard .kpi-card-link:hover { text-decoration: underline; }

/* KPI 行リスト */
.exec-dashboard .kpi-list { display: flex; flex-direction: column; gap: 16px; }
.exec-dashboard .kpi-row {
    display: flex; align-items: flex-end; justify-content: space-between;
    padding-bottom: 16px; border-bottom: 1px solid var(--gray-100);
}
.exec-dashboard .kpi-row:last-child { border-bottom: none; padding-bottom: 0; }
.exec-dashboard .kpi-row-label {
    font-size: 13px; color: var(--gray-500); font-weight: 500; margin-bottom: 4px;
}
.exec-dashboard .kpi-row-value {
    font-size: 22px; font-weight: 700; color: var(--gray-900);
    line-height: 1.1; letter-spacing: -.01em;
}
.exec-dashboard .kpi-row-value.profit { color: var(--green-700); }
.exec-dashboard .kpi-row-unit {
    font-size: 13px; color: var(--gray-500); font-weight: 500; margin-left: 4px;
}

/* YoY バッジ */
.exec-dashboard .yoy {
    display: inline-flex; align-items: center; gap: 3px;
    font-size: 12px; font-weight: 700;
    padding: 4px 9px; border-radius: 20px;
    flex-shrink: 0; margin-left: 10px;
}
.exec-dashboard .yoy.up      { color: var(--green-700); background: var(--green-50); }
.exec-dashboard .yoy.down    { color: var(--red-600);   background: var(--red-50); }
.exec-dashboard .yoy.neutral { color: var(--gray-500);  background: var(--gray-100); }

/* グラフカード */
.exec-dashboard .chart-stack { display: flex; flex-direction: column; gap: 16px; }
.exec-dashboard .chart-stack .chart-wrap { height: 230px; }
.exec-dashboard .chart-card {
    background: #fff; border: 1px solid var(--gray-200);
    border-radius: 14px; padding: 24px; box-shadow: var(--shadow-sm);
}
.exec-dashboard .chart-card-header {
    display: flex; align-items: baseline; gap: 10px; margin-bottom: 20px;
}
.exec-dashboard .chart-card-title { font-size: 15px; font-weight: 700; color: var(--gray-800); }
.exec-dashboard .chart-card-sub   { font-size: 12px; color: var(--gray-400); }
.exec-dashboard .chart-wrap       { position: relative; height: 220px; }

/* レスポンシブ */
@media (max-width: 960px) {
    .exec-dashboard .card-grid, .exec-dashboard .chart-grid { grid-template-columns: 1fr; }
    .exec-dashboard .card-grid-3 { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 640px) {
    .exec-dashboard .card-grid-3 { grid-template-columns: 1fr; }
    .exec-dashboard .filter-bar { padding: 6px 10px; gap: 6px; }
}
</style>

<div class="exec-dashboard">
    @include('dashboard._executive_filter')
    @include('dashboard._executive_tenant')
    @include('dashboard._executive_mansion')
    @include('dashboard._executive_housing')
    @include('dashboard._executive_realestate')
</div>

@include('dashboard._executive_charts')
@endsection
