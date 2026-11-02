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
        {{-- 追加は別の画面（その部署で開く） --}}
        <div style="margin-top: 16px;">
            <a href="{{ route('admin.survey-questions.create', ['department' => 'housing']) }}"
               class="inline-flex items-center gap-1 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold px-4 py-2 rounded-md">＋ 設問を追加</a>
        </div>
    </div>

    {{-- 不動産事業の設問リスト --}}
    <div x-show="activeTab === 'realestate'" style="display: none;" id="q-list-realestate">
        @forelse($realestateQuestions as $q)
            @include('admin.master.survey-questions._question_item', ['q' => $q])
        @empty
            <div style="text-align: center; padding: 24px; color: #9ca3af; font-size: 14px;">設問はまだ登録されていません</div>
        @endforelse
        {{-- 追加は別の画面（その部署で開く） --}}
        <div style="margin-top: 16px;">
            <a href="{{ route('admin.survey-questions.create', ['department' => 'realestate']) }}"
               class="inline-flex items-center gap-1 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold px-4 py-2 rounded-md">＋ 設問を追加</a>
        </div>
    </div>
</div>

<script>
function surveyQuestionManager() {
    return {
        activeTab: '{{ $department }}',

        switchTab: function(tab) {
            this.activeTab = tab;
        },

        deleteQuestion: function(id) {
            if (!confirm('この設問を削除しますか？')) return;
            var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            var xhr = new XMLHttpRequest();
            xhr.open('DELETE', '{{ url("/admin/survey-questions") }}/' + id);
            xhr.setRequestHeader('X-CSRF-TOKEN', token);
            xhr.setRequestHeader('Accept', 'application/json');
            // ⚠ これが無いとサーバは一覧への転送（302）を返し、XHR は DELETE のまま転送先へ送って 405 になる
            //   （302 を GET に変えるのは POST のときだけ）。onload が 200 を受け取れず、一覧を読み込み直さない
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
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
