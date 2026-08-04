{{-- アンケートフォーム共通パーシャル: 設問を動的レンダリング --}}
@php
    $qLetters = ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T'];
    $existingAnswers = $existingAnswers ?? [];
@endphp

<div style="background: #fefce8; border: 2px solid #fde68a; border-radius: 10px; padding: 24px; margin-top: 24px;">
    <div style="font-size: 16px; font-weight: 700; color: #92400e; margin-bottom: 20px; text-align: center; letter-spacing: 1px;">📋 ご来場アンケート</div>

    @foreach($questions as $idx => $q)
        @php
            $qId = $q->id;
            $letter = $qLetters[$idx] ?? ($idx + 1);
            $qType = $q->getRawOriginal('question_type');
            $options = $q->options ?? [];
            $settings = $q->settings ?? [];
            $existing = $existingAnswers[$qId] ?? null;
        @endphp

        <div style="margin-bottom: 20px;">
            <div style="font-size: 14px; font-weight: 700; color: #1f2937; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                <span style="display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; background: #059669; color: #fff; font-size: 12px; font-weight: 700; flex-shrink: 0;">{{ $letter }}</span>
                {{ $q->label }}
            </div>

            @if($qType === 'single_select')
                {{-- 単一選択（ラジオ） --}}
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    @foreach($options as $optIdx => $opt)
                        <label style="display: flex; align-items: center; gap: 5px; font-size: 13px; cursor: pointer; padding: 5px 10px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff;">
                            <input type="radio" name="survey[{{ $qId }}]" value="{{ $opt }}"
                                   style="accent-color: #059669;"
                                   {{ (is_string($existing) && $existing === $opt) ? 'checked' : '' }}>
                            ①{{ $opt }}
                        </label>
                    @endforeach
                </div>

            @elseif($qType === 'multi_select')
                {{-- 複数選択（チェックボックス） --}}
                @php $existArr = is_array($existing) ? $existing : []; @endphp
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    @foreach($options as $opt)
                        <label style="display: flex; align-items: center; gap: 5px; font-size: 13px; cursor: pointer; padding: 5px 10px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff;">
                            <input type="checkbox" name="survey[{{ $qId }}][]" value="{{ $opt }}"
                                   style="accent-color: #059669;"
                                   {{ in_array($opt, $existArr) ? 'checked' : '' }}>
                            {{ $opt }}
                        </label>
                    @endforeach
                </div>

            @elseif($qType === 'text')
                {{-- テキスト入力 --}}
                <input type="text" name="survey[{{ $qId }}]"
                       value="{{ is_string($existing) ? $existing : '' }}"
                       placeholder="{{ $settings['placeholder'] ?? '' }}"
                       style="width: 100%; max-width: 400px; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">

            @elseif($qType === 'number')
                {{-- 数値入力 --}}
                <div style="display: flex; align-items: center; gap: 6px;">
                    <input type="number" name="survey[{{ $qId }}]"
                           value="{{ is_string($existing) ? $existing : '' }}"
                           min="{{ $settings['min'] ?? '' }}" max="{{ $settings['max'] ?? '' }}"
                           style="width: 120px; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
                    @if(isset($settings['unit']))
                        <span style="font-size: 13px;">{{ $settings['unit'] }}</span>
                    @endif
                </div>

            @elseif($qType === 'slider')
                {{-- スライダー --}}
                @php
                    $sMin = $settings['min'] ?? 0;
                    $sMax = $settings['max'] ?? 100;
                    $sStep = $settings['step'] ?? 1;
                    $sUnit = $settings['unit'] ?? '';
                    $sDefault = is_string($existing) ? $existing : (int)(($sMin + $sMax) / 2);
                @endphp
                <div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">土地＋建物で</div>
                <div style="padding: 10px 0;" x-data="sliderComponent{{ $qId }}()">
                    <input type="range" name="survey[{{ $qId }}]"
                           min="{{ $sMin }}" max="{{ $sMax }}" step="{{ $sStep }}"
                           x-model="sliderVal"
                           style="width: 100%; height: 6px; accent-color: #059669;">
                    <div style="display: flex; justify-content: space-between; font-size: 11px; color: #6b7280; margin-top: 4px;">
                        @php
                            $stepCount = 4;
                            $range = $sMax - $sMin;
                        @endphp
                        @for($i = 0; $i <= $stepCount; $i++)
                            <span>{{ number_format($sMin + ($range / $stepCount) * $i) }}{{ $sUnit }}</span>
                        @endfor
                    </div>
                    <div style="text-align: center; font-size: 20px; font-weight: 800; color: #059669; margin-top: 6px;">
                        <span x-text="Number(sliderVal).toLocaleString()">{{ number_format((int)$sDefault) }}</span>{{ $sUnit }}
                    </div>
                </div>
                <script>
                function sliderComponent{{ $qId }}() {
                    return {
                        sliderVal: {{ (int)$sDefault }}
                    };
                }
                </script>

            @elseif($qType === 'select_with_text')
                {{-- 選択肢＋付随テキスト --}}
                @php $existArr = is_array($existing) ? $existing : []; @endphp
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    @foreach($options as $opt)
                        @php
                            $optVal = is_array($opt) ? ($opt['value'] ?? '') : $opt;
                            $hasText = is_array($opt) ? ($opt['has_text'] ?? false) : false;
                            $textLabel = is_array($opt) ? ($opt['text_label'] ?? '') : '';
                            $isChecked = false;
                            $textValue = '';
                            foreach ($existArr as $ea) {
                                if (is_array($ea) && ($ea['value'] ?? '') === $optVal) {
                                    $isChecked = true;
                                    $textValue = $ea['text'] ?? '';
                                } elseif (is_string($ea) && $ea === $optVal) {
                                    $isChecked = true;
                                }
                            }
                        @endphp
                        <label style="display: flex; align-items: center; gap: 5px; font-size: 13px; cursor: pointer; padding: 5px 10px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff;">
                            <input type="checkbox" name="survey[{{ $qId }}][{{ $loop->index }}][value]" value="{{ $optVal }}"
                                   style="accent-color: #059669;"
                                   {{ $isChecked ? 'checked' : '' }}>
                            {{ $optVal }}
                            @if($hasText)
                                <input type="text" name="survey[{{ $qId }}][{{ $loop->index }}][text]"
                                       value="{{ $textValue }}"
                                       placeholder="{{ $textLabel }}"
                                       style="width: 120px; height: 30px; font-size: 12px; margin-left: 6px; border: 1px solid #d1d5db; border-radius: 4px; padding: 4px 8px;">
                            @endif
                        </label>
                    @endforeach
                </div>

            @elseif($qType === 'conditional_select')
                {{-- 条件分岐付き選択 --}}
                @php
                    $existObj = is_array($existing) ? $existing : [];
                    $selectedValue = $existObj['value'] ?? '';
                    $subAnswers = $existObj['sub'] ?? [];
                @endphp
                <div x-data="condSelect{{ $qId }}()">
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 10px;">
                        @foreach($options as $optIdx => $opt)
                            @php
                                $optVal = is_array($opt) ? ($opt['value'] ?? '') : $opt;
                                $subFields = is_array($opt) ? ($opt['sub_fields'] ?? []) : [];
                            @endphp
                            <label style="display: flex; align-items: center; gap: 5px; font-size: 13px; cursor: pointer; padding: 5px 10px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff;">
                                <input type="radio" name="survey[{{ $qId }}][value]" value="{{ $optVal }}"
                                       style="accent-color: #059669;"
                                       x-on:change="selectedOption = '{{ $optVal }}'"
                                       {{ $selectedValue === $optVal ? 'checked' : '' }}>
                                ①{{ $optVal }}
                            </label>
                        @endforeach
                    </div>

                    {{-- サブフィールド表示 --}}
                    @foreach($options as $opt)
                        @php
                            $optVal = is_array($opt) ? ($opt['value'] ?? '') : $opt;
                            $subFields = is_array($opt) ? ($opt['sub_fields'] ?? []) : [];
                        @endphp
                        @if(count($subFields) > 0)
                            <div x-show="selectedOption === '{{ $optVal }}'" style="border: 2px dashed #d1d5db; border-radius: 8px; padding: 16px; background: #fff;">
                                <div style="font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 10px;">▼ {{ $optVal }} — 希望エリア</div>
                                <div class="grid-stack-sm" style="display: grid; grid-template-columns: repeat({{ count($subFields) }}, 1fr); gap: 10px;">
                                    @foreach($subFields as $sf)
                                        <div>
                                            <label style="font-size: 12px; font-weight: 600; display: block; margin-bottom: 4px;">{{ $sf['label'] ?? '' }}</label>
                                            <input type="{{ ($sf['type'] ?? 'text') === 'number' ? 'number' : 'text' }}"
                                                   name="survey[{{ $qId }}][sub][{{ $sf['key'] ?? '' }}]"
                                                   value="{{ $subAnswers[$sf['key'] ?? ''] ?? '' }}"
                                                   style="height: 34px; font-size: 13px; width: 100%; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px;">
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
                        selectedOption: '{{ $selectedValue }}'
                    };
                }
                </script>
            @endif
        </div>
    @endforeach
</div>
