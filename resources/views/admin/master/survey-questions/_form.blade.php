{{-- 設問の追加・編集のフォームの中身（create・edit が <form x-data="surveyQuestionForm()"> の中で読む）。
     最初の状態はコントローラが SurveyQuestionForm::state() で組み、下の script が Js::from で 1 つだけ受け取る（old() を読まない）。
     ⚠ 種類で使わない欄は x-show で隠し、:disabled で送らない（数値入力とスライダーが同じ settings[min] を持つ）。
     ⚠ 選択肢の行は original（保存されている名前）、付随の欄は key（今あるキー）を hidden で必ず送る。送らないと今ある欄に
       新しいキーが付き、過去の回答の sub が外れる。
     ⚠ 選択肢は静的な option で描く（x-for で作らない。Top trap #3）。属性の式に > を書かない（走査が要素の終わりと読む） --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
    <div class="sq-department">部署: {{ $departmentLabel }}</div>

    <div class="sq-field">
        <label for="sq-label" class="sq-label">設問の文<span class="sq-required">*</span></label>
        <input type="text" id="sq-label" name="label" x-model="label" maxlength="255" class="sq-input">
    </div>

    <div class="grid-stack-sm" style="display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px;">
        <div>
            <label for="sq-type" class="sq-label">種類</label>
            <select id="sq-type" name="question_type" x-model="questionType" class="sq-input">
                @foreach($questionTypes as $type)
                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <span class="sq-label">状態</span>
            <label class="sq-check"><input type="checkbox" name="is_active" value="1" x-model="isActive"> 有効（アンケートの画面に出す）</label>
        </div>
    </div>
</div>

{{-- 選択肢（単一選択・複数選択・選択肢＋付随テキスト・条件分岐付き選択） --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;" x-show="isChoice()" x-cloak>
    <div class="sq-field" x-show="questionType === 'conditional_select'">
        <label for="sq-sub-heading" class="sq-label">付随の欄の見出し</label>
        <input type="text" id="sq-sub-heading" name="settings[sub_heading]" x-model="settings.subHeading"
               :disabled="questionType !== 'conditional_select'" maxlength="{{ \App\Support\SurveyQuestionForm::SUB_HEADING_MAX }}"
               class="sq-input" style="max-width: 280px;">
        <div class="sq-help">選んだ選択肢の下に「▼ 選択肢の名前 — 見出し」と出ます。空なら「▼ 選択肢の名前」だけ。</div>
    </div>

    <div class="sq-label">選択肢</div>
    <template x-for="(row, i) in options" :key="row.uid">
        <div class="sq-card">
            <input type="hidden" :name="'options[' + i + '][original]'" :value="row.original" :disabled="!isChoice()">
            <div class="sq-line">
                <span class="inline-flex" :title="i === 0 ? '先頭の選択肢です' : null">
                    <button type="button" class="sq-arrow" x-on:click="moveOption(i, -1)" :disabled="i === 0"
                            :aria-label="'選択肢 ' + (i + 1) + ' 行目を上へ'">↑</button>
                </span>
                <span class="inline-flex" :title="i === options.length - 1 ? '末尾の選択肢です' : null">
                    <button type="button" class="sq-arrow" x-on:click="moveOption(i, 1)" :disabled="i === options.length - 1"
                            :aria-label="'選択肢 ' + (i + 1) + ' 行目を下へ'">↓</button>
                </span>
                <input type="text" :name="'options[' + i + '][value]'" x-model="row.value" :disabled="!isChoice()"
                       maxlength="{{ \App\Support\SurveyQuestionForm::OPTION_NAME_MAX }}" :aria-label="'選択肢 ' + (i + 1) + ' 行目の名前'"
                       class="sq-input" style="flex: 1; min-width: 0;">
                <button type="button" class="sq-remove" x-on:click="removeOption(i)" :aria-label="'選択肢 ' + (i + 1) + ' 行目を外す'">×</button>
            </div>

            {{-- 選択肢＋付随テキスト: 「文字も入れる」と、その欄の見出し --}}
            <div class="sq-indent sq-line" x-show="questionType === 'select_with_text'">
                <label class="sq-check" style="white-space: nowrap;">
                    <input type="checkbox" :name="'options[' + i + '][has_text]'" value="1" x-model="row.hasText"
                           :disabled="questionType !== 'select_with_text'"> 文字も入れる
                </label>
                <input type="text" :name="'options[' + i + '][text_label]'" x-model="row.textLabel" x-show="row.hasText"
                       :disabled="questionType !== 'select_with_text' || !row.hasText"
                       maxlength="{{ \App\Support\SurveyQuestionForm::TEXT_LABEL_MAX }}" placeholder="欄の見出し（任意）"
                       :aria-label="'選択肢 ' + (i + 1) + ' 行目の文字の欄の見出し'" class="sq-input" style="flex: 1; min-width: 0;">
            </div>

            {{-- 条件分岐付き選択: この選択肢を選んだときに出す欄（キーは自動で付き、画面に出さない） --}}
            <div class="sq-indent" x-show="questionType === 'conditional_select'">
                <div class="sq-sub-title">この選択肢を選んだときに出す欄</div>
                <template x-for="(field, j) in row.subFields" :key="field.uid">
                    <div class="sq-line" style="margin-bottom: 6px;">
                        <input type="hidden" :name="'options[' + i + '][sub_fields][' + j + '][key]'" :value="field.key"
                               :disabled="questionType !== 'conditional_select'">
                        <input type="text" :name="'options[' + i + '][sub_fields][' + j + '][label]'" x-model="field.label"
                               :disabled="questionType !== 'conditional_select'" maxlength="{{ \App\Support\SurveyQuestionForm::SUB_LABEL_MAX }}"
                               :aria-label="'選択肢 ' + (i + 1) + ' 行目の欄 ' + (j + 1) + ' 行目の見出し'"
                               class="sq-input" style="flex: 1; min-width: 0;">
                        <select :name="'options[' + i + '][sub_fields][' + j + '][type]'" x-model="field.type"
                                :disabled="questionType !== 'conditional_select'"
                                :aria-label="'選択肢 ' + (i + 1) + ' 行目の欄 ' + (j + 1) + ' 行目の種類'" class="sq-input" style="width: 90px;">
                            <option value="text">文字</option>
                            <option value="number">数</option>
                        </select>
                        <button type="button" class="sq-remove" x-on:click="removeSubField(i, j)"
                                :aria-label="'選択肢 ' + (i + 1) + ' 行目の欄 ' + (j + 1) + ' 行目を外す'">×</button>
                    </div>
                </template>
                <button type="button" class="sq-add" x-on:click="addSubField(i)">＋ 欄を足す</button>
            </div>
        </div>
    </template>
    <button type="button" class="sq-add" x-on:click="addOption()">＋ 選択肢を足す</button>
</div>

{{-- テキスト入力 --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;" x-show="questionType === 'text'" x-cloak>
    <label for="sq-placeholder" class="sq-label">入力欄の見本の文字（任意）</label>
    <input type="text" id="sq-placeholder" name="settings[placeholder]" x-model="settings.placeholder"
           :disabled="questionType !== 'text'" maxlength="{{ \App\Support\SurveyQuestionForm::PLACEHOLDER_MAX }}"
           placeholder="例: 3LDK" class="sq-input">
</div>

{{-- 数値入力（どれも任意） --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;" x-show="questionType === 'number'" x-cloak>
    <div class="grid-stack-sm" style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px;">
        <div>
            <label for="sq-number-min" class="sq-label">下限</label>
            <input type="text" inputmode="numeric" id="sq-number-min" name="settings[min]" x-model="settings.min"
                   :disabled="questionType !== 'number'" class="sq-input">
        </div>
        <div>
            <label for="sq-number-max" class="sq-label">上限</label>
            <input type="text" inputmode="numeric" id="sq-number-max" name="settings[max]" x-model="settings.max"
                   :disabled="questionType !== 'number'" class="sq-input">
        </div>
        <div>
            <label for="sq-number-unit" class="sq-label">単位</label>
            <input type="text" id="sq-number-unit" name="settings[unit]" x-model="settings.unit"
                   :disabled="questionType !== 'number'" maxlength="{{ \App\Support\SurveyQuestionForm::UNIT_MAX }}"
                   placeholder="例: 坪" class="sq-input">
        </div>
    </div>
</div>

{{-- スライダー（下限・上限・刻みは必須。上限と下限の差が刻みで割り切れないときは断る） --}}
<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;" x-show="questionType === 'slider'" x-cloak>
    <div class="grid-stack-sm" style="display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px;">
        <div>
            <label for="sq-slider-min" class="sq-label">下限<span class="sq-required">*</span></label>
            <input type="text" inputmode="numeric" id="sq-slider-min" name="settings[min]" x-model="settings.min"
                   :disabled="questionType !== 'slider'" class="sq-input">
        </div>
        <div>
            <label for="sq-slider-max" class="sq-label">上限<span class="sq-required">*</span></label>
            <input type="text" inputmode="numeric" id="sq-slider-max" name="settings[max]" x-model="settings.max"
                   :disabled="questionType !== 'slider'" class="sq-input">
        </div>
        <div>
            <label for="sq-slider-step" class="sq-label">刻み<span class="sq-required">*</span></label>
            <input type="text" inputmode="numeric" id="sq-slider-step" name="settings[step]" x-model="settings.step"
                   :disabled="questionType !== 'slider'" class="sq-input">
        </div>
        <div>
            <label for="sq-slider-unit" class="sq-label">単位</label>
            <input type="text" id="sq-slider-unit" name="settings[unit]" x-model="settings.unit"
                   :disabled="questionType !== 'slider'" maxlength="{{ \App\Support\SurveyQuestionForm::UNIT_MAX }}"
                   placeholder="例: 万円" class="sq-input">
        </div>
    </div>
    <div class="sq-help">数は整数で入れます。上限と下限の差が刻みで割り切れないときは断ります。</div>
</div>

@push('styles')
<style>
.sq-department { font-size: 13px; color: #6b7280; margin-bottom: 12px; }
.sq-field { margin-bottom: 16px; }
.sq-label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
.sq-required { color: #dc2626; margin-left: 4px; }
.sq-input { width: 100%; height: 36px; padding: 0 10px; font-size: 13px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; box-sizing: border-box; }
.sq-input:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.15); }
.sq-input:disabled { background: #f3f4f6; color: #6b7280; }
.sq-check { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #374151; }
.sq-help { font-size: 12px; color: #6b7280; margin-top: 4px; }
.sq-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 12px; background: #f9fafb; margin-bottom: 10px; }
.sq-line { display: flex; align-items: center; gap: 8px; }
.sq-indent { margin: 8px 0 0 64px; }
.sq-sub-title { font-size: 12px; font-weight: 600; color: #6b7280; margin-bottom: 6px; }
.sq-arrow { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; font-size: 12px; color: #374151; border: 1px solid #d1d5db; border-radius: 4px; background: #fff; cursor: pointer; }
.sq-arrow:disabled { color: #d1d5db; border-color: #e5e7eb; cursor: not-allowed; }
.sq-remove { flex-shrink: 0; padding: 4px 10px; font-size: 12px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 4px; background: #fff; cursor: pointer; }
.sq-add { display: inline-block; margin-top: 4px; padding: 5px 12px; font-size: 13px; font-weight: 600; color: #059669; border: 1px dashed #059669; border-radius: 6px; background: #fff; cursor: pointer; }
@media (max-width: 640px) {
    .sq-indent { margin-left: 0; }
}
</style>
@endpush

@push('scripts')
<script>
function surveyQuestionForm() {
    // 最初の状態（コントローラが SurveyQuestionForm::state() で組んだもの。入力エラーで戻ったときは前の入力）
    var state = {{ \Illuminate\Support\Js::from($formState) }};
    // 行の目印（x-for の :key に使う。送らない）
    var nextUid = 1;

    function field(source) {
        return { uid: nextUid++, key: source.key, label: source.label, type: source.type };
    }

    function option(source) {
        return {
            uid: nextUid++,
            value: source.value,
            original: source.original,
            hasText: source.hasText,
            textLabel: source.textLabel,
            subFields: source.subFields.map(field),
            answerCount: source.answerCount
        };
    }

    return {
        choiceTypes: state.choiceTypes,
        label: state.label,
        questionType: state.questionType,
        isActive: state.isActive,
        typeLocked: state.typeLocked,
        answerCount: state.answerCount,
        options: state.options.map(option),
        settings: state.settings,

        init: function () {
            var self = this;
            this.$watch('questionType', function (type) {
                self.typeChanged(type);
            });
        },

        // 選択肢の種類へ切り替えたとき、行が 0 なら空の行を 1 つ足す（選択肢を持たない種類で断られて戻った画面は 0 行）
        typeChanged: function (type) {
            if (this.choiceTypes.indexOf(type) !== -1 && this.options.length === 0) {
                this.addOption();
            }
        },

        isChoice: function () {
            return this.choiceTypes.indexOf(this.questionType) !== -1;
        },

        addOption: function () {
            this.options.push(option({ value: '', original: '', hasText: false, textLabel: '', subFields: [], answerCount: 0 }));
        },

        removeOption: function (i) {
            this.options.splice(i, 1);
        },

        // d は -1（上へ）か 1（下へ）。端では何もしない
        moveOption: function (i, d) {
            var to = i + d;
            if (to < 0 || to >= this.options.length) {
                return;
            }
            var moved = this.options.splice(i, 1)[0];
            this.options.splice(to, 0, moved);
        },

        addSubField: function (i) {
            this.options[i].subFields.push(field({ key: '', label: '', type: 'text' }));
        },

        removeSubField: function (i, j) {
            this.options[i].subFields.splice(j, 1);
        }
    };
}
</script>
@endpush
