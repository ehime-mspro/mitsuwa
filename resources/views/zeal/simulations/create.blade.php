@extends('layouts.app')

@section('title', '試算表 新規作成 — ZEAL')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>ZEAL</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('zeal.simulations.index') }}" class="text-gray-500 hover:text-emerald-600">経営試算表</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">新規作成</span>
@endsection

@section('content')
    <h1 class="text-lg font-bold text-gray-900 mb-5">試算表 新規作成</h1>

    <form action="{{ route('zeal.simulations.store') }}" method="POST">
        @csrf

        <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; max-width: 760px;">
            <div style="margin-bottom: 18px;">
                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">
                    会計年度 <span style="color: #dc2626;">*</span>
                </label>
                @if(count($candidates) > 0)
                    <select name="fiscal_year"
                            style="width: 100%; max-width: 300px; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; background: white;">
                        @foreach($candidates as $year)
                            <option value="{{ $year }}" {{ $year === $currentFy ? 'selected' : '' }}>
                                {{ $year }}年度（{{ $year }}/06 〜 {{ $year + 1 }}/05）
                            </option>
                        @endforeach
                    </select>
                @else
                    <p style="font-size: 13px; color: #dc2626; padding: 10px 14px; background: #fef2f2; border-radius: 6px;">
                        作成可能な年度がありません（過去 3 年〜未来 3 年は全て作成済み）。
                    </p>
                @endif
                <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">作成すると 12 ヶ月分のセル（固定費は項目マスターのデフォルト値）が自動生成されます。</div>
                @error('fiscal_year') <p style="font-size: 12px; color: #dc2626; margin-top: 4px;">{{ $message }}</p> @enderror
            </div>

            <div style="margin-bottom: 18px;">
                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">名称</label>
                <input type="text" name="name" value="{{ old('name') }}"
                       placeholder="例: 2025年度 経営試算表"
                       style="width: 100%; max-width: 500px; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">
                <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">任意。空欄でも自動採番されます。</div>
                @error('name') <p style="font-size: 12px; color: #dc2626; margin-top: 4px;">{{ $message }}</p> @enderror
            </div>

            <div style="margin-bottom: 18px;">
                <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">備考</label>
                <textarea name="notes" rows="3"
                          style="width: 100%; max-width: 500px; padding: 9px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;">{{ old('notes') }}</textarea>
                @error('notes') <p style="font-size: 12px; color: #dc2626; margin-top: 4px;">{{ $message }}</p> @enderror
            </div>
        </div>

        @if(count($candidates) > 0)
            <x-form-actions submit-label="作成する" :cancel-url="route('zeal.simulations.index')" />
        @endif
    </form>
@endsection
