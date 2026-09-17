@extends('layouts.app')

@section('title', '社員の一括登録')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.admin.users.index') }}" class="hover:text-emerald-600 transition-colors">利用者の管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">社員の一括登録</span>
@endsection

@section('content')
{{--
    社員の CSV 一括登録（設計書 §5.10）。

    流れ: アップロード → この画面に確認を描く → 描いた「取り込む」フォームで確定
      → 結果はログイン案内の画面（1 人 1 ページ・印刷用）。

    ⚠ 行エラーは `$rowErrors`。`$errors` は Blade の予約変数（ViewErrorBag）なので、
      コントローラから同じ名前で渡すと `$errors->any()` が fatal になる（Bug #53）。
    ⚠ エラー（`$rowErrors`）と注意（`$warnings`）は別のブロックに出す（Bug #54 ④）。
    ⚠ 成功・失敗の帯はレイアウトが出す（ここで出すと画面に 2 回出る）。$errors だけ各ビューの責任。
--}}
<div>

    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <h1 class="text-lg font-bold text-gray-900 mb-1">社員の一括登録</h1>
    <p class="text-[12px] text-gray-500 mb-5">
        CSV ファイルから、決裁だけを使う利用者をまとめて登録します。
        アップロードすると取り込む内容を確認できます。実際に登録されるのは、確認したあとに「取り込む」を押したときだけです。
    </p>

    {{-- 1. 書き方の説明 --}}
    <section class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3.5 mb-5">
        <h2 class="text-[13px] font-bold text-emerald-900 mb-1.5">CSV の書き方</h2>
        <ul class="list-disc list-outside pl-5 text-[12px] text-emerald-800 space-y-1">
            <li>1 行目に見出しを置き、<strong>社員番号・氏名・メールアドレス・所属部門</strong>の 4 つを入れます（列の順番は問いません）。</li>
            <li>1 行が 1 人です。1 つのファイルで <strong>{{ config('approval.csv_max_rows') }} 行</strong>まで取り込めます。多いときは分けてください。</li>
            <li><strong>所属部門</strong>は部門のアルファベット（RE など）を書きます。兼務の人は並べて書けます（区切りはカンマ・スラッシュ・空白・読点・中黒のどれでも構いません）。</li>
            <li><strong>メールアドレス</strong>は任意です。書く場合は、部門の管理で許可したドメインのものだけが使えます。</li>
            <li>文字コードは UTF-8・Shift_JIS・EUC-JP のどれでも読めます。</li>
        </ul>
    </section>

    {{-- 2. 取り込んだあとどうなるか --}}
    <section class="rounded-lg border border-gray-200 bg-white px-4 py-3.5 mb-5">
        <h2 class="text-[13px] font-bold text-gray-900 mb-1.5">取り込むとどうなるか</h2>
        <ul class="list-disc list-outside pl-5 text-[12px] text-gray-600 space-y-1">
            <li>まだいない人は<strong>新しく登録</strong>します。全員「決裁のみ利用者」（基幹の画面は使えません）・有効・初回にパスワードの変更を求める状態になります。</li>
            <li>社員番号かメールアドレスが<strong>今いる利用者と一致した人</strong>は、<strong>社員番号と決裁の所属部門だけ</strong>を書き換えます。氏名・メールアドレス・基幹のロール・状態は変わりません（CSV と違うときは確認の画面で注意として出します）。</li>
            <li>新しく登録した人の<strong>初期パスワードを作り、印刷用の案内</strong>（1 人 1 ページ）を開きます。通知メールは送りません。案内は紙で本人に渡してください。</li>
            <li>取り込めない行が 1 行でもあると、<strong>その回はまるごと取り込めません</strong>。CSV を直して、もう一度アップロードしてください。</li>
            <li>メールアドレスの変更と利用者の削除は、この画面ではできません。基幹の管理者に依頼してください。</li>
        </ul>
    </section>

    {{-- 3. テンプレートとアップロード --}}
    <section class="bg-white rounded-lg border border-gray-200 px-4 py-4 mb-5">
        <div class="mb-4">
            <h2 class="text-[13px] font-bold text-gray-900 mb-1.5">① テンプレートを使う</h2>
            <a href="{{ route('approvals.admin.users.import.template') }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-gray-300 rounded-md text-[12px] font-semibold text-gray-700 hover:bg-gray-50 transition-colors">
                テンプレート CSV をダウンロード
            </a>
            <p class="text-[12px] text-gray-500 mt-1.5">見本の行が入っています。Excel で開いて書き換えてください。</p>
        </div>

        <form method="POST" action="{{ route('approvals.admin.users.import.preview') }}" enctype="multipart/form-data">
            @csrf
            <h2 class="text-[13px] font-bold text-gray-900 mb-1.5">② ファイルを選んでアップロードする</h2>
            {{-- ⚠ `<input type="file">` に .form-input を当てない（枠が角張り、ブラウザの選択ボタンの意匠が消える。Bug #18）--}}
            <input type="file" name="csv_file" accept=".csv,.txt"
                   style="display:block; width:100%; max-width:520px; padding:8px 12px; font-size:13px; color:#374151; background:white; border:1px solid #d1d5db; border-radius:6px; cursor:pointer; box-sizing:border-box;">
            <button type="submit" class="mt-3 px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">アップロードして内容を確認</button>
        </form>
    </section>

    {{-- 4. 確認（アップロードした直後だけ出る） --}}
    @isset($rows)
        @include('approvals.admin.users._import_preview')
    @endisset

</div>
@endsection
