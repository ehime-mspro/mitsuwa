@extends('layouts.app')

@section('title', 'テナントCSVインポート')

@section('content')
<div class="text-sm text-gray-500" style="margin-bottom: 12px;">
    ダッシュボード &gt; システム管理 &gt; <span class="text-gray-800 font-medium">テナントCSVインポート</span>
</div>
<h1 style="font-size: 20px; font-weight: 700; margin: 0 0 20px;">テナントCSVインポート</h1>

@if(session('success'))
    <div class="bg-emerald-50 border border-emerald-300 text-emerald-800 text-sm rounded-md px-4 py-3" style="margin-bottom: 16px;">
        {{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; font-size: 13px; border-radius: 6px; padding: 10px 14px; margin-bottom: 16px;">
        {{ session('error') }}
    </div>
@endif

<div class="bg-white border border-gray-200 rounded-lg p-5" x-data="tenantImport()">

    @if(!isset($preview))
        {{-- ===== 初期フォーム ===== --}}
        <form method="POST" action="{{ route('admin.tenant-import.execute') }}" enctype="multipart/form-data" id="import-form">
            @csrf

            {{-- 説明 --}}
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px;">
                <div style="font-weight: 600; font-size: 14px; color: #166534; margin-bottom: 6px;">CSVファイル1つで物件・区画・顧客・契約を一括登録</div>
                <ul style="font-size: 12px; color: #15803d; margin: 0; padding-left: 18px; line-height: 1.8;">
                    <li>1行＝1区画（物件+区画+テナント+契約情報を横並び）</li>
                    <li>同一物件名の行が複数 → 物件は1つだけ作成、区画を複数追加</li>
                    <li>テナント名・家賃・契約日が空の行 → 空室として物件+区画のみ作成</li>
                    <li>テナント名・家賃・契約日が入力済みの行 → 入居中として契約も作成</li>
                </ul>
            </div>

            {{-- STEP 1: テンプレートDL --}}
            <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;">
                <div style="width: 28px; height: 28px; border-radius: 50%; background: #059669; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0;">1</div>
                <div style="flex: 1;">
                    <div style="font-weight: 600; margin-bottom: 8px;">テンプレートCSV</div>
                    <a href="{{ route('admin.tenant-import.template') }}"
                       style="display: inline-flex; align-items: center; gap: 4px; background: #fff; color: #374151; font-size: 13px; padding: 6px 14px; border-radius: 6px; font-weight: 600; border: 1px solid #9ca3af; text-decoration: none;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: -2px;"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        テンプレートCSVをダウンロード
                    </a>
                    <div style="font-size: 12px; color: #6b7280; margin-top: 6px;">※ サンプルデータ2行（空室例・入居例）付き。Excelで編集可能です。</div>
                </div>
            </div>

            {{-- STEP 2: ファイル選択 --}}
            <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;">
                <div style="width: 28px; height: 28px; border-radius: 50%; background: #059669; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0;">2</div>
                <div style="flex: 1;">
                    <div style="font-weight: 600; margin-bottom: 8px;">CSVファイルを選択</div>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <label style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border: 2px dashed #d1d5db; border-radius: 8px; cursor: pointer; font-size: 13px; color: #6b7280;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            ファイルを選択
                            <input type="file" name="csv_file" accept=".csv,.txt" style="display: none;" x-on:change="onFileSelect($event)">
                        </label>
                        <span x-show="fileName" style="font-size: 13px; color: #059669; font-weight: 600;" x-text="fileName"></span>
                    </div>
                    <div style="font-size: 12px; color: #6b7280; margin-top: 6px;">※ UTF-8 または Shift_JIS 形式に対応</div>
                </div>
            </div>

            {{-- STEP 3: プレビュー説�� --}}
            <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;">
                <div style="width: 28px; height: 28px; border-radius: 50%; background: #059669; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0;">3</div>
                <div style="flex: 1;">
                    <div style="font-weight: 600; margin-bottom: 8px;">プレビュー</div>
                    <div style="font-size: 13px; color: #9ca3af;">CSVファイルをアップロードするとプレビューが表示されま��</div>
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
                <div style="font-weight: 600; margin-bottom: 8px;">��レビュー</div>

                {{-- サマリー --}}
                <div style="border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden;">
                    <div style="background: #ecfdf5; padding: 10px 16px; font-size: 14px; font-weight: 600; display: flex; gap: 20px; flex-wrap: wrap;">
                        <span>全 <strong>{{ $totalRows }}</strong> 件</span>
                        <span style="color: #059669;">正常: <strong>{{ $validCount }}</strong> 件</span>
                        <span style="color: #dc2626;">エラー: <strong>{{ count($errors ?? []) }}</strong> ���</span>
                    </div>

                    {{-- 作成予定 --}}
                    <div style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb;">
                        <div style="font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 8px;">作成予定:</div>
                        <div style="display: flex; gap: 16px; flex-wrap: wrap; font-size: 13px;">
                            <div style="background: #f3f4f6; padding: 6px 14px; border-radius: 6px;">
                                <span style="color: #6b7280;">物件</span>
                                <strong style="color: #111827; margin-left: 4px;">{{ $propertyCount }}</strong> 件
                            </div>
                            <div style="background: #f3f4f6; padding: 6px 14px; border-radius: 6px;">
                                <span style="color: #6b7280;">区画</span>
                                <strong style="color: #111827; margin-left: 4px;">{{ $unitCount }}</strong> 件
                                <span style="color: #6b7280; font-size: 12px; margin-left: 4px;">（入居 {{ $contractCount }} / 空室 {{ $vacantCount }}）</span>
                            </div>
                            <div style="background: #f3f4f6; padding: 6px 14px; border-radius: 6px;">
                                <span style="color: #6b7280;">顧客</span>
                                <strong style="color: #111827; margin-left: 4px;">{{ $customerCount }}</strong> 件
                            </div>
                            <div style="background: #f3f4f6; padding: 6px 14px; border-radius: 6px;">
                                <span style="color: #6b7280;">契約</span>
                                <strong style="color: #111827; margin-left: 4px;">{{ $contractCount }}</strong> 件
                            </div>
                        </div>
                    </div>

                    {{-- エラー一覧 --}}
                    @if(count($errors ?? []) > 0)
                        <div style="max-height: 200px; overflow-y: auto;">
                            @foreach($errors as $err)
                                <div style="padding: 8px 16px; font-size: 13px; border-bottom: 1px solid #f3f4f6; color: #dc2626;">
                                    行{{ $err['row'] }}: {{ $err['message'] }}
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- STEP 4: 実行確認 --}}
        <div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;">
            <div style="width: 28px; height: 28px; border-radius: 50%; background: #059669; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0;">4</div>
            <div style="flex: 1;">
                <div style="font-weight: 600; margin-bottom: 8px;">インポート実行</div>

                @if($validCount > 0)
                    <form method="POST" action="{{ route('admin.tenant-import.execute') }}">
                        @csrf
                        <input type="hidden" name="confirmed" value="1">
                        <input type="hidden" name="csv_data" value="{{ $csvData }}">

                        <button type="submit"
                                style="background: #059669; color: #fff; padding: 10px 28px; border-radius: 6px; font-size: 15px; font-weight: 600; border: none; cursor: pointer;">
                            インポート実行（{{ $validCount }}件）
                        </button>
                        @if(count($errors ?? []) > 0)
                            <div style="font-size: 12px; color: #6b7280; margin-top: 6px;">※ エラー行（{{ count($errors) }}件）はスキップされます</div>
                        @endif
                    </form>
                @else
                    <div style="font-size: 13px; color: #dc2626;">インポート可能なデータがありません。CSVを修正してください。</div>
                @endif

                <a href="{{ route('admin.tenant-import') }}" style="display: inline-block; margin-top: 12px; font-size: 13px; color: #6b7280; text-decoration: underline;">← やり直す</a>
            </div>
        </div>
    @endif
</div>

<script>
function tenantImport() {
    return {
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
