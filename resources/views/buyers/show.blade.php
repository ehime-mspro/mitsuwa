@extends('layouts.app')

@section('title', '顧客詳細 — ' . $buyer->full_name)

@section('content')
<div class="text-sm text-gray-500" style="margin-bottom: 12px;">
    ダッシュボード &gt; {{ $deptLabel }} &gt; <a href="{{ route("{$department}.customers.index") }}" class="text-gray-500 hover:text-emerald-600 hover:underline">顧客一覧</a> &gt; <span class="text-gray-800 font-medium">{{ $buyer->full_name }}</span>
</div>

{{-- タイトル + ランクバッジ + 操作 --}}
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
    <div style="display: flex; align-items: center; gap: 14px;">
        <h1 style="font-size: 20px; font-weight: 700; margin: 0;">顧客詳細</h1>
        @if($pivot)
            <span style="display: inline-block; padding: 4px 16px; border-radius: 4px; font-size: 14px; font-weight: 700; {{ $pivot->rank_badge_style }}">{{ $pivot->rank->label() }}</span>
        @endif
    </div>
    <div style="display: flex; gap: 8px; align-items: center;">
        <a href="{{ route("{$department}.customers.index") }}"
           style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #6b7280; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; background: #fff;">顧客一覧に戻る</a>
        <a href="{{ route("{$department}.customers.edit", $buyer) }}"
           style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 6px; text-decoration: none; background: #fff;">編集</a>
        <form method="POST" action="{{ route("{$department}.customers.destroy", $buyer) }}" onsubmit="return confirm('本当に削除しますか？');" style="display: inline;">
            @csrf
            @method('DELETE')
            <button type="submit" style="display: inline-block; padding: 6px 16px; font-size: 13px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 6px; cursor: pointer; background: #fff;">削除</button>
        </form>
    </div>
</div>

@if(session('success'))
    <div class="bg-emerald-50 border border-emerald-300 text-emerald-800 text-sm rounded-md px-4 py-3" style="margin-bottom: 16px;">
        {{ session('success') }}
    </div>
@endif

{{-- 基本情報 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div style="font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; display: flex; align-items: center; gap: 8px;">
        <span style="width: 4px; height: 18px; background: #059669; border-radius: 2px; flex-shrink: 0;"></span>
        基本情報
    </div>
    <dl style="display: grid; grid-template-columns: 140px 1fr 140px 1fr; gap: 0; border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden;">
        <dt style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">氏名</dt>
        <dd style="padding: 10px 14px; font-size: 14px; color: #111827; border-bottom: 1px solid #e5e7eb; margin: 0;">
            <strong>{{ $buyer->full_name }}</strong>
            @if($buyer->full_name_kana)
                <span style="color: #6b7280;">（{{ $buyer->full_name_kana }}）</span>
            @endif
        </dd>
        <dt style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">取得日</dt>
        <dd style="padding: 10px 14px; font-size: 14px; color: #111827; border-bottom: 1px solid #e5e7eb; margin: 0;">
            {{ $pivot ? $pivot->acquired_date->format('Y/m/d') : '—' }}
        </dd>

        <dt style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">生年月日</dt>
        <dd style="padding: 10px 14px; font-size: 14px; color: #111827; border-bottom: 1px solid #e5e7eb; margin: 0;">{{ $buyer->birth_date_display ?: '—' }}</dd>
        <dt style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">ご家族</dt>
        <dd style="padding: 10px 14px; font-size: 14px; color: #111827; border-bottom: 1px solid #e5e7eb; margin: 0;">
            @if($buyer->family_adults !== null || $buyer->family_children !== null)
                大人 {{ $buyer->family_adults ?? 0 }}人 / 子供 {{ $buyer->family_children ?? 0 }}人
            @else
                —
            @endif
        </dd>

        <dt style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">所属部署</dt>
        <dd style="padding: 10px 14px; font-size: 14px; color: #111827; border-bottom: 1px solid #e5e7eb; margin: 0;">
            @foreach($buyer->departments as $dept)
                @php $dEnum = \App\Enums\BuyerDepartment::from($dept->department); @endphp
                <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; {{ $dEnum->badgeStyle() }}">{{ $dEnum->label() }}</span>
            @endforeach
        </dd>
        <dt style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">ランク</dt>
        <dd style="padding: 10px 14px; font-size: 14px; color: #111827; border-bottom: 1px solid #e5e7eb; margin: 0;">
            @if($pivot)
                <span style="display: inline-block; padding: 3px 12px; border-radius: 4px; font-size: 12px; font-weight: 700; {{ $pivot->rank_badge_style }}">{{ $pivot->rank->fullLabel() }}</span>
            @endif
        </dd>

        <dt style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">住所</dt>
        <dd style="padding: 10px 14px; font-size: 14px; color: #111827; border-bottom: 1px solid #e5e7eb; margin: 0; grid-column: span 3;">{{ $buyer->full_address ?: '—' }}</dd>

        <dt style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">電話番号</dt>
        <dd style="padding: 10px 14px; font-size: 14px; color: #111827; border-bottom: 1px solid #e5e7eb; margin: 0;">{{ $buyer->phone ?: '—' }}</dd>
        <dt style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">メールアドレス</dt>
        <dd style="padding: 10px 14px; font-size: 14px; color: #111827; border-bottom: 1px solid #e5e7eb; margin: 0;">{{ $buyer->email ?: '—' }}</dd>

        <dt style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">ご職業</dt>
        <dd style="padding: 10px 14px; font-size: 14px; color: #111827; border-bottom: 1px solid #e5e7eb; margin: 0;">
            @if($buyer->occupation || $buyer->employer)
                {{ $buyer->occupation ?? '' }}{{ $buyer->employer ? ' / ' . $buyer->employer : '' }}
            @else
                —
            @endif
        </dd>
        <dt style="background: #f9fafb; padding: 10px 14px; font-size: 13px; color: #4b5563; font-weight: 500; border-bottom: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">勤続年数</dt>
        <dd style="padding: 10px 14px; font-size: 14px; color: #111827; border-bottom: 1px solid #e5e7eb; margin: 0;">{{ $buyer->years_employed !== null ? $buyer->years_employed . '年' : '—' }}</dd>
    </dl>

    @if($buyer->memo)
        <div style="margin-top: 14px;">
            <div style="font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 4px;">備考</div>
            <div style="font-size: 14px; color: #374151; white-space: pre-wrap;">{{ $buyer->memo }}</div>
        </div>
    @endif
</div>

{{-- アンケート履歴 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
        <div style="font-size: 15px; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 8px;">
            <span style="width: 4px; height: 18px; background: #059669; border-radius: 2px; flex-shrink: 0;"></span>
            アンケート履歴
        </div>
        <a href="{{ route("{$department}.customers.surveys.create", $buyer) }}"
           class="inline-flex items-center gap-1 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold px-3 py-1.5 rounded-md" style="font-size: 13px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            アンケートを追加
        </a>
    </div>

    @forelse($buyer->surveys as $survey)
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; border: 1px solid #e5e7eb; border-radius: 6px; margin-bottom: 8px; background: #fff;"
             onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='#fff'">
            <div style="display: flex; align-items: center; gap: 16px;">
                <div style="font-size: 14px; font-weight: 600;">{{ $survey->survey_date->format('Y/m/d') }}</div>
                <div style="font-size: 13px; color: #6b7280;">{{ $survey->project ? $survey->project->project_name : '—' }}</div>
                @if($survey->staff_name)
                    <div style="font-size: 13px; color: #6b7280;">担当: {{ $staffNames[$survey->staff_name] ?? $survey->staff_name }}</div>
                @endif
            </div>
            <div style="display: flex; gap: 6px; align-items: center;">
                <a href="{{ route("{$department}.customers.surveys.edit", [$buyer, $survey]) }}"
                   style="display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #b45309; border: 1px solid #b45309; border-radius: 5px; text-decoration: none; background: #fff;">詳細</a>
                <a href="{{ route("{$department}.customers.surveys.edit", [$buyer, $survey]) }}"
                   style="display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 4px; text-decoration: none; background: #fff;">編集</a>
                <form method="POST" action="{{ route("{$department}.customers.surveys.destroy", [$buyer, $survey]) }}" onsubmit="return confirm('このアンケートを削除しますか？');" style="display: inline;">
                    @csrf
                    @method('DELETE')
                    <button type="submit" style="display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 4px; cursor: pointer; background: #fff;">削除</button>
                </form>
            </div>
        </div>
    @empty
        <div style="text-align: center; padding: 24px; color: #9ca3af; font-size: 14px;">
            アンケート履歴はまだありません
        </div>
    @endforelse
</div>

{{-- 関連案件（将来実装予定） --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="border-style: dashed; border-color: #d1d5db; background: #fafafa;">
    <div style="font-size: 15px; font-weight: 700; color: #6b7280; display: flex; align-items: center; gap: 8px;">
        <span style="width: 4px; height: 18px; background: #d1d5db; border-radius: 2px; flex-shrink: 0;"></span>
        関連案件 <span style="font-size: 12px; font-weight: 400; color: #9ca3af;">（将来実装予定）</span>
    </div>
    <div style="font-size: 13px; color: #9ca3af; margin-top: 8px;">建売契約・注文住宅案件・不動産案件との紐づけは今後のアップデートで対応します。</div>
</div>
@endsection
