{{--
    ログイン案内（設計書 §5.12）。

    ⚠ レイアウトを継承しない独立した HTML。サイドバー・ヘッダーを出さないことを構造で保証する。
    ⚠ @vite を使わない（withoutVite() のテストでも本番でも同じものが出る）。
    ⚠ @page の余白を 0 にして、ブラウザが余白へ日時や URL を印刷しないようにする。
       実際の余白はページの中の要素で取る。
--}}
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>ログインのご案内</title>
    <style>
        @page { size: A4; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Hiragino Sans", "Noto Sans JP", Meiryo, sans-serif; color: #111827; background: #F3F4F6; }

        /* 画面にだけ出る帯 */
        .guide-bar { position: sticky; top: 0; z-index: 10; background: #FFFBEB; border-bottom: 1px solid #FDE68A; padding: 12px 16px; font-size: 13px; line-height: 1.7; }
        .guide-bar strong { color: #92400E; }
        .guide-bar button, .guide-bar a { font: inherit; }
        .guide-print-btn { padding: 6px 14px; background: #059669; color: #fff; border: none; border-radius: 6px; cursor: pointer; }
        .guide-back { margin-left: 12px; color: #4B5563; }

        .guide-page { width: 210mm; min-height: 297mm; margin: 0 auto; padding: 24mm 20mm; background: #fff; break-after: page; }
        .guide-page:last-child { break-after: auto; }

        .guide-title { font-size: 22px; font-weight: 700; margin: 0 0 4px; }
        .guide-system { font-size: 13px; color: #6B7280; margin: 0 0 24px; }
        /* ⚠ 空白の無い長い氏名（ローマ字・入力の連結）が用紙の外へ無警告で切れるのを防ぐ。
           実測: 66 文字で右余白へ 54px 食い込み、160 文字で完全に切れた */
        .guide-name { font-size: 16px; margin: 0 0 20px; overflow-wrap: anywhere; }

        .guide-creds { display: flex; gap: 20mm; align-items: flex-start; border: 1px solid #D1D5DB; border-radius: 8px; padding: 16px 20px; margin-bottom: 20px; }
        .guide-creds dl { margin: 0; flex: 1; }
        .guide-creds dt { font-size: 12px; color: #6B7280; margin-bottom: 2px; }
        .guide-creds dd { margin: 0 0 14px; font-family: "SFMono-Regular", Consolas, Menlo, monospace; font-size: 20px; letter-spacing: 0.08em; word-break: break-all; }
        .guide-qr { width: 32mm; height: 32mm; flex: 0 0 auto; }
        .guide-url { font-size: 11px; color: #4B5563; word-break: break-all; text-align: center; margin-top: 4px; }

        .guide-steps { font-size: 13px; line-height: 2; padding-left: 1.4em; margin: 0 0 18px; }
        .guide-notes { font-size: 12px; color: #4B5563; line-height: 1.9; border-top: 1px solid #E5E7EB; padding-top: 12px; margin: 0; list-style: none; padding-left: 0; }
        .guide-issued { font-size: 11px; color: #9CA3AF; margin-top: 18px; }

        @media print {
            body { background: #fff; }
            .guide-bar { display: none; }
            .guide-page { margin: 0; box-shadow: none; }
        }
        @media screen {
            .guide-page { box-shadow: 0 1px 4px rgba(0,0,0,0.12); margin-bottom: 16px; }
        }
    </style>
</head>
<body>

{{-- 画面にだけ出る帯（印刷されない） --}}
<div class="guide-bar">
    <button type="button" class="guide-print-btn" onclick="window.print()">印刷する</button>
    <span style="margin-left: 12px;">{{ count($entries) }} 人分</span>
    @if($mailCounts !== null)
        {{-- 通知メールを送るのは再発行のときだけ（F7。新規登録と CSV の確定では件数を出さない） --}}
        <span style="margin-left: 12px;">通知メール: 送る {{ $mailCounts['notified'] }} 人／送らない {{ $mailCounts['skipped'] }} 人（メールアドレスなし・許可していないドメイン）</span>
    @endif
    {{-- 戻り先は入口が決めて渡す（ここで url()->previous() を呼ばない。リファラーが優先され、CSV の確定では POST 専用の URL になる。F2） --}}
    <a href="{{ $backUrl }}" class="guide-back">元の画面へ戻る</a>
    <div><strong>この画面を閉じると初期パスワードは二度と表示されません。印刷してから閉じてください。</strong></div>
</div>

{{-- QR は全員同じなので 1 つだけ置き、各ページは <use> で参照する（1 枚 約 10KB あるため） --}}
<svg width="0" height="0" aria-hidden="true" focusable="false" style="position:absolute">
    <symbol id="login-qr" viewBox="{{ $qr['viewBox'] }}">{!! $qr['inner'] !!}</symbol>
</svg>

@foreach($entries as $entry)
    <section class="guide-page">
        <h1 class="guide-title">ログインのご案内</h1>
        <p class="guide-system">ミツワ都市開発 経営管理システム</p>

        <p class="guide-name">{{ $entry['user']->name }} 様</p>

        <div class="guide-creds">
            <dl>
                <dt>ログインID</dt>
                <dd>{{ $entry['user']->employee_number ?? $entry['user']->email }}</dd>
                <dt>初期パスワード</dt>
                <dd>{{ $entry['password'] }}</dd>
            </dl>
            <div>
                <svg class="guide-qr" role="img" aria-label="ログイン画面のQRコード"><use href="#login-qr"></use></svg>
                <div class="guide-url">{{ $loginUrl }}</div>
            </div>
        </div>

        <ol class="guide-steps">
            <li>QRコードを読み取るか、上のURLを開きます</li>
            <li>ログインIDと初期パスワードを入力します</li>
            <li>新しいパスワードを決めます（8文字以上・英字と数字を含む）</li>
        </ol>

        <ul class="guide-notes">
            <li>初期パスワードは、初回ログインのあとは使えなくなります。</li>
            <li>この紙は本人以外に見せないでください。</li>
            <li>新しいパスワードに変えたら、この紙は破棄してください。</li>
        </ul>

        <p class="guide-issued">発行日: {{ $issuedAt }}</p>
    </section>
@endforeach

<script>
    // 閉じようとしたら必ず確認する（設計書 §5.12・D9）。
    //
    // ⚠ 「印刷したら確認しない」にしてはいけない。afterprint は**印刷ダイアログを閉じたとき**に
    //    発火し、実際に印刷したのかキャンセルしたのかを JS から区別する手段が無い（主要ブラウザ共通）。
    //    プリンタの不調や用紙の選び直しで一度キャンセルしただけで確認が外れ、そのまま閉じると
    //    初期パスワードが二度と表示されない（実駆動で確認: afterprint のあと beforeunload は
    //    preventDefault を呼ばなくなった）。
    //    確認が 1 回増える煩わしさより、100〜200 人ぶんの紙を配り直す事故のほうが重い。
    window.addEventListener('beforeunload', function (e) {
        e.preventDefault();
        e.returnValue = '';
    });
</script>

</body>
</html>
