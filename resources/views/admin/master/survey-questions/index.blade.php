@extends('layouts.app')

@section('title', 'アンケート設問管理')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>システム管理</span>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">アンケート設問管理</span>
@endsection

@section('content')
<h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">アンケート設問管理</h1>

{{-- バリデーションエラー（行の ↑↓ のフォームを手で組んだ送信など） --}}
@if($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3">
        @foreach($errors->all() as $error)
            <p class="text-sm text-red-800">{{ $error }}</p>
        @endforeach
    </div>
@endif

<div class="bg-white border border-gray-200 rounded-lg p-5 max-sm:p-3" x-data="surveyQuestionManager()">
    {{-- 部署タブ --}}
    <div style="display: flex; border-bottom: 2px solid #e5e7eb; margin-bottom: 16px;">
        @foreach($departments as $key => $name)
            <button type="button" x-on:click="switchTab({{ \Illuminate\Support\Js::from($key) }})"
                    x-bind:style="activeTab === {{ \Illuminate\Support\Js::from($key) }} ? 'padding:10px 20px;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;border-bottom:3px solid #059669;color:#059669;margin-bottom:-2px;' : 'padding:10px 20px;font-size:14px;font-weight:600;border:none;background:none;cursor:pointer;color:#6b7280;margin-bottom:-2px;'">
                {{ $name }}
            </button>
        @endforeach
    </div>

    <div style="background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 10px 14px; margin-bottom: 14px; font-size: 13px; color: #0c4a6e;">
        ℹ️ 選択肢の名前を変えても、過去の回答は元の名前のまま残ります（アンケートの編集画面では「（選択肢に無い値）」と出ます）。回答がある設問は、種類を変えられません。並び替え・有効／無効・設問文を変えると、前にダウンロードした顧客の CSV テンプレートは取り込めなくなります（ダウンロードし直してください）。
    </div>

    {{-- 部署ごとの設問の並び。開いているタブはサーバーが描く時点で出しておく（↑↓ のあとの #question-… の行へ移るため。
         隠れた要素へはブラウザが移らない）。番号は有効な設問だけの通し番号、端の ↑↓ は無効の設問も含めた並びで決める --}}
    @foreach($departments as $key => $name)
        <div x-show="activeTab === {{ \Illuminate\Support\Js::from($key) }}" id="q-list-{{ $key }}" @style(['display: none' => $key !== $department])>
            @php($number = 0)
            @forelse($questions[$key] as $q)
                @include('admin.master.survey-questions._question_item', [
                    'q'       => $q,
                    'number'  => $q->is_active ? ++$number : null,
                    'isFirst' => $loop->first,
                    'isLast'  => $loop->last,
                ])
            @empty
                <div style="text-align: center; padding: 24px; color: #9ca3af; font-size: 14px;">設問はまだ登録されていません</div>
            @endforelse
            {{-- 追加は別の画面（その部署で開く） --}}
            <div style="margin-top: 16px;">
                <a href="{{ route('admin.survey-questions.create', ['department' => $key]) }}"
                   class="inline-flex items-center gap-1 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold px-4 py-2 rounded-md">＋ 設問を追加</a>
            </div>
        </div>
    @endforeach
</div>

@push('styles')
<style>
.sq-row { display: flex; align-items: center; gap: 12px; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; margin-bottom: 8px; background: #fff; }
.sq-row-off { background: #fafafa; }
.sq-row-off .sq-row-label { color: #6b7280; }
.sq-row:target { border-color: #059669; }
.sq-moves { display: flex; flex-direction: column; gap: 4px; flex-shrink: 0; }
.sq-moves form { margin: 0; }
.sq-move { display: inline-flex; align-items: center; justify-content: center; width: 26px; height: 26px; font-size: 12px; color: #374151; border: 1px solid #d1d5db; border-radius: 4px; background: #fff; cursor: pointer; }
.sq-move:disabled { color: #d1d5db; border-color: #e5e7eb; cursor: not-allowed; }
.sq-row-body { flex: 1; min-width: 0; }
.sq-row-label { font-weight: 600; margin-bottom: 4px; overflow-wrap: anywhere; }
.sq-row-meta { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.sq-badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
.sq-pill { display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 600; background: #d1fae5; color: #065f46; }
.sq-pill-off { background: #f3f4f6; color: #6b7280; }
.sq-row-note { font-size: 12px; color: #6b7280; }
.sq-row-actions { display: flex; gap: 6px; flex-shrink: 0; }
.sq-row-actions form { margin: 0; }
.sq-edit { display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #059669; border: 1px solid #059669; border-radius: 4px; text-decoration: none; background: #fff; }
.sq-delete { display: inline-block; padding: 3px 10px; font-size: 12px; font-weight: 600; color: #dc2626; border: 1px solid #dc2626; border-radius: 4px; cursor: pointer; background: #fff; }
@media (max-width: 640px) {
    .sq-row { gap: 8px; padding: 10px 10px; }
    .sq-row-actions { flex-direction: column; }
}
</style>
@endpush

<script>
function surveyQuestionManager() {
    return {
        activeTab: {{ \Illuminate\Support\Js::from($department) }},

        switchTab: function(tab) {
            this.activeTab = tab;
        }
    };
}
</script>
@endsection
