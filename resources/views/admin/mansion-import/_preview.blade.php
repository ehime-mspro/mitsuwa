{{-- 各タブ共通のプレビュー表示（賃貸マンション） --}}
{{-- 必要変数: $tab, $actionUrl, $entityLabel, $totalRows, $validCount, $errors, $skippedRows, $warnings(任意), $summary, $csvData --}}

{{-- プレビュー結果 --}}
<div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;">
    <div style="width: 28px; height: 28px; border-radius: 50%; background: #059669; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
    </div>
    <div style="flex: 1;">
        <div style="font-weight: 600; margin-bottom: 8px;">プレビュー結果</div>

        {{-- サマリー --}}
        <div style="border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden;">
            <div style="background: #ecfdf5; padding: 10px 16px; font-size: 14px; font-weight: 600; display: flex; gap: 20px; flex-wrap: wrap;">
                <span>全 <strong>{{ $totalRows }}</strong> 件</span>
                <span style="color: #059669;">正常: <strong>{{ $validCount }}</strong> 件</span>
                @if(count($errors ?? []) > 0)
                    <span style="color: #dc2626;">エラー: <strong>{{ count($errors) }}</strong> 件</span>
                @endif
                @if(count($skippedRows ?? []) > 0)
                    <span style="color: #6b7280;">スキップ: <strong>{{ count($skippedRows) }}</strong> 件</span>
                @endif
                @if(count($warnings ?? []) > 0)
                    <span style="color: #b45309;">警告: <strong>{{ count($warnings) }}</strong> 件</span>
                @endif
            </div>

            {{-- 作成予定 --}}
            <div style="padding: 12px 16px; border-bottom: 1px solid #e5e7eb;">
                <div style="font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 4px;">作成予定:</div>
                <div style="font-size: 13px; color: #111827;">{{ $summary }}</div>
            </div>

            {{-- 警告一覧（契約の二重契約等） --}}
            @if(count($warnings ?? []) > 0)
                <div style="max-height: 150px; overflow-y: auto; background: #fffbeb;">
                    @foreach($warnings as $warn)
                        <div style="padding: 8px 16px; font-size: 13px; border-bottom: 1px solid #fef3c7; color: #92400e;">
                            ⚠ 行{{ $warn['row'] }}: {{ $warn['message'] }}
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- スキップ一覧 --}}
            @if(count($skippedRows ?? []) > 0)
                <div style="max-height: 150px; overflow-y: auto; background: #f9fafb;">
                    @foreach($skippedRows as $skip)
                        <div style="padding: 8px 16px; font-size: 13px; border-bottom: 1px solid #f3f4f6; color: #6b7280;">
                            行{{ $skip['row'] }}: {{ $skip['message'] }}
                        </div>
                    @endforeach
                </div>
            @endif

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

{{-- インポート実行 --}}
<div style="display: flex; align-items: flex-start; gap: 14px; margin-bottom: 20px;">
    <div style="width: 28px; height: 28px; border-radius: 50%; background: #059669; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
    </div>
    <div style="flex: 1;">
        <div style="font-weight: 600; margin-bottom: 8px;">インポート実行</div>

        @if($validCount > 0)
            <form method="POST" action="{{ $actionUrl }}">
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
                @if(count($skippedRows ?? []) > 0)
                    <div style="font-size: 12px; color: #6b7280; margin-top: 4px;">※ 既存データ（{{ count($skippedRows) }}件）はスキップされます</div>
                @endif
                @if(count($warnings ?? []) > 0)
                    <div style="font-size: 12px; color: #b45309; margin-top: 4px;">※ 警告のある行（{{ count($warnings) }}件）もそのまま登録されます</div>
                @endif
            </form>
        @else
            <div style="font-size: 13px; color: #dc2626;">インポート可能なデータがありません。CSVを修正してください。</div>
        @endif

        <a href="{{ route('admin.mansion-import') }}?selected_tab={{ $tab }}" style="display: inline-block; margin-top: 12px; font-size: 13px; color: #6b7280; text-decoration: underline;">← やり直す</a>
    </div>
</div>
