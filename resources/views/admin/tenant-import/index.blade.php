@extends('layouts.app')

@section('title', 'テナントCSVインポート')

@section('content')
<div class="text-sm text-gray-500" style="margin-bottom: 12px;">
    ダッシュボード &gt; システム管理 &gt; <span class="text-gray-800 font-medium">テナントCSVインポート</span>
</div>
<h1 style="font-size: 20px; font-weight: 700; margin: 0 0 20px;">テナントCSVインポート</h1>


<div x-data="tenantImportTabs()">
    {{-- タブヘッダー --}}
    <div style="display: flex; border-bottom: 2px solid #e5e7eb; margin-bottom: 0;">
        <button type="button" x-on:click="activeTab = 'property'"
                :style="activeTab === 'property' ? 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #fff; color: #059669; border-bottom: 2px solid #059669; font-weight: 700; margin-bottom: -2px;' : 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #f9fafb; color: #6b7280; border-bottom: 2px solid transparent; margin-bottom: -2px;'">
            ① 物件
        </button>
        <button type="button" x-on:click="activeTab = 'unit'"
                :style="activeTab === 'unit' ? 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #fff; color: #059669; border-bottom: 2px solid #059669; font-weight: 700; margin-bottom: -2px;' : 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #f9fafb; color: #6b7280; border-bottom: 2px solid transparent; margin-bottom: -2px;'">
            ② 区画
        </button>
        <button type="button" x-on:click="activeTab = 'customer'"
                :style="activeTab === 'customer' ? 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #fff; color: #059669; border-bottom: 2px solid #059669; font-weight: 700; margin-bottom: -2px;' : 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #f9fafb; color: #6b7280; border-bottom: 2px solid transparent; margin-bottom: -2px;'">
            ③ 顧客
        </button>
        <button type="button" x-on:click="activeTab = 'contract'"
                :style="activeTab === 'contract' ? 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #fff; color: #059669; border-bottom: 2px solid #059669; font-weight: 700; margin-bottom: -2px;' : 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #f9fafb; color: #6b7280; border-bottom: 2px solid transparent; margin-bottom: -2px;'">
            ④ 契約
        </button>
        <button type="button" x-on:click="activeTab = 'past-contract'"
                :style="activeTab === 'past-contract' ? 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #fff; color: #059669; border-bottom: 2px solid #059669; font-weight: 700; margin-bottom: -2px;' : 'padding: 10px 20px; font-size: 14px; border: none; cursor: pointer; border-radius: 6px 6px 0 0; background: #f9fafb; color: #6b7280; border-bottom: 2px solid transparent; margin-bottom: -2px;'">
            ⑤ 過去契約
        </button>
    </div>

    {{-- タブコンテンツ --}}
    <div class="bg-white border border-gray-200 rounded-b-lg p-5" style="border-top: none;">

        {{-- ===== ① 物件タブ ===== --}}
        <div x-show="activeTab === 'property'">
            @if(isset($preview) && $preview === 'property')
                @include('admin.tenant-import._preview', [
                    'tab'         => 'property',
                    'routeName'   => 'admin.tenant-import.property',
                    'entityLabel' => '物件',
                ])
            @else
                {{-- 説明 --}}
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px;">
                    <div style="font-weight: 600; font-size: 14px; color: #166534; margin-bottom: 6px;">物件マスタの一括登録</div>
                    <ul style="font-size: 12px; color: #15803d; margin: 0; padding-left: 18px; line-height: 1.8;">
                        <li>1行＝1物件として登録します</li>
                        <li>同名の物件が既にDBに存在する場合はスキップされます</li>
                        <li>物件コード（T-001等）は自動採番されます</li>
                    </ul>
                </div>
                @include('admin.tenant-import._form', [
                    'tab'           => 'property',
                    'routeName'     => 'admin.tenant-import.property',
                    'templateRoute' => 'admin.tenant-import.template.property',
                    'fileInputName' => 'fileProperty',
                ])
            @endif
        </div>

        {{-- ===== ② 区画タブ ===== --}}
        <div x-show="activeTab === 'unit'">
            @if(isset($preview) && $preview === 'unit')
                @include('admin.tenant-import._preview', [
                    'tab'         => 'unit',
                    'routeName'   => 'admin.tenant-import.unit',
                    'entityLabel' => '区画',
                ])
            @else
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px;">
                    <div style="font-weight: 600; font-size: 14px; color: #1e40af; margin-bottom: 6px;">区画の一括登録</div>
                    <ul style="font-size: 12px; color: #1d4ed8; margin: 0; padding-left: 18px; line-height: 1.8;">
                        <li>1行＝1区画として登録します</li>
                        <li>CSVの「物件名」で既存の物件を検索し紐づけます</li>
                        <li style="font-weight: 600;">※ 物件が先に登録されている必要があります</li>
                    </ul>
                </div>
                @include('admin.tenant-import._form', [
                    'tab'           => 'unit',
                    'routeName'     => 'admin.tenant-import.unit',
                    'templateRoute' => 'admin.tenant-import.template.unit',
                    'fileInputName' => 'fileUnit',
                ])
            @endif
        </div>

        {{-- ===== ③ 顧客タブ ===== --}}
        <div x-show="activeTab === 'customer'">
            @if(isset($preview) && $preview === 'customer')
                @include('admin.tenant-import._preview', [
                    'tab'         => 'customer',
                    'routeName'   => 'admin.tenant-import.customer',
                    'entityLabel' => '顧客',
                ])
            @else
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px;">
                    <div style="font-weight: 600; font-size: 14px; color: #166534; margin-bottom: 6px;">テナント顧客の一括登録</div>
                    <ul style="font-size: 12px; color: #15803d; margin: 0; padding-left: 18px; line-height: 1.8;">
                        <li>1行＝1顧客として登録します</li>
                        <li>同名の顧客が既にDBに存在する場合はスキップされます</li>
                        <li>顧客コード（CUS-001等）は自動採番されます</li>
                        <li>物件・区画の登録とは独立して実行できます</li>
                    </ul>
                </div>
                @include('admin.tenant-import._form', [
                    'tab'           => 'customer',
                    'routeName'     => 'admin.tenant-import.customer',
                    'templateRoute' => 'admin.tenant-import.template.customer',
                    'fileInputName' => 'fileCustomer',
                ])
            @endif
        </div>

        {{-- ===== ④ 契約タブ ===== --}}
        <div x-show="activeTab === 'contract'">
            @if(isset($preview) && $preview === 'contract')
                @include('admin.tenant-import._preview', [
                    'tab'         => 'contract',
                    'routeName'   => 'admin.tenant-import.contract',
                    'entityLabel' => '契約',
                ])
            @else
                <div style="background: #fefce8; border: 1px solid #fde68a; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px;">
                    <div style="font-weight: 600; font-size: 14px; color: #92400e; margin-bottom: 6px;">契約の一括登録</div>
                    <ul style="font-size: 12px; color: #a16207; margin: 0; padding-left: 18px; line-height: 1.8;">
                        <li>CSVの「物件名」「階」「部屋番号」で既存区画を検索し紐づけます</li>
                        <li>契約番号（C-2026-001等）は自動採番されます</li>
                        <li>契約作成時に区画のステータスが「入居中」に更新されます</li>
                        <li style="font-weight: 600;">※ 物件・区画は事前登録必須。「テナント名」は任意で、指定する場合のみ既存顧客が事前登録されている必要があります</li>
                        <li style="font-weight: 600;">※「階」の記法: <code>1</code>=1階／<code>-1</code>=地下1階／空欄=階情報なしの区画。同じ物件で同じ部屋番号が複数階に存在する場合は必ず指定してください</li>
                    </ul>
                </div>
                @include('admin.tenant-import._form', [
                    'tab'           => 'contract',
                    'routeName'     => 'admin.tenant-import.contract',
                    'templateRoute' => 'admin.tenant-import.template.contract',
                    'fileInputName' => 'fileContract',
                ])
            @endif
        </div>

        {{-- ===== ⑤ 過去契約タブ（解約済み契約の一括取込） ===== --}}
        <div x-show="activeTab === 'past-contract'">
            @if(isset($preview) && $preview === 'past-contract')
                @include('admin.tenant-import._preview', [
                    'tab'         => 'past-contract',
                    'routeName'   => 'admin.tenant-import.past-contract',
                    'entityLabel' => '過去契約',
                ])
            @else
                <div style="background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 8px; padding: 14px 18px; margin-bottom: 24px;">
                    <div style="font-weight: 600; font-size: 14px; color: #5b21b6; margin-bottom: 6px;">過去契約の一括登録（解約済み契約）</div>
                    <ul style="font-size: 12px; color: #6d28d9; margin: 0; padding-left: 18px; line-height: 1.8;">
                        <li>データ移行・過去実績登録のために、<strong>解約済みの契約</strong>を一括取込します</li>
                        <li>必須項目: <strong>物件名・部屋番号・テナント名・契約日・解約日</strong>（他は任意）</li>
                        <li>解約日があるので status=<strong>terminated（解約済）</strong>として登録</li>
                        <li>契約番号は<strong>契約日の年</strong>で自動採番（例: 2020-04-01 契約 → <code>C-2020-001</code>）</li>
                        <li>テナント名が顧客マスタにない場合は<strong>自動作成</strong>（同名顧客があれば再利用）</li>
                        <li>過去契約取込では区画のステータスは<strong>変更しません</strong>（現状の入居状態に影響なし）</li>
                        <li>同一区画に期間が重なる契約があっても警告のみで取込実行されます</li>
                    </ul>
                </div>
                @include('admin.tenant-import._form', [
                    'tab'           => 'past-contract',
                    'routeName'     => 'admin.tenant-import.past-contract',
                    'templateRoute' => 'admin.tenant-import.template.past-contract',
                    'fileInputName' => 'filePastContract',
                ])
            @endif
        </div>

    </div>
</div>

<script>
function tenantImportTabs() {
    return {
        activeTab: '{{ $activeTab ?? "property" }}',
        fileNames: { property: '', unit: '', customer: '', contract: '', 'past-contract': '' },
        onFileSelect: function(event, tab) {
            var file = event.target.files[0];
            if (file) {
                this.fileNames[tab] = file.name;
            }
        }
    };
}
</script>
@endsection
