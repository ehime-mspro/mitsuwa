@extends('layouts.app')

@section('title', $employee->name)

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>DAD</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('dad.employees.index') }}" class="text-emerald-600 hover:text-emerald-700">従業員管理</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">{{ $employee->name }}</span>
@endsection

@section('content')

    {{-- ヘッダー --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <div class="flex items-center gap-3">
            <span class="text-sm text-gray-500" style="font-variant-numeric: tabular-nums;">{{ $employee->employee_code }}</span>
            <h1 class="text-lg font-bold text-gray-900">{{ $employee->name }}</h1>
            <span style="display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; {{ $employee->status->badgeStyle() }}">{{ $employee->status->label() }}</span>
        </div>
        <div style="display: flex; gap: 8px; align-items: center;">
            <a href="{{ route('dad.employees.index') }}"
               style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; background: #fff;">従業員一覧に戻る</a>
            @if(auth()->user()->role->isManagerOrAbove())
                <a href="{{ route('dad.employees.edit', $employee) }}"
                   style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 6px; text-decoration: none; background: #fff;">編集</a>
            @endif
            @if(auth()->user()->role->isExecutive())
                <form method="POST" action="{{ route('dad.employees.destroy', $employee) }}"
                      onsubmit="return confirm('この従業員を削除しますか？')">
                    @csrf @method('DELETE')
                    <button type="submit"
                            style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 6px; background: #fff; cursor: pointer;">削除</button>
                </form>
            @endif
        </div>
    </div>


    {{-- 基本情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-4">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">基本情報</h2>
        </div>
        <div class="border border-gray-200 rounded-md overflow-hidden" style="display: grid; grid-template-columns: 120px 1fr 120px 1fr;">
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">社員番号</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200" style="font-variant-numeric: tabular-nums;">{{ $employee->employee_code }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">氏名</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200 font-semibold">{{ $employee->name }}</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">フリガナ</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $employee->name_kana ?: '—' }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">役職</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $employee->position ?: '—' }}</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">電話番号</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ $employee->phone ?: '—' }}</dd>
            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-b border-r border-gray-200">入社日</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900 border-b border-gray-200">{{ optional($employee->hire_date)->format('Y年n月j日') ?: '—' }}</dd>

            <dt class="bg-gray-50 px-3.5 py-2.5 text-sm text-gray-600 font-medium border-r border-gray-200">在籍状況</dt>
            <dd class="px-3.5 py-2.5 text-sm text-gray-900" style="grid-column: span 3;">{{ $employee->status->label() }}</dd>
        </div>
    </div>

    {{-- 保有資格 --}}
    @if($employee->qualifications)
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-3">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">保有資格</h2>
        </div>
        <div class="text-sm text-gray-800 leading-relaxed whitespace-pre-wrap">{{ $employee->qualifications }}</div>
    </div>
    @endif

    {{-- 備考 --}}
    @if($employee->notes)
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
        <div class="flex items-center gap-2 mb-3">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">備考</h2>
        </div>
        <div class="text-sm text-gray-800 leading-relaxed whitespace-pre-wrap">{{ $employee->notes }}</div>
    </div>
    @endif

    {{-- 配置履歴 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5">
        <div class="flex items-center gap-2 mb-4">
            <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
            <h2 class="text-base font-bold text-gray-900">配置履歴</h2>
            <span class="text-xs text-gray-500 ml-1">（{{ $assignments->count() }}件）</span>
        </div>

        @if($assignments->isEmpty())
            <div style="padding: 24px; text-align: center; font-size: 13px; color: #9ca3af; background: #f9fafb; border-radius: 6px;">
                この従業員の配置履歴はまだありません。
            </div>
        @else
            <div class="border border-gray-200 rounded-md overflow-hidden" style="overflow-x: auto;">
                <table class="w-full border-collapse">
                    <thead>
                        <tr>
                            <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">案件番号</th>
                            <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">ステータス</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap" style="padding-left: 16px;">工事名</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">役割</th>
                            <th class="px-4 py-2 text-center text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">配置期間</th>
                            <th class="px-4 py-2 text-left text-xs font-semibold text-gray-600 bg-gray-50 border-b-2 border-gray-200 whitespace-nowrap">備考</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($assignments as $a)
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-4 py-2.5 border-b border-gray-100 text-center whitespace-nowrap">
                                    @if($a->project)
                                        <a href="{{ route('dad.projects.show', $a->project) }}" class="text-sm font-semibold text-emerald-600 hover:underline" style="font-variant-numeric: tabular-nums;">{{ $a->project->project_code }}</a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-center whitespace-nowrap">
                                    @if($a->project)
                                        <span style="display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 9999px; font-size: 11px; font-weight: 600; {{ $a->project->status->badgeStyle() }}">{{ $a->project->status->label() }}</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-sm font-medium" style="padding-left: 16px;">{{ optional($a->project)->project_name ?: '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-sm whitespace-nowrap">{{ $a->role ?: '—' }}</td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-xs text-center text-gray-600 whitespace-nowrap">
                                    {{ optional($a->start_date)->format('Y/n/j') ?: '—' }}
                                    〜
                                    {{ optional($a->end_date)->format('Y/n/j') ?: '配置中' }}
                                </td>
                                <td class="px-4 py-2.5 border-b border-gray-100 text-sm text-gray-600">{{ $a->notes ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

@endsection
