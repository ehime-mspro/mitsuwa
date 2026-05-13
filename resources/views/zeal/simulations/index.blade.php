@extends('layouts.app')

@section('title', '経営試算表 — ZEAL')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>ZEAL</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">経営試算表</span>
@endsection

@section('content')
    @php
        // 編集権限: executive / manager のみ。削除は executive のみ
        // 注意: User->role は UserRole Enum。Enum===Enum か Enum::method() で比較する
        $user      = auth()->user();
        $canEdit   = $user && $user->role->isManagerOrAbove();
        $canDelete = $user && $user->role->isExecutive();
    @endphp

    <div class="flex items-center justify-between mb-5">
        <h1 class="text-lg font-bold text-gray-900">経営試算表（年度別）</h1>
        @if($canEdit)
            <a href="{{ route('zeal.simulations.create') }}"
               style="display: inline-flex; align-items: center; padding: 8px 16px; background: #059669; color: white; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none;">
                + 新規作成
            </a>
        @endif
    </div>

    <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px;">
        <div style="font-weight: 600; font-size: 14px; color: #92400e; margin-bottom: 6px;">ZEAL 経営試算表について</div>
        <ul style="font-size: 12px; color: #a16207; margin: 0; padding-left: 18px; line-height: 1.8;">
            <li>ZEAL/DAD は <strong>6月始まり</strong>の会計年度です（例: 2025年度 = 2025/06〜2026/05）</li>
            <li>項目の追加・編集は「<a href="{{ route('admin.master.zeal-simulation-categories.index') }}" style="color: #059669; text-decoration: underline;">項目マスター</a>」から行います</li>
            <li>売上連動行（ロイヤリティ・決済手数料 等）と集計行（経費計・営業利益・累計利益）は自動計算されます</li>
        </ul>
    </div>

    <div style="background: white; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f9fafb;">
                    <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 130px;">会計年度</th>
                    <th style="padding: 12px 16px; text-align: left; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb;">名称</th>
                    <th style="padding: 12px 16px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 160px;">期間</th>
                    <th style="padding: 12px 16px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 130px;">作成者</th>
                    <th style="padding: 12px 16px; text-align: center; font-size: 12px; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; width: 200px;">操作</th>
                </tr>
            </thead>
            <tbody>
                @forelse($simulations as $sim)
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 14px 16px; font-size: 15px; font-weight: 700; color: #059669;">{{ $sim->fiscal_year }}年度</td>
                        <td style="padding: 14px 16px; font-size: 13px; color: #111827;">{{ $sim->name ?? '—' }}</td>
                        <td style="padding: 14px 16px; text-align: center; font-size: 12px; color: #6b7280;">
                            {{ $sim->fiscal_year }}/06 〜 {{ $sim->fiscal_year + 1 }}/05
                        </td>
                        <td style="padding: 14px 16px; text-align: center; font-size: 12px; color: #6b7280;">
                            {{ $sim->createdBy?->name ?? '—' }}
                        </td>
                        <td style="padding: 14px 16px; text-align: center; white-space: nowrap;">
                            <a href="{{ route('zeal.simulations.show', $sim) }}"
                               style="display: inline-block; padding: 4px 12px; font-size: 12px; font-weight: 600; color: #1d4ed8; border: 1px solid #1d4ed8; border-radius: 4px; text-decoration: none; background: #fff;">詳細</a>
                            @if($canEdit)
                                <a href="{{ route('zeal.simulations.edit', $sim) }}"
                                   style="display: inline-block; padding: 4px 12px; font-size: 12px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 4px; text-decoration: none; background: #fff; margin-left: 4px;">編集</a>
                            @endif
                            @if($canDelete)
                                <form action="{{ route('zeal.simulations.destroy', $sim) }}" method="POST" style="display: inline-block; margin-left: 4px;"
                                      onsubmit="return confirm('{{ $sim->fiscal_year }}年度の試算表を削除しますか？月別データも全て削除されます。');">
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
                        <td colspan="5" style="padding: 40px 16px; text-align: center; color: #9ca3af; font-size: 13px;">
                            試算表が作成されていません。「+ 新規作成」から年度を選択してください。
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
