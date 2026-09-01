{{--
    工程表カード（設計書 §4.1）。**4 つの詳細画面が共有する唯一の定義**。

    ⚠ 部署ディレクトリに置かないこと。不動産の部品を住宅が借りている形にすると、
       次に触る人が不動産都合で壊す（設計書 §4.1）。

    必要な変数: $schedule（App\Services\ScheduleCardService::build() の戻り値）
                $scheduleCanEdit（bool）
--}}
<div class="bg-white border border-gray-200 rounded-lg p-5 mb-5">
    <div class="flex items-center gap-2 mb-3">
        <span class="w-1 h-5 bg-emerald-600 rounded-sm"></span>
        <h2 class="text-base font-bold text-gray-900">工程表</h2>
    </div>

    @include('_partials._schedule_gantt', ['schedule' => $schedule])

    @if($scheduleCanEdit)
        <div x-data="scheduleSection()" style="margin-top: 18px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <button type="button" @click="startAdd()"
                        style="padding: 5px 12px; font-size: 12px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 6px; background: white; cursor: pointer;">
                    ＋ 工程を追加
                </button>
                <span x-show="message" x-text="message" style="font-size: 12px; color: #047857;"></span>
            </div>

            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 12.5px;">
                    <thead>
                        <tr>
                            <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap; width: 70px;">並び</th>
                            <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">工程名</th>
                            <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">種類</th>
                            <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">予定開始</th>
                            <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">予定終了</th>
                            <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">実績開始</th>
                            <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">実績終了</th>
                            <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">備考</th>
                            <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, i) in rows" :key="row.id">
                            <tr>
                                <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6; white-space: nowrap;">
                                    {{-- 並べ替えはドラッグでなく ↑↓ ボタン（設計書 §4.4） --}}
                                    <button type="button" @click="move(i, -1)" :disabled="i === 0" title="上へ"
                                            style="border: 1px solid #D1D5DB; background: white; border-radius: 4px; width: 24px; height: 24px; cursor: pointer;">↑</button>
                                    <button type="button" @click="move(i, 1)" :disabled="i === rows.length - 1" title="下へ"
                                            style="border: 1px solid #D1D5DB; background: white; border-radius: 4px; width: 24px; height: 24px; cursor: pointer;">↓</button>
                                </td>
                                <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6;">
                                    {{-- ⚠ 日本語入力の確定 Enter で誤発火しないように isComposing を挟む --}}
                                    <input type="text" x-model="row.name" maxlength="100" @change="save(row)"
                                           @keydown.enter="$event.isComposing || save(row)"
                                           style="width: 100%; height: 32px; padding: 0 8px; font-size: 12.5px; border: 1px solid #D1D5DB; border-radius: 6px; background: white; box-sizing: border-box;">
                                </td>
                                <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6;">
                                    {{-- ⚠ option は @@foreach で静的に出す（x-for は x-model 同期後に描画される。Bug #16） --}}
                                    <select x-model="row.category" @change="save(row)"
                                            style="width: 100%; height: 32px; padding: 0 8px; font-size: 12.5px; border: 1px solid #D1D5DB; border-radius: 6px; background: white; box-sizing: border-box;">
                                        @foreach($schedule['categories'] as $c)
                                            <option value="{{ $c['value'] }}">{{ $c['label'] }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6;"><input type="date" x-model="row.planned_start" @change="save(row)" style="width: 100%; height: 32px; padding: 0 8px; font-size: 12.5px; border: 1px solid #D1D5DB; border-radius: 6px; background: white; box-sizing: border-box;"></td>
                                <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6;"><input type="date" x-model="row.planned_end" @change="save(row)" style="width: 100%; height: 32px; padding: 0 8px; font-size: 12.5px; border: 1px solid #D1D5DB; border-radius: 6px; background: white; box-sizing: border-box;"></td>
                                <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6;"><input type="date" x-model="row.actual_start" @change="save(row)" style="width: 100%; height: 32px; padding: 0 8px; font-size: 12.5px; border: 1px solid #D1D5DB; border-radius: 6px; background: white; box-sizing: border-box;"></td>
                                <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6;"><input type="date" x-model="row.actual_end" @change="save(row)" style="width: 100%; height: 32px; padding: 0 8px; font-size: 12.5px; border: 1px solid #D1D5DB; border-radius: 6px; background: white; box-sizing: border-box;"></td>
                                <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6;">
                                    <input type="text" x-model="row.notes" maxlength="255" @change="save(row)"
                                           @keydown.enter="$event.isComposing || save(row)"
                                           style="width: 100%; height: 32px; padding: 0 8px; font-size: 12.5px; border: 1px solid #D1D5DB; border-radius: 6px; background: white; box-sizing: border-box;">
                                </td>
                                <td style="padding: 7px 10px; border-bottom: 1px solid #F3F4F6; white-space: nowrap;">
                                    <button type="button" @click="remove(row)"
                                            style="color: #DC2626; border: none; background: none; cursor: pointer; font-size: 16px; line-height: 1;">×</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ⚠ @@json には**単一の変数**しか渡さない（Bug #26）。多行の配列リテラルや
             ->method() を渡すと Blade の引数パーサが途中で打ち切り、本番の view:cache 後に
             ParseError で 500 する。配列は ScheduleCardService が組み立てて 'rows' で渡す。 --}}
        @php($scheduleEndpoints = $schedule['endpoints'])
        @php($scheduleRows = $schedule['rows'])
        <script>
        var SCHEDULE_ENDPOINTS = @json($scheduleEndpoints);
        var SCHEDULE_ROWS = @json($scheduleRows);

        function scheduleSection() {
            return {
                rows: SCHEDULE_ROWS,
                message: '',
                token: document.querySelector('meta[name="csrf-token"]').content,

                // 保存のたびにサーバでガントを描き直して差し替える。
                // 位置の計算を JS 側に持たせないため（同じ計算の 2 実装は無音で漂流する）。
                apply: function (data, fn) {
                    if (!data) { return; }
                    if (data.gantt_html) {
                        document.getElementById('schedule-gantt').outerHTML = data.gantt_html;
                    }
                    if (fn) { fn(data); }
                    this.notify();
                },

                notify: function () {
                    var self = this;
                    self.message = '保存しました。';
                    setTimeout(function () { self.message = ''; }, 3000);
                },

                send: function (url, method, body) {
                    return fetch(url, {
                        method: method,
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': this.token,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: body ? JSON.stringify(body) : null
                    })
                    .then(function (res) {
                        if (!res.ok) {
                            return res.json().then(function (err) {
                                var msg = err.message || 'エラーが発生しました。';
                                if (err.errors) { msg = msg + '\n' + Object.values(err.errors).flat().join('\n'); }
                                alert(msg);
                                return null;
                            }).catch(function () {
                                alert('サーバーエラーが発生しました（' + res.status + '）');
                                return null;
                            });
                        }
                        return res.json();
                    })
                    .catch(function () { alert('通信に失敗しました。'); return null; });
                },

                payload: function (row) {
                    return {
                        name: row.name,
                        category: row.category,
                        planned_start: row.planned_start || null,
                        planned_end: row.planned_end || null,
                        actual_start: row.actual_start || null,
                        actual_end: row.actual_end || null,
                        notes: row.notes || null
                    };
                },

                startAdd: function () {
                    var self = this;
                    self.send(SCHEDULE_ENDPOINTS.store, 'POST', {
                        name: '新しい工程', category: 'other',
                        planned_start: null, planned_end: null, actual_start: null, actual_end: null, notes: null
                    }).then(function (d) {
                        self.apply(d, function (data) { self.rows.push(data.step); });
                    });
                },

                save: function (row) {
                    var self = this;
                    self.send(SCHEDULE_ENDPOINTS.update.replace('__ID__', row.id), 'PATCH', self.payload(row))
                        .then(function (d) { self.apply(d, null); });
                },

                remove: function (row) {
                    if (!confirm('この工程を削除しますか？')) { return; }
                    var self = this;
                    self.send(SCHEDULE_ENDPOINTS.destroy.replace('__ID__', row.id), 'DELETE', null)
                        .then(function (d) {
                            self.apply(d, function (data) {
                                self.rows = self.rows.filter(function (r) { return r.id !== data.id; });
                            });
                        });
                },

                move: function (index, delta) {
                    var target = index + delta;
                    if (target < 0 || target >= this.rows.length) { return; }

                    var moved = this.rows.slice();
                    var tmp = moved[index];
                    moved[index] = moved[target];
                    moved[target] = tmp;
                    this.rows = moved;

                    var self = this;
                    self.send(SCHEDULE_ENDPOINTS.reorder, 'PATCH', {
                        ids: moved.map(function (r) { return r.id; })
                    }).then(function (d) { self.apply(d, null); });
                }
            };
        }
        </script>
    @endif
</div>
