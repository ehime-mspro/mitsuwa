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

<style>
    .preview-table { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 800px; }
    .preview-table thead th { background: #f9fafb; text-align: left; padding: 8px 10px; border-bottom: 1px solid #e5e7eb; font-size: 11px; font-weight: 700; color: #374151; white-space: nowrap; }
    .preview-table tbody td { padding: 8px 10px; border-bottom: 1px solid #f3f4f6; color: #374151; }
    .preview-table tbody tr:last-child td { border-bottom: none; }
    .badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .badge-ok     { background: #d1fae5; color: #065f46; }
    .badge-error  { background: #fee2e2; color: #991b1b; }
    .badge-skip   { background: #f3f4f6; color: #6b7280; }
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
        <span style="font-size: 22px; font-weight: 700; color: #065f46;">{{ count($validRows) }}</span>
        <span style="font-size: 13px; color: #065f46; font-weight: 600;">件 登録予定</span>
    </div>
    @if(count($skippedRows) > 0)
        <div style="display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 8px;">
            <span style="font-size: 22px; font-weight: 700; color: #6b7280;">{{ count($skippedRows) }}</span>
            <span style="font-size: 13px; color: #6b7280; font-weight: 600;">件 スキップ（既存）</span>
        </div>
    @endif
    @if(count($errorRows) > 0)
        <div style="display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: #fee2e2; border: 1px solid #fca5a5; border-radius: 8px;">
            <span style="font-size: 22px; font-weight: 700; color: #991b1b;">{{ count($errorRows) }}</span>
            <span style="font-size: 13px; color: #991b1b; font-weight: 600;">件 エラー（スキップ）</span>
        </div>
    @endif
</div>

{{-- エラー行の詳細 --}}
@if(count($errorRows) > 0)
    <div class="bg-white border border-red-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="font-size: 14px; font-weight: 700; color: #991b1b; margin-bottom: 12px;">
            ⚠️ エラー行（取込対象外）
        </div>
        @foreach($errorRows as $errRow)
            <div style="padding: 8px 12px; background: #fef2f2; border-radius: 6px; margin-bottom: 8px; font-size: 13px;">
                <span style="font-weight: 700; color: #991b1b;">行 {{ $errRow['row'] }}: {{ $errRow['data']['name'] ?: '（氏名なし）' }}</span>
                <ul style="margin: 4px 0 0; padding-left: 18px; color: #b91c1c;">
                    @foreach($errRow['errors'] as $msg)
                        <li>{{ $msg }}</li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
@endif

{{-- スキップ行の詳細 --}}
@if(count($skippedRows) > 0)
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="font-size: 14px; font-weight: 700; color: #6b7280; margin-bottom: 12px;">
            スキップ行（既存重複）
        </div>
        @foreach($skippedRows as $skipRow)
            <div style="padding: 7px 12px; background: #f9fafb; border-radius: 6px; margin-bottom: 6px; font-size: 13px; color: #6b7280;">
                行 {{ $skipRow['row'] }}: {{ $skipRow['data']['name'] }} — {{ $skipRow['reason'] }}
            </div>
        @endforeach
    </div>
@endif

{{-- 登録予定の行一覧 --}}
@if(count($validRows) > 0)
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 12px;">
            登録予定 {{ count($validRows) }}件
        </div>
        <div style="overflow-x: auto;">
            <table class="preview-table">
                <thead>
                    <tr>
                        <th>行</th>
                        <th>氏名</th>
                        <th>フリガナ</th>
                        <th>性別</th>
                        <th>入会日</th>
                        <th>プラン</th>
                        <th>月会費（税抜）</th>
                        <th>税込</th>
                        <th>担当トレーナー</th>
                        <th>集客</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($validRows as $vr)
                        <tr>
                            <td style="color: #9ca3af;">{{ $vr['row_num'] }}</td>
                            <td style="font-weight: 600;">{{ $vr['name'] }}</td>
                            <td style="color: #6b7280;">{{ $vr['name_kana'] }}</td>
                            <td>{{ $vr['gender_label'] }}</td>
                            <td>{{ $vr['joined_on'] }}</td>
                            <td style="font-weight: 600; color: #047857;">{{ $vr['plan_name'] }}</td>
                            <td style="text-align: right;">{{ number_format($vr['applied_price_excl']) }}円</td>
                            <td style="text-align: right; color: #047857; font-weight: 700;">{{ number_format($vr['price_incl']) }}円</td>
                            <td style="color: #6b7280;">{{ $vr['trainer_name'] ?: '—' }}</td>
                            <td style="color: #6b7280;">{{ $vr['acquisition_source'] ? \App\Enums\ZealAcquisitionSource::from($vr['acquisition_source'])->label() : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

{{-- 登録予定がなければ実行ボタンを無効化 --}}
@if(count($validRows) === 0)
    <div style="padding: 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; text-align: center; font-size: 14px; color: #6b7280; margin-bottom: 20px;">
        登録対象の行がありません。CSVを修正して再度アップロードしてください。
    </div>
@endif

{{-- 実行フォーム --}}
@if(count($validRows) > 0)
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
                <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                {{ count($validRows) }}件をインポート実行する
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
