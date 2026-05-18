@extends('layouts.app')

@section('title', '本部 Sheet URL 設定 — ' . $simulation->fiscal_year . '年度経営試算表')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>ZEAL</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.simulations.index', ['list' => 1]) }}" class="text-gray-500 hover:text-emerald-600">経営試算表</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.simulations.show', $simulation) }}" class="text-gray-500 hover:text-emerald-600">{{ $simulation->fiscal_year }}年度</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">本部 Sheet URL 設定</span>
@endsection

@section('content')
<div style="max-width: 720px;">
    <h1 style="font-size: 18px; font-weight: 700; color: #111827; margin-bottom: 8px;">
        本部 Google Sheets URL 設定
    </h1>
    <p style="font-size: 13px; color: #6b7280; margin-bottom: 20px; line-height: 1.7;">
        本部 (株式会社 ZEAL) から共有された Google Sheets の <strong>公開 CSV エクスポート URL</strong> を 2 件登録します。<br>
        登録後、「本部 Sheet を取り込む」ボタンから対象月を選んで売上 / 経費を試算表に取り込めます。
    </p>

    @if(session('success'))
        <div style="background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; padding:10px 14px; border-radius:6px; font-size:13px; margin-bottom:14px;">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:10px 14px; border-radius:6px; font-size:13px; margin-bottom:14px;">
            <ul style="margin:0; padding-left:20px;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- 公開リンクの作り方ガイド --}}
    <div style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a; padding:12px 16px; border-radius:8px; font-size:12px; line-height:1.8; margin-bottom:20px;">
        <strong style="color:#1d4ed8;">📋 CSV エクスポート URL の作り方</strong>
        <ol style="margin:6px 0 0; padding-left:22px;">
            <li>Google Sheets を開き、<strong>共有 → 一般的なアクセス</strong>を「リンクを知っている全員 (閲覧者)」に変更</li>
            <li>シートタブを右クリック →「URL をコピー」(または通常の共有 URL を取得)</li>
            <li>URL 末尾の <code style="background:#dbeafe; padding:1px 4px; border-radius:3px;">/edit#gid=XXX</code> を <code style="background:#dbeafe; padding:1px 4px; border-radius:3px;">/export?format=csv&gid=XXX</code> に書き換える</li>
        </ol>
        <div style="margin-top:6px;">
            例: <code style="background:#dbeafe; padding:1px 4px; border-radius:3px;">https://docs.google.com/spreadsheets/d/{SHEET_ID}/export?format=csv&gid={GID}</code>
        </div>
    </div>

    <form method="POST" action="{{ route('zeal.simulations.sheet-urls.update', $simulation) }}">
        @csrf
        @method('PUT')

        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px; margin-bottom:18px;">
            <h2 style="font-size:14px; font-weight:700; color:#111827; margin-bottom:14px; display:flex; align-items:center; gap:8px;">
                <span style="width:4px; height:18px; background:#7c3aed; border-radius:2px;"></span>
                売上項目清算書 Sheet
            </h2>
            <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:6px;">CSV エクスポート URL</label>
            <input type="url" name="sales_sheet_url"
                   value="{{ old('sales_sheet_url', $simulation->sales_sheet_url) }}"
                   placeholder="https://docs.google.com/spreadsheets/d/.../export?format=csv&gid=..."
                   style="width:100%; height:38px; padding:7px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; font-family: monospace;">
            <p style="font-size:11px; color:#6b7280; margin-top:6px;">「当月日割売上金 / 前月時点会費預り金 / 調整金 / 当月売上合計 / ロイヤリティ額 / 差し引き精算額」が含まれる Sheet</p>
        </div>

        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px; margin-bottom:18px;">
            <h2 style="font-size:14px; font-weight:700; color:#111827; margin-bottom:14px; display:flex; align-items:center; gap:8px;">
                <span style="width:4px; height:18px; background:#7c3aed; border-radius:2px;"></span>
                運営費請求根拠 Sheet
            </h2>
            <label style="display:block; font-size:12px; font-weight:600; color:#374151; margin-bottom:6px;">CSV エクスポート URL</label>
            <input type="url" name="expense_sheet_url"
                   value="{{ old('expense_sheet_url', $simulation->expense_sheet_url) }}"
                   placeholder="https://docs.google.com/spreadsheets/d/.../export?format=csv&gid=..."
                   style="width:100%; height:38px; padding:7px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; font-family: monospace;">
            <p style="font-size:11px; color:#6b7280; margin-top:6px;">運営費 (店舗運営委託費 / 研修 / WEB / hacomono 決済手数料 等) + 店舗備品費の明細が含まれる Sheet</p>
        </div>

        <div style="display:flex; gap:10px; justify-content:flex-end;">
            <a href="{{ route('zeal.simulations.show', $simulation) }}"
               style="padding:8px 18px; font-size:13px; font-weight:600; color:#6b7280; border:1px solid #d1d5db; border-radius:6px; text-decoration:none; background:#fff;">キャンセル</a>
            <button type="submit"
                    style="padding:8px 18px; font-size:13px; font-weight:600; color:#fff; border:1px solid #7c3aed; border-radius:6px; background:#7c3aed; cursor:pointer;">保存</button>
        </div>
    </form>
</div>
@endsection
