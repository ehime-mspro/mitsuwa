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

{{-- フラッシュメッセージ --}}
@if(session('success'))
    <div style="padding: 12px 16px; margin-bottom: 16px; background: #d1fae5; border: 1px solid #6ee7b7; border-radius: 8px; font-size: 13px; color: #065f46;">
        {{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div style="padding: 12px 16px; margin-bottom: 16px; background: #fee2e2; border: 1px solid #fca5a5; border-radius: 8px; font-size: 13px; color: #991b1b;">
        {{ session('error') }}
    </div>
@endif
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
        <div style="font-weight: 700; font-size: 14px; color: #166534; margin-bottom: 8px;">会員マスタの一括登録</div>
        <ul style="font-size: 13px; color: #15803d; margin: 0; padding-left: 18px; line-height: 2;">
            <li>1行 = 1会員として登録します</li>
            <li>同名・同入会日の会員が既にDBに存在する場合はスキップされます</li>
            <li>取込時に初回契約レコード（new_join）も自動作成されます</li>
            <li>月会費（税抜）が空欄の場合は、選択プランの通常価格を適用します</li>
            <li>文字コードは UTF-8 / Shift_JIS どちらでも対応しています</li>
        </ul>
    </div>

    {{-- CSV カラム仕様 --}}
    <div style="margin-bottom: 24px;">
        <div style="font-size: 14px; font-weight: 700; color: #374151; margin-bottom: 10px;">CSVカラム仕様（16列）</div>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                <thead>
                    <tr style="background: #f9fafb;">
                        <th style="text-align: left; padding: 8px 12px; border: 1px solid #e5e7eb; color: #374151;">#</th>
                        <th style="text-align: left; padding: 8px 12px; border: 1px solid #e5e7eb; color: #374151;">ヘッダー名</th>
                        <th style="text-align: left; padding: 8px 12px; border: 1px solid #e5e7eb; color: #374151;">必須</th>
                        <th style="text-align: left; padding: 8px 12px; border: 1px solid #e5e7eb; color: #374151;">値の例・注意事項</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                    $columns = [
                        [1,  '氏名',           '◎', '山本 健太'],
                        [2,  'フリガナ',        '◎', 'ヤマモト ケンタ'],
                        [3,  '性別',            '◎', '男性 / 女性 / その他'],
                        [4,  '生年月日',        '—', 'YYYY-MM-DD（例: 1992-03-14）'],
                        [5,  '電話番号',        '—', '090-1234-5678'],
                        [6,  'メールアドレス',  '—', 'yamamoto@example.com'],
                        [7,  '郵便番号',        '—', '790-0001'],
                        [8,  '住所',            '—', '愛媛県松山市一番町1-2-3'],
                        [9,  '入会日',          '◎', 'YYYY-MM-DD（例: 2025-10-17）'],
                        [10, 'プラン名',        '◎', 'ZEALプランマスタに登録済みのプラン名と完全一致'],
                        [11, '月会費（税抜）',  '—', '18000（空欄でプランの通常価格を使用）'],
                        [12, '担当トレーナー',  '—', 'トレーナーマスタに登録済みのトレーナー名と完全一致'],
                        [13, '集客チャネル',    '—', 'SNS / 検索エンジン / 紹介 / 口コミ / ポスティングチラシ / 街頭チラシ / 地図検索 / 電話 / 不明 / その他'],
                        [14, '入会目的',        '—', 'ボディメイク / ダイエット / 運動不足解消 / 機能改善 / 下半身強化 / 体力向上 / ストレス発散 / 健康増進 / その他'],
                        [15, '所属店舗',        '—', '店舗マスタの店舗名と完全一致。空欄の場合は表示順が最も小さい有効店舗に自動で紐付きます'],
                        [16, 'メモ',            '—', '特記事項など'],
                    ];
                    @endphp
                    @foreach($columns as [$num, $col, $req, $note])
                        <tr style="{{ $req === '◎' ? 'background: #fffbeb;' : '' }}">
                            <td style="padding: 7px 12px; border: 1px solid #e5e7eb; color: #6b7280;">{{ $num }}</td>
                            <td style="padding: 7px 12px; border: 1px solid #e5e7eb; font-weight: 600;">{{ $col }}</td>
                            <td style="padding: 7px 12px; border: 1px solid #e5e7eb; color: {{ $req === '◎' ? '#dc2626' : '#9ca3af' }}; font-weight: 700;">{{ $req }}</td>
                            <td style="padding: 7px 12px; border: 1px solid #e5e7eb; color: #6b7280;">{{ $note }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ファイルアップロードフォーム --}}
    <form method="POST" action="{{ route('admin.zeal.member-import.preview') }}"
          enctype="multipart/form-data">
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

            <a href="{{ route('admin.zeal.member-import.template') }}"
               style="display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px; border: 1px solid #d1d5db; border-radius: 6px; background: white; font-size: 13px; color: #374151; text-decoration: none;">
                <svg style="width: 14px; height: 14px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                サンプルCSVをダウンロード
            </a>
        </div>

    </form>

</div>

@endsection
