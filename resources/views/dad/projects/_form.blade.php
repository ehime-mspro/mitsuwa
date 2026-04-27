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
                        <option value="{{ $u->id }}" {{ old('staff_user_id', $project?->staff_user_id) == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
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

    {{-- カード: 工事現場 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div class="card-title">工事現場</div>
        <div class="fld" style="margin-bottom: 16px;">
            <label>工事現場住所</label>
            <input type="text" name="site_address" maxlength="300" placeholder="例: 愛媛県松山市中央"
                   value="{{ old('site_address', $project?->site_address) }}">
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px 20px;">
            <div class="fld">
                <label>緯度</label>
                <input type="text" name="latitude" placeholder="例: 33.8417"
                       value="{{ old('latitude', $project?->latitude) }}">
            </div>
            <div class="fld">
                <label>経度</label>
                <input type="text" name="longitude" placeholder="例: 132.7665"
                       value="{{ old('longitude', $project?->longitude) }}">
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
                <input type="text" name="estimate_date" placeholder="YYYY-MM-DD"
                       value="{{ old('estimate_date', optional($project?->estimate_date)->format('Y-m-d')) }}">
            </div>
            <div class="fld">
                <label>受注日</label>
                <input type="text" name="order_date" placeholder="YYYY-MM-DD"
                       value="{{ old('order_date', optional($project?->order_date)->format('Y-m-d')) }}">
            </div>
            <div class="fld">
                <label>入金日</label>
                <input type="text" name="payment_date" placeholder="YYYY-MM-DD"
                       value="{{ old('payment_date', optional($project?->payment_date)->format('Y-m-d')) }}">
            </div>
            <div class="fld">
                <label>着工日</label>
                <input type="text" name="start_date" placeholder="YYYY-MM-DD"
                       value="{{ old('start_date', optional($project?->start_date)->format('Y-m-d')) }}">
            </div>
            <div class="fld">
                <label>完工日</label>
                <input type="text" name="completion_date" placeholder="YYYY-MM-DD"
                       value="{{ old('completion_date', optional($project?->completion_date)->format('Y-m-d')) }}">
            </div>
            <div></div>
            <div class="fld">
                <label>工期開始</label>
                <input type="text" name="period_start" placeholder="YYYY-MM-DD"
                       value="{{ old('period_start', optional($project?->period_start)->format('Y-m-d')) }}">
            </div>
            <div class="fld">
                <label>工期終了</label>
                <input type="text" name="period_end" placeholder="YYYY-MM-DD"
                       value="{{ old('period_end', optional($project?->period_end)->format('Y-m-d')) }}">
            </div>
        </div>
    </div>

    {{-- カード: 原価明細（Alpine 配列） --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5" style="margin-bottom: 20px;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px;">
            <div class="card-title" style="margin-bottom: 0;">原価明細</div>
            <button type="button" @click="addCostRow()"
                    style="padding: 6px 14px; background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 6px; color: #047857; font-size: 12px; font-weight: 600; cursor: pointer;">＋ 行追加</button>
        </div>

        <div x-show="costRows.length === 0" style="padding: 16px; text-align: center; font-size: 12px; color: #9ca3af;">
            ＋ 行追加 ボタンで原価明細を追加してください。
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
                                <input type="text" :name="'assignments[' + idx + '][start_date]'" x-model="a.start_date" placeholder="YYYY-MM-DD"
                                       style="width: 100%; height: 34px; padding: 0 8px; font-size: 12px; border: 1px solid #d1d5db; border-radius: 4px;">
                            </td>
                            <td style="padding: 6px; border-bottom: 1px solid #f3f4f6;">
                                <input type="text" :name="'assignments[' + idx + '][end_date]'" x-model="a.end_date" placeholder="YYYY-MM-DD"
                                       style="width: 100%; height: 34px; padding: 0 8px; font-size: 12px; border: 1px solid #d1d5db; border-radius: 4px;">
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
</style>

<script>
function projectForm() {
    return {
        costRows: [],
        assignmentRows: [],

        initForm: function () {
            // 既存データを Alpine に投入
            const existingCosts = @json($existingCosts);
            const existingAssignments = @json($existingAssignments);
            this.costRows = existingCosts.length > 0 ? existingCosts.slice() : [];
            this.assignmentRows = existingAssignments.length > 0 ? existingAssignments.slice() : [];
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
        }
    };
}
</script>
