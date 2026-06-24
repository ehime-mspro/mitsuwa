@extends('layouts.app')

@section('title', 'ZEAL 会員CSVインポート')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.dashboard') }}" class="hover:text-emerald-600 transition-colors">ZEAL</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">会員CSVインポート</span>
@endsection

@section('content')

{{-- ページヘッダー --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
    <h1 style="font-size: 20px; font-weight: 700; margin: 0;">ZEAL 会員 CSVインポート</h1>
</div>

@if($errors->any())
    <div style="padding: 12px 16px; margin-bottom: 16px; background: #fee2e2; border: 1px solid #fca5a5; border-radius: 8px;">
        <div style="font-size: 13px; font-weight: 600; color: #991b1b; margin-bottom: 6px;">入力エラー</div>
        <ul style="margin: 0; padding-left: 18px; font-size: 13px; color: #991b1b;">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="bg-white border border-gray-200 rounded-lg p-5">

    {{-- 説明 --}}
    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px;">
        <div style="font-weight: 700; font-size: 14px; color: #166534; margin-bottom: 8px;">会員管理システムのエクスポートCSVを取り込みます</div>
        <ul style="font-size: 13px; color: #15803d; margin: 0; padding-left: 18px; line-height: 2;">
            <li>会員管理システムからエクスポートした会員CSVをそのままアップロードします（編集不要）</li>
            <li>在籍・退会済・休会・チケット・定期OFF を自動判定して取り込みます</li>
            <li>ビジター等（会員/停止中 以外）は取込対象外として自動的に除外します</li>
            <li>プラン名は登録済みプランへ自動マッピングします（解決できない行はエラーとして取込しません）</li>
            <li>同名・同入会日の会員が既にDBに存在する場合はスキップします</li>
            <li>取込時に契約レコードも自動作成します（チケット会員は契約なし／退会済は契約クローズ）</li>
            <li>文字コードは UTF-8 / Shift_JIS どちらでも対応しています</li>
        </ul>
    </div>

    {{-- 区分の説明 --}}
    <div style="margin-bottom: 24px;">
        <div style="font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 10px;">取込時の区分</div>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                <thead>
                    <tr style="background: #f9fafb;">
                        <th style="text-align: left; padding: 8px 12px; border: 1px solid #e5e7eb; color: #374151;">区分</th>
                        <th style="text-align: left; padding: 8px 12px; border: 1px solid #e5e7eb; color: #374151;">判定条件</th>
                        <th style="text-align: left; padding: 8px 12px; border: 1px solid #e5e7eb; color: #374151;">月会費（税抜）・契約</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                    $kinds = [
                        ['在籍',    '状態=会員 で通常課金',         '実請求額から税抜換算。契約を作成（継続中）'],
                        ['退会済',  '状態=停止中 または 退会日あり',  'プラン定価（税抜）。契約は退会日でクローズ'],
                        ['休会',    'コース名=休会プラン',           '実休会費（税抜）。契約は継続中'],
                        ['チケット','プラン未対応（チケット会員等）',  '会員のみ作成・契約なし'],
                        ['定期OFF', '定期購入=FALSE かつ 実請求0',    'プラン定価（税抜）。契約を作成'],
                    ];
                    @endphp
                    @foreach($kinds as [$kind, $cond, $note])
                        <tr>
                            <td style="padding: 7px 12px; border: 1px solid #e5e7eb; font-weight: 700;">{{ $kind }}</td>
                            <td style="padding: 7px 12px; border: 1px solid #e5e7eb; color: #6b7280;">{{ $cond }}</td>
                            <td style="padding: 7px 12px; border: 1px solid #e5e7eb; color: #6b7280;">{{ $note }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ファイルアップロードフォーム --}}
    <form method="POST" action="{{ route('admin.zeal.member-import.preview') }}" enctype="multipart/form-data">
        @csrf

        <div style="margin-bottom: 16px;">
            <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">
                CSVファイルを選択 <span style="color: #dc2626;">*</span>
            </label>
            <input type="file" name="csv_file" accept=".csv,.txt" required
                   style="display: block; width: 100%; max-width: 520px; padding: 8px 12px; font-size: 13px; color: #374151; background: white; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; box-sizing: border-box;">
            <div style="font-size: 11px; color: #9ca3af; margin-top: 6px;">
                対応形式: CSV（UTF-8 / Shift_JIS）/ 最大 10MB
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <button type="submit"
                    style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 24px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer;">
                <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/>
                    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                </svg>
                プレビューを確認する
            </button>
        </div>

    </form>

</div>

@endsection
