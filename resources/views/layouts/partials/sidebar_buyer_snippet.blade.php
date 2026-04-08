{{-- ========================================================================
    顧客管理（買主マスタ）サイドバー追加スニペット
    
    以下の変更をsidebar.blade.phpに適用してください:
    
    1. PC展開サイドバー: 不動産管理グループ内に「顧客管理」を追加
    2. PC展開サイドバー: 住宅事業グループ内に「顧客管理」を追加
    3. PC展開サイドバー: システム管理グループ内に「アンケート設問管理」「顧客CSVインポート」を追加
    4. モバイルドロワーにも同様に追加
========================================================================== --}}

{{-- ★ 変更1: 不動産管理グループ（「仕入れ先管理」の後に追加） --}}
{{-- 
    既存:
        <x-sidebar-item :href="url('/realestate/suppliers')" label="仕入れ先管理" ... />
    追加↓:
--}}
            <x-sidebar-item :href="url('/realestate/customers')" label="顧客管理" :active="request()->is('realestate/customers*')" />


{{-- ★ 変更2: 住宅事業グループ内（「建売物件」の後、または注文住宅の後に追加） --}}
{{--
    既存の住宅事業グループ内に追加:
--}}
            <x-sidebar-item :href="url('/housing/customers')" label="顧客管理" :active="request()->is('housing/customers*')" />


{{-- ★ 変更3: システム管理グループ内（「マスター設定」の前に追加） --}}
{{--
    既存:
        <x-sidebar-item :href="url('/admin/master/re-cost-items')" label="原価項目マスター" ... />
    追加↓（「マスター設定」の前に2行追加）:
--}}
            <x-sidebar-item :href="url('/admin/survey-questions')" label="アンケート設問管理" :active="request()->is('admin/survey-questions*')" />
            <x-sidebar-item :href="url('/admin/customers/import')" label="顧客CSVインポート" :active="request()->is('admin/customers/import*')" />


{{-- ★ 変更4: PC折りたたみサイドバーの不動産アイコンのアクティブ判定を拡張 --}}
{{--
    既存: request()->is('realestate/*')
    → 変更不要（realestate/customers* もマッチするため）
    
    住宅事業アイコンも同様:
    既存: request()->is('housing/*')
    → 変更不要（housing/customers* もマッチするため）
--}}


{{-- ★ 変更5: サイドバーのアクセス権限について --}}
{{--
    不動産管理グループ: 既存の $hasRealEstateAccess で制御済み
    住宅事業グループ: 既存の housing belongsToDepartment で制御済み
    
    追加で必要:
    @php
        $hasHousingAccess = $isExecutive || $user->belongsToDepartment('housing');
    @endphp
    
    ※ 既にsidebar_housing_snippet.blade.phpで
      $isExecutive || $user->belongsToDepartment('housing') のチェックがあるため、
      住宅事業グループ内に追加するだけでOK
--}}
