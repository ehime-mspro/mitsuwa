@extends('layouts.app')

@section('title', '賃貸マンションCSVインポート')

@section('content')
<div class="text-sm text-gray-500" style="margin-bottom: 12px;">
    ダッシュボード &gt; システム管理 &gt; <span class="text-gray-800 font-medium">賃貸マンションCSVインポート</span>
</div>
<h1 style="font-size: 20px; font-weight: 700; margin: 0 0 20px;">賃貸マンションCSVインポート</h1>

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

<div x-data="mansionImportTabs()">
    {{-- タブヘッダー --}}
    <div style="display: flex; border-bottom: 2px solid #e5e7eb; margin-bottom: 0; flex-wrap: wrap;">
        <button type="button" x-on:click="activeTab = 'property'"
                :style="activeTab === 'property' ? 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #fff; color: #059669; border-bottom: 2px solid #059669; font-weight: 700; margin-bottom: -2px;' : 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #f9fafb; color: #6b7280; border-bottom: 2px solid transparent; margin-bottom: -2px;'">
            ① 物件
        </button>
        <button type="button" x-on:click="activeTab = 'room'"
                :style="activeTab === 'room' ? 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #fff; color: #059669; border-bottom: 2px solid #059669; font-weight: 700; margin-bottom: -2px;' : 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #f9fafb; color: #6b7280; border-bottom: 2px solid transparent; margin-bottom: -2px;'">
            ② 部屋
        </button>
        <button type="button" x-on:click="activeTab = 'parking'"
                :style="activeTab === 'parking' ? 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #fff; color: #059669; border-bottom: 2px solid #059669; font-weight: 700; margin-bottom: -2px;' : 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #f9fafb; color: #6b7280; border-bottom: 2px solid transparent; margin-bottom: -2px;'">
            ③ 駐車場
        </button>
        <button type="button" x-on:click="activeTab = 'tenant'"
                :style="activeTab === 'tenant' ? 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #fff; color: #059669; border-bottom: 2px solid #059669; font-weight: 700; margin-bottom: -2px;' : 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #f9fafb; color: #6b7280; border-bottom: 2px solid transparent; margin-bottom: -2px;'">
            ④ 入居者
        </button>
        <button type="button" x-on:click="activeTab = 'room_contract'"
                :style="activeTab === 'room_contract' ? 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #fff; color: #059669; border-bottom: 2px solid #059669; font-weight: 700; margin-bottom: -2px;' : 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #f9fafb; color: #6b7280; border-bottom: 2px solid transparent; margin-bottom: -2px;'">
            ⑤ 部屋契約
        </button>
        <button type="button" x-on:click="activeTab = 'parking_contract'"
                :style="activeTab === 'parking_contract' ? 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #fff; color: #059669; border-bottom: 2px solid #059669; font-weight: 700; margin-bottom: -2px;' : 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #f9fafb; color: #6b7280; border-bottom: 2px solid transparent; margin-bottom: -2px;'">
            ⑥ 駐車場契約
        </button>
    </div>

    {{-- タブコンテンツ --}}
    <div class="bg-white border border-gray-200 rounded-b-lg p-5" style="border-top: none;">

        {{-- ===== ① 物件タブ ===== --}}
        <div x-show="activeTab === 'property'">
            @if(isset($preview) && $preview === 'property')
                @include('admin.mansion-import._preview', [
                    'tab'         => 'property',
                    'actionUrl'   => route('admin.mansion-import.execute-property'),
                    'entityLabel' => '物件',
                ])
            @else
                {{-- 説明 --}}
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px;">
                    <div style="font-weight: 600; font-size: 14px; color: #166534; margin-bottom: 6px;">賃貸マンション物件マスタの一括登録</div>
                    <ul style="font-size: 12px; color: #15803d; margin: 0; padding-left: 18px; line-height: 1.8;">
                        <li>1行＝1物件として登録します</li>
                        <li>同名の物件が既にDBに存在する場合はスキップされます</li>
                        <li>物件コード（MSP-001等）は自動採番されます</li>
                        <li style="font-weight: 600;">※ 総戸数は部屋インポート後に自動再集計で上書きされます</li>
                    </ul>
                </div>
                @include('admin.mansion-import._form', [
                    'tab'           => 'property',
                    'actionUrl'     => route('admin.mansion-import.execute-property'),
                    'templateUrl'   => route('admin.mansion-import.template-property'),
                    'fileInputName' => 'fileProperty',
                    'fileNamesKey'  => 'property',
                ])
            @endif
        </div>

        {{-- ===== ② 部屋タブ ===== --}}
        <div x-show="activeTab === 'room'">
            @if(isset($preview) && $preview === 'room')
                @include('admin.mansion-import._preview', [
                    'tab'         => 'room',
                    'actionUrl'   => route('admin.mansion-import.execute-room'),
                    'entityLabel' => '部屋',
                ])
            @else
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px;">
                    <div style="font-weight: 600; font-size: 14px; color: #1e40af; margin-bottom: 6px;">部屋マスタの一括登録</div>
                    <ul style="font-size: 12px; color: #1d4ed8; margin: 0; padding-left: 18px; line-height: 1.8;">
                        <li>1行＝1部屋として登録します</li>
                        <li>CSVの「物件名」で既存の賃貸マンション物件を検索し紐づけます</li>
                        <li>同一物件内で部屋番号が重複する場合はスキップされます</li>
                        <li style="font-weight: 600;">※ 物件が先に登録されている必要があります</li>
                    </ul>
                </div>
                @include('admin.mansion-import._form', [
                    'tab'           => 'room',
                    'actionUrl'     => route('admin.mansion-import.execute-room'),
                    'templateUrl'   => route('admin.mansion-import.template-room'),
                    'fileInputName' => 'fileRoom',
                    'fileNamesKey'  => 'room',
                ])
            @endif
        </div>

        {{-- ===== ③ 駐車場タブ ===== --}}
        <div x-show="activeTab === 'parking'">
            @if(isset($preview) && $preview === 'parking')
                @include('admin.mansion-import._preview', [
                    'tab'         => 'parking',
                    'actionUrl'   => route('admin.mansion-import.execute-parking'),
                    'entityLabel' => '駐車場',
                ])
            @else
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px;">
                    <div style="font-weight: 600; font-size: 14px; color: #1e40af; margin-bottom: 6px;">駐車場マスタの一括登録</div>
                    <ul style="font-size: 12px; color: #1d4ed8; margin: 0; padding-left: 18px; line-height: 1.8;">
                        <li>1行＝1駐車場区画として登録します</li>
                        <li>CSVの「物件名」で既存の賃貸マンション物件を検索し紐づけます</li>
                        <li>同一物件内で区画番号が重複する場合はスキップされます</li>
                        <li style="font-weight: 600;">※ 物件が先に登録されている必要があります</li>
                    </ul>
                </div>
                @include('admin.mansion-import._form', [
                    'tab'           => 'parking',
                    'actionUrl'     => route('admin.mansion-import.execute-parking'),
                    'templateUrl'   => route('admin.mansion-import.template-parking'),
                    'fileInputName' => 'fileParking',
                    'fileNamesKey'  => 'parking',
                ])
            @endif
        </div>

        {{-- ===== ④ 入居者タブ ===== --}}
        <div x-show="activeTab === 'tenant'">
            @if(isset($preview) && $preview === 'tenant')
                @include('admin.mansion-import._preview', [
                    'tab'         => 'tenant',
                    'actionUrl'   => route('admin.mansion-import.execute-tenant'),
                    'entityLabel' => '入居者',
                ])
            @else
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px;">
                    <div style="font-weight: 600; font-size: 14px; color: #166534; margin-bottom: 6px;">入居者の一括登録</div>
                    <ul style="font-size: 12px; color: #15803d; margin: 0; padding-left: 18px; line-height: 1.8;">
                        <li>1行＝1入居者として登録します</li>
                        <li>同名の入居者が既にDBに存在する場合はスキップされます</li>
                        <li>入居者コード（TN-0001等）は自動採番されます</li>
                        <li>物件・部屋・駐車場の登録とは独立して実行できます</li>
                    </ul>
                </div>
                @include('admin.mansion-import._form', [
                    'tab'           => 'tenant',
                    'actionUrl'     => route('admin.mansion-import.execute-tenant'),
                    'templateUrl'   => route('admin.mansion-import.template-tenant'),
                    'fileInputName' => 'fileTenant',
                    'fileNamesKey'  => 'tenant',
                ])
            @endif
        </div>

        {{-- ===== ⑤ 部屋契約タブ ===== --}}
        <div x-show="activeTab === 'room_contract'">
            @if(isset($preview) && $preview === 'room_contract')
                @include('admin.mansion-import._preview', [
                    'tab'         => 'room_contract',
                    'actionUrl'   => route('admin.mansion-import.execute-room-contract'),
                    'entityLabel' => '部屋契約',
                ])
            @else
                <div style="background: #fefce8; border: 1px solid #fde68a; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px;">
                    <div style="font-weight: 600; font-size: 14px; color: #92400e; margin-bottom: 6px;">部屋契約の一括登録</div>
                    <ul style="font-size: 12px; color: #a16207; margin: 0; padding-left: 18px; line-height: 1.8;">
                        <li>CSVの「物件名」「部屋番号」「入居者名」で既存データを検索し紐づけます</li>
                        <li>契約番号（MSC-2026-0001等）は自動採番されます</li>
                        <li>契約作成時に部屋のステータスが「入居中」に更新されます</li>
                        <li style="font-weight: 700; color: #b45309;">※ 先に物件 / 部屋 / 駐車場 / 入居者の登録が必要です</li>
                    </ul>
                </div>
                @include('admin.mansion-import._form', [
                    'tab'           => 'room_contract',
                    'actionUrl'     => route('admin.mansion-import.execute-room-contract'),
                    'templateUrl'   => route('admin.mansion-import.template-room-contract'),
                    'fileInputName' => 'fileRoomContract',
                    'fileNamesKey'  => 'roomContract',
                ])
            @endif
        </div>

        {{-- ===== ⑥ 駐車場契約タブ ===== --}}
        <div x-show="activeTab === 'parking_contract'">
            @if(isset($preview) && $preview === 'parking_contract')
                @include('admin.mansion-import._preview', [
                    'tab'         => 'parking_contract',
                    'actionUrl'   => route('admin.mansion-import.execute-parking-contract'),
                    'entityLabel' => '駐車場契約',
                ])
            @else
                <div style="background: #fefce8; border: 1px solid #fde68a; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px;">
                    <div style="font-weight: 600; font-size: 14px; color: #92400e; margin-bottom: 6px;">駐車場契約の一括登録</div>
                    <ul style="font-size: 12px; color: #a16207; margin: 0; padding-left: 18px; line-height: 1.8;">
                        <li>CSVの「物件名」「区画番号」「入居者名」で既存データを検索し紐づけます</li>
                        <li>契約番号（MPC-2026-0001等）は自動採番されます</li>
                        <li>契約作成時に駐車場のステータスが「使用中」に更新されます</li>
                        <li>紐付部屋番号は任意。記入時は active な部屋契約が必要です</li>
                        <li style="font-weight: 700; color: #b45309;">※ 先に物件 / 部屋 / 駐車場 / 入居者の登録が必要です</li>
                    </ul>
                </div>
                @include('admin.mansion-import._form', [
                    'tab'           => 'parking_contract',
                    'actionUrl'     => route('admin.mansion-import.execute-parking-contract'),
                    'templateUrl'   => route('admin.mansion-import.template-parking-contract'),
                    'fileInputName' => 'fileParkingContract',
                    'fileNamesKey'  => 'parkingContract',
                ])
            @endif
        </div>

    </div>
</div>

<script>
function mansionImportTabs() {
    return {
        activeTab: '{{ $activeTab ?? "property" }}',
        fileNames: {
            property: '',
            room: '',
            parking: '',
            tenant: '',
            roomContract: '',
            parkingContract: ''
        },
        onFileSelect: function(event, key) {
            var file = event.target.files[0];
            if (file) {
                this.fileNames[key] = file.name;
            }
        }
    };
}
</script>
@endsection
