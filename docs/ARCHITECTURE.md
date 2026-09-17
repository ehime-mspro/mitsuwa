# Architecture — ミツワ都市開発 経営管理システム

## Directory Structure

```
manage/
├── app/
│   ├── Enums/
│   │   ├── ProcurementStatus.php    # 仕入れ案件ステータス (8種)
│   │   ├── ProjectStatus.php        # 分譲地PJステータス (8種)
│   │   ├── LotStatus.php            # 区画ステータス (4種: unsold/on_sale/negotiating/sold)
│   │   ├── ReContractType.php       # 契約種別 (5種: 仕入れ土地/中古MS/中古戸建/分譲地/仲介)
│   │   ├── ReContractStatus.php     # 契約ステータス (4種: contracted/listing/closed/lost)
│   │   ├── HousingPropertyStatus.php
│   │   ├── CustomOrderStatus.php
│   │   ├── BuyerRank.php, BuyerDepartment.php
│   │   ├── SupplierType.php
│   │   ├── RealEstatePropertyType.php, RealEstateTransactionType.php
│   │   ├── ScheduleStepCategory.php      # 工程の分類 (5種: permit/work/survey/sale/other)
│   │   └── InquiryStatus.php, InitialMonthType.php, SurveyQuestionType.php
│   ├── Http/Controllers/
│   │   ├── Admin/                   # UserController, UsageTypeController, ReCostItemController, SurveyQuestionController, CustomerImportController
│   │   ├── Approval/                # HomeController, UserController, UserImportController (CSV), OrganizationController
│   │   ├── Housing/                 # PropertyController (建売), ContractController (建売契約), CustomOrderController (注文住宅)
│   │   ├── RealEstate/              # ProcurementController (仕入れ), ProjectController (分譲地PJ), SupplierController (仕入れ先), ReContractController (契約)
│   │   ├── Tenant/                  # PropertyController, ContractController, CustomerController, InvestmentController, RepairController, InquiryController, UnitController
│   │   ├── CustomerController.php   # 買主マスタ (部署横断)
│   │   ├── CustomerSurveyController.php
│   │   ├── ScheduleStepController.php # 工程表 CRUD (4親共通, Ajax)
│   │   └── AttachmentController.php # ファイル添付 (ポリモーフィック)
│   └── Models/
│       ├── ReContract.php           # 不動産契約
│       ├── ReProcurement.php        # 仕入れ案件
│       ├── ReProject.php            # 分譲地PJ
│       ├── ReProjectLot.php         # 分譲地区画
│       ├── ReProjectCost.php        # 分譲地原価
│       ├── ReProcurementCost.php    # 仕入れ原価
│       ├── ReSupplier.php           # 仕入れ先
│       ├── ReCostItem.php           # 原価項目マスタ
│       ├── HsProperty.php           # 建売物件
│       ├── HsContract.php           # 建売契約
│       ├── HsCustomOrder.php        # 注文住宅
│       ├── Buyer.php                # 買主マスタ (SoftDeletes)
│       ├── BuyerSurvey.php, BuyerSurveyAnswer.php
│       ├── Customer.php             # テナント顧客
│       ├── ScheduleStep.php         # 工程表の1行 (ポリモーフィック, 4親)
│       ├── Attachment.php           # 添付ファイル (ポリモーフィック, SoftDeletes)
│       └── ... (Property, Unit, Contract, Investment, Repair, Inquiry, etc.)
├── resources/views/
│   ├── layouts/
│   │   ├── app.blade.php            # メインレイアウト
│   │   └── partials/sidebar.blade.php
│   ├── realestate/
│   │   ├── contracts/               # 契約管理 (index/create/show/edit)
│   │   ├── procurements/            # 仕入れ案件 (index/create/show/edit/_form)
│   │   ├── projects/                # 分譲地PJ (index/create/show/edit/_form/lots)
│   │   └── suppliers/               # 仕入れ先
│   ├── housing/
│   │   ├── properties/              # 建売物件
│   │   ├── contracts/               # 建売契約
│   │   └── custom-orders/           # 注文住宅
│   ├── buyers/                      # 買主マスタ
│   ├── tenant/                      # テナント管理 (properties/contracts/customers/investments/repairs/inquiries)
│   └── components/                  # attachment-section, attachment-upload
├── routes/
│   ├── web.php                      # 全ルート定義 (末尾で approval.php を require)
│   ├── approval.php                 # 決裁申請 段階1 (19 ルート。門番 approval.admin の中に管理系)
│   └── console.php                  # 定期実行の予定 (schedule:run が読む)
└── database/sql/                    # 直接実行用SQL
```

## Completed Modules (~185 routes)

| Module | Routes | Key Features |
|--------|--------|-------------|
| STEP 1-11 テナント管理 | ~80 | 物件/区画/契約/投資/修繕/問合せ/顧客/収支/ダッシュボード |
| 不動産 仕入れ管理 | 23 | Google Maps, Ajax原価管理, 添付ファイル |
| 不動産 分譲地PJ | 16 | 区画管理, 図面管理, 収支シミュレーション |
| 不動産 仕入れ先管理 | 7 | SoftDeletes, Ajax検索 |
| 住宅事業 建売管理 | 16 | 建売契約, ファイルカテゴリ管理 |
| 住宅事業 注文住宅管理 | 10 | ファイルカテゴリ管理 |
| 顧客管理(買主マスタ) | ~29 | 部署横断, アンケート, CSVインポート, 郵便番号逆引き |
| 不動産 契約管理 | 12 | 5種別統合, 仲介ライフサイクル, 原価自動参照 |

## Key Database Tables

| Table | Purpose |
|-------|---------|
| `re_contracts` | 不動産契約 (5種別統合, department列で住宅事業にも拡張可能) |
| `re_procurements` | 仕入れ案件 |
| `re_procurement_costs` | 仕入れ原価明細 |
| `re_projects` | 分譲地PJ |
| `re_project_costs` | 分譲地原価明細 |
| `re_project_lots` | 分譲地区画 |
| `re_project_drawings` | 区画図面 |
| `re_suppliers` | 仕入れ先 |
| `re_cost_items` | 原価項目マスタ |
| `hs_properties` | 建売物件 |
| `hs_contracts` | 建売契約 |
| `hs_custom_orders` | 注文住宅 |
| `schedule_steps` | 工程表（ポリモーフィック。仕入れ案件 / 分譲地PJ / 建売物件 / 注文住宅 の 4 親）|
| `buyers` | 買主マスタ (SoftDeletes) |
| `buyer_departments` | 買主×部署紐付け（ランク・取得日。UNIQUE(buyer_id, department)）|
| `buyer_surveys` / `buyer_survey_answers` | アンケート |
| `properties` | テナント物件 |
| `units` | テナント区画 (floor + room_number → display_name自動生成) |
| `contracts` | テナント契約 |
| `users` | ユーザー (role: executive/manager/staff/approval_only、SoftDeletes) |
| `settings` | システム設定 (消費税率等) |
| `approval_companies` | 決裁: 会社（期の始まりの月）|
| `approval_departments` | 決裁: 部門（略称・英大文字 1〜3 文字のコード。申請番号に使う）|
| `approval_department_user` | 決裁: 所属部門（兼務可。複合主キー）|
| `approval_members` | 決裁: 利用者ごとの印（`is_admin` = 決裁の管理者 / `can_view_all` = 全件閲覧者）|
| `approval_settings` | 決裁: 社長の指定（1 行）|
| `approval_mail_domains` | 決裁: 許可するメールドメイン |
| `approval_setting_logs` | 決裁: 設定の変更の記録（**追記のみ**。`updated_at` を持たない）|

## Authentication & Authorization

- Roles: `executive` (経営層), `manager` (管理者), `staff` (一般担当), `approval_only` (決裁のみ)
- Middleware: `role:executive`, `role:executive,manager`
- 決裁: `approval_only` は `RestrictApprovalOnlyUsers` が決裁以外の全画面から締め出す（web グループ・`SubstituteBindings` より前）。決裁の管理系は 2 段目の `approval.admin`（`EnsureApprovalAdmin`）が守る。**ロールとは独立**で、基幹を使う人（executive / manager / staff）も `approval_members.is_admin` で決裁の管理者になれる
- Department access: `$user->belongsToDepartment('realestate')` / `('housing')` / `('tenant')`
