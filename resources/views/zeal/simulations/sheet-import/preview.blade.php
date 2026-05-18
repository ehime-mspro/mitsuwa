@extends('layouts.app')

@section('title', '本部 Sheet 取り込みプレビュー — ' . $simulation->fiscal_year . '年度経営試算表')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>ZEAL</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.simulations.index', ['list' => 1]) }}" class="text-gray-500 hover:text-emerald-600">経営試算表</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.simulations.show', $simulation) }}" class="text-gray-500 hover:text-emerald-600">{{ $simulation->fiscal_year }}年度</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">本部 Sheet 取り込みプレビュー</span>
@endsection

@section('content')
@php
    $yearLabel  = substr($yearMonth, 0, 4) . '年' . (int) substr($yearMonth, 5, 2) . '月';
    $salesOk    = $sales['parsed'] !== null;
    $expenseOk  = $expense['parsed'] !== null;
    $hasAnyUpdates = collect($applyPlan)->contains(fn($r) => $r['will_update']);
    $fmt = fn($v) => $v === null ? '—' : number_format((int) $v) . ' 円';
@endphp

<div style="max-width: 980px;">
    <h1 style="font-size:18px; font-weight:700; color:#111827; margin-bottom:6px;">
        本部 Sheet 取り込みプレビュー
    </h1>
    <div style="font-size:13px; color:#6b7280; margin-bottom:18px;">
        対象月: <strong style="color:#111827;">{{ $yearLabel }}</strong>
        <span style="margin-left:10px; color:#9ca3af;">|</span>
        <span style="margin-left:10px;">{{ $simulation->fiscal_year }}年度 経営試算表</span>
    </div>

    {{-- 売上 Sheet --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px; margin-bottom:20px;">
        <h2 style="font-size:14px; font-weight:700; color:#111827; margin-bottom:14px; display:flex; align-items:center; gap:8px;">
            <span style="width:4px; height:18px; background:#0d9488; border-radius:2px;"></span>
            ① 売上項目清算書
            @if($salesOk)
                @if($sales['validation']['ok'])
                    <span style="font-size:11px; padding:2px 8px; background:#d1fae5; color:#065f46; border-radius:4px; font-weight:600;">整合 OK</span>
                @else
                    <span style="font-size:11px; padding:2px 8px; background:#fee2e2; color:#991b1b; border-radius:4px; font-weight:600;">整合エラー</span>
                @endif
            @else
                <span style="font-size:11px; padding:2px 8px; background:#fef3c7; color:#92400e; border-radius:4px; font-weight:600;">取得失敗</span>
            @endif
        </h2>

        @if(!$salesOk)
            <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:10px 14px; border-radius:6px; font-size:13px;">
                {{ $sales['error'] }}
            </div>
        @else
            {{-- 6 行サマリ --}}
            <table style="width:100%; border-collapse:collapse; font-size:13px; margin-bottom:12px;">
                <thead>
                    <tr style="background:#f9fafb;">
                        <th style="text-align:left; padding:8px 12px; border-bottom:1px solid #e5e7eb; color:#6b7280;">項目</th>
                        <th style="text-align:right; padding:8px 12px; border-bottom:1px solid #e5e7eb; color:#6b7280;">Sheet 値</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td style="padding:6px 12px; border-bottom:1px solid #f3f4f6;">当月日割売上金</td><td style="padding:6px 12px; border-bottom:1px solid #f3f4f6; text-align:right;">{{ $fmt($sales['parsed']['daily_sales']) }}</td></tr>
                    <tr><td style="padding:6px 12px; border-bottom:1px solid #f3f4f6;">前月時点 会費預り金</td><td style="padding:6px 12px; border-bottom:1px solid #f3f4f6; text-align:right;">{{ $fmt($sales['parsed']['prepaid']) }}</td></tr>
                    <tr><td style="padding:6px 12px; border-bottom:1px solid #f3f4f6;">調整金</td><td style="padding:6px 12px; border-bottom:1px solid #f3f4f6; text-align:right;">{{ $fmt($sales['parsed']['adjustment']) }}</td></tr>
                    <tr style="background:#fffbeb;"><td style="padding:6px 12px; border-bottom:1px solid #f3f4f6; font-weight:700;">当月売上合計</td><td style="padding:6px 12px; border-bottom:1px solid #f3f4f6; text-align:right; font-weight:700;">{{ $fmt($sales['parsed']['total_sales']) }}</td></tr>
                    <tr><td style="padding:6px 12px; border-bottom:1px solid #f3f4f6;">ロイヤリティ額 (3%)</td><td style="padding:6px 12px; border-bottom:1px solid #f3f4f6; text-align:right;">{{ $fmt($sales['parsed']['royalty']) }}</td></tr>
                    <tr><td style="padding:6px 12px; font-weight:700;">差し引き精算額</td><td style="padding:6px 12px; text-align:right; font-weight:700;">{{ $fmt($sales['parsed']['settlement']) }}</td></tr>
                </tbody>
            </table>

            {{-- 整合チェック結果 --}}
            <div style="background:{{ $sales['validation']['ok'] ? '#ecfdf5' : '#fef2f2' }}; border:1px solid {{ $sales['validation']['ok'] ? '#a7f3d0' : '#fecaca' }}; color:{{ $sales['validation']['ok'] ? '#065f46' : '#991b1b' }}; padding:10px 14px; border-radius:6px; font-size:12px; line-height:1.7;">
                <strong>整合チェック</strong>
                @if($sales['validation']['ok'])
                    : 3 式すべて一致しました ✅
                @else
                    <ul style="margin:6px 0 0; padding-left:20px;">
                        @foreach($sales['validation']['errors'] as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                @endif
                @if(!empty($sales['validation']['warnings']))
                    <ul style="margin:6px 0 0; padding-left:20px; color:#92400e;">
                        @foreach($sales['validation']['warnings'] as $warn)
                            <li>{{ $warn }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </div>

    {{-- 経費 Sheet --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px; margin-bottom:20px;">
        <h2 style="font-size:14px; font-weight:700; color:#111827; margin-bottom:14px; display:flex; align-items:center; gap:8px;">
            <span style="width:4px; height:18px; background:#0d9488; border-radius:2px;"></span>
            ② 運営費請求根拠
            @if($expenseOk)
                <span style="font-size:11px; padding:2px 8px; background:#d1fae5; color:#065f46; border-radius:4px; font-weight:600;">取得 OK</span>
            @else
                <span style="font-size:11px; padding:2px 8px; background:#fef3c7; color:#92400e; border-radius:4px; font-weight:600;">取得失敗</span>
            @endif
        </h2>

        @if(!$expenseOk)
            <div style="background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:10px 14px; border-radius:6px; font-size:13px;">
                {{ $expense['error'] }}
            </div>
        @else
            {{-- 集計 --}}
            <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:10px; margin-bottom:14px;">
                <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:10px 14px;">
                    <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">運営費</div>
                    <div style="font-size:16px; font-weight:700; color:#111827;">{{ $fmt($expense['aggregate']['summary_operating']) }}</div>
                </div>
                <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:10px 14px;">
                    <div style="font-size:11px; color:#6b7280; margin-bottom:2px;">店舗備品費</div>
                    <div style="font-size:16px; font-weight:700; color:#111827;">{{ $fmt($expense['aggregate']['summary_supplies']) }}</div>
                </div>
                <div style="background:#fffbeb; border:1px solid #fde68a; border-radius:6px; padding:10px 14px;">
                    <div style="font-size:11px; color:#92400e; margin-bottom:2px;">総計</div>
                    <div style="font-size:16px; font-weight:700; color:#111827;">{{ $fmt($expense['aggregate']['summary_total']) }}</div>
                </div>
            </div>

            {{-- 集約された費目別金額 --}}
            <h3 style="font-size:12px; font-weight:600; color:#374151; margin-bottom:6px;">費目別集約 (試算表への反映単位)</h3>
            <table style="width:100%; border-collapse:collapse; font-size:13px; margin-bottom:10px;">
                <thead>
                    <tr style="background:#f9fafb;">
                        <th style="text-align:left; padding:6px 10px; border-bottom:1px solid #e5e7eb; color:#6b7280;">code</th>
                        <th style="text-align:left; padding:6px 10px; border-bottom:1px solid #e5e7eb; color:#6b7280;">金額</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($expense['aggregate']['by_code'] as $code => $amt)
                        <tr><td style="padding:5px 10px; border-bottom:1px solid #f3f4f6; font-family:monospace; color:#374151;">{{ $code }}</td><td style="padding:5px 10px; border-bottom:1px solid #f3f4f6;">{{ $fmt($amt) }}</td></tr>
                    @endforeach
                </tbody>
            </table>

            {{-- hacomono 決済手数料の整合チェック --}}
            @if(!empty($paymentFeeCheck['message']))
                <div style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a; padding:8px 12px; border-radius:6px; font-size:11px; line-height:1.6; margin-bottom:10px;">
                    💳 {{ $paymentFeeCheck['message'] }}
                </div>
            @endif

            {{-- 未マッピング項目 --}}
            @if(!empty($expense['aggregate']['unmapped']))
                <div style="background:#fffbeb; border:1px solid #fde68a; color:#92400e; padding:8px 12px; border-radius:6px; font-size:11px; line-height:1.6;">
                    <strong>未マッピング項目</strong> (store_supplies に集約済):
                    {{ collect($expense['aggregate']['unmapped'])->map(fn($u) => $u['item'].' ('.number_format($u['amount']).')')->implode(', ') }}
                </div>
            @endif
        @endif
    </div>

    {{-- 反映プラン --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px; margin-bottom:20px;">
        <h2 style="font-size:14px; font-weight:700; color:#111827; margin-bottom:14px; display:flex; align-items:center; gap:8px;">
            <span style="width:4px; height:18px; background:#7c3aed; border-radius:2px;"></span>
            ③ 試算表セル反映プラン ({{ $yearLabel }})
        </h2>

        @if(empty($applyPlan))
            <p style="font-size:13px; color:#6b7280;">取り込むべきデータがありません。</p>
        @else
            <p style="font-size:11px; color:#6b7280; margin-bottom:10px; line-height:1.6;">
                <span style="display:inline-block; padding:1px 6px; background:#dbeafe; color:#1e40af; border-radius:4px; font-weight:600;">更新</span> Sheet 値で上書き
                ／
                <span style="display:inline-block; padding:1px 6px; background:#f3f4f6; color:#6b7280; border-radius:4px; font-weight:600;">同値</span> 変更なし
                ／ 取り込まれたセルは <code style="background:#fef3c7; padding:1px 4px; border-radius:3px;">is_manual_override=true</code> で保護されます
            </p>
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#f9fafb;">
                        <th style="text-align:left; padding:8px 12px; border-bottom:1px solid #e5e7eb; color:#6b7280;">項目</th>
                        <th style="text-align:right; padding:8px 12px; border-bottom:1px solid #e5e7eb; color:#6b7280;">現在値</th>
                        <th style="text-align:right; padding:8px 12px; border-bottom:1px solid #e5e7eb; color:#6b7280;">取り込み後</th>
                        <th style="text-align:center; padding:8px 12px; border-bottom:1px solid #e5e7eb; color:#6b7280; width:80px;">状態</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($applyPlan as $row)
                        <tr>
                            <td style="padding:6px 12px; border-bottom:1px solid #f3f4f6;">{{ $row['name'] }} <span style="font-size:10px; color:#9ca3af; font-family:monospace;">({{ $row['code'] }})</span></td>
                            <td style="padding:6px 12px; border-bottom:1px solid #f3f4f6; text-align:right; color:#6b7280;">{{ $fmt($row['current_amount']) }}</td>
                            <td style="padding:6px 12px; border-bottom:1px solid #f3f4f6; text-align:right; font-weight:600; color:#111827;">{{ $fmt($row['new_amount']) }}</td>
                            <td style="padding:6px 12px; border-bottom:1px solid #f3f4f6; text-align:center;">
                                @if($row['will_update'])
                                    <span style="display:inline-block; padding:1px 6px; background:#dbeafe; color:#1e40af; border-radius:4px; font-weight:600; font-size:11px;">更新</span>
                                @else
                                    <span style="display:inline-block; padding:1px 6px; background:#f3f4f6; color:#6b7280; border-radius:4px; font-weight:600; font-size:11px;">同値</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- 反映ボタン --}}
    <div style="display:flex; gap:10px; justify-content:flex-end; padding-bottom:40px;">
        <a href="{{ route('zeal.simulations.show', $simulation) }}"
           style="padding:8px 20px; font-size:13px; font-weight:600; color:#6b7280; border:1px solid #d1d5db; border-radius:6px; text-decoration:none; background:#fff;">キャンセル</a>

        @if($hasAnyUpdates)
            <form method="POST" action="{{ route('zeal.simulations.sheet-import.apply', $simulation) }}" style="display:inline;">
                @csrf
                <input type="hidden" name="year_month" value="{{ $yearMonth }}">
                <button type="submit"
                        style="padding:8px 20px; font-size:13px; font-weight:700; color:#fff; border:1px solid #7c3aed; border-radius:6px; background:#7c3aed; cursor:pointer;">
                    試算表に反映する
                </button>
            </form>
        @else
            <button type="button" disabled
                    style="padding:8px 20px; font-size:13px; font-weight:700; color:#9ca3af; border:1px solid #e5e7eb; border-radius:6px; background:#f9fafb; cursor:not-allowed;">
                反映する変更がありません
            </button>
        @endif
    </div>
</div>
@endsection
