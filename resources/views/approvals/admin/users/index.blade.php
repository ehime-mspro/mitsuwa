@extends('layouts.app')

@section('title', '利用者の管理')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">利用者の管理</span>
@endsection

@section('content')
{{--
    利用者の管理（設計書 §5.9・D8・D16）。

    ⚠ **<form> を入れ子にしない。** まとめて再発行のフォームで表を囲むと、行ごとの再発行・
      無効化のフォームが入れ子になり、ブラウザは内側を捨てて最初の </form> で外側を閉じる。
      まとめて再発行のフォームは表の外に置き、行の選択欄は form 属性でそこへ結び付ける。
    ⚠ 成功・失敗の帯はレイアウトが出す（ここで出すと画面に 2 回出る）。$errors だけ各ビューの責任。
--}}
<div x-data="approvalUsers()" x-cloak>

    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- ⚠ 取込画面へのリンクは**アプリ内でここ 1 つだけ**にする。同じ URL を指す要素を 2 つ置くと、
         片方を消しても素朴なアサートが必ず false-pass する（Bug #43 / #47）。下の説明文は
         リンクを重ねず言葉で案内するだけにしてある。サイドバーの項目は別タスク --}}
    <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
        <h1 class="text-lg font-bold text-gray-900">利用者の管理</h1>
        <a href="{{ route('approvals.admin.users.import') }}"
           class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-[12px] font-semibold rounded-md cursor-pointer whitespace-nowrap">社員の一括登録（CSV）</a>
    </div>
    <p class="text-[12px] text-gray-500 mb-5">
        決裁の所属部門は全員について変えられます。氏名・社員番号の修正、無効化・有効化、パスワードの再発行は、決裁だけを使う利用者に対してのみ行えます。
        メールアドレスの変更と利用者の削除は、基幹の管理者に依頼してください。
        決裁だけを使う利用者を新しく登録するときは「社員の一括登録（CSV）」を使ってください（1 人だけでも使えます）。
    </p>

    {{-- 絞り込み。プルダウンとチェックは、変えた瞬間に送る（F8・CLAUDE.md の即時フィルタ）。
         まとめて再発行の hidden は適用済みの条件を運ぶので、画面の表示と再発行の対象を一致させる。
         検索語は「検索」ボタン／Enter で送る（打鍵ごとに送らない） --}}
    <form id="filter-form" method="GET" action="{{ route('approvals.admin.users.index') }}"
          class="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2 mb-4 bg-white border border-gray-200 rounded-lg px-3.5 py-2.5">
        <select name="kind" onchange="document.getElementById('filter-form').submit()" class="h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">区分: すべて</option>
            <option value="approval" {{ $filters['kind'] === 'approval' ? 'selected' : '' }}>決裁のみ</option>
            <option value="base" {{ $filters['kind'] === 'base' ? 'selected' : '' }}>基幹も使う</option>
        </select>
        <select name="department" onchange="document.getElementById('filter-form').submit()" class="h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">決裁の所属部門: すべて</option>
            <option value="none" {{ $filters['department'] === 'none' ? 'selected' : '' }}>所属なし</option>
            @foreach($companies as $company)
                @foreach($company->departments as $department)
                    <option value="{{ $department->id }}" {{ $filters['department'] === (string) $department->id ? 'selected' : '' }}>{{ $company->name }} / {{ $department->name }}</option>
                @endforeach
            @endforeach
        </select>
        <select name="status" onchange="document.getElementById('filter-form').submit()" class="h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none cursor-pointer w-full sm:w-auto">
            <option value="">状態: すべて</option>
            @foreach(App\Enums\UserStatus::cases() as $case)
                <option value="{{ $case->value }}" {{ $filters['status'] === $case->value ? 'selected' : '' }}>{{ $case->label() }}</option>
            @endforeach
        </select>
        <label class="flex items-center gap-1.5 text-[12px] text-gray-700 cursor-pointer">
            <input type="checkbox" name="never_logged_in" value="1" onchange="document.getElementById('filter-form').submit()" {{ $filters['never_logged_in'] === '1' ? 'checked' : '' }}
                   class="w-[15px] h-[15px] accent-emerald-600 cursor-pointer">
            一度もログインしていない人だけ
        </label>
        <input type="text" name="search" value="{{ $filters['search'] }}" placeholder="氏名・社員番号・メールで検索"
               class="h-8 px-2.5 border border-gray-300 rounded-md text-[12px] text-gray-700 bg-white focus:border-emerald-500 focus:outline-none w-full sm:flex-1 sm:min-w-[140px]">
        <button type="submit" class="h-8 px-3.5 bg-gray-50 border border-gray-300 rounded-md text-[12px] text-gray-700 hover:bg-gray-100 cursor-pointer transition-colors w-full sm:w-auto">検索</button>
        @if(collect($filters)->contains(fn ($value) => $value !== ''))
            <a href="{{ route('approvals.admin.users.index') }}" class="text-[12px] text-gray-500 hover:text-emerald-600 transition-colors text-center sm:text-left">クリア</a>
        @endif
    </form>

    {{--
        まとめて再発行（D9）。表の外に置き、行の選択欄は form 属性でここへ結び付ける（入れ子にしない）。
        「絞り込んだ全員」は確定の時にサーバーが同じ条件で引き直すので、絞り込みの条件をそのまま送る。
    --}}
    <form id="approvalBulkReissue" method="POST" action="{{ route('approvals.admin.users.reissueBulk') }}">
        @csrf
        {{-- 1 回限りの鍵はサーバーで描く（:value にすると往復テストが拾えず配線が無防備になる。Bug #47）。
             1 ページの読み込みにつき 1 つ ＝ ブラウザの再送信では同じ鍵になり 2 回目が止まる --}}
        <input type="hidden" name="guide_token" value="{{ \App\Support\OneTimeAction::issue() }}">
        {{-- ⚠ `request($key)` を素で出さない。配列（`?search[]=a`）で 500 になる。
             コントローラが正規化した $filters を使う（`filteredQuery()` と同じ値）--}}
        @foreach($filters as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach

        <div class="flex flex-wrap items-center gap-2 mb-3">
            {{-- ⚠ 押せない理由はボタン自身の title では出ない（ホバーを受ける span で包む。Bug #43）。
                 ⚠ この 2 つは `type="button"` で、押すと確認のモーダルを開くだけ（設計書 §5.9 は
                   「確認のモーダルに人数と氏名を出す」と定めている）。実際に送るのはモーダルの中の
                   submit ボタンで、`name="mode"` の値はサーバーが描く（`:value` にすると往復テストが
                   拾えず配線が無防備になる。Bug #47）。
                 ⚠ 素の `onclick="return confirm(…)"` には戻せない — 人数も氏名も出せない。
                   Alpine の `@@click` も不可で、評価器が `__self.result = <式>` に埋め込むため
                   `return confirm(…)` は構文エラーになり、確認が出ないまま送信される（evaluator.js:96 を実測）--}}
            <span :title="selected.length === 0 ? '再発行する利用者を、表の左端の選択欄で選んでください。' : null" style="display: inline-flex;">
                <button type="button" @click="openConfirm('selected')" :disabled="selected.length === 0"
                        class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white text-[12px] font-semibold rounded-md cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">選んだ <span x-text="selected.length">0</span> 人を再発行</button>
            </span>
            <span @if($reissuableCount === 0) title="この絞り込みには、再発行できる利用者がいません。" @endif style="display: inline-flex;">
                <button type="button" @click="openConfirm('filtered')" @if($reissuableCount === 0) disabled @endif
                        class="px-3 py-1.5 bg-white border border-amber-300 text-amber-700 text-[12px] font-semibold rounded-md cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed">絞り込んだ全員（{{ $reissuableCount }} 人）を再発行</button>
            </span>
            <span class="text-[12px] text-gray-500">一度に再発行できるのは {{ config('approval.bulk_reissue_max') }} 人までです。決裁だけを使う有効な利用者のうち、決裁の権限に指定されていない人が対象です。</span>
        </div>

        {{-- 再発行の確認（設計書 §5.9）。一度に何十人ものパスワードが変わるので、
             人数と氏名を出してから確定させる。このモーダルは**まとめて再発行のフォームの中**に置く
             （外に出すと submit ボタンがどのフォームにも属さなくなる）--}}
        <div x-show="confirmOpen" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
            <div @click.outside="confirmOpen = false" class="bg-white rounded-xl w-full max-w-[520px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">パスワードの再発行</div>
                <div class="px-6 py-4 space-y-3">
                    <p class="text-[13px] text-gray-700">
                        次の <span class="font-bold text-amber-700" x-text="confirmCount">0</span> 人のパスワードを再発行します。
                        いまのパスワードは使えなくなり、印刷用の案内が開きます。
                    </p>
                    <ul class="border border-gray-200 rounded-md px-3 py-2 max-h-[220px] overflow-y-auto text-[13px] text-gray-700 space-y-0.5">
                        {{-- ⚠ :key は付けない。同姓同名が居ると重複キーになる（並びは固定なので添字で足りる）--}}
                        <template x-for="person in confirmNames">
                            <li x-text="person"></li>
                        </template>
                    </ul>
                    <p class="text-[12px] text-gray-500" x-show="confirmCount > confirmNames.length" style="display:none;">
                        ほか <span x-text="confirmCount - confirmNames.length"></span> 人（全員が対象です）
                    </p>
                    <p class="text-[12px] text-gray-500" x-show="confirmMode === 'filtered'" style="display:none;">
                        対象は確定のときにもう一度絞り込み直すので、いま表示している氏名と入れ替わることがあります。
                    </p>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="confirmOpen = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">やめる</button>
                    {{-- ⚠ `mode` の値はサーバーが描く。2 本を x-show で出し分け、Alpine のバインドにしない --}}
                    <button type="submit" name="mode" value="selected" x-show="confirmMode === 'selected'" style="display:none;"
                            class="px-5 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">再発行する</button>
                    <button type="submit" name="mode" value="filtered" x-show="confirmMode === 'filtered'" style="display:none;"
                            class="px-5 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">再発行する</button>
                </div>
            </div>
        </div>
    </form>

    {{-- 表 --}}
    <div class="bg-white rounded-lg border border-gray-200">
        <div class="scroll-hint at-start">
            <div class="scroll-hint-inner">
        <table class="w-full min-w-[980px] border-collapse">
            <thead>
                <tr>
                    <th class="px-3.5 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">選択</th>
                    <th class="px-3.5 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">社員番号</th>
                    <th class="px-3.5 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">氏名</th>
                    <th class="px-3.5 py-2.5 text-center text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">区分</th>
                    <th class="px-3.5 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">決裁の所属部門</th>
                    <th class="px-3.5 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200">メールアドレス</th>
                    <th class="px-3.5 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">最終ログイン</th>
                    <th class="px-3.5 py-2.5 text-center text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">状態</th>
                    <th class="px-3.5 py-2.5 text-right text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 w-[1%] whitespace-nowrap">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $u)
                    @php
                        $privilegeLabel = $u->approvalPrivilegeLabel();
                        $manageable     = $u->isApprovalOnly() && $privilegeLabel === null;
                        $refusal        = $privilegeLabel !== null
                            ? $u->name . 'さんは' . $privilegeLabel . 'に指定されています。基幹の管理者に依頼してください。'
                            : ($u->isApprovalOnly() ? null : '基幹を使う利用者のため、基幹の管理者に依頼してください。');
                    @endphp
                    <tr class="{{ $u->status === App\Enums\UserStatus::Inactive ? 'opacity-60' : '' }} hover:bg-gray-50">
                        <td class="px-3.5 py-2.5 border-b border-gray-100 w-[1%]">
                            @if($manageable && $u->status === App\Enums\UserStatus::Active)
                                {{-- ⚠ form 属性でまとめて再発行のフォームへ結び付ける（表を <form> で囲むと入れ子になる）。
                                     data-name は確認のモーダルが氏名を出すために読む --}}
                                <input type="checkbox" name="user_ids[]" value="{{ $u->id }}" form="approvalBulkReissue" x-model="selected"
                                       data-name="{{ $u->name }}"
                                       aria-label="{{ $u->name }}さんを選ぶ" class="w-[15px] h-[15px] accent-emerald-600 cursor-pointer">
                            @elseif($privilegeLabel !== null)
                                <span title="{{ $refusal }}" class="text-[11px] text-gray-400">—</span>
                            @else
                                <span class="text-[11px] text-gray-300">—</span>
                            @endif
                        </td>
                        <td class="px-3.5 py-2.5 border-b border-gray-100 whitespace-nowrap font-mono text-[12px] text-gray-700">{{ $u->employee_number ?? '—' }}</td>
                        <td class="px-3.5 py-2.5 border-b border-gray-100">
                            <span class="text-[13px] font-medium text-gray-900">{{ $u->name }}</span>
                            @if($privilegeLabel !== null)
                                {{-- ⚠ 理由は title だけでなく画面の本文にも出す（tooltip はキーボード・読み上げに届かない。Bug #43） --}}
                                <span class="block text-[11px] text-gray-500">{{ $privilegeLabel }}に指定されています</span>
                            @endif
                        </td>
                        <td class="px-3.5 py-2.5 border-b border-gray-100 text-center whitespace-nowrap">
                            <span class="inline-block px-2 rounded text-[11px] font-medium {{ $u->isApprovalOnly() ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-600' }}" style="padding-top:2px; padding-bottom:2px;">{{ $u->isApprovalOnly() ? '決裁のみ' : '基幹も使う' }}</span>
                        </td>
                        <td class="px-3.5 py-2.5 border-b border-gray-100 text-[11px] text-gray-700">
                            {{ $u->approvalDepartments->pluck('name')->join('・') ?: '—' }}
                        </td>
                        <td class="px-3.5 py-2.5 border-b border-gray-100 text-[12px] text-gray-700">{{ $u->email ?? '—' }}</td>
                        <td class="px-3.5 py-2.5 border-b border-gray-100 text-[12px] text-gray-400 whitespace-nowrap">
                            {{ \App\Support\JapanTime::format($u->last_login_at) ?? '未ログイン' }}
                        </td>
                        <td class="px-3.5 py-2.5 border-b border-gray-100 text-center whitespace-nowrap">
                            <span class="inline-block px-2 rounded text-[11px] font-medium {{ $u->status === App\Enums\UserStatus::Active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}" style="padding-top:2px; padding-bottom:2px;">{{ $u->status->label() }}</span>
                        </td>
                        <td class="px-3.5 py-2.5 border-b border-gray-100 text-right whitespace-nowrap">
                            <button type="button" @click="openEdit({{ \Illuminate\Support\Js::from([
                                        'id'          => $u->id,
                                        'name'        => $u->name,
                                        'number'      => $u->employee_number ?? '',
                                        'email'       => $u->email ?? '',
                                        'departments' => $u->approvalDepartments->pluck('id')->map(fn ($id) => (string) $id)->values(),
                                        'editable'    => $manageable,
                                        'reason'      => $refusal ?? '',
                                    ]) }})"
                                    class="text-[12px] text-blue-600 hover:underline cursor-pointer bg-transparent border-none p-0">編集</button>

                            <span class="text-gray-200 mx-1">|</span>
                            @if($manageable)
                                <form method="POST" action="{{ route('approvals.admin.users.reissue', $u) }}" class="inline"
                                      onsubmit="return confirm({{ \Illuminate\Support\Js::from($u->name . 'さんのパスワードを再発行します。印刷用の案内が開きます。よろしいですか。') }});">
                                    @csrf
                                    <input type="hidden" name="guide_token" value="{{ \App\Support\OneTimeAction::issue() }}">
                                    <button type="submit" class="text-[12px] text-amber-600 hover:underline cursor-pointer bg-transparent border-none p-0">再発行</button>
                                </form>
                                <span class="text-gray-200 mx-1">|</span>
                                <form method="POST" action="{{ route('approvals.admin.users.toggleStatus', $u) }}" class="inline"
                                      onsubmit="return confirm({{ \Illuminate\Support\Js::from($u->name . 'さんの状態を変更します。よろしいですか。') }});">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="{{ $u->status === App\Enums\UserStatus::Active ? App\Enums\UserStatus::Inactive->value : App\Enums\UserStatus::Active->value }}">
                                    <button type="submit" class="text-[12px] {{ $u->status === App\Enums\UserStatus::Active ? 'text-red-600' : 'text-emerald-600' }} hover:underline cursor-pointer bg-transparent border-none p-0">{{ $u->status === App\Enums\UserStatus::Active ? '無効化' : '有効化' }}</button>
                                </form>
                            @else
                                <span title="{{ $refusal }}" class="text-[12px] text-gray-400">再発行・無効化はできません</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-3.5 py-8 text-center text-[13px] text-gray-400">該当する利用者が見つかりません。</td></tr>
                @endforelse
            </tbody>
        </table>
            </div>{{-- /scroll-hint-inner --}}
            <div class="scroll-hint-text">← スクロールできます →</div>
        </div>{{-- /scroll-hint --}}

        @if($users->hasPages())
            <div class="flex justify-center gap-0.5 py-3 border-t border-gray-200">
                @if($users->onFirstPage())
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&lt;</span>
                @else
                    <a href="{{ $users->previousPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&lt;</a>
                @endif
                @foreach($users->getUrlRange(1, $users->lastPage()) as $page => $url)
                    @if($page == $users->currentPage())
                        <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-white bg-emerald-600 border border-emerald-600 font-semibold">{{ $page }}</span>
                    @else
                        <a href="{{ $url }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">{{ $page }}</a>
                    @endif
                @endforeach
                @if($users->hasMorePages())
                    <a href="{{ $users->nextPageUrl() }}" class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 transition-colors">&gt;</a>
                @else
                    <span class="w-8 h-8 flex items-center justify-center rounded text-xs text-gray-300 bg-white border border-gray-200">&gt;</span>
                @endif
            </div>
        @endif
    </div>

    {{-- 利用者の編集（追加のモーダルは無い。決裁のみ利用者の登録は上の「社員の一括登録（CSV）」と基幹の利用者管理から） --}}
    <div x-show="editModal" class="fixed inset-0 bg-black/35 z-50 flex items-center justify-center" style="display:none;">
        <div @click.outside="editModal = false" class="bg-white rounded-xl w-full max-w-[520px] max-h-[90vh] overflow-y-auto shadow-xl mx-4">
            <form method="POST" :action="'{{ url('approvals/admin/users') }}/' + editUserId">
                @csrf
                @method('PUT')
                <div class="px-6 pt-5 text-[15px] font-bold text-gray-900">利用者の編集</div>
                <div class="px-6 py-4 space-y-3.5">
                    <template x-if="!editEditable">
                        <p class="rounded-md bg-amber-50 border border-amber-200 px-3 py-2 text-[12px] text-amber-800" x-text="editReason + ' 決裁の所属部門だけ変えられます。'"></p>
                    </template>

                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">氏名</label>
                        <input type="text" name="name" x-model="editName" :readonly="!editEditable" maxlength="100"
                               class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px]"
                               :class="editEditable ? '' : 'bg-gray-50 text-gray-500'">
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">社員番号</label>
                        <input type="text" name="employee_number" x-model="editNumber" :readonly="!editEditable" maxlength="20"
                               autocapitalize="characters" spellcheck="false"
                               class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] font-mono"
                               :class="editEditable ? '' : 'bg-gray-50 text-gray-500'">
                        <p class="text-[11px] text-gray-400 mt-1">英数字とハイフン。ログイン ID になります</p>
                    </div>
                    <div>
                        <label class="block text-[12px] font-semibold text-gray-700 mb-1">メールアドレス</label>
                        {{-- 送信しない（この画面からは変えられないので、入力欄そのものを置かない） --}}
                        <div class="w-full min-h-[38px] px-2.5 py-2 border border-gray-200 rounded-md bg-gray-50 text-[13px] text-gray-500 break-all" x-text="editEmail || '（未登録）'"></div>
                        <p class="text-[11px] text-gray-400 mt-1">変更は基幹の管理者に依頼してください</p>
                    </div>
                    <div>
                        <span class="block text-[12px] font-semibold text-gray-700 mb-1">決裁の所属部門</span>
                        <div class="border border-gray-200 rounded-md px-3 py-2 max-h-[220px] overflow-y-auto">
                            @forelse($companies as $company)
                                <p class="text-[11px] text-gray-400 mt-1.5 first:mt-0">{{ $company->name }}</p>
                                @forelse($company->departments as $department)
                                    <label class="flex items-center gap-2 py-1 text-[13px] text-gray-700 cursor-pointer">
                                        <input type="checkbox" name="approval_departments[]" value="{{ $department->id }}" x-model="editDepartments"
                                               class="w-[15px] h-[15px] accent-emerald-600 cursor-pointer">
                                        {{ $department->name }}
                                    </label>
                                @empty
                                    <p class="text-[12px] text-gray-400 py-1">部門が登録されていません。</p>
                                @endforelse
                            @empty
                                <p class="text-[12px] text-gray-400 py-1">会社が登録されていません。先に部門の管理で登録してください。</p>
                            @endforelse
                        </div>
                        <p class="text-[11px] text-gray-400 mt-1">兼務する場合は複数選べます。すべて外すと所属なしになります</p>
                    </div>
                </div>
                <div class="px-6 pb-5 flex justify-end gap-2">
                    <button type="button" @click="editModal = false" class="px-3.5 py-2 bg-white border border-gray-300 rounded-md text-[13px] cursor-pointer">キャンセル</button>
                    <button type="submit" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[13px] font-semibold cursor-pointer">保存する</button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script>
// 「絞り込んだ全員」の確認に出す氏名と人数。サーバーが数え直した値で、画面の人数表示と同じ出どころ。
// ⚠ Js::from を使う（@@json は属性でも <script> でも構造の " を素のまま出す。Bug #23）
var APPROVAL_FILTERED_NAMES = {{ \Illuminate\Support\Js::from($reissuableNames) }};
var APPROVAL_FILTERED_COUNT = {{ (int) $reissuableCount }};

function approvalUsers() {
    return {
        selected: [],

        confirmOpen: false,
        confirmMode: 'selected',
        confirmNames: [],
        confirmCount: 0,

        // 選んだ人の氏名は、選択欄そのものが持つ data-name から引く
        // （サーバーが描いた行と 1 対 1 なので、別に一覧を持たなくてよい）
        selectedNames() {
            return this.selected.map(function (id) {
                var box = document.querySelector('input[name="user_ids[]"][value="' + id + '"]');
                return box ? box.getAttribute('data-name') : '';
            }).filter(function (name) { return name !== ''; });
        },

        openConfirm(mode) {
            this.confirmMode = mode;

            if (mode === 'selected') {
                this.confirmNames = this.selectedNames();
                this.confirmCount = this.selected.length;
            } else {
                this.confirmNames = APPROVAL_FILTERED_NAMES.slice();
                this.confirmCount = APPROVAL_FILTERED_COUNT;
            }

            this.confirmOpen = true;
        },

        editModal: false,
        editUserId: null,
        editName: '',
        editNumber: '',
        editEmail: '',
        editDepartments: [],
        editEditable: false,
        editReason: '',

        openEdit(row) {
            this.editUserId = row.id;
            this.editName = row.name;
            this.editNumber = row.number;
            this.editEmail = row.email;
            this.editDepartments = row.departments.map(String);
            this.editEditable = row.editable;
            this.editReason = row.reason;
            this.editModal = true;
        }
    };
}
</script>
@endpush
