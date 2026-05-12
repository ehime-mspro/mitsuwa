@extends('layouts.app')

@section('title', 'ZEAL 試算表 項目マスター')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>システム管理</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">ZEAL 試算表 項目マスター</span>
@endsection

@section('content')
    <div class="flex items-center justify-between mb-5">
        <h1 class="text-lg font-bold text-gray-900">ZEAL 試算表 項目マスター</h1>
        <a href="{{ route('admin.master.zeal-simulation-categories.create') }}"
           style="display: inline-flex; align-items: center; padding: 8px 16px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none;">
            + 新規項目を追加
        </a>
    </div>

    <div style="background: #fefce8; border: 1px solid #fde68a; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px;">
        <div style="font-weight: 600; font-size: 14px; color: #92400e; margin-bottom: 6px;">項目マスターについて</div>
        <ul style="font-size: 12px; color: #a16207; margin: 0; padding-left: 18px; line-height: 1.8;">
            <li>試算表の縦軸（賃料・委託費・売上 等）をここで管理します</li>
            <li>「計算タイプ」で挙動を切り替えます：<strong>手入力</strong> / <strong>固定額</strong>（毎月同額のデフォルト）/ <strong>売上連動</strong>（売上 × 率）/ <strong>システム計算</strong></li>
            <li><span style="background: #d1fae5; padding: 2px 8px; border-radius: 4px; font-weight: 600;">システム固定</span> 行（経費計・営業利益・累計利益）は削除・グループ変更ができません</li>
            <li>項目追加・削除後、既存の試算表に自動反映するには「実績反映」ボタンの実行が必要です</li>
        </ul>
    </div>

    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f9fafb;">
                    <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 80px;">並び順</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 130px;">コード</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb;">項目名</th>
                    <th style="padding: 12px 16px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 90px;">グループ</th>
                    <th style="padding: 12px 16px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 160px;">計算タイプ</th>
                    <th style="padding: 12px 16px; text-align: right; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 120px;">デフォルト額</th>
                    <th style="padding: 12px 16px; text-align: right; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 80px;">率(%)</th>
                    <th style="padding: 12px 16px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 80px;">状態</th>
                    <th style="padding: 12px 16px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 130px;">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($categories as $cat)
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 12px 16px; font-size: 13px; color: #6b7280;">{{ $cat->sort_order }}</td>
                        <td style="padding: 12px 16px; font-size: 12px; color: #374151; font-family: 'SFMono-Regular','Consolas',monospace;">{{ $cat->code }}</td>
                        <td style="padding: 12px 16px; font-size: 13px; color: #111827; font-weight: 600;">
                            {{ $cat->name }}
                            @if($cat->is_system)
                                <span style="display: inline-block; margin-left: 6px; padding: 2px 8px; background: #d1fae5; color: #047857; font-size: 10px; font-weight: 700; border-radius: 4px;">システム固定</span>
                            @endif
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            <span style="display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; background: {{ $cat->group_type->backgroundColor() }}; color: #374151;">
                                {{ $cat->group_type->label() }}
                            </span>
                        </td>
                        <td style="padding: 12px 16px; text-align: center; font-size: 12px; color: #4b5563;">{{ $cat->calc_type->label() }}</td>
                        <td style="padding: 12px 16px; text-align: right; font-size: 13px; color: #111827; font-weight: 500;">
                            @if($cat->default_amount !== null)
                                {{ number_format($cat->default_amount) }}円
                            @else
                                <span style="color: #9ca3af;">—</span>
                            @endif
                        </td>
                        <td style="padding: 12px 16px; text-align: right; font-size: 13px; color: #111827; font-weight: 500;">
                            @if($cat->rate_percent !== null)
                                {{ rtrim(rtrim(number_format($cat->rate_percent, 3), '0'), '.') }}%
                            @else
                                <span style="color: #9ca3af;">—</span>
                            @endif
                        </td>
                        <td style="padding: 12px 16px; text-align: center;">
                            @if($cat->is_active)
                                <span style="display: inline-block; padding: 2px 10px; background: #d1fae5; color: #047857; font-size: 11px; font-weight: 600; border-radius: 999px;">有効</span>
                            @else
                                <span style="display: inline-block; padding: 2px 10px; background: #f3f4f6; color: #6b7280; font-size: 11px; font-weight: 600; border-radius: 999px;">無効</span>
                            @endif
                        </td>
                        <td style="padding: 12px 16px; text-align: center; white-space: nowrap;">
                            <a href="{{ route('admin.master.zeal-simulation-categories.edit', $cat) }}"
                               style="display: inline-block; padding: 4px 12px; font-size: 12px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 4px; text-decoration: none; background: #fff;">編集</a>
                            @if(!$cat->is_system)
                                <form action="{{ route('admin.master.zeal-simulation-categories.destroy', $cat) }}" method="POST" style="display: inline-block; margin-left: 4px;"
                                      onsubmit="return confirm('「{{ $cat->name }}」を削除しますか？既存試算表のセル値も削除されます。');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            style="padding: 4px 12px; font-size: 12px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 4px; background: #fff; cursor: pointer;">削除</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" style="padding: 40px 16px; text-align: center; color: #9ca3af; font-size: 13px;">
                            項目が登録されていません。
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
