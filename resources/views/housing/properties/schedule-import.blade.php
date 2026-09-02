{{--
    工程表の取込（設計書 §5.2）。2 段構成。

    ⚠ ①ファイルを選ぶ → プレビュー ②確定、の 2 段。確定フォームは**この画面が描いたものを
       そのまま送り返す**形にする（Bug #47 / #54 ②）。テストも parseForm で解析して送る。

    ⚠ 行エラーの変数名は $rowErrors。**$errors にしない**（Blade の ViewErrorBag を壊す。Bug #53）。
--}}
@extends('layouts.app')

@section('title', '工程表の取込')

@section('breadcrumb')
    <span class="mx-1.5">›</span>
    <span>住宅事業</span>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.properties.index') }}" class="text-gray-500 hover:text-emerald-600">建売物件一覧</a>
    <span class="mx-1.5">›</span>
    <a href="{{ route('housing.properties.show', $property) }}" class="text-gray-500 hover:text-emerald-600">{{ $property->property_code }}</a>
    <span class="mx-1.5">›</span>
    <span class="text-gray-600">工程表の取込</span>
@endsection

@section('content')

    <a href="{{ route('housing.properties.show', $property) }}"
       class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-emerald-600 transition-colors mb-3">
        <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
        物件詳細に戻る
    </a>

    <h1 class="text-lg max-lg:text-base font-bold text-gray-900 mb-4">工程表の取込</h1>

    @if($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4">
            <ul class="list-disc list-inside text-xs text-red-700 space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    {{-- ① 取込先とファイルの選択 --}}
    <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
        <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">取込先</div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
            <div>
                <div class="text-xs text-gray-500 mb-1">物件コード</div>
                <div class="text-sm text-gray-900">{{ $property->property_code }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-500 mb-1">物件名</div>
                <div class="text-sm text-gray-900">{{ $property->property_name }}</div>
            </div>
        </div>

        <form method="POST" action="{{ route('housing.properties.schedule-import.preview', $property) }}"
              enctype="multipart/form-data">
            @csrf
            <label class="block text-sm font-semibold text-gray-700 mb-1">工程表の書き出しファイル</label>
            {{-- ⚠ file input に .form-input を付けない（角丸とネイティブ装飾が消える。Bug #18） --}}
            <input type="file" name="file" accept=".xlsx" required
                   style="display:block; width:100%; max-width:520px; padding:8px 12px; font-size:13px; color:#374151; background:white; border:1px solid #d1d5db; border-radius:6px; cursor:pointer; box-sizing:border-box;">
            <p class="mt-2 text-xs text-gray-500">
                「一覧」形式で書き出したファイルを選んでください。
                工程表（ガント）形式は施工完了日を持たないため取り込めません。
                上限 {{ $maxUploadMb }}MB。
            </p>
            <button type="submit"
                    class="mt-3 h-9 px-4 rounded-md bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 transition-colors">
                内容を確認する
            </button>
        </form>
    </div>

    @isset($result)
        {{-- ② 照合 --}}
        @php($mismatch = $result['site_name'] && ! str_contains($result['site_name'], $property->property_name)
                         && ! str_contains($property->property_name, mb_substr($result['site_name'], 0, 4)))
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">ファイルの内容</div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <div class="text-xs text-gray-500 mb-1">現場名</div>
                    <div class="text-sm text-gray-900">{{ $result['site_name'] ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 mb-1">住所</div>
                    <div class="text-sm text-gray-900">{{ $result['address'] ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 mb-1">工事期間</div>
                    <div class="text-sm text-gray-900">{{ $result['period'] ?? '—' }}</div>
                </div>
                <div>
                    <div class="text-xs text-gray-500 mb-1">読み取った工程</div>
                    <div class="text-sm text-gray-900">{{ count($result['rows']) }} 件</div>
                </div>
            </div>

            {{-- ⚠ 名前が食い違っても**止めない**（本番に該当物件が無い実例がある。設計書 §3.1 C） --}}
            @if($mismatch)
                <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3">
                    <p class="text-xs text-amber-800">
                        ファイルの現場名「{{ $result['site_name'] }}」と、取込先の物件名「{{ $property->property_name }}」が一致しません。
                        取込先の物件が正しいか確認してください。このまま取り込むこともできます。
                    </p>
                </div>
            @endif
        </div>

        {{-- ③ 入れ替えの予告 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">取り込むと どうなるか</div>
            <ul class="list-disc list-inside text-sm text-gray-700 space-y-1">
                <li>取り込み済みの既存の工程 <span class="font-bold">{{ $importedCount }}</span> 件を削除します</li>
                <li>ファイルから <span class="font-bold">{{ count($result['rows']) }}</span> 件を登録します</li>
                <li>手で追加した工程 <span class="font-bold">{{ $manualCount }}</span> 件は残ります</li>
                @foreach($dateChanges ?? [] as $change)
                    <li>{{ $change['label'] }}を <span class="font-bold">{{ $change['to'] }}</span> にします（現在: {{ $change['from'] }}）</li>
                @endforeach
            </ul>
            <p class="mt-3 text-xs text-gray-500">
                実績（実績開始・実績終了）は取り込みません。取り込んだ日付は予定として登録します。
            </p>
        </div>

        {{-- ④ 警告・行エラー --}}
        @if(count($warnings) > 0)
            <div class="mb-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
                <div class="text-xs font-bold text-amber-800 mb-1.5">警告 {{ count($warnings) }} 件（このまま取り込めます）</div>
                <ul class="list-disc list-inside text-xs text-amber-800 space-y-0.5">
                    @foreach($warnings as $warning)<li>{{ $warning }}</li>@endforeach
                </ul>
            </div>
        @endif

        @if(count($rowErrors) > 0)
            <div class="mb-3 rounded-lg border border-red-200 bg-red-50 p-4">
                <div class="text-xs font-bold text-red-700 mb-1.5">取り込めない行 {{ count($rowErrors) }} 件（この行は登録されません）</div>
                <ul class="list-disc list-inside text-xs text-red-700 space-y-0.5">
                    @foreach($rowErrors as $rowError)<li>{{ $rowError }}</li>@endforeach
                </ul>
            </div>
        @endif

        {{-- ⑤ 工程一覧 --}}
        <div class="bg-white border border-gray-200 rounded-lg p-5 mb-3">
            <div class="text-sm font-bold text-gray-800 pb-2 mb-3.5 border-b border-gray-200">読み取った工程</div>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 12.5px;">
                    <thead>
                        <tr>
                            @foreach(['#', '工程名', '種類', '予定開始', '予定終了', '日数', '備考'] as $th)
                                <th style="background: #F9FAFB; color: #6B7280; font-size: 11.5px; font-weight: 700; padding: 8px 10px; border-bottom: 2px solid #E5E7EB; text-align: left; white-space: nowrap;">{{ $th }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($result['rows'] as $row)
                            @php($days = $row['planned_end']
                                ? (new DateTimeImmutable($row['planned_start']))->diff(new DateTimeImmutable($row['planned_end']))->days + 1
                                : null)
                            <tr>
                                <td style="padding: 6px 10px; border-bottom: 1px solid #F3F4F6; color: #9CA3AF; white-space: nowrap;">{{ $row['sort_order'] }}</td>
                                <td style="padding: 6px 10px; border-bottom: 1px solid #F3F4F6;">{{ $row['name'] }}</td>
                                <td style="padding: 6px 10px; border-bottom: 1px solid #F3F4F6; white-space: nowrap;">
                                    <span style="display: inline-block; width: 9px; height: 9px; border-radius: 2px; background: {{ \App\Enums\ScheduleStepCategory::from($row['category'])->color() }};"></span>
                                    {{ \App\Enums\ScheduleStepCategory::from($row['category'])->label() }}
                                </td>
                                <td style="padding: 6px 10px; border-bottom: 1px solid #F3F4F6; white-space: nowrap;">{{ $row['planned_start'] }}</td>
                                <td style="padding: 6px 10px; border-bottom: 1px solid #F3F4F6; white-space: nowrap;">{{ $row['planned_end'] ?? '—' }}</td>
                                <td style="padding: 6px 10px; border-bottom: 1px solid #F3F4F6; white-space: nowrap;">{{ $days !== null ? $days . ' 日' : '—' }}</td>
                                <td style="padding: 6px 10px; border-bottom: 1px solid #F3F4F6; color: #6B7280;">{{ $row['notes'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ⑥ 確定 --}}
        @if(count($result['rows']) > 0)
            <form method="POST" action="{{ route('housing.properties.schedule-import.execute', $property) }}">
                @csrf
                <input type="hidden" name="rows_json" value="{{ json_encode($result['rows'], JSON_UNESCAPED_UNICODE) }}">
                <button type="submit"
                        class="h-10 px-5 rounded-md bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 transition-colors">
                    この内容で取り込む
                </button>
            </form>
        @else
            <p class="text-sm text-gray-500">取り込める工程がありません。ファイルを確認してください。</p>
        @endif
    @endisset

@endsection
