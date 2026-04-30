@extends('layouts.app')

@section('title', 'テナントダッシュボード')

@section('breadcrumb')
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="text-gray-600">テナントダッシュボード</span>
@endsection

@section('content')
{{-- テナントダッシュボード全体スタイル（モック docs/mockups/dashboard/tenant.html を移植） --}}
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
    --green-100: #d1fae5;
    --green-600: #059669;
    --green-700: #047857;

    --red-50:   #fef2f2;
    --red-100:  #fee2e2;
    --red-600:  #dc2626;
    --red-700:  #b91c1c;

    --amber-50:  #fffbeb;
    --amber-100: #fef3c7;
    --amber-600: #d97706;
    --amber-700: #b45309;

    --blue-50:  #eff6ff;
    --blue-100: #dbeafe;
    --blue-600: #2563eb;
    --blue-700: #1d4ed8;

    --shadow-sm: 0 1px 3px rgba(0,0,0,.07), 0 1px 2px rgba(0,0,0,.05);
    --shadow-md: 0 4px 12px rgba(0,0,0,.08), 0 2px 4px rgba(0,0,0,.05);
}

/* ページラッパー */
.tenant-dashboard { max-width: 1360px; margin: 0 auto; }

.tenant-dashboard .page-title {
    font-size: 17px;
    font-weight: 600;
    color: var(--gray-700);
    letter-spacing: 0;
    margin: 0 0 28px;
}

/* セクション */
.tenant-dashboard .section { margin-bottom: 32px; }

.tenant-dashboard .section-heading {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 16px;
}

.tenant-dashboard .section-accent {
    width: 4px;
    height: 20px;
    border-radius: 2px;
    flex-shrink: 0;
}
.tenant-dashboard .section-accent.teal { background: var(--green-600); }

.tenant-dashboard .section-label {
    font-size: 14px;
    font-weight: 700;
    color: var(--gray-700);
    letter-spacing: .02em;
}

.tenant-dashboard .section-divider {
    flex: 1;
    height: 1px;
    background: var(--gray-200);
}

/* =========================================================
   実績：メインカード（全体合計）
   ========================================================= */
.tenant-dashboard .summary-main {
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: 14px;
    padding: 22px 26px;
    box-shadow: var(--shadow-sm);
    position: relative;
    overflow: hidden;
}

.tenant-dashboard .summary-main::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    border-radius: 14px 14px 0 0;
    background: var(--green-600);
}

.tenant-dashboard .summary-main-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 18px;
}

.tenant-dashboard .summary-main-badge {
    display: inline-block;
    padding: 3px 12px;
    background: var(--gray-700);
    color: #fff;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.1em;
}

.tenant-dashboard .summary-main-title {
    font-size: 13px;
    color: var(--gray-500);
    font-weight: 500;
}

.tenant-dashboard .summary-main-grid {
    display: grid;
    grid-template-columns: 1fr 1px 1fr;
    gap: 0;
}

.tenant-dashboard .summary-main-cell { padding: 0 24px; }
.tenant-dashboard .summary-main-cell:first-child { padding-left: 0; }
.tenant-dashboard .summary-main-cell:last-child  { padding-right: 0; }

.tenant-dashboard .summary-main-label {
    font-size: 12px;
    color: var(--gray-500);
    font-weight: 500;
    margin-bottom: 6px;
}

.tenant-dashboard .summary-main-value {
    font-size: 24px;
    font-weight: 700;
    line-height: 1.1;
    letter-spacing: -.01em;
    color: var(--gray-900);
}

.tenant-dashboard .summary-main-unit {
    font-size: 14px;
    font-weight: 500;
    margin-left: 4px;
    color: var(--gray-500);
}

.tenant-dashboard .summary-main-breakdown {
    font-size: 11px;
    color: var(--gray-500);
    margin-top: 8px;
    letter-spacing: 0;
}

.tenant-dashboard .summary-main-breakdown strong {
    color: var(--gray-700);
    font-weight: 600;
}

.tenant-dashboard .summary-main-divider {
    background: var(--gray-200);
    width: 1px;
}

/* =========================================================
   実績：ビル別カード
   ========================================================= */
.tenant-dashboard .building-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-top: 16px;
}

.tenant-dashboard .building-card {
    background: #fff;
    border: 1px solid var(--gray-200);
    border-radius: 12px;
    padding: 16px 18px;
    box-shadow: var(--shadow-sm);
    transition: box-shadow .2s;
    text-decoration: none;
    color: inherit;
    display: block;
}

.tenant-dashboard .building-card:hover { box-shadow: var(--shadow-md); }

.tenant-dashboard .building-card-name {
    font-size: 13px;
    font-weight: 700;
    color: var(--gray-700);
    margin-bottom: 12px;
    border-bottom: 1px solid var(--gray-100);
    padding-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
}

/* ビル名左の縦アクセント（黄色系） */
.tenant-dashboard .building-card-name::before {
    content: '';
    display: block;
    width: 3px;
    height: 14px;
    background: var(--amber-600);
    border-radius: 2px;
    flex-shrink: 0;
}

.tenant-dashboard .building-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

.tenant-dashboard .building-stat-label {
    font-size: 11px;
    color: var(--gray-500);
    margin-bottom: 4px;
}

.tenant-dashboard .building-stat-value {
    font-size: 18px;
    font-weight: 700;
    color: var(--gray-900);
    letter-spacing: -.01em;
}

.tenant-dashboard .building-stat-unit {
    font-size: 12px;
    color: var(--gray-500);
    font-weight: 500;
    margin-left: 3px;
}

.tenant-dashboard .building-empty {
    grid-column: 1 / -1;
    background: #fff;
    border: 1px dashed var(--gray-200);
    border-radius: 12px;
    padding: 32px;
    text-align: center;
    color: var(--gray-400);
    font-size: 13px;
}

/* レスポンシブ */
@media (max-width: 1100px) {
    .tenant-dashboard .building-grid { grid-template-columns: 1fr 1fr; }
}

@media (max-width: 600px) {
    .tenant-dashboard .building-grid { grid-template-columns: 1fr; }
    .tenant-dashboard .summary-main-grid { grid-template-columns: 1fr; gap: 16px; }
    .tenant-dashboard .summary-main-divider { display: none; }
    .tenant-dashboard .summary-main-cell { padding: 0; }
}
</style>

<div class="tenant-dashboard">
    <h1 class="page-title">テナントダッシュボード</h1>

    <div class="section">
        @include('dashboard._tenant_summary_main')

        {{-- 「実績」サブタイトル（全体カードとビル別カードの間: 前月の月数） --}}
        <div class="section-heading" style="margin-top: 40px;">
            <div class="section-accent teal"></div>
            <span class="section-label">{{ $previousMonthLabel }}</span>
            <div class="section-divider"></div>
        </div>

        @include('dashboard._tenant_buildings')
    </div>
</div>
@endsection
