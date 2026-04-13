{{-- 各タブ共通のインポートフォーム --}}
{{-- 必要変数: $tab, $routeName, $templateRoute, $fileInputName --}}

{{-- STEP 1: テンプレートDL --}}
<div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;">
    <div style="width: 28px; height: 28px; border-radius: 50%; background: #059669; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0;">1</div>
    <div style="flex: 1;">
        <div style="font-weight: 600; margin-bottom: 8px;">テンプレートCSV</div>
        <a href="{{ route($templateRoute) }}"
           style="display: inline-flex; align-items: center; gap: 4px; background: #fff; color: #374151; font-size: 13px; padding: 6px 14px; border-radius: 6px; font-weight: 600; border: 1px solid #9ca3af; text-decoration: none;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px;"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            テンプレートCSVをダウンロード
        </a>
        <div style="font-size: 12px; color: #6b7280; margin-top: 6px;">※ サンプルデータ付き。Excelで編集可能です。</div>
    </div>
</div>

{{-- STEP 2&3: ファイル選択＋アップロード --}}
<form method="POST" action="{{ route($routeName) }}" enctype="multipart/form-data">
    @csrf

    <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;">
        <div style="width: 28px; height: 28px; border-radius: 50%; background: #059669; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0;">2</div>
        <div style="flex: 1;">
            <div style="font-weight: 600; margin-bottom: 8px;">CSVファイルを選択</div>
            <div style="display: flex; align-items: center; gap: 12px;">
                <label style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border: 2px dashed #d1d5db; border-radius: 8px; cursor: pointer; font-size: 13px; color: #6b7280;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    ファイルを選択
                    <input type="file" name="csv_file" accept=".csv,.txt" style="display: none;" x-on:change="onFileSelect($event, '{{ $tab }}')">
                </label>
                <span x-show="fileNames['{{ $tab }}']" style="font-size: 13px; color: #059669; font-weight: 600;" x-text="fileNames['{{ $tab }}']"></span>
            </div>
            <div style="font-size: 12px; color: #6b7280; margin-top: 6px;">※ UTF-8 または Shift_JIS 形式に対応</div>
        </div>
    </div>

    <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;">
        <div style="width: 28px; height: 28px; border-radius: 50%; background: #059669; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0;">3</div>
        <div style="flex: 1;">
            <div style="font-weight: 600; margin-bottom: 8px;">プレビュー確認後にインポート</div>
            <button type="submit"
                    style="background: #059669; color: #fff; padding: 10px 28px; border-radius: 6px; font-size: 15px; font-weight: 600; border: none; cursor: pointer;">
                アップロードしてプレビュー
            </button>
        </div>
    </div>
</form>
