@extends('layouts.app')

@section('title', 'アンケート登録 — ' . $buyer->full_name)

@section('content')
<div class="text-sm text-gray-500" style="margin-bottom: 12px;">
    ダッシュボード &gt; {{ $deptLabel }} &gt; <a href="{{ route("{$department}.customers.index") }}" class="text-gray-500 hover:text-emerald-600 hover:underline">顧客一覧</a> &gt; <a href="{{ route("{$department}.customers.show", $buyer) }}" class="text-gray-500 hover:text-emerald-600 hover:underline">{{ $buyer->full_name }}</a> &gt; <span class="text-gray-800 font-medium">アンケート登録</span>
</div>
<h1 style="font-size: 20px; font-weight: 700; margin: 0 0 20px;">アンケート登録</h1>

@if($errors->any())
    <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; font-size: 13px; border-radius: 6px; padding: 10px 14px; margin-bottom: 16px;">
        <ul style="margin: 0; padding-left: 18px;">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form method="POST" action="{{ route("{$department}.customers.surveys.store", $buyer) }}">
    @csrf
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        {{-- ヘッダ情報 --}}
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 24px;">
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">来場日<span class="text-red-600" style="margin-left: 2px;">*</span></label>
                <input type="date" name="survey_date" value="{{ old('survey_date', date('Y-m-d')) }}"
                       class="form-input w-full" style="height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
            </div>
            @if($department === 'housing')
                <div>
                    <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">来場分譲地</label>
                    <select name="project_id" class="form-input w-full" style="height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
                        <option value="">（任意）選択してください</option>
                        @foreach($projects as $pId => $pName)
                            <option value="{{ $pId }}" {{ old('project_id') == $pId ? 'selected' : '' }}>{{ $pName }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">担当者</label>
                    <select name="staff_user_id" class="form-input w-full" style="height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
                        <option value="">選択してください</option>
                        @foreach($staffList as $sId => $sName)
                            <option value="{{ $sId }}" {{ old('staff_user_id') == $sId ? 'selected' : '' }}>{{ $sName }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>

        {{-- アンケート設問 --}}
        @if($questions->count() > 0)
            @include('buyers._survey_form', ['questions' => $questions, 'existingAnswers' => []])
        @else
            <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 12px 16px; font-size: 13px; color: #0c4a6e;">
                ℹ️ この部署のアンケート設問は未登録です。
            </div>
        @endif

        {{-- 備考 --}}
        <div style="margin-top: 20px;">
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">備考</label>
            <textarea name="survey_memo" rows="3" placeholder="メモ等"
                      style="width: 100%; border: 1px solid #d1d5db; border-radius: 6px; padding: 10px 12px; font-size: 14px; resize: vertical;">{{ old('survey_memo') }}</textarea>
        </div>

        <x-form-actions submit-label="登録する" :cancel-url="route(&quot;{$department}.customers.show&quot;, $buyer)" />
    </div>
</form>
@endsection
