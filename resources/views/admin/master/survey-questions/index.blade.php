@extends('layouts.app')

@section('title', 'アンケート設問管理')

@section('content')
<div class="text-sm text-gray-500" style="margin-bottom: 12px;">
    ダッシュボード &gt; マスタ管理 &gt; <span class="text-gray-800 font-medium">アンケート設問管理</span>
</div>
<h1 style="font-size: 20px; font-weight: 700; margin: 0 0 20px;">アンケート設問管理</h1>


<div class="bg-white border border-gray-200 rounded-lg p-5" x-data="surveyQuestionManager()">
    {{-- 部署タブ --}}
    <div style="display: flex; border-bottom: 2px solid #e5e7eb; margin-bottom: 20px;">
        <button type="button" x-on:click="switchTab('housing')"
                x-bind:style="activeTab === 'housing' ? 'padding:10px 20px;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:3px solid #059669;color:#059669;margin-bottom:-2px;' : 'padding:10px 20px;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;color:#6b7280;margin-bottom:-2px;'">
            住宅事業
        </button>
        <button type="button" x-on:click="switchTab('realestate')"
                x-bind:style="activeTab === 'realestate' ? 'padding:10px 20px;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:3px solid #059669;color:#059669;margin-bottom:-2px;' : 'padding:10px 20px;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;color:#6b7280;margin-bottom:-2px;'">
            不動産事業
        </button>
    </div>

    {{-- スナップショット説明バナー --}}
    <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 12px 16px; margin-bottom: 16px; font-size: 13px; color: #0c4a6e;">
        ℹ️ 設問や選択肢を変更しても、過去のアンケート回答には影響しません。回答保存時の設問内容がスナップショットとして記録されています。
    </div>

    <div style="font-size: 13px; color: #6b7280; margin-bottom: 16px;">ドラッグ＆ドロップで並び替えできます</div>

    {{-- 住宅事業の設問リスト --}}
    <div x-show="activeTab === 'housing'" id="q-list-housing">
        @forelse($housingQuestions as $q)
            @include('admin.master.survey-questions._question_item', ['q' => $q])
        @empty
            <div style="text-align: center; padding: 24px; color: #9ca3af; font-size: 14px;">設問はまだ登録されていません</div>
        @endforelse
    </div>

    {{-- 不動産事業の設問リスト --}}
    <div x-show="activeTab === 'realestate'" style="display: none;" id="q-list-realestate">
        @forelse($realestateQuestions as $q)
            @include('admin.master.survey-questions._question_item', ['q' => $q])
        @empty
            <div style="text-align: center; padding: 24px; color: #9ca3af; font-size: 14px;">設問はまだ登録されていません</div>
        @endforelse
    </div>

    {{-- 設問追加ボタン --}}
    <div style="margin-top: 16px;">
        <button type="button" x-on:click="showAddModal = true"
                class="inline-flex items-center gap-1 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold px-4 py-2 rounded-md">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            設問を追加
        </button>
    </div>

    {{-- 追加モーダル --}}
    <div x-show="showAddModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 9999; display: flex; align-items: center; justify-content: center;"
         x-on:click.self="showAddModal = false">
        <div style="background: #fff; border-radius: 12px; padding: 24px; max-width: 500px; width: 90%; box-shadow: 0 12px 40px rgba(0,0,0,0.15);">
            <h3 style="font-size: 16px; font-weight: 700; margin: 0 0 16px;">設問を追加</h3>
            <form x-on:submit.prevent="addQuestion()">
                <div style="margin-bottom: 16px;">
                    <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">設問ラベル<span class="text-red-600" style="margin-left: 2px;">*</span></label>
                    <input type="text" x-model="newLabel" placeholder="設問の内容"
                           style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
                </div>
                <div style="margin-bottom: 16px;">
                    <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">設問タイプ<span class="text-red-600" style="margin-left: 2px;">*</span></label>
                    <select x-model="newType" style="width: 100%; height: 38px; border: 1px solid #d1d5db; border-radius: 6px; padding: 7px 12px; font-size: 14px;">
                        @foreach($questionTypes as $qt)
                            <option value="{{ $qt->value }}">{{ $qt->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div style="margin-bottom: 16px;">
                    <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">選択肢（JSON形式、任意）</label>
                    <textarea x-model="newOptions" rows="3" placeholder='["選択肢1","選択肢2","選択肢3"]'
                              style="width: 100%; border: 1px solid #d1d5db; border-radius: 6px; padding: 10px 12px; font-size: 13px; resize: vertical; font-family: monospace;"></textarea>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="button" x-on:click="showAddModal = false"
                            style="background: #fff; color: #374151; padding: 8px 16px; border-radius: 6px; font-size: 14px; font-weight: 600; border: 1px solid #9ca3af; cursor: pointer;">キャンセル</button>
                    <button type="submit"
                            style="background: #059669; color: #fff; padding: 8px 20px; border-radius: 6px; font-size: 14px; font-weight: 600; border: none; cursor: pointer;">追加</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function surveyQuestionManager() {
    return {
        activeTab: '{{ $department }}',
        showAddModal: false,
        newLabel: '',
        newType: 'single_select',
        newOptions: '',

        switchTab: function(tab) {
            this.activeTab = tab;
        },

        addQuestion: function() {
            var self = this;
            var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '{{ route("admin.survey-questions.store") }}');
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-CSRF-TOKEN', token);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    window.location.href = '{{ route("admin.survey-questions.index") }}?department=' + self.activeTab;
                } else {
                    alert('追加に失敗しました');
                }
            };
            xhr.send(JSON.stringify({
                department: self.activeTab,
                label: self.newLabel,
                question_type: self.newType,
                options: self.newOptions || null
            }));
        },

        deleteQuestion: function(id) {
            if (!confirm('この設問を削除しますか？')) return;
            var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            var xhr = new XMLHttpRequest();
            xhr.open('DELETE', '{{ url("/admin/survey-questions") }}/' + id);
            xhr.setRequestHeader('X-CSRF-TOKEN', token);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    window.location.reload();
                }
            };
            xhr.send();
        }
    };
}
</script>
@endsection
