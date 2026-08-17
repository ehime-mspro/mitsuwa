@extends('layouts.app')

@section('title', '顧客CSVインポート')

@section('content')
<div class="text-sm text-gray-500" style="margin-bottom: 12px;">
    ダッシュボード &gt; マスタ管理 &gt; <span class="text-gray-800 font-medium">顧客CSVインポート</span>
</div>
<h1 style="font-size: 20px; font-weight: 700; margin: 0 0 20px;">顧客CSVインポート</h1>

{{-- ⚠ この画面は長らく無音だった（2026-08-17 に発見）。`execute()` は csv_file を
     mimes:csv,txt で、department を in:housing,realestate で検証しており、落ちると
     この画面へ差し戻される。取込の行単位の警告はプレビューに出るが、**validate() の
     失敗は $errors に入る**ので別の表示先が要る（`session('error')` はレイアウトが
     描画するが、$errors は描画しない）。例: xlsx を上げると何も起きないように見えた --}}
@if($errors->any())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
        <p class="text-sm font-semibold text-red-800 mb-1">入力内容にエラーがあります。</p>
        <ul class="list-disc list-inside text-xs text-red-700 space-y-0.5">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="bg-white border border-gray-200 rounded-lg p-5" x-data="csvImport()">

    @if(!isset($preview))
        {{-- ===== 初期フォーム ===== --}}
        <form method="POST" action="{{ route('admin.customers.import.execute') }}" enctype="multipart/form-data" id="import-form">
            @csrf

            {{-- 部署選択 --}}
            <div style="margin-bottom: 20px;">
                <label class="block text-sm font-semibold text-gray-700" style="margin-bottom: 5px;">インポート先部署<span class="text-red-600" style="margin-left: 2px;">*</span></label>
                <div style="display: flex; gap: 12px; margin-top: 6px; align-items: center;">
                    <label :style="'display: flex; align-items: center; gap: 5px; font-size: 13px; cursor: pointer; padding: 8px 16px; border-radius: 6px; ' + (selectedDept === 'housing' ? 'border: 1px solid #059669; background: #ecfdf5;' : 'border: 1px solid #d1d5db; background: #fff;')">
                        <input type="radio" name="department" value="housing" style="accent-color: #059669;"
                               x-model="selectedDept" checked>
                        住宅事業
                    </label>
                    <label :style="'display: flex; align-items: center; gap: 5px; font-size: 13px; cursor: pointer; padding: 8px 16px; border-radius: 6px; ' + (selectedDept === 'realestate' ? 'border: 1px solid #059669; background: #ecfdf5;' : 'border: 1px solid #d1d5db; background: #fff;')">
                        <input type="radio" name="department" value="realestate" style="accent-color: #059669;"
                               x-model="selectedDept">
                        不動産事業
                    </label>
                </div>
            </div>

            {{-- STEP 1: ファイル選択 --}}
            <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;">
                <div style="width: 28px; height: 28px; border-radius: 50%; background: #059669; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0;">1</div>
                <div style="flex: 1;">
                    <div style="font-weight: 600; margin-bottom: 8px;">CSVファイルを選択</div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <label style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border: 2px dashed #d1d5db; border-radius: 8px; cursor: pointer; font-size: 13px; color: #6b7280;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            ファイルを選択
                            <input type="file" name="csv_file" accept=".csv,.txt" style="display: none;" x-on:change="onFileSelect($event)">
                        </label>
                        <span x-show="fileName" style="font-size: 13px; color: #059669; font-weight: 600;" x-text="'✓ ' + fileName"></span>
                    </div>
                    <div style="font-size: 12px; color: #6b7280; margin-top: 6px;">※ UTF-8 または Shift_JIS 形式に対応</div>
                    <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">※ 担当者がシステムに未登録（退職者等）の場合、名前はテキストとして保存されます</div>
                </div>
            </div>

            {{-- STEP 2: テンプレートDL --}}
            <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;">
                <div style="width: 28px; height: 28px; border-radius: 50%; background: #059669; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0;">2</div>
                <div style="flex: 1;">
                    <div style="font-weight: 600; margin-bottom: 8px;">テンプレートCSV</div>
                    <a x-bind:href="'{{ route('admin.customers.import.template') }}?department=' + selectedDept"
                       style="display: inline-flex; align-items: center; gap: 4px; background: #fff; color: #374151; font-size: 13px; padding: 6px 14px; border-radius: 6px; font-weight: 600; border: 1px solid #9ca3af; text-decoration: none;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px;"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        テンプレートCSVをダウンロード
                    </a>
                    <div style="font-size: 12px; color: #6b7280; margin-top: 6px;">※ 選択した部署に応じたテンプレートがダウンロードされます</div>
                </div>
            </div>

            {{-- STEP 3: プレビュー（送信後に表示） --}}
            <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;">
                <div style="width: 28px; height: 28px; border-radius: 50%; background: #059669; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0;">3</div>
                <div style="flex: 1;">
                    <div style="font-weight: 600; margin-bottom: 8px;">プレビュー</div>
                    <div style="font-size: 13px; color: #9ca3af;">CSVファイルをアップロードするとプレビューが表示されます</div>
                </div>
            </div>

            {{-- STEP 4: 実行 --}}
            <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;">
                <div style="width: 28px; height: 28px; border-radius: 50%; background: #059669; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0;">4</div>
                <div style="flex: 1;">
                    <div style="font-weight: 600; margin-bottom: 8px;">プレビュー確認後にインポート</div>
                    <button type="submit"
                            style="background: #059669; color: #fff; padding: 10px 28px; border-radius: 6px; font-size: 15px; font-weight: 600; border: none; cursor: pointer;">
                        アップロードしてプレビュー
                    </button>
                </div>
            </div>
        </form>

    @else
        {{-- ===== プレビュー表示 ===== --}}
        <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;">
            <div style="width: 28px; height: 28px; border-radius: 50%; background: #059669; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0;">3</div>
            <div style="flex: 1;">
                <div style="font-weight: 600; margin-bottom: 8px;">プレビュー</div>
                <div style="border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden; max-height: 300px; overflow-y: auto;">
                    <div style="background: #ecfdf5; padding: 10px 16px; font-size: 14px; font-weight: 600; display: flex; gap: 20px;">
                        <span>全 <strong>{{ $totalRows }}</strong> 件</span>
                        <span style="color: #059669;">正常: <strong>{{ $validCount }}</strong> 件</span>
                        <span style="color: #dc2626;">エラー: <strong>{{ count($errors ?? []) }}</strong> 件</span>
                        <span style="color: #d97706;">重複候補: <strong>{{ count($dupeRows ?? []) }}</strong> 件</span>
                    </div>
                    @foreach($errors ?? [] as $err)
                        <div style="padding: 8px 16px; font-size: 13px; border-bottom: 1px solid #f3f4f6; color: #dc2626;">
                            ⚠ 行{{ $err['row'] }}: {{ $err['message'] }}
                        </div>
                    @endforeach
                    @foreach($dupeRows ?? [] as $dup)
                        <div style="padding: 8px 16px; font-size: 13px; border-bottom: 1px solid #f3f4f6; color: #d97706;">
                            🔄 行{{ $dup['row'] }}: 重複の可能性 — {{ $dup['name'] }}（{{ $dup['address'] }}）※ 既存ID: {{ $dup['existing_id'] }}
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- STEP 4: 実行確認 --}}
        <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;">
            <div style="width: 28px; height: 28px; border-radius: 50%; background: #059669; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0;">4</div>
            <div style="flex: 1;">
                <div style="font-weight: 600; margin-bottom: 8px;">インポート実行</div>
                <form method="POST" action="{{ route('admin.customers.import.execute') }}">
                    @csrf
                    <input type="hidden" name="department" value="{{ $department }}">
                    <input type="hidden" name="confirmed" value="1">
                    <input type="hidden" name="csv_data" value="{{ $csvData }}">

                    @if(count($dupeRows ?? []) > 0)
                        <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; margin-bottom: 12px; cursor: pointer;">
                            <input type="checkbox" name="include_duplicates" value="1" style="accent-color: #059669; width: 16px; height: 16px;">
                            重複候補もインポートする（別人として新規登録）
                        </label>
                    @endif

                    <button type="submit"
                            style="background: #059669; color: #fff; padding: 10px 28px; border-radius: 6px; font-size: 15px; font-weight: 600; border: none; cursor: pointer;">
                        インポート実行（{{ $validCount }}件）
                    </button>
                    <div style="font-size: 12px; color: #6b7280; margin-top: 6px;">※ エラー行（{{ count($errors ?? []) }}件）はスキップされます</div>
                </form>

                <a href="{{ route('admin.customers.import') }}" style="display: inline-block; margin-top: 12px; font-size: 13px; color: #6b7280; text-decoration: underline;">← やり直す</a>
            </div>
        </div>
    @endif
</div>

<script>
function csvImport() {
    return {
        selectedDept: '{{ $department ?? "housing" }}',
        fileName: '',
        onFileSelect: function(event) {
            var file = event.target.files[0];
            if (file) {
                this.fileName = file.name;
            }
        }
    };
}
</script>
@endsection
