{{-- 工事案件 共通フォーム --}}
{{-- 期待: $project (DadProject or null), $clients, $subcontractors, $staffUsers, $employees (optional) --}}

@php
    $project = $project ?? null;
    $employees = $employees ?? collect();

    // 既存原価明細を JSON 用に変換
    $existingCosts = [];
    if ($project) {
        foreach ($project->costs as $c) {
            $existingCosts[] = [
                'cost_category' => $c->cost_category->value,
                'description' => $c->description,
                'estimated_amount' => $c->estimated_amount,
                'actual_amount' => $c->actual_amount,
                'subcontractor_id' => $c->subcontractor_id,
                'notes' => $c->notes,
            ];
        }
    }

    // 既存人員配置を JSON 用に変換
    $existingAssignments = [];
    if ($project) {
        foreach ($project->assignments as $a) {
            $existingAssignments[] = [
                'employee_id' => $a->employee_id,
                'role' => $a->role,
                'start_date' => optional($a->start_date)->format('Y-m-d'),
                'end_date' => optional($a->end_date)->format('Y-m-d'),
                'notes' => $a->notes,
            ];
        }
    }

    // 協力業者の id × 会社名 を Excel取込で名前→ID 解決に使う（@json 用に id/company_name のみ抽出）
    $subcontractorJsonList = $subcontractors->map(function ($s) {
        return ['id' => $s->id, 'company_name' => $s->company_name];
    })->values()->all();
@endphp

<div x-data="projectForm()" x-init="initForm()" style="max-width: 1100px;">

    {{-- カード: 基本情報 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="card-title">基本情報</div>

        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px 20px;">
            <div class="fld">
                <label>工事種別<span class="required">*</span></label>
                <select name="project_type" required>
                    @foreach(\App\Enums\DadProjectType::cases() as $t)
                        <option value="{{ $t->value }}" {{ old('project_type', $project?->project_type?->value ?? 'public') === $t->value ? 'selected' : '' }}>{{ $t->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="fld">
                <label>ステータス<span class="required">*</span></label>
                <select name="status" required>
                    @foreach(\App\Enums\DadProjectStatus::cases() as $s)
                        <option value="{{ $s->value }}" {{ old('status', $project?->status?->value ?? 'estimate') === $s->value ? 'selected' : '' }}>{{ $s->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="fld">
                <label>担当者</label>
                <select name="staff_user_id">
                    <option value="">選択してください</option>
                    @foreach($staffUsers as $u)
                        <option value="{{ $u->id }}" {{ old('staff_user_id', $project?->staff_user_id) == $u->id ? 'selected' : '' }}>{{ $u->name }}@if($u->trashed())（削除済み）@elseif($u->status === \App\Enums\UserStatus::Inactive)（無効）@endif</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px 20px; margin-top: 16px;">
            <div class="fld">
                <label>工事名<span class="required">*</span></label>
                <input type="text" name="project_name" maxlength="200" required placeholder="例: 松山駅前道路改良工事"
                       value="{{ old('project_name', $project?->project_name) }}">
            </div>
            <div class="fld">
                <label>発注者</label>
                <select name="client_id">
                    <option value="">選択してください</option>
                    @foreach($clients as $c)
                        <option value="{{ $c->id }}" {{ old('client_id', $project?->client_id) == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- カード: 工事現場 + 所在地マップ --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="card-title">工事現場</div>
        <div class="fld" style="margin-bottom: 16px;">
            <label>工事現場住所</label>
            <input type="text" name="site_address" id="input-site-address" maxlength="300" placeholder="例: 愛媛県松山市中央"
                   value="{{ old('site_address', $project?->site_address) }}">
        </div>

        {{-- 緯度・経度（hidden）— マップピンの位置で自動更新 --}}
        <input type="hidden" name="latitude" id="input-latitude" value="{{ old('latitude', $project?->latitude) }}">
        <input type="hidden" name="longitude" id="input-longitude" value="{{ old('longitude', $project?->longitude) }}">

        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
            <button type="button" id="btn-geocode" onclick="geocodeAddress()" style="background: #059669; color: #fff; padding: 7px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                マップで確認
            </button>
            <span style="font-size: 12px; color: #6b7280;">住所からピン位置を検索します。空欄でも地図上でピンを配置できます</span>
        </div>

        <div id="map-status" style="display: none; padding: 8px 14px; border-radius: 6px; font-size: 13px; margin-bottom: 8px;"></div>

        <div id="map-wrap" style="display: {{ ($project && $project->latitude) ? 'block' : 'none' }};">
            <div style="border: 1px solid #d1d5db; border-radius: 8px; overflow: hidden;">
                <div id="dad-project-map" style="height: 350px;"></div>
            </div>
            <div style="display: flex; gap: 8px; margin-top: 6px; align-items: flex-start;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#4b5563" stroke-width="2" style="flex-shrink: 0; margin-top: 1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <span style="font-size: 12px; color: #6b7280;">ピンをドラッグ、またはマップ上をクリックして正確な位置に調整できます</span>
            </div>
            <div style="display: flex; gap: 14px; margin-top: 6px;">
                <span style="font-size: 12px; color: #6b7280;">緯度: <strong style="color: #1f2937;" id="display-lat">—</strong></span>
                <span style="font-size: 12px; color: #6b7280;">経度: <strong style="color: #1f2937;" id="display-lng">—</strong></span>
            </div>
        </div>
    </div>

    {{-- カード: 金額・日程 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="card-title">金額・日程</div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px 20px; margin-bottom: 16px;">
            <div class="fld">
                <label>見積金額（税抜）</label>
                <input type="text" inputmode="numeric" name="estimate_amount" placeholder="例: 28000000"
                       value="{{ old('estimate_amount', $project?->estimate_amount) }}">
                <div class="hint">円単位 半角数値</div>
            </div>
            <div class="fld">
                <label>受注金額（税抜）</label>
                <input type="text" inputmode="numeric" name="contract_amount" placeholder="例: 27500000"
                       value="{{ old('contract_amount', $project?->contract_amount) }}">
                <div class="hint">円単位 半角数値</div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px 20px;">
            <div class="fld">
                <label>見積日</label>
                @include('dad.projects._date-picker', [
                    'name' => 'estimate_date',
                    'value' => old('estimate_date', optional($project?->estimate_date)->format('Y-m-d')),
                ])
            </div>
            <div class="fld">
                <label>受注日</label>
                @include('dad.projects._date-picker', [
                    'name' => 'order_date',
                    'value' => old('order_date', optional($project?->order_date)->format('Y-m-d')),
                ])
            </div>
            <div class="fld">
                <label>入金日</label>
                @include('dad.projects._date-picker', [
                    'name' => 'payment_date',
                    'value' => old('payment_date', optional($project?->payment_date)->format('Y-m-d')),
                ])
            </div>
            <div class="fld">
                <label>着工日</label>
                @include('dad.projects._date-picker', [
                    'name' => 'start_date',
                    'value' => old('start_date', optional($project?->start_date)->format('Y-m-d')),
                ])
            </div>
            <div class="fld">
                <label>完工日</label>
                @include('dad.projects._date-picker', [
                    'name' => 'completion_date',
                    'value' => old('completion_date', optional($project?->completion_date)->format('Y-m-d')),
                ])
            </div>
            <div></div>
            <div class="fld">
                <label>工期開始</label>
                @include('dad.projects._date-picker', [
                    'name' => 'period_start',
                    'value' => old('period_start', optional($project?->period_start)->format('Y-m-d')),
                ])
            </div>
            <div class="fld">
                <label>工期終了</label>
                @include('dad.projects._date-picker', [
                    'name' => 'period_end',
                    'value' => old('period_end', optional($project?->period_end)->format('Y-m-d')),
                ])
            </div>
        </div>
    </div>

    {{-- カード: 原価明細（Alpine 配列） --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
            <div class="card-title" style="margin-bottom: 0;">原価明細</div>
            <div style="display: flex; gap: 8px;">
                <button type="button" @click="openExcelImport()" x-show="!excelImport.open"
                        style="padding: 6px 14px; background: white; border: 1px solid #a7f3d0; border-radius: 6px; color: #047857; font-size: 12px; font-weight: 600; cursor: pointer;">📂 Excel取込</button>
                <button type="button" @click="addCostRow()"
                        style="padding: 6px 14px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; color: #047857; font-size: 12px; font-weight: 600; cursor: pointer;">＋ 行追加</button>
            </div>
        </div>

        {{-- Excel取込パネル（partial） --}}
        @include('dad.projects._excel_import')

        <div x-show="costRows.length === 0 && !excelImport.open" style="padding: 16px; text-align: center; font-size: 12px; color: #9ca3af;">
            ＋ 行追加 または Excel取込 で原価明細を追加してください。
        </div>

        <table x-show="costRows.length > 0" class="w-full" style="border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 11px; font-weight: 700; text-align: left; width: 12%;">カテゴリ<span class="required">*</span></th>
                    <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 11px; font-weight: 700; text-align: left;">内容</th>
                    <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 11px; font-weight: 700; text-align: right; width: 12%;">見積額</th>
                    <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 11px; font-weight: 700; text-align: right; width: 12%;">実績額</th>
                    <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 11px; font-weight: 700; text-align: left; width: 16%;">協力業者</th>
                    <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 11px; font-weight: 700; text-align: left; width: 14%;">備考</th>
                    <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 11px; font-weight: 700; text-align: center; width: 60px;">削除</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(row, idx) in costRows" :key="idx">
                    <tr>
                        <td style="padding: 6px; border-bottom: 1px solid #f3f4f6;">
                            <select :name="'costs[' + idx + '][cost_category]'" x-model="row.cost_category"
                                    style="width: 100%; height: 34px; padding: 0 8px; font-size: 12px; border: 1px solid #d1d5db; border-radius: 4px; background: #fff;">
                                <option value="">選択</option>
                                @foreach(\App\Enums\DadCostCategory::cases() as $cat)
                                    <option value="{{ $cat->value }}">{{ $cat->label() }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td style="padding: 6px; border-bottom: 1px solid #f3f4f6;">
                            <input type="text" :name="'costs[' + idx + '][description]'" x-model="row.description" maxlength="200"
                                   style="width: 100%; height: 34px; padding: 0 8px; font-size: 12px; border: 1px solid #d1d5db; border-radius: 4px;">
                        </td>
                        <td style="padding: 6px; border-bottom: 1px solid #f3f4f6;">
                            <input type="text" inputmode="numeric" :name="'costs[' + idx + '][estimated_amount]'" x-model="row.estimated_amount"
                                   style="width: 100%; height: 34px; padding: 0 8px; font-size: 12px; border: 1px solid #d1d5db; border-radius: 4px; text-align: right; font-variant-numeric: tabular-nums;">
                        </td>
                        <td style="padding: 6px; border-bottom: 1px solid #f3f4f6;">
                            <input type="text" inputmode="numeric" :name="'costs[' + idx + '][actual_amount]'" x-model="row.actual_amount"
                                   style="width: 100%; height: 34px; padding: 0 8px; font-size: 12px; border: 1px solid #d1d5db; border-radius: 4px; text-align: right; font-variant-numeric: tabular-nums;">
                        </td>
                        <td style="padding: 6px; border-bottom: 1px solid #f3f4f6;">
                            <select :name="'costs[' + idx + '][subcontractor_id]'" x-model="row.subcontractor_id"
                                    style="width: 100%; height: 34px; padding: 0 8px; font-size: 12px; border: 1px solid #d1d5db; border-radius: 4px; background: #fff;">
                                <option value="">—</option>
                                @foreach($subcontractors as $sub)
                                    <option value="{{ $sub->id }}">{{ $sub->company_name }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td style="padding: 6px; border-bottom: 1px solid #f3f4f6;">
                            <input type="text" :name="'costs[' + idx + '][notes]'" x-model="row.notes" maxlength="200"
                                   style="width: 100%; height: 34px; padding: 0 8px; font-size: 12px; border: 1px solid #d1d5db; border-radius: 4px;">
                        </td>
                        <td style="padding: 6px; border-bottom: 1px solid #f3f4f6; text-align: center;">
                            <button type="button" @click="removeCostRow(idx)"
                                    style="font-size: 11px; color: #b91c1c; border: 1px solid #fca5a5; padding: 3px 8px; border-radius: 3px; background: white; cursor: pointer;">削除</button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    @if(!is_null($project))
        {{-- カード: 人員配置（編集時のみ） --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
                <div class="card-title" style="margin-bottom: 0;">人員配置</div>
                <button type="button" @click="addAssignmentRow()"
                        style="padding: 6px 14px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; color: #047857; font-size: 12px; font-weight: 600; cursor: pointer;">＋ 配置追加</button>
            </div>

            <div x-show="assignmentRows.length === 0" style="padding: 16px; text-align: center; font-size: 12px; color: #9ca3af;">
                ＋ 配置追加 ボタンで人員配置を追加してください。
            </div>

            <table x-show="assignmentRows.length > 0" class="w-full" style="border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 11px; font-weight: 700; text-align: left;">従業員<span class="required">*</span></th>
                        <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 11px; font-weight: 700; text-align: left; width: 16%;">役割</th>
                        <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 11px; font-weight: 700; text-align: left; width: 12%;">配置開始</th>
                        <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 11px; font-weight: 700; text-align: left; width: 12%;">配置終了</th>
                        <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 11px; font-weight: 700; text-align: left; width: 18%;">備考</th>
                        <th style="padding: 8px; background: #f9fafb; border-bottom: 1px solid #e5e7eb; font-size: 11px; font-weight: 700; text-align: center; width: 60px;">削除</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(a, idx) in assignmentRows" :key="idx">
                        <tr>
                            <td style="padding: 6px; border-bottom: 1px solid #f3f4f6;">
                                <select :name="'assignments[' + idx + '][employee_id]'" x-model="a.employee_id"
                                        style="width: 100%; height: 34px; padding: 0 8px; font-size: 12px; border: 1px solid #d1d5db; border-radius: 4px; background: #fff;">
                                    <option value="">選択</option>
                                    @foreach($employees as $emp)
                                        <option value="{{ $emp->id }}">{{ $emp->employee_code }} {{ $emp->name }}{{ $emp->position ? '（' . $emp->position . '）' : '' }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td style="padding: 6px; border-bottom: 1px solid #f3f4f6;">
                                <input type="text" :name="'assignments[' + idx + '][role]'" x-model="a.role" maxlength="50"
                                       style="width: 100%; height: 34px; padding: 0 8px; font-size: 12px; border: 1px solid #d1d5db; border-radius: 4px;">
                            </td>
                            <td style="padding: 6px; border-bottom: 1px solid #f3f4f6;">
                                @include('dad.projects._date-picker-row', [
                                    'valueExpr'  => 'a.start_date',
                                    'nameExpr'   => "'assignments[' + idx + '][start_date]'",
                                    'assignExpr' => 'a.start_date',
                                ])
                            </td>
                            <td style="padding: 6px; border-bottom: 1px solid #f3f4f6;">
                                @include('dad.projects._date-picker-row', [
                                    'valueExpr'  => 'a.end_date',
                                    'nameExpr'   => "'assignments[' + idx + '][end_date]'",
                                    'assignExpr' => 'a.end_date',
                                ])
                            </td>
                            <td style="padding: 6px; border-bottom: 1px solid #f3f4f6;">
                                <input type="text" :name="'assignments[' + idx + '][notes]'" x-model="a.notes" maxlength="200"
                                       style="width: 100%; height: 34px; padding: 0 8px; font-size: 12px; border: 1px solid #d1d5db; border-radius: 4px;">
                            </td>
                            <td style="padding: 6px; border-bottom: 1px solid #f3f4f6; text-align: center;">
                                <button type="button" @click="removeAssignmentRow(idx)"
                                        style="font-size: 11px; color: #b91c1c; border: 1px solid #fca5a5; padding: 3px 8px; border-radius: 3px; background: white; cursor: pointer;">削除</button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    @endif

    {{-- カード: 備考 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="card-title">備考</div>
        <div class="fld">
            <textarea name="memo" rows="4" placeholder="特記事項・備考...">{{ old('memo', $project?->memo) }}</textarea>
        </div>
    </div>

</div>

<style>
.card-title { font-size: 14px; font-weight: 700; color: #111827; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 1px solid #e5e7eb; }
.fld label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 5px; }
.fld input[type="text"], .fld input[type="tel"], .fld input[type="email"], .fld textarea, .fld select {
    width: 100%; padding: 0 10px; font-size: 13px; border: 1px solid #d1d5db; border-radius: 4px; background: #fff;
}
.fld input[type="text"], .fld input[type="tel"], .fld input[type="email"], .fld select { height: 36px; }
.fld textarea { padding: 8px 10px; resize: vertical; min-height: 80px; }
.fld input:focus, .fld textarea:focus, .fld select:focus { outline: none; border-color: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.15); }
.required { color: #dc2626; margin-left: 4px; }
.hint { font-size: 11px; color: #6b7280; margin-top: 3px; }

/* ===== カスタム日付ピッカー（housing/mansion 共通パターン流用、DAD 用に高さ/文字サイズ調整） ===== */
.date-picker-wrap { position: relative; }
.date-input-trigger {
    width: 100%; height: 36px;
    padding: 0 10px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    font-size: 13px; color: #111827; background: #fff;
    box-sizing: border-box;
    display: flex; align-items: center; justify-content: space-between;
    cursor: pointer;
    text-align: left;
    font-family: inherit;
}
.date-input-trigger:hover { border-color: #10b981; }
.date-input-trigger:focus {
    outline: none; border-color: #10b981;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
}
.date-input-trigger .placeholder { color: #9ca3af; }
.date-input-trigger .cal-icon { color: #059669; display: inline-flex; }

.picker-popup {
    position: absolute;
    top: calc(100% + 6px); left: 0;
    z-index: 100;
    width: 320px;
    background: white;
    border-radius: 16px;
    box-shadow: 0 20px 50px rgba(0,0,0,0.1), 0 2px 4px rgba(0,0,0,0.04);
    padding: 18px;
    box-sizing: border-box;
}
.picker-popup .cal-info {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 14px;
}
.picker-popup .cal-info .pill {
    background: #ecfdf5; color: #047857;
    font-size: 11px; font-weight: 700;
    padding: 3px 10px; border-radius: 99px;
    letter-spacing: 0.3px;
}
.picker-popup .cal-info .sel-date { font-size: 12px; color: #6b7280; }
.picker-popup .cal-info .sel-date b { color: #047857; font-weight: 700; }

.picker-popup .cal-nav {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 4px 12px;
}
.picker-popup .cal-nav .arrow-btn {
    width: 28px; height: 28px;
    display: inline-flex; align-items: center; justify-content: center;
    border: none; background: #f9fafb; border-radius: 50%;
    cursor: pointer; color: #6b7280; font-size: 13px;
    transition: all 0.15s;
}
.picker-popup .cal-nav .arrow-btn:hover { background: #ecfdf5; color: #059669; }
.picker-popup .cal-nav .arrow-btn.hidden { visibility: hidden; }
.picker-popup .cal-nav .month-btns { display: flex; align-items: center; gap: 4px; }
.picker-popup .cal-nav .ym-btn {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 15px; font-weight: 700; color: #111827;
    background: transparent; border: none; cursor: pointer;
    padding: 5px 10px; border-radius: 8px;
    transition: all 0.15s;
    font-family: inherit;
}
.picker-popup .cal-nav .ym-btn:hover { background: #f3f4f6; color: #059669; }
.picker-popup .cal-nav .ym-btn.active { background: #ecfdf5; color: #047857; }
.picker-popup .cal-nav .ym-btn .chev { font-size: 10px; color: #9ca3af; transition: transform 0.15s; }
.picker-popup .cal-nav .ym-btn.active .chev { transform: rotate(180deg); color: #047857; }

.picker-popup .cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; }
.picker-popup .cal-dow {
    text-align: center; font-size: 11px; font-weight: 700;
    color: #9ca3af; padding: 5px 0 8px;
}
.picker-popup .cal-dow.sun { color: #dc2626; }
.picker-popup .cal-dow.sat { color: #2563eb; }
.picker-popup .cal-cell {
    text-align: center; font-size: 12px; color: #374151;
    cursor: pointer; height: 32px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    position: relative;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    border: none; background: transparent;
    font-family: inherit;
}
.picker-popup .cal-cell:hover { background: #f3f4f6; }
.picker-popup .cal-cell.muted { color: #e5e7eb; }
.picker-popup .cal-cell.sun { color: #dc2626; }
.picker-popup .cal-cell.sat { color: #2563eb; }
.picker-popup .cal-cell.today { color: #059669; font-weight: 700; }
.picker-popup .cal-cell.today::after {
    content: ''; position: absolute; bottom: 4px; left: 50%;
    transform: translateX(-50%);
    width: 4px; height: 4px; border-radius: 50%;
    background: #059669;
}
.picker-popup .cal-cell.selected {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white; font-weight: 700;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.35);
    transform: scale(1.05);
}
.picker-popup .cal-cell.selected.sun,
.picker-popup .cal-cell.selected.sat { color: white; }
.picker-popup .cal-cell.selected.today::after { background: white; }

.picker-popup .ym-picker { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; padding: 8px 2px; }
.picker-popup .ym-picker button {
    padding: 12px 6px;
    font-size: 12px; font-weight: 600;
    background: #f9fafb;
    color: #374151;
    border: 1px solid transparent;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
    position: relative;
    font-family: inherit;
}
.picker-popup .ym-picker button:hover { background: #ecfdf5; color: #059669; }
.picker-popup .ym-picker button.today {
    color: #059669; font-weight: 700;
    background: white; border-color: #a7f3d0;
}
.picker-popup .ym-picker button.today::after {
    content: ''; position: absolute; bottom: 5px; left: 50%;
    transform: translateX(-50%);
    width: 4px; height: 4px; border-radius: 50%;
    background: #059669;
}
.picker-popup .ym-picker button.selected {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white; font-weight: 700;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.35);
    border-color: transparent;
}
.picker-popup .ym-picker button.selected.today::after { background: white; }

.picker-popup .range-hint {
    text-align: center;
    font-size: 11px; color: #9ca3af;
    padding-top: 8px; margin-top: 4px;
    border-top: 1px dashed #f3f4f6;
}

.picker-popup .cal-foot {
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid #f3f4f6;
    display: flex; gap: 6px;
}
.picker-popup .cal-foot .shortcut {
    flex: 1;
    padding: 6px 8px;
    font-size: 11px; font-weight: 600;
    background: #f9fafb;
    color: #374151;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    cursor: pointer;
    text-align: center;
    font-family: inherit;
}
.picker-popup .cal-foot .shortcut:hover {
    background: #ecfdf5; color: #059669; border-color: #a7f3d0;
}
</style>

<script>
function projectForm() {
    return {
        costRows: [],
        assignmentRows: [],

        // ========== Excel取込 ==========
        excelImport: {
            open: false,
            step: 1,
            fileName: '',
            sheets: [],
            selectedSheet: '',
            allRows: [],
            headerRowIndex: 0,
            columns: [],
            previewRows: []
        },
        _workbook: null,
        // 協力業者名 → id マップ（Excel取込時の自動解決用）
        _subcontractorByName: {},

        initForm: function () {
            // 既存データを Alpine に投入
            const existingCosts = @json($existingCosts);
            const existingAssignments = @json($existingAssignments);
            this.costRows = existingCosts.length > 0 ? existingCosts.slice() : [];
            this.assignmentRows = existingAssignments.length > 0 ? existingAssignments.slice() : [];

            // 協力業者リストから 名前→id マップを作成
            const subList = @json($subcontractorJsonList);
            const self = this;
            subList.forEach(function (s) {
                if (s.company_name) self._subcontractorByName[s.company_name] = s.id;
            });
        },

        addCostRow: function () {
            this.costRows.push({
                cost_category: '',
                description: '',
                estimated_amount: '',
                actual_amount: '',
                subcontractor_id: '',
                notes: ''
            });
        },
        removeCostRow: function (idx) {
            this.costRows.splice(idx, 1);
        },

        addAssignmentRow: function () {
            this.assignmentRows.push({
                employee_id: '',
                role: '',
                start_date: '',
                end_date: '',
                notes: ''
            });
        },
        removeAssignmentRow: function (idx) {
            this.assignmentRows.splice(idx, 1);
        },

        // ========== Excel取込メソッド ==========
        openExcelImport: function () {
            this.resetExcelImport();
            this.excelImport.open = true;
        },
        closeExcelImport: function () {
            this.excelImport.open = false;
            this.resetExcelImport();
        },
        resetExcelImport: function () {
            this.excelImport.step = 1;
            this.excelImport.fileName = '';
            this.excelImport.sheets = [];
            this.excelImport.selectedSheet = '';
            this.excelImport.allRows = [];
            this.excelImport.headerRowIndex = 0;
            this.excelImport.columns = [];
            this.excelImport.previewRows = [];
            this._workbook = null;
        },
        onExcelFile: async function (e) {
            const file = e.target.files && e.target.files[0];
            if (!file) return;
            await this.readExcel(file);
        },
        onExcelDrop: async function (e) {
            const file = e.dataTransfer.files && e.dataTransfer.files[0];
            if (!file) return;
            await this.readExcel(file);
        },
        readExcel: async function (file) {
            this.excelImport.fileName = file.name;
            try {
                if (typeof XLSX === 'undefined') {
                    alert('Excel 読み込みライブラリが読み込まれていません。ページを再読み込みしてください。');
                    return;
                }
                const buf = await file.arrayBuffer();
                const wb = XLSX.read(buf, { type: 'array' });
                this._workbook = wb;
                this.excelImport.sheets = wb.SheetNames;
                this.excelImport.selectedSheet = wb.SheetNames[0];
                this.excelImport.headerRowIndex = 0;
                this.loadSheet();
                this.excelImport.step = 2;
                // 複数シートがある場合は select に option を動的注入（Bug #16: x-for で <option> を生成しない）
                if (wb.SheetNames.length > 1) {
                    const self = this;
                    setTimeout(function () {
                        const sel = document.getElementById('excel-sheet-select');
                        if (!sel) return;
                        sel.innerHTML = '';
                        wb.SheetNames.forEach(function (name) {
                            const opt = document.createElement('option');
                            opt.value = name;
                            opt.textContent = name;
                            if (name === self.excelImport.selectedSheet) opt.selected = true;
                            sel.appendChild(opt);
                        });
                    }, 50);
                }
            } catch (err) {
                alert('ファイルの読み込みに失敗しました: ' + err.message);
            }
        },
        loadSheet: function () {
            if (!this._workbook) return;
            const ws = this._workbook.Sheets[this.excelImport.selectedSheet];
            this.excelImport.allRows = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
            // 最初の非空行をヘッダー行とみなす
            const firstNonEmpty = this.excelImport.allRows.findIndex(function (r) {
                return r.some(function (v) { return String(v).trim() !== ''; });
            });
            this.excelImport.headerRowIndex = firstNonEmpty >= 0 ? firstNonEmpty : 0;
            this.buildColumns();
        },
        buildColumns: function () {
            const ei = this.excelImport;
            const header = ei.allRows[ei.headerRowIndex] || [];
            const body = ei.allRows.slice(ei.headerRowIndex + 1, ei.headerRowIndex + 4);
            const colCount = ei.allRows.length
                ? Math.max.apply(null, ei.allRows.map(function (r) { return r.length; }))
                : 0;
            const cols = [];
            const self = this;
            for (let i = 0; i < colCount; i++) {
                const headerText = String(header[i] || '');
                cols.push({
                    idx: i,
                    letter: XLSX.utils.encode_col(i),
                    header: headerText,
                    samples: body.map(function (r) { return String(r[i] === undefined ? '' : r[i]); }),
                    mapping: self.autoGuess(headerText, body.map(function (r) { return String(r[i] === undefined ? '' : r[i]); }))
                });
            }
            ei.columns = cols;
        },
        autoGuess: function (headerText, samples) {
            const h = String(headerText).replace(/\s/g, '');
            if (/(費用|費目|項目|分類|カテゴリ|工種)/.test(h)) return 'category';
            if (/(内容|摘要|作業|品名|仕様)/.test(h)) return 'detail';
            if (/(金額|見積|予算|合計)/.test(h) && !/単価/.test(h)) return 'amount';
            if (/(業者|協力|下請|外注先|取引先)/.test(h)) return 'subcontractor';
            if (/(備考|メモ|注記|コメント)/.test(h)) return 'note';
            // ヘッダーが空でもサンプル値で「数値らしい列=金額」を推定
            if (h === '' && samples && samples.length > 0) {
                const numericHits = samples.filter(function (s) {
                    const n = String(s).replace(/[,，\s円¥]/g, '');
                    return /^-?\d+(\.\d+)?$/.test(n) && parseInt(n, 10) >= 1000;
                }).length;
                if (numericHits >= 2) return 'amount';
            }
            return '';
        },
        goToPreview: function () {
            const ei = this.excelImport;
            const map = { category: -1, detail: -1, amount: -1, subcontractor: -1, note: -1 };
            ei.columns.forEach(function (c) { if (c.mapping) map[c.mapping] = c.idx; });
            const body = ei.allRows.slice(ei.headerRowIndex + 1).filter(function (r) {
                return r.some(function (v) { return String(v).trim() !== ''; });
            });
            const self = this;
            ei.previewRows = body.map(function (r) {
                // 金額正規化（全角→半角、カンマ・空白・「円」「¥」除去）
                const rawAmount = map.amount >= 0 ? String(r[map.amount] === undefined ? '' : r[map.amount]) : '';
                const normalized = rawAmount.replace(/[０-９]/g, function (c) {
                    return String.fromCharCode(c.charCodeAt(0) - 0xFEE0);
                }).replace(/[,，\s円¥]/g, '');
                const isNumeric = /^-?\d+$/.test(normalized);
                const nAmount = isNumeric ? normalized : '';

                // カテゴリマッチング → DAD Enum value 形式
                const rawCat = map.category >= 0 ? String(r[map.category] === undefined ? '' : r[map.category]).trim() : '';
                const matchedCat = self.matchCategory(rawCat);

                // 協力業者名 → id 解決
                const rawSub = map.subcontractor >= 0 ? String(r[map.subcontractor] === undefined ? '' : r[map.subcontractor]).trim() : '';
                const subId = rawSub ? (self._subcontractorByName[rawSub] || '') : '';

                return {
                    category: matchedCat,
                    rawCategory: rawCat,
                    detail: map.detail >= 0 ? String(r[map.detail] === undefined ? '' : r[map.detail]) : '',
                    amount: nAmount,
                    subcontractorId: subId,
                    rawSubcontractor: rawSub,
                    note: map.note >= 0 ? String(r[map.note] === undefined ? '' : r[map.note]) : '',
                    warnCategory: rawCat !== '' && !matchedCat,
                    warnAmount: rawAmount !== '' && !isNumeric
                };
            });
            ei.step = 3;
        },
        matchCategory: function (raw) {
            // DAD Enum value (material / subcontract / labor / equipment / overhead / other) を返す
            const r = String(raw).replace(/\s/g, '');
            if (!r) return '';
            // 日本語ラベル完全一致
            const exactMap = {
                '材料費': 'material', '外注費': 'subcontract', '人件費': 'labor',
                '機械経費': 'equipment', '諸経費': 'overhead', 'その他': 'other'
            };
            if (exactMap[r]) return exactMap[r];
            // エイリアス（部分一致）
            const aliases = [
                { keys: ['材料', '材料代', '資材'], value: 'material' },
                { keys: ['外注', '下請', '下請費'], value: 'subcontract' },
                { keys: ['人件', '労務', '労務費'], value: 'labor' },
                { keys: ['機械', '重機', '機材'], value: 'equipment' },
                { keys: ['諸経費', '経費'], value: 'overhead' }
            ];
            for (let i = 0; i < aliases.length; i++) {
                const a = aliases[i];
                for (let j = 0; j < a.keys.length; j++) {
                    if (r.indexOf(a.keys[j]) >= 0) return a.value;
                }
            }
            return '';
        },
        warnCountCategory: function () {
            return this.excelImport.previewRows.filter(function (r) { return r.warnCategory; }).length;
        },
        warnCountAmount: function () {
            return this.excelImport.previewRows.filter(function (r) { return r.warnAmount; }).length;
        },
        commitImport: function () {
            const self = this;
            this.excelImport.previewRows.forEach(function (p) {
                self.costRows.push({
                    cost_category: p.category || '',
                    description: p.detail || '',
                    estimated_amount: p.amount || '',
                    actual_amount: '',
                    subcontractor_id: p.subcontractorId || '',
                    notes: p.note || ''
                });
            });
            this.closeExcelImport();
        }
    };
}
</script>

<script>
// カスタム日付ピッカー（housing/mansion 共通パターンを流用）
// initial: "YYYY-MM-DD" 形式の初期値（空文字の場合は未選択）
function datePicker(initial) {
    var initialDate = null;
    if (initial) {
        var parts = initial.split('-');
        initialDate = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, parseInt(parts[2]));
    }
    var now = new Date();
    var todayDate = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    var viewBase = initialDate || todayDate;

    return {
        selected: initialDate,
        viewYear: viewBase.getFullYear(),
        viewMonth: viewBase.getMonth(),
        open: false,
        mode: 'calendar',

        todayYear: todayDate.getFullYear(),
        todayMonth: todayDate.getMonth(),
        todayDate: todayDate.getDate(),

        get yearRange() {
            var years = [];
            var start = this.todayYear - 10;
            var end = this.todayYear + 1;
            for (var y = start; y <= end; y++) { years.push(y); }
            return years;
        },
        get yearRangeHint() {
            var start = this.todayYear - 10;
            var end = this.todayYear + 1;
            return '過去10年～未来1年（' + start + ' - ' + end + '）';
        },

        get selectedLabel() {
            if (!this.selected) return '';
            var d = this.selected;
            return d.getFullYear() + '/' +
                String(d.getMonth() + 1).padStart(2, '0') + '/' +
                String(d.getDate()).padStart(2, '0');
        },
        get selectedLong() {
            if (!this.selected) return '';
            var d = this.selected;
            var dowNames = ['日', '月', '火', '水', '木', '金', '土'];
            return d.getFullYear() + '年' +
                (d.getMonth() + 1) + '月' +
                d.getDate() + '日（' + dowNames[d.getDay()] + '）';
        },
        get isoValue() {
            if (!this.selected) return '';
            var d = this.selected;
            return d.getFullYear() + '-' +
                String(d.getMonth() + 1).padStart(2, '0') + '-' +
                String(d.getDate()).padStart(2, '0');
        },

        get calendarCells() {
            var cells = [];
            var firstDow = new Date(this.viewYear, this.viewMonth, 1).getDay();
            var daysInMonth = new Date(this.viewYear, this.viewMonth + 1, 0).getDate();
            var prevMonthDays = new Date(this.viewYear, this.viewMonth, 0).getDate();

            for (var i = firstDow - 1; i >= 0; i--) {
                cells.push({ day: prevMonthDays - i, muted: true, dow: (firstDow - 1 - i), date: null });
            }
            for (var d = 1; d <= daysInMonth; d++) {
                var cellDate = new Date(this.viewYear, this.viewMonth, d);
                cells.push({ day: d, muted: false, dow: cellDate.getDay(), date: cellDate });
            }
            var nextDay = 1;
            while (cells.length < 42) {
                cells.push({ day: nextDay, muted: true, dow: cells.length % 7, date: null });
                nextDay++;
            }
            return cells;
        },

        isToday: function (cell) {
            if (!cell.date) return false;
            return cell.date.getFullYear() === this.todayYear &&
                cell.date.getMonth() === this.todayMonth &&
                cell.date.getDate() === this.todayDate;
        },
        isSelected: function (cell) {
            if (!cell.date || !this.selected) return false;
            return cell.date.getFullYear() === this.selected.getFullYear() &&
                cell.date.getMonth() === this.selected.getMonth() &&
                cell.date.getDate() === this.selected.getDate();
        },

        prevMonth: function () {
            if (this.viewMonth === 0) { this.viewMonth = 11; this.viewYear--; }
            else { this.viewMonth--; }
        },
        nextMonth: function () {
            if (this.viewMonth === 11) { this.viewMonth = 0; this.viewYear++; }
            else { this.viewMonth++; }
        },

        toggleYearMode: function () { this.mode = this.mode === 'year' ? 'calendar' : 'year'; },
        toggleMonthMode: function () { this.mode = this.mode === 'month' ? 'calendar' : 'month'; },

        pick: function (cell) {
            if (!cell.date) return;
            this.selected = cell.date;
            this.open = false;
        },
        pickYear: function (year) { this.viewYear = year; this.mode = 'calendar'; },
        pickMonth: function (month) { this.viewMonth = month; this.mode = 'calendar'; },

        setToday: function () {
            this.selected = new Date(this.todayYear, this.todayMonth, this.todayDate);
            this.viewYear = this.todayYear;
            this.viewMonth = this.todayMonth;
            this.open = false;
        },
        setWeekAgo: function () {
            var d = new Date(this.todayYear, this.todayMonth, this.todayDate);
            d.setDate(d.getDate() - 7);
            this.selected = d;
            this.viewYear = d.getFullYear();
            this.viewMonth = d.getMonth();
            this.open = false;
        },
        setMonthAgo: function () {
            var d = new Date(this.todayYear, this.todayMonth, this.todayDate);
            d.setMonth(d.getMonth() - 1);
            this.selected = d;
            this.viewYear = d.getFullYear();
            this.viewMonth = d.getMonth();
            this.open = false;
        }
    };
}
</script>

{{-- ============================================================ --}}
{{-- Google Maps — 工事現場の所在地マップ --}}
{{-- realestate/projects と同じ段階的フォールバックパターンを移植 --}}
{{-- ============================================================ --}}
<script>
var dadMap = null;
var dadMarker = null;
var dadGeocoder = null;

// 既定の中心位置（松山市役所付近）— 住所空欄/全失敗時のフォールバック
var DAD_DEFAULT_CENTER = { lat: 33.8392, lng: 132.7657, zoom: 13 };

function onGoogleMapsReady() {
    dadGeocoder = new google.maps.Geocoder();

    var savedLat = document.getElementById('input-latitude').value;
    var savedLng = document.getElementById('input-longitude').value;
    if (savedLat && savedLng) {
        showDadMap(parseFloat(savedLat), parseFloat(savedLng), 17);
    }
}

// 住所を段階的に短くしてフォールバック候補を生成
// 例: "愛媛県松山市勝山町2丁目4-7" →
//   [フル, "愛媛県松山市勝山町2丁目"(番地除去), "愛媛県松山市勝山町"(丁目除去), "愛媛県松山市", "愛媛県"]
function buildDadAddressFallbacks(address) {
    var candidates = [{ address: address, level: 'full', zoom: 17 }];

    // 末尾の番地（"4-7"、"5番地3号"など）を除去
    var stripped = address
        .replace(/[\d０-９]+(?:[-‐−ー－―][\d０-９]+)+(?:号)?$/, '')
        .replace(/[\d０-９]+番地?(?:[\d０-９]+号?)?$/, '')
        .trim();
    if (stripped && stripped !== address) {
        candidates.push({ address: stripped, level: 'block', zoom: 16 });
    }

    // 丁目以下を除去
    stripped = address.replace(/[\d０-９]+丁目.*$/, '').trim();
    if (stripped && !candidates.some(function(c) { return c.address === stripped; })) {
        candidates.push({ address: stripped, level: 'town', zoom: 15 });
    }

    // 市区町村まで
    var cityMatch = address.match(/^.*?[市区町村]/);
    if (cityMatch) {
        var cityLevel = cityMatch[0];
        if (!candidates.some(function(c) { return c.address === cityLevel; })) {
            candidates.push({ address: cityLevel, level: 'city', zoom: 13 });
        }
    }

    // 都道府県のみ
    var prefMatch = address.match(/^.*?[都道府県]/);
    if (prefMatch) {
        var prefLevel = prefMatch[0];
        if (!candidates.some(function(c) { return c.address === prefLevel; })) {
            candidates.push({ address: prefLevel, level: 'prefecture', zoom: 10 });
        }
    }

    return candidates;
}

// 候補を順番にジオコードして最初にヒットしたものを返す
function tryGeocodeDadCandidates(candidates, index, callback) {
    if (index >= candidates.length) {
        callback(null);
        return;
    }
    var candidate = candidates[index];
    dadGeocoder.geocode({ address: candidate.address }, function(results, status) {
        if (status === 'OK' && results[0]) {
            callback({
                location: results[0].geometry.location,
                level: candidate.level,
                zoom: candidate.zoom,
                matchedAddress: candidate.address
            });
        } else {
            tryGeocodeDadCandidates(candidates, index + 1, callback);
        }
    });
}

function geocodeAddress() {
    var addressInput = document.getElementById('input-site-address');
    var address = addressInput ? addressInput.value.trim() : '';

    if (!dadGeocoder) {
        showMapStatus('Google Maps を読み込み中です。しばらくお待ちください。', '#fef3c7', '#92400e');
        return;
    }

    // 住所が空欄 → 既定の松山市中心を表示してピン操作を促す
    if (!address) {
        showMapStatus('所在地が空欄です。松山市中心を表示しています。地図をクリックして位置を指定してください。', '#dbeafe', '#1e40af');
        showDadMap(DAD_DEFAULT_CENTER.lat, DAD_DEFAULT_CENTER.lng, DAD_DEFAULT_CENTER.zoom);
        return;
    }

    showMapStatus('住所を検索中...', '#fef3c7', '#92400e');
    document.getElementById('btn-geocode').disabled = true;

    var candidates = buildDadAddressFallbacks(address);

    tryGeocodeDadCandidates(candidates, 0, function(result) {
        document.getElementById('btn-geocode').disabled = false;

        if (result) {
            if (result.level === 'full') {
                showMapStatus('住所が見つかりました。ピンをドラッグして正確な位置に調整できます。', '#d1fae5', '#065f46');
            } else {
                showMapStatus('「' + result.matchedAddress + '」までヒットしました。地図をクリックして正確な位置を指定してください。', '#fef3c7', '#92400e');
            }
            showDadMap(result.location.lat(), result.location.lng(), result.zoom);
        } else {
            showMapStatus('住所が見つかりませんでした。松山市中心を表示しています。地図をクリックして位置を指定してください。', '#fef3c7', '#92400e');
            showDadMap(DAD_DEFAULT_CENTER.lat, DAD_DEFAULT_CENTER.lng, DAD_DEFAULT_CENTER.zoom);
        }
    });
}

function showMapStatus(msg, bg, color) {
    var el = document.getElementById('map-status');
    el.style.display = 'block';
    el.style.background = bg;
    el.style.color = color;
    el.textContent = msg;
}

function showDadMap(lat, lng, zoom) {
    var wrap = document.getElementById('map-wrap');
    wrap.style.display = 'block';

    if (typeof zoom !== 'number') zoom = 17;

    if (!dadMap) {
        dadMap = new google.maps.Map(document.getElementById('dad-project-map'), {
            center: { lat: lat, lng: lng },
            zoom: zoom,
            mapTypeControl: true,
            streetViewControl: true,
            fullscreenControl: false
        });

        dadMarker = new google.maps.Marker({
            position: { lat: lat, lng: lng },
            map: dadMap,
            draggable: true,
            title: 'ドラッグして位置を調整'
        });

        dadMarker.addListener('dragend', function() {
            var pos = dadMarker.getPosition();
            updateDadCoords(pos.lat(), pos.lng());
        });

        dadMap.addListener('click', function(e) {
            dadMarker.setPosition(e.latLng);
            updateDadCoords(e.latLng.lat(), e.latLng.lng());
        });
    } else {
        dadMap.setCenter({ lat: lat, lng: lng });
        dadMap.setZoom(zoom);
        dadMarker.setPosition({ lat: lat, lng: lng });
    }

    updateDadCoords(lat, lng);
}

function updateDadCoords(lat, lng) {
    document.getElementById('input-latitude').value = lat.toFixed(7);
    document.getElementById('input-longitude').value = lng.toFixed(7);
    document.getElementById('display-lat').textContent = lat.toFixed(7);
    document.getElementById('display-lng').textContent = lng.toFixed(7);
}
</script>

{{-- Google Maps API 読み込み --}}
<script src="https://maps.googleapis.com/maps/api/js?key={{ config('services.google_maps.api_key') }}&callback=onGoogleMapsReady&language=ja&region=JP" async defer></script>

{{-- SheetJS（Excel ファイル解析）— CLAUDE.md ルール: cdn.jsdelivr.net のみ許可 --}}
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
