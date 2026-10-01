@extends('layouts.app')

@section('title', 'お知らせ')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <a href="{{ route('approvals.home') }}" class="hover:text-emerald-600 transition-colors">決裁申請</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">お知らせ</span>
@endsection

{{-- お知らせ一覧（画面⑥・段階3 設計書 §5.6）。新しい順に 20 件ずつ。未読は太字と「未読」の印。押すと既読にして申請の詳細へ。
     「未読だけ」の絞り込みは作らない（D15）。スマホの幅でも 1 件 1 枚（表は使わない） --}}
@section('content')
<div class="max-w-[960px]">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <h1 class="text-lg font-bold text-gray-900">お知らせ</h1>
        @if($hasUnread)
            <form method="POST" action="{{ route('approvals.notices.readAll') }}">
                @csrf
                <button type="submit" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-[13px] font-semibold text-gray-700 hover:bg-gray-50">すべて既読にする</button>
            </form>
        @endif
    </div>

    <div class="bg-white rounded-lg border border-gray-200">
        @if($notices->isEmpty())
            <p class="px-5 py-8 text-center text-[13px] text-gray-400">お知らせはありません。</p>
        @else
            <ul class="divide-y divide-gray-100">
                @foreach($notices as $notice)
                    @include('approvals.notices._item', ['notice' => $notice])
                @endforeach
            </ul>
            @include('approvals._pager', ['paginator' => $notices, 'pages' => $pages])
        @endif
    </div>
</div>
@endsection
