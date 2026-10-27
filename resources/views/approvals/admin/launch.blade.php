@extends('layouts.app')

@section('title', '使い始め')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">使い始め</span>
@endsection

{{--
    使い始め（画面⑬。段階6 設計書 §5.9・計画 §0.8）。決裁の管理者だけ・準備中・試す期間・使い始めたあとのどれでも開ける。
    必要変数: $stage（使い始めたあとはこれだけ）・$testerCount / $people / $q / $found / $overflow / $coverage

    ⚠ 欄 1 の 1 行の中は 氏名 → 役 → 印とボタン の順（LaunchScreenTest がこの順を見る）。検索の結果も同じ形の行
    ⚠ 無効の人・削除した人には「試す人にする」を出さない（試す人の印があれば「外す」は出す）
    ⚠ 「試す人にする」「外す」は二度押し止め（approvalSubmitOnce。決裁の管理の画面の部品）。外すのに確認の小窓は出さない（また足せる）
    ⚠ 欄 2 の ○／× は文字と色の両方で示す（色だけに頼らない）。判定は TrialCoverage::overview() の 1 か所
    ⚠ 使い始めた日時は LaunchState::launchedAt() を JapanTime::format に通す（保存した日時は UTC。Bug #61）
    ⚠ 欄 3・4（試しの申請の片付け・使い始める）は 6b で足す
--}}
@php
    $stageBadge = match ($stage) {
        \App\Enums\ApprovalLaunchStage::Preparing => 'border-gray-300 bg-gray-50 text-gray-700',
        \App\Enums\ApprovalLaunchStage::Trial     => 'border-amber-300 bg-amber-50 text-amber-900',
        \App\Enums\ApprovalLaunchStage::Launched  => 'border-emerald-300 bg-emerald-50 text-emerald-800',
    };
    $th = 'px-4 py-2.5 text-left text-[11px] font-semibold text-gray-500 bg-gray-50 border-b border-gray-200 whitespace-nowrap';
    $td = 'px-4 py-2.5 border-b border-gray-100 text-[13px]';
@endphp

@section('content')
<div class="max-w-[960px]">
    @include('approvals._mail_failure')

    {{-- 成功・失敗の帯はレイアウトが出す（ここで出すと画面に 2 回出る）。$errors だけ各ビューの責任 --}}
    @if($errors->any())
        <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4">
            <p class="text-[13px] font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
            <ul class="list-disc list-inside text-[12px] text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mb-2">
        <h1 class="text-lg font-bold text-gray-900">使い始め</h1>
        <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-[12px] font-semibold {{ $stageBadge }}">{{ $stage->label() }}</span>
        {{-- 使い始めたあとは状態の名前だけ（試す人の数は出さない） --}}
        @if($stage !== \App\Enums\ApprovalLaunchStage::Launched)
            <span class="text-[13px] text-gray-600">試す人 {{ $testerCount }} 人</span>
        @endif
    </div>

    @if($stage === \App\Enums\ApprovalLaunchStage::Launched)
        <section class="bg-white rounded-lg border border-gray-200 px-4 py-4">
            <p class="text-[13px] text-gray-800">使い始めました（{{ \App\Support\JapanTime::format(\App\Support\Approval\LaunchState::launchedAt()) }}）。</p>
        </section>
    @else
        <p class="text-[12px] text-gray-500 mb-5 max-w-[720px]">
            使い始める前に、試す人を選んで申請を一通り回してみます。試す人が 1 人以上いるあいだ（試す期間）は、試す人にだけ申請の画面・お知らせ・メールが届きます。ほかの人には今と同じく何も変わりません。
        </p>

        {{-- 欄 1「試す人」 --}}
        <section aria-labelledby="launch-testers-heading" class="bg-white rounded-lg border border-gray-200 mb-5">
            <div class="px-4 py-3 border-b border-gray-200">
                <h2 id="launch-testers-heading" class="text-[14px] font-bold text-gray-900">試す人</h2>
                <p class="mt-0.5 text-[12px] text-gray-500">役のある人（社長・部門長・審査の担当・決裁の管理者）を並べています。その下は役の無い試す人です。</p>
            </div>

            {{-- 役のある人と役の無い試す人の一覧（people）と、「ほかの人を足す」の検索の結果（found）。行の作りは同じ --}}
            @foreach(['people' => $people, 'found' => $found] as $list => $rows)
                @if($list === 'found')
                    <div class="border-t border-gray-200 px-4 pt-3 pb-3">
                        <h3 class="text-[13px] font-bold text-gray-900">ほかの人を足す</h3>
                        <p class="mt-0.5 text-[12px] text-gray-500">役の無い人は、名前か社員番号で探して試す人にします（有効な人だけ出します）。</p>
                        <form method="GET" action="{{ route('approvals.admin.launch.index') }}" role="search" class="mt-2 flex flex-wrap items-end gap-2">
                            <div class="min-w-0 flex-1 basis-56 max-w-[360px]">
                                <label for="launch-search" class="block text-[12px] font-semibold text-gray-700 mb-1">名前か社員番号で探す</label>
                                <input id="launch-search" type="search" name="q" value="{{ $q }}" maxlength="100" class="w-full h-[38px] px-2.5 border border-gray-300 rounded-md text-[13px] focus:border-emerald-500 focus:outline-none">
                            </div>
                            <button type="submit" class="h-[38px] px-4 bg-white border border-gray-300 hover:bg-gray-50 rounded-md text-[13px] font-semibold text-gray-700 cursor-pointer">探す</button>
                        </form>
                        @if($q !== '' && $rows === [])
                            <p class="mt-2 text-[12px] text-gray-500">当てはまる有効な人はいません。</p>
                        @endif
                        @if($overflow !== null)
                            <p class="mt-2 text-[12px] text-amber-800">{{ $overflow }}</p>
                        @endif
                    </div>
                @elseif($rows === [])
                    <p class="px-4 py-6 text-center text-[13px] text-gray-400">役のある人も試す人も、まだいません。</p>
                @endif

                @if($rows !== [])
                    <ul class="divide-y divide-gray-100 {{ $list === 'found' ? 'border-t border-gray-100' : '' }}">
                        @foreach($rows as $person)
                            @php
                                $user   = $person['user'];
                                $active = ! $user->trashed() && $user->status === \App\Enums\UserStatus::Active;
                            @endphp
                            <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5">
                                <div class="min-w-0 flex-1 basis-56">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="text-[13px] font-semibold text-gray-900 break-words">{{ $user->name }}</span>
                                        @if($user->employee_number !== null)
                                            <span class="text-[11px] text-gray-500 tabular-nums">{{ $user->employee_number }}</span>
                                        @endif
                                        @if($user->trashed())
                                            <span class="rounded border border-gray-300 bg-gray-100 px-1.5 py-px text-[11px] font-semibold text-gray-700">削除済み</span>
                                        @elseif(! $active)
                                            <span class="rounded border border-gray-300 bg-gray-100 px-1.5 py-px text-[11px] font-semibold text-gray-700">無効</span>
                                        @endif
                                    </div>
                                    <p class="mt-0.5 text-[12px] text-gray-600 break-words">{{ $person['roles'] === [] ? '役なし' : implode('・', $person['roles']) }}</p>
                                </div>
                                <div class="ml-auto flex flex-wrap items-center justify-end gap-2">
                                    @if($person['tester'])
                                        <span class="rounded-full border border-emerald-300 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-800">試す人</span>
                                        <form method="POST" action="{{ route('approvals.admin.launch.testers.destroy', $user) }}" class="flex items-center gap-2"
                                              x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                                            @csrf
                                            @method('DELETE')
                                            <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
                                            <button type="submit" :disabled="submitting" aria-label="{{ $user->name }} さんを試す人から外す" class="px-3 py-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 rounded-md text-[12px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">外す</button>
                                        </form>
                                    @elseif($active)
                                        <form method="POST" action="{{ route('approvals.admin.launch.testers.store') }}" class="flex items-center gap-2"
                                              x-data="approvalSubmitOnce()" x-on:submit="onSubmit($event)" x-on:pageshow.window="resetSubmit()">
                                            @csrf
                                            <input type="hidden" name="user_id" value="{{ $user->id }}">
                                            <span role="status" x-text="submitting ? '送っています…' : ''" class="text-[12px] text-gray-600 whitespace-nowrap"></span>
                                            <button type="submit" :disabled="submitting" aria-label="{{ $user->name }} さんを試す人にする" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-[12px] font-semibold cursor-pointer disabled:cursor-not-allowed disabled:opacity-60">試す人にする</button>
                                        </form>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
        </section>

        {{-- 欄 2「試せる部門と種類」（TrialCoverage::overview()） --}}
        <section aria-labelledby="launch-coverage-heading" class="bg-white rounded-lg border border-gray-200">
            <div class="px-4 py-3 border-b border-gray-200">
                <h2 id="launch-coverage-heading" class="text-[14px] font-bold text-gray-900">試せる部門と種類</h2>
                <p class="mt-0.5 text-[12px] text-gray-500">試す期間に申請を出せるのは、回る先の人（部門長・審査の担当の 1 人以上・社長）がみな試す人のときだけです。○ は試す人がそろっている所、× は試す人に入っていない人です。</p>
            </div>

            <dl class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 border-b border-gray-200 text-[13px]">
                <dt class="font-semibold text-gray-700">社長</dt>
                <dd class="text-gray-900">
                    @if($coverage['president']['ok'])
                        <span class="font-bold text-emerald-700" aria-hidden="true">○</span><span class="sr-only">そろっている:</span>
                    @else
                        <span class="font-bold text-red-700" aria-hidden="true">×</span><span class="sr-only">足りない:</span>
                    @endif
                    {{ $coverage['president']['user']?->name ?? '未設定' }}
                </dd>
            </dl>

            <h3 class="px-4 pt-3 pb-2 text-[13px] font-bold text-gray-900">部門ごとの部門長</h3>
            <div class="scroll-hint at-start">
                <div class="scroll-hint-inner">
            {{-- 2 列にする（375px の幅でも ○／× の列が横スクロールの先に隠れないように）。会社名は部門名の下に小さく --}}
            <table class="w-full border-collapse">
                <thead>
                    <tr>
                        <th scope="col" class="{{ $th }}">部門</th>
                        <th scope="col" class="{{ $th }}">部門長</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($coverage['departments'] as $row)
                        <tr>
                            <td class="{{ $td }} break-words">
                                <span class="block text-gray-900">{{ $row['department']->name }}</span>
                                <span class="block text-[11px] text-gray-500">{{ $row['department']->company->name }}</span>
                            </td>
                            <td class="{{ $td }} text-gray-900">
                                @if($row['ok'])
                                    <span class="font-bold text-emerald-700" aria-hidden="true">○</span><span class="sr-only">そろっている:</span>
                                @else
                                    <span class="font-bold text-red-700" aria-hidden="true">×</span><span class="sr-only">足りない:</span>
                                @endif
                                {{ $row['head']?->name ?? '未設定' }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="px-4 py-6 text-center text-[13px] text-gray-400">部門はまだありません。</td></tr>
                    @endforelse
                </tbody>
            </table>
                </div>{{-- /scroll-hint-inner --}}
                <div class="scroll-hint-text">← スクロールできます →</div>
            </div>{{-- /scroll-hint --}}

            <h3 class="px-4 pt-4 pb-2 text-[13px] font-bold text-gray-900">種類ごとの審査の担当</h3>
            <div class="scroll-hint at-start">
                <div class="scroll-hint-inner">
            {{-- 2 列にする（部門の表と同じ理由）。使える部門は種類名の下に小さく --}}
            <table class="w-full border-collapse">
                <thead>
                    <tr>
                        <th scope="col" class="{{ $th }}">種類</th>
                        <th scope="col" class="{{ $th }}">審査の担当</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($coverage['types'] as $row)
                        <tr>
                            <td class="{{ $td }} break-words">
                                <span class="block text-gray-900">{{ $row['type']->name }}</span>
                                <span class="block text-[11px] text-gray-500">{{ $row['limitedTo'] === [] ? '全部門' : implode('・', $row['limitedTo']) . 'だけ' }}</span>
                            </td>
                            <td class="{{ $td }} text-gray-900">
                                @if($row['ok'])
                                    <span class="font-bold text-emerald-700" aria-hidden="true">○</span><span class="sr-only">そろっている:</span>
                                @else
                                    <span class="font-bold text-red-700" aria-hidden="true">×</span><span class="sr-only">足りない:</span>
                                @endif
                                審査部門「{{ $row['type']->reviewDepartment->name }}」の担当
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="2" class="px-4 py-6 text-center text-[13px] text-gray-400">使っている種類はまだありません。</td></tr>
                    @endforelse
                </tbody>
            </table>
                </div>{{-- /scroll-hint-inner --}}
                <div class="scroll-hint-text">← スクロールできます →</div>
            </div>{{-- /scroll-hint --}}

            <div class="px-4 py-3">
                <h3 class="text-[13px] font-bold text-gray-900">いま試せる組み合わせ</h3>
                @if($coverage['combinations'] === [])
                    <p class="mt-1 text-[13px] text-gray-500">まだありません</p>
                @else
                    <ul class="mt-1 list-disc list-inside text-[13px] text-gray-900 space-y-0.5">
                        @foreach($coverage['combinations'] as $combination)
                            <li>{{ $combination }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
    @endif
</div>

{{-- 「試す人にする」「外す」の二度押し止めの部品（approvalSubmitOnce。定義は 1 か所） --}}
@include('approvals._submit_once')
@endsection
