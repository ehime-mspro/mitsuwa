@extends('layouts.app')

@section('title', '顧客登録（' . $deptLabel . '）')

@section('content')
<div class="text-sm text-gray-500" style="margin-bottom: 12px;">
    ダッシュボード &gt; {{ $deptLabel }} &gt; <a href="{{ route("{$department}.customers.index") }}" class="text-gray-500 hover:text-emerald-600 hover:underline">顧客一覧</a> &gt; <span class="text-gray-800 font-medium">新規登録</span>
</div>
<h1 style="font-size: 20px; font-weight: 700; margin: 0 0 20px;">顧客登録（{{ $deptLabel }}）</h1>

@if(session('error'))
    <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; font-size: 13px; border-radius: 6px; padding: 10px 14px; margin-bottom: 16px;">
        {{ session('error') }}
    </div>
@endif

@if($errors->any())
    <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; font-size: 13px; border-radius: 6px; padding: 10px 14px; margin-bottom: 16px;">
        <ul style="margin: 0; padding-left: 18px;">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route("{$department}.customers.store") }}">
    @csrf
    @include('buyers._form', ['buyer' => null, 'pivot' => null, 'isEdit' => false])
</form>
@endsection
