{{-- アンケートフォーム共通パーシャル: 設問を動的レンダリング --}}
{{--
    描く値: 入力エラーで戻ったなら送った値（old('survey')。送られていない設問は「回答なし」）、でなければ保存済みの値（編集画面）。
    1 問の状態（選択肢に無い値・付随の欄・スライダーの「回答しない」）は SurveyAnswers::formState() の 1 か所で決める。
    ⚠ 条件分岐付き選択の付随の欄は、選択肢をまたいで同じ名前を持つ。選んでいない選択肢の欄は :disabled で送らない
      （隠れた欄も送られ、後ろの空の欄が勝って、打った値が消えていた。Bug #3 と同じ形）。
    ⚠ スライダーの範囲の入力欄は name を持たない（ブラウザは値を今の下限〜上限に丸めて送る）。送るのは hidden の 1 つだけ。
--}}
@php
    $qLetters = ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T'];
    $existingAnswers = $existingAnswers ?? [];
    $returnedWithInput = session()->hasOldInput();
@endphp

<div style="background: #fefce8; border: 2px solid #fde68a; border-radius: 10px; padding: 24px; margin-top: 24px;">
    <div style="font-size: 16px; font-weight: 700; color: #92400e; margin-bottom: 20px; text-align: center; letter-spacing: 1px;">📋 ご来場アンケート</div>

    @foreach($questions as $idx => $q)
        @php
            $qId = $q->id;
            $letter = $qLetters[$idx] ?? ($idx + 1);
            $qType = $q->getRawOriginal('question_type');
            $settings = $q->settings ?? [];
            $saved = $existingAnswers[$qId] ?? null;
            $shown = $returnedWithInput ? old("survey.{$qId}") : $saved;
            $state = \App\Support\SurveyAnswers::formState($q, $shown, $saved);
        @endphp

        <div style="margin-bottom: 20px;">
            <div style="font-size: 14px; font-weight: 700; color: #1f2937; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                <span style="display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; background: #059669; color: #fff; font-size: 12px; font-weight: 700; flex-shrink: 0;">{{ $letter }}</span>
                {{ $q->label }}
            </div>

            @if($qType === 'single_select')
                {{-- 単一選択（ラジオ） --}}
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    @foreach($state['choices'] as $choice)
                        <label style="display: flex; align-items: center; gap: 5px; font-size: 13px; cursor: pointer; padding: 5px 10px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff;">
                            <input type="radio" name="survey[{{ $qId }}]" value="{{ $choice['value'] }}"
                                   style="accent-color: #059669;"
                                   {{ $choice['checked'] ? 'checked' : '' }}>
                            ①{{ $choice['label'] }}
                        </label>
                    @endforeach
                </div>

            @elseif($qType === 'multi_select')
                {{-- 複数選択（チェックボックス） --}}
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    @foreach($state['choices'] as $choice)
                        <label style="display: flex; align-items: center; gap: 5px; font-size: 13px; cursor: pointer; padding: 5px 10px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff;">
                            <input type="checkbox" name="survey[{{ $qId }}][]" value="{{ $choice['value'] }}"
                                   style="accent-color: #059669;"
                                   {{ $choice['checked'] ? 'checked' : '' }}>
                            {{ $choice['label'] }}
                        </label>
                    @endforeach
                </div>

            @elseif($qType === 'text')
                {{-- テキスト入力 --}}
                <input type="text" name="survey[{{ $qId }}]"
                       value="{{ $state['value'] }}"
                       placeholder="{{ $settings['placeholder'] ?? '' }}"
                       style="width: 100%; max-width: 400px; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">

            @elseif($qType === 'number')
                {{-- 数値入力 --}}
                <div style="display: flex; align-items: center; gap: 6px;">
                    <input type="number" name="survey[{{ $qId }}]"
                           value="{{ $state['value'] }}"
                           min="{{ $settings['min'] ?? '' }}" max="{{ $settings['max'] ?? '' }}"
                           style="width: 120px; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
                    @if(isset($settings['unit']))
                        <span style="font-size: 13px;">{{ $settings['unit'] }}</span>
                    @endif
                </div>

            @elseif($qType === 'slider')
                {{-- スライダー（答えていなければ「回答しない」。動かすと外れる） --}}
                @php
                    $slider = $state['slider'];
                    $stepCount = 4;
                    $range = $slider['max'] - $slider['min'];
                @endphp
                <div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">土地＋建物で</div>
                <div style="padding: 10px 0;" x-data="sliderComponent{{ $qId }}()">
                    <input type="range" aria-label="{{ $q->label }}"
                           min="{{ $slider['min'] }}" max="{{ $slider['max'] }}" step="{{ $slider['step'] }}"
                           x-model="sliderVal"
                           x-on:input="skip = false"
                           style="width: 100%; height: 6px; accent-color: #059669;">
                    <input type="hidden" name="survey[{{ $qId }}]" value="{{ $slider['answered'] ? $slider['value'] : '' }}" :value="sliderVal" :disabled="skip">
                    <div style="display: flex; justify-content: space-between; font-size: 11px; color: #6b7280; margin-top: 4px;">
                        @for($i = 0; $i <= $stepCount; $i++)
                            <span>{{ number_format($slider['min'] + ($range / $stepCount) * $i) }}{{ $slider['unit'] }}</span>
                        @endfor
                    </div>
                    <div style="display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: 6px 16px; margin-top: 6px;">
                        <div style="font-size: 20px; font-weight: 800; color: #059669;">
                            <span x-text="skip ? '—' : Number(sliderVal).toLocaleString()">{{ $slider['answered'] ? number_format((float) $slider['value']) : '—' }}</span><span x-show="! skip">{{ $slider['unit'] }}</span>
                        </div>
                        <label style="display: flex; align-items: center; gap: 5px; font-size: 13px; cursor: pointer;">
                            <input type="checkbox" x-model="skip" style="accent-color: #059669;">
                            回答しない
                        </label>
                    </div>
                </div>
                <script>
                function sliderComponent{{ $qId }}() {
                    return {
                        sliderVal: {{ \Illuminate\Support\Js::from($slider['value']) }},
                        skip: {{ \Illuminate\Support\Js::from(! $slider['answered']) }}
                    };
                }
                </script>

            @elseif($qType === 'select_with_text')
                {{-- 選択肢＋付随テキスト --}}
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    @foreach($state['choices'] as $choice)
                        <label style="display: flex; align-items: center; gap: 5px; font-size: 13px; cursor: pointer; padding: 5px 10px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff;">
                            <input type="checkbox" name="survey[{{ $qId }}][{{ $loop->index }}][value]" value="{{ $choice['value'] }}"
                                   style="accent-color: #059669;"
                                   {{ $choice['checked'] ? 'checked' : '' }}>
                            {{ $choice['label'] }}
                            @if($choice['hasText'])
                                <input type="text" name="survey[{{ $qId }}][{{ $loop->index }}][text]"
                                       value="{{ $choice['text'] }}"
                                       placeholder="{{ $choice['textLabel'] }}"
                                       style="width: 120px; height: 30px; font-size: 12px; margin-left: 6px; border: 1px solid #d1d5db; border-radius: 4px; padding: 4px 8px;">
                            @endif
                        </label>
                    @endforeach
                </div>

            @elseif($qType === 'conditional_select')
                {{-- 条件分岐付き選択 --}}
                <div x-data="condSelect{{ $qId }}()">
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 10px;">
                        @foreach($state['choices'] as $choice)
                            <label style="display: flex; align-items: center; gap: 5px; font-size: 13px; cursor: pointer; padding: 5px 10px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff;">
                                <input type="radio" name="survey[{{ $qId }}][value]" value="{{ $choice['value'] }}"
                                       style="accent-color: #059669;"
                                       x-model="selectedOption"
                                       {{ $choice['checked'] ? 'checked' : '' }}>
                                ①{{ $choice['label'] }}
                            </label>
                        @endforeach
                    </div>

                    {{-- サブフィールド表示（選んでいない選択肢の欄は送らない） --}}
                    @foreach($state['choices'] as $choice)
                        @if(count($choice['subFields']) > 0)
                            <div x-show="selectedOption === {{ \Illuminate\Support\Js::from($choice['value']) }}" style="border: 2px dashed #d1d5db; border-radius: 8px; padding: 16px; background: #fff;">
                                <div style="font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 10px;">▼ {{ $choice['value'] }} — 希望エリア</div>
                                <div class="grid-stack-sm" style="display: grid; grid-template-columns: repeat({{ count($choice['subFields']) }}, 1fr); gap: 10px;">
                                    @foreach($choice['subFields'] as $sf)
                                        <div>
                                            <label style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 4px;">{{ $sf['label'] }}</label>
                                            @if($sf['extra'])
                                                {{-- 今の欄に無い保存済みの欄: 保存済みと同じ選択肢を選んでいる間だけ送る --}}
                                                <input type="text"
                                                       name="survey[{{ $qId }}][sub][{{ $sf['key'] }}]"
                                                       value="{{ $sf['value'] }}"
                                                       :disabled="selectedOption !== savedOption"
                                                       style="height: 34px; font-size: 13px; width: 100%; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px;">
                                            @else
                                                <input type="{{ $sf['type'] }}"
                                                       name="survey[{{ $qId }}][sub][{{ $sf['key'] }}]"
                                                       value="{{ $sf['value'] }}"
                                                       :disabled="selectedOption !== {{ \Illuminate\Support\Js::from($choice['value']) }}"
                                                       style="height: 34px; font-size: 13px; width: 100%; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px;">
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
                <script>
                function condSelect{{ $qId }}() {
                    return {
                        selectedOption: {{ \Illuminate\Support\Js::from($state['selected']) }},
                        savedOption: {{ \Illuminate\Support\Js::from($state['savedSelected']) }}
                    };
                }
                </script>
            @endif
        </div>
    @endforeach
</div>
