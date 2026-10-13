@extends('layouts.app')

@section('title', '紙の決裁台帳の取り込み')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.admin.papers.index') }}" class="hover:text-emerald-600 transition-colors">過去の決裁の取り込み</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">Excel の台帳から取り込む</span>
@endsection

@section('content')
{{--
    紙の決裁台帳（年度ごとの Excel）の取り込み（画面⑪。段階5 設計書 §5.9・§5.10）。決裁の管理者だけ・使い始める前から（D11）。

    流れ: アップロード → この画面に確認を描く（何も書かない）→ 描いた「取り込む」フォームで確定 → 紙の決裁の一覧。
    ⚠ 行のエラーは $analysis['rowErrors']。コントローラから $errors の名前で渡さない（Blade の予約変数。Bug #53）
    ⚠ 成功・失敗の帯はレイアウトが出す（ここで出すと画面に 2 回出る）。$errors だけ各ビューの責任
    ⚠ 年度はファイルの名前（「08年度」）から先に選ぶ（paperLedgerYear）。違う年度を選んでも、日付の年が合わない行はエラーになる
      （PaperDate。違う年度の記録を黙って入れない）
--}}
<div>
    @include('approvals._mail_failure')

    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <h1 class="text-lg font-bold text-gray-900 mb-1">紙の決裁台帳の取り込み</h1>
    <p class="text-[12px] text-gray-500 mb-5 max-w-[720px]">
        紙で回していたころの決裁台帳（年度ごとの Excel）から、決裁No・決裁日・申請者・件名と金額を取り込みます。
        アップロードすると取り込む内容を確認できます。実際に取り込むのは、確認したあとに「取り込む」を押したときだけです。
    </p>

    {{-- 1. 台帳の形 --}}
    <section class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3.5 mb-5">
        <h2 class="text-[13px] font-bold text-emerald-900 mb-1.5">読み取る台帳の形</h2>
        <ul class="list-disc list-outside pl-5 text-[12px] text-emerald-800 space-y-1">
            <li>シートの名前の<strong>先頭の英字</strong>が部門のアルファベットです（「Ｊ・住宅」は J、「ＪＢ・住宅」は JB）。部門の管理に無いアルファベットのシートは取り込みません。</li>
            <li>受付簿は 1 枚の中が<strong>左右 2 段</strong>（ＮＯ・日付・申請者・件名）です。ＮＯ だけの行（まだ使っていない行）は読みません。</li>
            <li>日付は「8.05.13」の形（和暦の年・月・日）です。年は、選んだ年度と月から決めます（期の始まりの月より前の月は次の年）。</li>
            <li>名前に「金額」を含むシートは<strong>金額の控え</strong>です。日付と件名が同じ受付簿の行に、契約金額・工事原価・粗利益を付けます。</li>
        </ul>
    </section>

    {{-- 2. 取り込むとどうなるか --}}
    <section class="rounded-lg border border-gray-200 bg-white px-4 py-3.5 mb-5">
        <h2 class="text-[13px] font-bold text-gray-900 mb-1.5">取り込むとどうなるか</h2>
        <ul class="list-disc list-outside pl-5 text-[12px] text-gray-600 space-y-1">
            <li>決裁No は「年度の和暦-部門のアルファベット-ＮＯ」（R8-J-001 など）になります。決裁台帳で「紙」として申請と一緒に並びます。</li>
            <li>読めない行（日付が無い・年度と合わないなど）は<strong>その行だけ取り込みません</strong>。ほかの行は取り込みます。確認の画面に、どの行がなぜかを出します。</li>
            <li><strong>すでにある決裁No は変えません</strong>（手で直した中身を消さないため）。金額が空の記録にだけ、金額の控えの金額を入れます。</li>
            <li>今年度の台帳を取り込むと、その部門の<strong>次に付く番号</strong>が、取り込んだいちばん大きい番号の次まで進みます（戻りません）。</li>
            <li>取り込みを間違えたときは、紙の決裁の一覧の「取り込みの記録」から、<strong>1 回分をまとめて取り消せます</strong>（そのあと手で直した記録も消えます。進んだ次の番号は戻りません）。</li>
        </ul>
    </section>

    {{-- 3. アップロード --}}
    <section class="bg-white rounded-lg border border-gray-200 px-4 py-4 mb-5">
        <form method="POST" action="{{ route('approvals.admin.papers.import.preview') }}" enctype="multipart/form-data">
            @csrf
            <h2 class="text-[13px] font-bold text-gray-900 mb-1.5">① 台帳のファイルと年度を選んでアップロードする</h2>
            {{-- ⚠ `<input type="file">` に .form-input を当てない（枠が角張り、ブラウザの選択ボタンの意匠が消える。Bug #18）--}}
            <input type="file" name="ledger_file" accept=".xls,.xlsx" onchange="paperLedgerFileChosen(this)" aria-label="台帳のファイル"
                   style="display:block; width:100%; max-width:520px; padding:8px 12px; font-size:13px; color:#374151; background:white; border:1px solid #d1d5db; border-radius:6px; cursor:pointer; box-sizing:border-box;">
            <p class="text-[12px] text-gray-500 mt-1.5">.xls・.xlsx で 10MB までです。</p>

            <label for="paper-ledger-year" class="block text-[12px] font-semibold text-gray-700 mt-3 mb-1">年度</label>
            <select id="paper-ledger-year" name="year" class="h-9 px-2.5 border border-gray-300 rounded-md text-[13px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer">
                @foreach($years as $value => $label)
                    <option value="{{ $value }}" {{ (int) old('year', $year ?? array_key_first($years)) === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
            <p class="text-[12px] text-gray-500 mt-1.5">ファイルを選ぶと、名前の「08年度」から年度を選びます。違うときは選び直してください。</p>

            <button type="submit" class="mt-3 px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">アップロードして内容を確認</button>
        </form>
    </section>

    {{-- 4. 確認（アップロードした直後だけ出る） --}}
    @isset($analysis)
        @include('approvals.admin.papers._import_preview')
    @endisset
</div>
@endsection

{{--
    ファイルの名前の「08年度」（全角の数字も）から年度を選ぶ。n 年度は令和（2018＋n）が選択肢にあればそれ、無ければ平成（1988＋n）。
    どちらも無いとき・名前に年度が無いときは選び直さない。
    ⚠ 説明はこの Blade コメントに書く（script の中のコメントにアットマークで始まる語を書くと Blade が展開して壊す。Bug #30）
--}}
@push('scripts')
    <script>
    function paperLedgerYear(fileName, choices) {
        var name = String(fileName).replace(/[０-９]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 0xFEE0); });
        var m = name.match(/(\d{1,2})\s*年度/);
        if (!m) {
            return null;
        }
        var n = parseInt(m[1], 10);
        var reiwa = String(2018 + n);
        var heisei = String(1988 + n);
        if (choices.indexOf(reiwa) !== -1) {
            return reiwa;
        }
        return choices.indexOf(heisei) !== -1 ? heisei : null;
    }
    function paperLedgerFileChosen(input) {
        var select = document.getElementById('paper-ledger-year');
        var file = input.files && input.files[0];
        if (!select || !file) {
            return;
        }
        var choices = Array.prototype.map.call(select.options, function (o) { return o.value; });
        var year = paperLedgerYear(file.name, choices);
        if (year !== null) {
            select.value = year;
        }
    }
    </script>
@endpush
