{{-- 設問管理アイテム（1行分） --}}
@php
    $qTypeEnum = $q->question_type;
    $optCount = $q->options_count;
@endphp
<div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 18px; margin-bottom: 10px; background: #fff; display: flex; align-items: center; justify-content: space-between;"
     onmouseover="this.style.borderColor='#059669';this.style.background='#f0fdf4';" onmouseout="this.style.borderColor='#e5e7eb';this.style.background='#fff';">
    <div style="display: flex; align-items: center; flex: 1;">
        <div style="color: #9ca3af; font-size: 18px; margin-right: 12px; cursor: grab;">☰</div>
        <div>
            <div style="font-weight: 600; margin-bottom: 4px;">{{ $q->sort_order }}. {{ $q->label }}</div>
            <div style="display: flex; gap: 8px; align-items: center;">
                <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; {{ $qTypeEnum->badgeStyle() }}">{{ $qTypeEnum->label() }}</span>
                @if($qTypeEnum === \App\Enums\SurveyQuestionType::Slider)
                    <span style="font-size: 12px; color: #6b7280;">{{ $q->slider_description }}</span>
                @elseif($optCount > 0)
                    <span style="font-size: 12px; color: #6b7280;">選択肢: {{ $optCount }}個</span>
                @endif
                @if($q->is_active)
                    <span style="display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 600; background: #d1fae5; color: #065f46;">有効</span>
                @else
                    <span style="display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 600; background: #f3f4f6; color: #6b7280;">無効</span>
                @endif
            </div>
        </div>
    </div>
    <div style="display: flex; gap: 6px;">
        <a href="{{ route('admin.survey-questions.index', ['department' => $q->department]) }}"
           style="display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 4px; text-decoration: none; background: #fff;">編集</a>
        {{-- この部品は surveyQuestionManager() の x-data の中に描かれるので、Alpine のクリックで直接呼ぶ。
             以前の onclick は Alpine 2 の __x に頼っていて（Alpine 3 に無い）、しかも最初の [x-data] は <body> だったので押しても何も起きなかった --}}
        <button type="button" x-on:click="deleteQuestion({{ $q->id }})"
                style="display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 4px; cursor: pointer; background: #fff;">削除</button>
    </div>
</div>
