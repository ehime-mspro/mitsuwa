@extends('layouts.app')

@section('title', 'ZEAL 会員CSVインポート — プレビュー')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.dashboard') }}" class="hover:text-emerald-600 transition-colors">ZEAL</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('admin.zeal.member-import') }}" class="hover:text-emerald-600 transition-colors">会員CSVインポート</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">プレビュー</span>
@endsection

@section('content')

{{-- Mapper の区分定数は FQCN で直書き（Blade php ブロック内の use 文はメソッドスコープになり parse エラーのため） --}}
@php
    $kindLabel = [
        \App\Support\Zeal\HacomonoMemberMapper::KIND_ACTIVE        => '在籍',
        \App\Support\Zeal\HacomonoMemberMapper::KIND_WITHDRAWN     => '退会済',
        \App\Support\Zeal\HacomonoMemberMapper::KIND_DORMANT       => '休会',
        \App\Support\Zeal\HacomonoMemberMapper::KIND_TICKET        => 'チケット',
        \App\Support\Zeal\HacomonoMemberMapper::KIND_INACTIVE_ZERO => '定期OFF',
    ];
    $kindStyle = [
        \App\Support\Zeal\HacomonoMemberMapper::KIND_ACTIVE        => 'background:#d1fae5;color:#065f46;',
        \App\Support\Zeal\HacomonoMemberMapper::KIND_WITHDRAWN     => 'background:#fee2e2;color:#991b1b;',
        \App\Support\Zeal\HacomonoMemberMapper::KIND_DORMANT       => 'background:#fef3c7;color:#92400e;',
        \App\Support\Zeal\HacomonoMemberMapper::KIND_TICKET        => 'background:#e0e7ff;color:#3730a3;',
        \App\Support\Zeal\HacomonoMemberMapper::KIND_INACTIVE_ZERO => 'background:#f3f4f6;color:#374151;',
    ];
@endphp

<style>
    .preview-table { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 880px; }
    .preview-table thead th { background: #f9fafb; text-align: left; padding: 8px 10px; border-bottom: 1px solid #e5e7eb; font-size: 11px; font-weight: 700; color: #374151; white-space: nowrap; }
    .preview-table tbody td { padding: 8px 10px; border-bottom: 1px solid #f3f4f6; color: #374151; vertical-align: top; }
    .preview-table tbody tr:last-child td { border-bottom: none; }
    .kind-badge { display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 700; white-space: nowrap; }
</style>

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
    <h1 style="font-size: 20px; font-weight: 700; margin: 0;">インポート プレビュー</h1>
    <a href="{{ route('admin.zeal.member-import') }}"
       style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
        <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        やり直す
    </a>
</div>

{{-- サマリーバッジ --}}
<div style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px;">
    <div style="display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: #d1fae5; border: 1px solid #6ee7b7; border-radius: 8px;">
        <span style="font-size: 22px; font-weight: 700; color: #065f46;">{{ count($toImport) }}</span>
        <span style="font-size: 13px; color: #065f46; font-weight: 600;">件 登録予定</span>
    </div>
    @if(count($skipped) > 0)
        <div style="display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 8px;">
            <span style="font-size: 22px; font-weight: 700; color: #6b7280;">{{ count($skipped) }}</span>
            <span style="font-size: 13px; color: #6b7280; font-weight: 600;">件 スキップ（既存）</span>
        </div>
    @endif
    @if(count($errored) > 0)
        <div style="display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: #fee2e2; border: 1px solid #fca5a5; border-radius: 8px;">
            <span style="font-size: 22px; font-weight: 700; color: #991b1b;">{{ count($errored) }}</span>
            <span style="font-size: 13px; color: #991b1b; font-weight: 600;">件 エラー（取込しない）</span>
        </div>
    @endif
    @if(count($excluded) > 0)
        <div style="display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px;">
            <span style="font-size: 22px; font-weight: 700; color: #6b7280;">{{ count($excluded) }}</span>
            <span style="font-size: 13px; color: #6b7280; font-weight: 600;">件 除外（対象外）</span>
        </div>
    @endif
</div>

{{-- エラー行（取込しない） --}}
@if(count($errored) > 0)
    <div style="background:white; border:1px solid #fecaca; border-radius:8px; padding:20px; margin-bottom:20px;">
        <div style="font-size: 14px; font-weight: 700; color: #991b1b; margin-bottom: 12px;">⚠️ エラー行（取込対象外）</div>
        @foreach($errored as $m)
            <div style="padding: 8px 12px; background: #fef2f2; border-radius: 6px; margin-bottom: 8px; font-size: 13px;">
                <span style="font-weight: 700; color: #991b1b;">{{ $m->sourceId }} {{ $m->displayName ?: '（氏名なし）' }}</span>
                <ul style="margin: 4px 0 0; padding-left: 18px; color: #b91c1c;">
                    @foreach($m->errors as $msg)
                        <li>{{ $msg }}</li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
@endif

{{-- スキップ（既存重複） --}}
@if(count($skipped) > 0)
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="font-size: 14px; font-weight: 700; color: #6b7280; margin-bottom: 12px;">スキップ行（同名・同入会日が既存）</div>
        @foreach($skipped as $m)
            <div style="padding: 7px 12px; background: #f9fafb; border-radius: 6px; margin-bottom: 6px; font-size: 13px; color: #6b7280;">
                {{ $m->sourceId }} {{ $m->displayName }} — 入会日 {{ $m->memberAttributes['joined_on'] }}
            </div>
        @endforeach
    </div>
@endif

{{-- 除外（ビジター・テスト用アカウント等・取込対象外） --}}
@if(count($excluded) > 0)
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="font-size: 14px; font-weight: 700; color: #6b7280; margin-bottom: 12px;">除外（取込対象外）</div>
        @foreach($excluded as $ex)
            <div style="padding: 7px 12px; background: #f9fafb; border-radius: 6px; margin-bottom: 6px; font-size: 13px; color: #6b7280;">
                {{ $ex['name'] ?: '（氏名なし）' }} — {{ $ex['reason'] }}
            </div>
        @endforeach
    </div>
@endif

{{-- 登録予定の一覧 --}}
@if(count($toImport) > 0)
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 12px;">登録予定 {{ count($toImport) }}件</div>
        <div style="overflow-x: auto;">
            <table class="preview-table">
                <thead>
                    <tr>
                        <th>元ID</th>
                        <th>氏名</th>
                        <th>状態</th>
                        <th>区分</th>
                        <th>プラン</th>
                        <th>月会費（税抜）</th>
                        <th>退会(予定)日</th>
                        <th>警告</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($toImport as $m)
                        <tr>
                            <td style="color:#9ca3af; white-space:nowrap;">{{ $m->sourceId }}</td>
                            <td style="font-weight:600; white-space:nowrap;">{{ $m->displayName }}</td>
                            <td style="color:#6b7280;">{{ $m->status }}</td>
                            <td><span class="kind-badge" style="{{ $kindStyle[$m->kind] ?? 'background:#f3f4f6;color:#374151;' }}">{{ $kindLabel[$m->kind] ?? $m->kind }}</span></td>
                            <td style="font-weight:600; color:#047857;">{{ $m->planName ?? '（未対応:'.$m->rawPlan.'）' }}</td>
                            <td style="text-align:right; white-space:nowrap;">{{ $m->appliedPriceExcl !== null ? number_format($m->appliedPriceExcl).'円' : '—' }}</td>
                            <td style="white-space:nowrap;">{{ $m->withdrewOn ?? $m->scheduledOn ?? '—' }}</td>
                            <td style="color:#92400e; font-size:11px;">{{ implode(' / ', $m->warnings) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@else
    <div style="padding: 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; text-align: center; font-size: 14px; color: #6b7280; margin-bottom: 20px;">
        登録対象の行がありません。CSVを確認して再度アップロードしてください。
    </div>
@endif

{{-- 実行フォーム --}}
@if(count($toImport) > 0)
    <form method="POST" action="{{ route('admin.zeal.member-import.execute') }}">
        @csrf
        <input type="hidden" name="confirmed" value="1">
        <input type="hidden" name="csv_data" value="{{ base64_encode($content) }}">

        <div style="display: flex; gap: 12px; align-items: center;">
            <a href="{{ route('admin.zeal.member-import') }}"
               style="display: inline-flex; align-items: center; padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 14px; font-weight: 600; color: #374151; text-decoration: none;">
                キャンセル
            </a>
            <button type="submit"
                    style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 28px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 700; cursor: pointer;">
                <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                {{ count($toImport) }}件をインポート実行する
            </button>
        </div>
    </form>
@else
    <a href="{{ route('admin.zeal.member-import') }}"
       style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 14px; font-weight: 600; color: #374151; text-decoration: none;">
        <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        やり直す
    </a>
@endif

@endsection
