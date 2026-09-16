@extends('layouts.app')

@section('title', '決裁申請')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">決裁申請</span>
@endsection

@section('content')
<div class="max-w-[640px]">

    @if(session('warning'))
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-800">
            {{ session('warning') }}
        </div>
    @endif

    <h1 class="text-lg font-bold text-gray-900 mb-4">決裁申請</h1>

    <div class="bg-white rounded-lg border border-gray-200 px-5 py-5">
        <p class="text-[13px] text-gray-700 leading-relaxed mb-4">
            決裁の機能は準備中です。使い始める日が決まったらお知らせします。
        </p>

        <dl class="text-[13px] text-gray-700 space-y-1.5 mb-5">
            <div class="flex gap-3">
                <dt class="w-[6em] shrink-0 text-gray-500">氏名</dt>
                <dd class="font-medium text-gray-900">{{ $user->name }}</dd>
            </div>
            <div class="flex gap-3">
                <dt class="w-[6em] shrink-0 text-gray-500">ログインID</dt>
                <dd class="font-mono text-gray-900">{{ $loginId }}</dd>
            </div>
        </dl>

        <div class="flex flex-wrap gap-3 text-[13px]">
            <a href="{{ route('password.change') }}" class="text-emerald-600 hover:underline">パスワードを変更する</a>
        </div>
    </div>
</div>
@endsection
