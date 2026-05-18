{{-- 顧客フォーム共通パーシャル: create/edit 共用 --}}
@php
    $isEdit = isset($isEdit) ? $isEdit : false;
@endphp

<div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;" x-data="buyerForm()">

    {{-- ===== 来場情報（住宅事業のみ） ===== --}}
    @if($department === 'housing')
        <div style="font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; display: flex; align-items: center; gap: 8px;">
            <span style="width: 4px; height: 18px; background: #059669; border-radius: 2px; flex-shrink: 0;"></span>
            来場情報
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 24px;">
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">取得日（来場日）<span class="text-red-600" style="margin-left: 2px;">*</span></label>
                <input type="date" name="acquired_date"
                       value="{{ old('acquired_date', ($isEdit && $pivot) ? $pivot->acquired_date->format('Y-m-d') : date('Y-m-d')) }}"
                       class="form-input w-full" style="height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">来場分譲地</label>
                <select name="project_id" class="form-input w-full" style="height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
                    <option value="">（任意）選択してください</option>
                    @foreach($projects as $pId => $pName)
                        <option value="{{ $pId }}" {{ old('project_id') == $pId ? 'selected' : '' }}>{{ $pName }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">担当者</label>
                <select name="staff_user_id" class="form-input w-full" style="height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
                    <option value="">選択してください</option>
                    @foreach($staffList as $sId => $sName)
                        <option value="{{ $sId }}" {{ old('staff_user_id') == $sId ? 'selected' : '' }}>{{ $sName }}</option>
                    @endforeach
                </select>
                <div style="font-size: 11px; color: #6b7280; margin-top: 3px;">※ 同姓の場合のみ名も表示</div>
            </div>
        </div>
    @else
        {{-- 不動産事業: 取得日のみ --}}
        <div style="font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; display: flex; align-items: center; gap: 8px;">
            <span style="width: 4px; height: 18px; background: #059669; border-radius: 2px; flex-shrink: 0;"></span>
            基本情報
        </div>
        <div style="margin-bottom: 26px;">
            <div style="max-width: 250px;">
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">取得日<span class="text-red-600" style="margin-left: 2px;">*</span></label>
                <input type="date" name="acquired_date"
                       value="{{ old('acquired_date', ($isEdit && $pivot) ? $pivot->acquired_date->format('Y-m-d') : date('Y-m-d')) }}"
                       class="form-input w-full" style="height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
            </div>
        </div>
    @endif

    {{-- ===== 基本情報セクションヘッダ（住宅事業のみ — 不動産は上で出力済み） ===== --}}
    @if($department === 'housing')
        <div style="font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 14px; display: flex; align-items: center; gap: 8px;">
            <span style="width: 4px; height: 18px; background: #059669; border-radius: 2px; flex-shrink: 0;"></span>
            基本情報
        </div>
    @endif

    {{-- 氏名 4カラム --}}
    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 16px; margin-bottom: 4px;">
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">姓<span class="text-red-600" style="margin-left: 2px;">*</span></label>
            <input type="text" name="last_name" value="{{ old('last_name', $isEdit ? $buyer->last_name : '') }}"
                   placeholder="山田"
                   class="form-input w-full" style="height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;"
                   x-on:compositionstart="startKana('last_name_kana')"
                   x-on:input="trackKana($event, 'last_name_kana')"
                   x-on:compositionend="endKana('last_name_kana')"
                   x-on:blur="checkDuplicate()">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">名<span class="text-red-600" style="margin-left: 2px;">*</span></label>
            <input type="text" name="first_name" value="{{ old('first_name', $isEdit ? $buyer->first_name : '') }}"
                   placeholder="太郎"
                   class="form-input w-full" style="height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;"
                   x-on:compositionstart="startKana('first_name_kana')"
                   x-on:input="trackKana($event, 'first_name_kana')"
                   x-on:compositionend="endKana('first_name_kana')"
                   x-on:blur="checkDuplicate()">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">セイ</label>
            <input type="text" name="last_name_kana" value="{{ old('last_name_kana', $isEdit ? $buyer->last_name_kana : '') }}"
                   placeholder="ヤマダ" x-ref="last_name_kana"
                   class="form-input w-full" style="height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;"
                   x-bind:style="kanaAutoFilled.last_name_kana ? 'height:38px;border:1px solid #86efac;border-radius:6px;padding:7px 12px;font-size:14px;background:#f0fdf4;' : 'height:38px;border:1px solid #d1d5db;border-radius:6px;padding:7px 12px;font-size:14px;'">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">メイ</label>
            <input type="text" name="first_name_kana" value="{{ old('first_name_kana', $isEdit ? $buyer->first_name_kana : '') }}"
                   placeholder="タロウ" x-ref="first_name_kana"
                   class="form-input w-full" style="height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;"
                   x-bind:style="kanaAutoFilled.first_name_kana ? 'height:38px;border:1px solid #86efac;border-radius:6px;padding:7px 12px;font-size:14px;background:#f0fdf4;' : 'height:38px;border:1px solid #d1d5db;border-radius:6px;padding:7px 12px;font-size:14px;'">
        </div>
    </div>
    <div style="margin-bottom: 26px;">
        <div style="font-size: 11px; color: #059669; margin-top: 3px;">💡 氏名入力時にフリガナが自動入力されます（手動修正可）</div>
    </div>

    {{-- 生年月日 + ご家族 --}}
    @php
        // 編集時: 西暦→元号年の逆変換
        $editBirthYear = '';
        if ($isEdit && $buyer->birth_date) {
            $westernYear = (int) $buyer->birth_date->format('Y');
            $era = $buyer->birth_era ?? '';
            if ($era === 'S') {
                $editBirthYear = $westernYear - 1925;
            } elseif ($era === 'H') {
                $editBirthYear = $westernYear - 1988;
            } elseif ($era === 'R') {
                $editBirthYear = $westernYear - 2018;
            } else {
                $editBirthYear = $westernYear;
            }
        }
    @endphp
    <div style="display: flex; gap: 10px; margin-bottom: 26px; align-items: flex-end;">
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">生年月日</label>
            <select name="birth_era" style="width: 80px; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 8px; font-size: 14px;">
                <option value="">元号</option>
                <option value="S" {{ old('birth_era', $isEdit ? $buyer->birth_era : '') === 'S' ? 'selected' : '' }}>S.</option>
                <option value="H" {{ old('birth_era', $isEdit ? $buyer->birth_era : '') === 'H' ? 'selected' : '' }}>H.</option>
                <option value="R" {{ old('birth_era', $isEdit ? $buyer->birth_era : '') === 'R' ? 'selected' : '' }}>R.</option>
            </select>
        </div>
        <div style="display: flex; align-items: center; gap: 4px;">
            <input type="number" name="birth_year" placeholder="年"
                   value="{{ old('birth_year', $editBirthYear) }}"
                   style="width: 70px; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 8px; font-size: 14px;">
            <span style="font-size: 13px;">年</span>
        </div>
        <div style="display: flex; align-items: center; gap: 4px;">
            <input type="number" name="birth_month" placeholder="月"
                   value="{{ old('birth_month', ($isEdit && $buyer->birth_date) ? (int)$buyer->birth_date->format('m') : '') }}"
                   style="width: 60px; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 8px; font-size: 14px;">
            <span style="font-size: 13px;">月</span>
        </div>
        <div style="display: flex; align-items: center; gap: 4px;">
            <input type="number" name="birth_day" placeholder="日"
                   value="{{ old('birth_day', ($isEdit && $buyer->birth_date) ? (int)$buyer->birth_date->format('d') : '') }}"
                   style="width: 60px; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 8px; font-size: 14px;">
            <span style="font-size: 13px;">日</span>
        </div>
        <div style="width: 70px;"></div>
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">ご家族</label>
            <div style="display: flex; align-items: center; gap: 6px;">
                <span style="font-size: 13px;">大人</span>
                <input type="number" name="family_adults" min="0"
                       value="{{ old('family_adults', $isEdit ? $buyer->family_adults : '') }}"
                       style="width: 60px; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 8px; font-size: 14px;">
                <span style="font-size: 13px;">人　子供</span>
                <input type="number" name="family_children" min="0"
                       value="{{ old('family_children', $isEdit ? $buyer->family_children : '') }}"
                       style="width: 60px; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 8px; font-size: 14px;">
                <span style="font-size: 13px;">人</span>
            </div>
        </div>
    </div>

    {{-- 郵便番号 + 住所 --}}
    <div style="display: grid; grid-template-columns: 160px auto 1fr 1fr; gap: 10px; margin-bottom: 4px; align-items: flex-end;">
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">郵便番号</label>
            <input type="text" name="postal_code" x-ref="postal_code"
                   value="{{ old('postal_code', $isEdit ? $buyer->postal_code : '') }}"
                   placeholder="790-0001"
                   style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
        </div>
        <div style="display: flex; gap: 6px; align-items: flex-end;">
            <button type="button" onclick="lookupZip()"
                    style="height: 38px; display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; font-size: 11px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 4px; cursor: pointer; background: #fff; white-space: nowrap;">〒→住所</button>
            <button type="button" onclick="reverseLookup()"
                    style="height: 38px; display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; font-size: 11px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 4px; cursor: pointer; background: #fff; white-space: nowrap;">住所→〒</button>
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">都道府県</label>
            <select name="prefecture" x-ref="prefecture"
                    style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
                <option value="">選択</option>
                @foreach($prefectures as $pref)
                    <option value="{{ $pref }}" {{ old('prefecture', $isEdit ? $buyer->prefecture : '愛媛県') === $pref ? 'selected' : '' }}>{{ $pref }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">市区町村</label>
            <input type="text" name="city" x-ref="city"
                   value="{{ old('city', $isEdit ? $buyer->city : '') }}"
                   placeholder="松山市"
                   style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
        </div>
    </div>
    <div style="font-size: 11px; color: #059669; margin-bottom: 26px;">💡 郵便番号入力で都道府県・市区町村を自動補完 / 住所から郵便番号も逆引き可能</div>

    {{-- 住所詳細 + 建物名 --}}
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 26px;">
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">住所詳細</label>
            <input type="text" name="address_detail"
                   value="{{ old('address_detail', $isEdit ? $buyer->address_detail : '') }}"
                   placeholder="道後温泉1-2-3"
                   class="form-input w-full" style="height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">アパート・マンション名・号</label>
            <input type="text" name="building_name"
                   value="{{ old('building_name', $isEdit ? $buyer->building_name : '') }}"
                   class="form-input w-full" style="height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
        </div>
    </div>

    {{-- 電話 + メール --}}
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 26px;">
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">お電話</label>
            <input type="tel" name="phone"
                   value="{{ old('phone', $isEdit ? $buyer->phone : '') }}"
                   placeholder="089-123-4567"
                   class="form-input w-full" style="height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">メールアドレス</label>
            <input type="email" name="email"
                   value="{{ old('email', $isEdit ? $buyer->email : '') }}"
                   placeholder="example@mail.com"
                   class="form-input w-full" style="height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
        </div>
    </div>

    {{-- 職業 + 勤務先 + 勤続年数 --}}
    <div style="display: grid; grid-template-columns: 200px 1fr 120px; gap: 16px; margin-bottom: 26px;">
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">ご職業</label>
            <select name="occupation"
                    style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
                <option value="">選択</option>
                @foreach(['会社員','公務員','自営業','医師','自由業','その他'] as $occ)
                    <option value="{{ $occ }}" {{ old('occupation', $isEdit ? $buyer->occupation : '') === $occ ? 'selected' : '' }}>{{ $occ }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">ご勤務先</label>
            <input type="text" name="employer"
                   value="{{ old('employer', $isEdit ? $buyer->employer : '') }}"
                   class="form-input w-full" style="height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">勤続年数</label>
            <div style="display: flex; align-items: center; gap: 4px;">
                <input type="number" name="years_employed" min="0"
                       value="{{ old('years_employed', $isEdit ? $buyer->years_employed : '') }}"
                       style="width: 80px; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 8px; font-size: 14px;">
                <span style="font-size: 13px;">年</span>
            </div>
        </div>
    </div>

    {{-- 重複チェック警告エリア —— x-for内にtemplate x-ifは使わない（ルール19） --}}
    <div x-show="duplicateInfo.length > 0" style="display: none;">
        <template x-for="dup in duplicateInfo" :key="dup.id">
            <div style="background: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px; padding: 14px 18px; margin-bottom: 10px; display: flex; align-items: flex-start; gap: 10px;">
                <div style="color: #d97706; font-size: 20px; flex-shrink: 0;">⚠️</div>
                <div style="font-size: 13px; color: #92400e;">
                    {{-- 同一部署に既存 --}}
                    <div x-show="dup.same_dept">
                        <strong>{{ $deptLabel }}</strong>に同名の顧客が登録済みです：<a x-bind:href="'{{ url($department . '/customers') }}/' + dup.id" style="color: #1d4ed8; text-decoration: underline;" x-text="dup.full_name + '（' + dup.address + '）'"></a><br>
                        <span style="font-size: 12px; color: #6b7280;">このまま登録すると別の顧客として追加されます</span>
                    </div>
                    {{-- 他部署にのみ既存 --}}
                    <div x-show="!dup.same_dept && dup.other_dept.length > 0">
                        <strong x-text="getDeptLabel(dup.other_dept[0])"></strong>に同名の顧客が登録されています：<a x-bind:href="'/' + dup.other_dept[0] + '/customers/' + dup.id" style="color: #1d4ed8; text-decoration: underline;" x-text="dup.full_name + '（' + dup.address + '）'"></a><br>
                        <button type="button" x-on:click="addToDepartment(dup.id)" style="margin-top: 8px; margin-right: 8px; padding: 4px 12px; font-size: 12px; font-weight: 600; border-radius: 4px; border: none; cursor: pointer; background: #059669; color: #fff;">この顧客を{{ $deptLabel }}にも追加</button>
                        <button type="button" x-on:click="dismissDuplicate(dup.id)" style="margin-top: 8px; padding: 4px 12px; font-size: 12px; font-weight: 600; border-radius: 4px; cursor: pointer; background: #fff; color: #374151; border: 1px solid #9ca3af;">別人として新規登録</button>
                    </div>
                </div>
            </div>
        </template>
    </div>

    {{-- ===== アンケートセクション ===== --}}
    @if(!$isEdit && isset($questions) && $questions->count() > 0)
        @include('buyers._survey_form', ['questions' => $questions])
    @elseif(!$isEdit && $department === 'realestate')
        @php
            $hasQ = isset($questions) && $questions->count() > 0;
        @endphp
        @if(!$hasQ)
            <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 12px 16px; margin-top: 16px; font-size: 13px; color: #0c4a6e;">
                ℹ️ 不動産事業のアンケート設問は未登録です。設問を追加するとここにアンケートフォームが表示されます。
            </div>
        @endif
    @endif

    {{-- 備考 --}}
    <div style="margin-top: 20px;">
        <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">備考</label>
        <textarea name="memo" rows="3" placeholder="メモ等"
                  style="width: 100%; border: 1px solid #d1d5db; border-radius: 6px; padding: 10px 12px; font-size: 14px; resize: vertical;">{{ old('memo', $isEdit ? $buyer->memo : '') }}</textarea>
    </div>

    <x-form-actions
        :submit-label="$isEdit ? '更新する' : '登録する'"
        :cancel-url="route($department.'.customers.index')" />

    {{-- hidden: birth_date 変換 --}}
    <input type="hidden" name="birth_date" x-ref="birth_date_hidden">
</div>

<script>
function buyerForm() {
    return {
        duplicateInfo: [],
        kanaAutoFilled: { last_name_kana: false, first_name_kana: false },
        _composing: { last_name_kana: false, first_name_kana: false },
        _kanaBuffer: { last_name_kana: '', first_name_kana: '' },

        startKana: function(targetRef) {
            // IME変換開始 — バッファをリセットしてフラグON
            this._composing[targetRef] = true;
            this._kanaBuffer[targetRef] = '';
        },

        trackKana: function(event, targetRef) {
            // input イベント: IME変換中のみ処理
            if (!this._composing[targetRef]) return;

            var value = event.target.value || '';
            // フィールドの値にひらがなが含まれていれば保存（変換前の段階）
            // IME変換中はフィールドにひらがなが表示される
            // 変換後（漢字）にはひらがなが含まれないのでバッファは上書きされない
            if (/[\u3041-\u3096]/.test(value)) {
                // ひらがなのみを抽出（ローマ字途中の文字を除外）
                var hiragana = '';
                for (var i = 0; i < value.length; i++) {
                    var code = value.charCodeAt(i);
                    if (code >= 0x3041 && code <= 0x3096) {
                        hiragana += value.charAt(i);
                    }
                }
                if (hiragana) {
                    this._kanaBuffer[targetRef] = hiragana;
                }
            }
        },

        endKana: function(targetRef) {
            // IME変換確定 — バッファのひらがなをカタカナに変換してセット
            this._composing[targetRef] = false;
            var hiragana = this._kanaBuffer[targetRef] || '';
            if (!hiragana) return;

            // ひらがな→カタカナ変換
            var katakana = hiragana.replace(/[\u3041-\u3096]/g, function(ch) {
                return String.fromCharCode(ch.charCodeAt(0) + 0x60);
            });

            if (this.$refs[targetRef]) {
                this.$refs[targetRef].value = katakana;
                this.kanaAutoFilled[targetRef] = true;
            }
            this._kanaBuffer[targetRef] = '';
        },

        checkDuplicate: function() {
            var lastName  = document.querySelector('input[name="last_name"]').value;
            var firstName = document.querySelector('input[name="first_name"]').value;
            if (!lastName || !firstName) return;

            var prefecture = this.$refs.prefecture ? this.$refs.prefecture.value : '';
            var city       = this.$refs.city ? this.$refs.city.value : '';
            var self = this;
            var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '{{ route("api.customers.check-duplicate") }}');
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', token);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var data = JSON.parse(xhr.responseText);
                    self.duplicateInfo = data.duplicates || [];
                }
            };
            xhr.send(JSON.stringify({
                last_name: lastName,
                first_name: firstName,
                prefecture: prefecture,
                city: city,
                department: '{{ $department }}',
                exclude_id: '{{ $isEdit && $buyer ? $buyer->id : "" }}'
            }));
        },

        getDeptLabel: function(dept) {
            return dept === 'housing' ? '住宅事業' : '不動産事業';
        },

        addToDepartment: function(buyerId) {
            var acquiredDate = document.querySelector('input[name="acquired_date"]').value;
            var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '{{ url("/api/customers") }}/' + buyerId + '/add-department');
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', token);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var data = JSON.parse(xhr.responseText);
                    if (data.redirect) {
                        window.location.href = data.redirect;
                    }
                }
            };
            xhr.send(JSON.stringify({
                department: '{{ $department }}',
                acquired_date: acquiredDate
            }));
        },

        dismissDuplicate: function(id) {
            this.duplicateInfo = this.duplicateInfo.filter(function(d) { return d.id !== id; });
        },

        init: function() {
            // フォーム送信前にbirth_dateを組み立て（元号年→西暦年変換）
            var form = this.$el.closest('form');
            if (form) {
                form.addEventListener('submit', function() {
                    var era = document.querySelector('select[name="birth_era"]');
                    var y = document.querySelector('input[name="birth_year"]');
                    var m = document.querySelector('input[name="birth_month"]');
                    var d = document.querySelector('input[name="birth_day"]');
                    var hidden = document.querySelector('input[name="birth_date"]');
                    if (era && y && m && d && hidden && y.value && m.value && d.value) {
                        // 元号年→西暦年変換
                        var eraYear = parseInt(y.value, 10);
                        var westernYear = eraYear;
                        if (era.value === 'S') {
                            westernYear = eraYear + 1925;
                        } else if (era.value === 'H') {
                            westernYear = eraYear + 1988;
                        } else if (era.value === 'R') {
                            westernYear = eraYear + 2018;
                        }
                        var mm = String(m.value).padStart(2, '0');
                        var dd = String(d.value).padStart(2, '0');
                        hidden.value = westernYear + '-' + mm + '-' + dd;
                    }
                });
            }
        }
    };
}

function lookupZip() {
    var zip = document.querySelector('input[name="postal_code"]').value.replace(/-/g, '');
    if (!zip || zip.length < 7) { alert('郵便番号を入力してください（7桁）'); return; }
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'https://zipcloud.ibsnet.co.jp/api/search?zipcode=' + zip);
    xhr.onload = function() {
        if (xhr.status === 200) {
            var data = JSON.parse(xhr.responseText);
            if (data.results && data.results.length > 0) {
                var r = data.results[0];
                document.querySelector('select[name="prefecture"]').value = r.address1;
                document.querySelector('input[name="city"]').value = r.address2 + r.address3;
            } else {
                alert('該当する住所が見つかりませんでした');
            }
        }
    };
    xhr.send();
}

function reverseLookup() {
    var pref = document.querySelector('select[name="prefecture"]').value;
    var city = document.querySelector('input[name="city"]').value;
    if (!pref) { alert('都道府県を選択してください'); return; }
    if (!city) { alert('市区町村を入力してください'); return; }

    var xhr = new XMLHttpRequest();
    xhr.open('GET', '{{ route("api.reverse-zip") }}?prefecture=' + encodeURIComponent(pref) + '&city=' + encodeURIComponent(city));
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.onload = function() {
        if (xhr.status === 200) {
            var data = JSON.parse(xhr.responseText);
            if (data.postal_code) {
                document.querySelector('input[name="postal_code"]').value = data.postal_code;
            }
        } else {
            var data = JSON.parse(xhr.responseText);
            alert(data.error || '該当する郵便番号が見つかりませんでした');
        }
    };
    xhr.onerror = function() {
        alert('通信エラーが発生しました');
    };
    xhr.send();
}
</script>
