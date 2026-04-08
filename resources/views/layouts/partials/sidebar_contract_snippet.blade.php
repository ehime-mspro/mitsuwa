{{-- ========================================================================
    不動産 契約管理 サイドバー追加スニペット
    
    sidebar.blade.php の不動産管理グループ内に「契約管理」を追加
    
    ★ 3箇所に追加が必要:
    1. PC展開サイドバーの「不動産管理」グループ
    2. モバイルドロワーの「不動産管理」グループ
    3. PC折りたたみサイドバーは変更不要（realestate/* で既にマッチ）
========================================================================== --}}

{{-- 
    ★ 変更箇所: 不動産管理グループ内、仕入れ先管理（or 顧客管理）の後に追加

    既存:
        <x-sidebar-item :href="url('/realestate/suppliers')" label="仕入れ先管理" ... />
        <x-sidebar-item :href="url('/realestate/customers')" label="顧客管理" ... />  ← 買主マスタで追加済みの場合
    追加↓:
--}}
            <x-sidebar-item :href="url('/realestate/contracts')" label="契約管理" :active="request()->is('realestate/contracts*')" />
