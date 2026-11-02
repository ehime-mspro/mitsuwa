{{-- 一覧の設問の 1 行。$number は有効な設問だけの通し番号（無効の設問は null で番号を出さない）、
     $isFirst / $isLast は無効の設問も含めた部署の並びの端（端の ↑・↓ は押せない。理由はボタンを包む span の title。Top trap #12） --}}
@php
    $qTypeEnum = $q->question_type;
    $optCount = $q->options_count;
@endphp
<div id="question-{{ $q->id }}" class="sq-row{{ $q->is_active ? '' : ' sq-row-off' }}">
    <div class="sq-moves">
        @if($isFirst)
            <span title="先頭の設問です" style="display: inline-flex;"><button type="button" class="sq-move" disabled aria-label="上へ動かす">↑</button></span>
        @else
            <form method="POST" action="{{ route('admin.survey-questions.move', $q) }}">
                @csrf
                <input type="hidden" name="direction" value="up">
                <button type="submit" class="sq-move" aria-label="上へ動かす">↑</button>
            </form>
        @endif
        @if($isLast)
            <span title="末尾の設問です" style="display: inline-flex;"><button type="button" class="sq-move" disabled aria-label="下へ動かす">↓</button></span>
        @else
            <form method="POST" action="{{ route('admin.survey-questions.move', $q) }}">
                @csrf
                <input type="hidden" name="direction" value="down">
                <button type="submit" class="sq-move" aria-label="下へ動かす">↓</button>
            </form>
        @endif
    </div>
    <div class="sq-row-body">
        <div class="sq-row-label">{{ $number !== null ? $number . '. ' : '' }}{{ $q->label }}</div>
        <div class="sq-row-meta">
            <span class="sq-badge" style="{{ $qTypeEnum->badgeStyle() }}">{{ $qTypeEnum->label() }}</span>
            @if($qTypeEnum === \App\Enums\SurveyQuestionType::Slider)
                <span class="sq-row-note">{{ $q->slider_description }}</span>
            @elseif($optCount > 0)
                <span class="sq-row-note">選択肢: {{ $optCount }}個</span>
            @endif
            @if($q->is_active)
                <span class="sq-pill">有効</span>
            @else
                <span class="sq-pill sq-pill-off">無効</span>
            @endif
            @if($q->answers_count > 0)
                <span class="sq-row-note">回答 {{ $q->answers_count }}件</span>
            @endif
        </div>
    </div>
    <div class="sq-row-actions">
        <a href="{{ route('admin.survey-questions.edit', $q) }}" class="sq-edit">編集</a>
        {{-- 回答がある設問は消さずに無効にする（destroy()）。確認の文の設問文は Js::from で渡す（Bug #76） --}}
        <form method="POST" action="{{ route('admin.survey-questions.destroy', $q) }}"
              onsubmit="return confirm('「' + {{ \Illuminate\Support\Js::from($q->label) }} + '」を削除しますか？回答がある設問は無効にします。');">
            @csrf
            @method('DELETE')
            <button type="submit" class="sq-delete">削除</button>
        </form>
    </div>
</div>
