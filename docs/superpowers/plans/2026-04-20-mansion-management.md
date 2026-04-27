# 賃貸マンション管理 Phase 2 実装計画

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 要件定義書 v2 (`docs/賃貸マンション管理_要件定義書_v1.md`) に基づき、テーブル8本・Enum5本・Controller6本・Blade30+本・ルート約43本の賃貸マンション管理モジュールを Laravel 12 で実装する。

**Architecture:** 既存 Tenant モジュール（`app/Http/Controllers/Tenant/*`, `resources/views/tenant/*`）と同一パターンで `Mansion` 名前空間を新設。DBはマイグレーションを使わず `database/sql/` に直接実行用SQLを配置する。Blade はモック (`docs/mockups/mansion/*.html`) を変換して作成。賃料改定/料金改定は専用履歴テーブル `ms_contract_revisions` / `ms_parking_contract_revisions` で管理。

**Tech Stack:** Laravel 12.x / PHP 8.5 / MySQL 8.0 / Blade + Tailwind (Vite build) + Alpine.js v3

**実装ワークフロー（プロジェクト固有）:**
このプロジェクトには PHP CLI と PHPUnit がない。各 Phase の検証は以下で行う：
1. SQL を `sudo mysql manage < database/sql/{file}.sql` で実行
2. キャッシュクリア `sudo rm -f storage/framework/views/*.php && sudo systemctl restart apache2`
3. ブラウザ `https://domain/manage/public/mansion/...` で目視確認
4. 動作確認後にコミット

**Phase 一覧:**
- Phase A: 基盤（DB schema / Enum / Model / サイドバー）
- Phase B: 物件 CRUD（`/mansion/properties`）
- Phase C: 部屋管理（物件配下）
- Phase D: 駐車場管理（物件配下）
- Phase E: 入居者 CRUD（`/mansion/tenants` + 入居申込書アップロード）
- Phase F: 部屋契約 CRUD + 賃料改定 + 解約
- Phase G: 駐車場契約 CRUD + 料金改定 + 解約
- Phase H: ダッシュボード（`/mansion/dashboard`）
- Phase I: 30点品質監査 + デプロイ確認

---

## Phase A: 基盤（DB / Enum / Model / Sidebar）

### Task A1: DB スキーマ SQL 作成

**Files:**
- Create: `database/sql/create_mansion_tables.sql`

- [ ] **Step 1: SQL ファイル作成**

要件定義書 §2.1〜2.8 の通り 8 テーブルを定義。FK 順序に注意（`ms_properties` → `ms_rooms` / `ms_parkings` / `ms_tenants` → `ms_contracts` → `ms_parking_contracts` → revisions）。

```sql
-- 賃貸マンション管理 テーブル一式
-- 実行: sudo mysql manage < database/sql/create_mansion_tables.sql

CREATE TABLE IF NOT EXISTS `ms_properties` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `property_code` VARCHAR(20) NOT NULL COMMENT '物件番号 MS-NNN',
  `property_name` VARCHAR(100) NOT NULL COMMENT '物件名',
  `ownership_type` VARCHAR(20) NOT NULL COMMENT 'self_owned/managed',
  `owner_name` VARCHAR(100) NULL COMMENT '管理受託時オーナー名',
  `postal_code` VARCHAR(10) NULL,
  `address` VARCHAR(200) NOT NULL,
  `total_units` SMALLINT UNSIGNED NULL COMMENT '総戸数',
  `total_floors` TINYINT UNSIGNED NULL COMMENT '階数',
  `structure` VARCHAR(50) NULL COMMENT 'RC造等',
  `built_year_month` VARCHAR(7) NULL COMMENT 'YYYY-MM',
  `notes` TEXT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uk_ms_properties_code` (`property_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='マンション物件';

CREATE TABLE IF NOT EXISTS `ms_rooms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `property_id` BIGINT UNSIGNED NOT NULL,
  `room_number` VARCHAR(20) NOT NULL COMMENT '101等',
  `floor` TINYINT UNSIGNED NULL,
  `room_type` VARCHAR(20) NULL COMMENT '1K/2LDK等',
  `area_sqm` DECIMAL(8,2) NULL COMMENT '専有面積㎡',
  `status` VARCHAR(20) NOT NULL COMMENT 'vacant/occupied/negotiating/move_out_planned',
  `rent` INT UNSIGNED NULL COMMENT '募集賃料 税抜',
  `common_fee` INT UNSIGNED NULL COMMENT '共益費',
  `deposit` INT UNSIGNED NULL,
  `key_money` INT UNSIGNED NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uk_ms_rooms_property_room` (`property_id`, `room_number`),
  CONSTRAINT `fk_ms_rooms_property` FOREIGN KEY (`property_id`) REFERENCES `ms_properties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='マンション部屋';

CREATE TABLE IF NOT EXISTS `ms_parkings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `property_id` BIGINT UNSIGNED NOT NULL,
  `parking_number` VARCHAR(20) NOT NULL COMMENT 'A-1等',
  `monthly_fee` INT UNSIGNED NOT NULL COMMENT '月額料金 税抜',
  `status` VARCHAR(20) NOT NULL COMMENT 'vacant/occupied',
  `has_roof` BOOLEAN NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY `uk_ms_parkings_property_number` (`property_id`, `parking_number`),
  CONSTRAINT `fk_ms_parkings_property` FOREIGN KEY (`property_id`) REFERENCES `ms_properties`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='マンション駐車場';

CREATE TABLE IF NOT EXISTS `ms_tenants` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `tenant_type` VARCHAR(20) NOT NULL COMMENT 'resident/parking_only',
  `name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) NULL,
  `email` VARCHAR(255) NULL,
  `workplace` VARCHAR(100) NULL,
  `emergency_contact_name` VARCHAR(100) NULL,
  `emergency_contact_phone` VARCHAR(20) NULL,
  `emergency_contact_relation` VARCHAR(50) NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='マンション利用者';

CREATE TABLE IF NOT EXISTS `ms_contracts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `room_id` BIGINT UNSIGNED NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(20) NOT NULL COMMENT 'active/terminated',
  `contract_date` DATE NULL,
  `move_in_date` DATE NULL,
  `move_out_date` DATE NULL,
  `rent` INT UNSIGNED NULL,
  `common_fee` INT UNSIGNED NULL,
  `deposit` INT UNSIGNED NULL,
  `key_money` INT UNSIGNED NULL,
  `staff_user_id` BIGINT UNSIGNED NULL,
  `memo` TEXT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT `fk_ms_contracts_room` FOREIGN KEY (`room_id`) REFERENCES `ms_rooms`(`id`),
  CONSTRAINT `fk_ms_contracts_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `ms_tenants`(`id`),
  CONSTRAINT `fk_ms_contracts_staff` FOREIGN KEY (`staff_user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='マンション部屋契約';

CREATE TABLE IF NOT EXISTS `ms_parking_contracts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `parking_id` BIGINT UNSIGNED NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `contract_id` BIGINT UNSIGNED NULL COMMENT '部屋契約と連動するときのみ',
  `status` VARCHAR(20) NOT NULL,
  `contract_date` DATE NULL,
  `start_date` DATE NULL,
  `end_date` DATE NULL,
  `monthly_fee` INT UNSIGNED NOT NULL,
  `deposit` INT UNSIGNED NULL,
  `staff_user_id` BIGINT UNSIGNED NULL,
  `memo` TEXT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT `fk_ms_pc_parking` FOREIGN KEY (`parking_id`) REFERENCES `ms_parkings`(`id`),
  CONSTRAINT `fk_ms_pc_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `ms_tenants`(`id`),
  CONSTRAINT `fk_ms_pc_contract` FOREIGN KEY (`contract_id`) REFERENCES `ms_contracts`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ms_pc_staff` FOREIGN KEY (`staff_user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='マンション駐車場契約';

CREATE TABLE IF NOT EXISTS `ms_contract_revisions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `contract_id` BIGINT UNSIGNED NOT NULL,
  `revision_date` DATE NOT NULL,
  `new_rent` INT UNSIGNED NULL,
  `new_common_fee` INT UNSIGNED NULL,
  `reason` VARCHAR(200) NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT `fk_ms_cr_contract` FOREIGN KEY (`contract_id`) REFERENCES `ms_contracts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='部屋契約 賃料改定履歴';

CREATE TABLE IF NOT EXISTS `ms_parking_contract_revisions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `parking_contract_id` BIGINT UNSIGNED NOT NULL,
  `revision_date` DATE NOT NULL,
  `new_monthly_fee` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(200) NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT `fk_ms_pcr_pc` FOREIGN KEY (`parking_contract_id`) REFERENCES `ms_parking_contracts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='駐車場契約 料金改定履歴';
```

- [ ] **Step 2: DB に実行**

```bash
sudo mysql manage < database/sql/create_mansion_tables.sql
sudo mysql manage -e "SHOW TABLES LIKE 'ms_%';"
```

期待結果: 8 テーブル `ms_contract_revisions / ms_contracts / ms_parking_contract_revisions / ms_parking_contracts / ms_parkings / ms_properties / ms_rooms / ms_tenants` が表示される。

- [ ] **Step 3: departments テーブルに mansion レコード追加**

```bash
sudo mysql manage -e "INSERT IGNORE INTO departments (code, name, sort_order, created_at, updated_at) VALUES ('mansion', '賃貸マンション', 4, NOW(), NOW());"
sudo mysql manage -e "SELECT * FROM departments;"
```

期待結果: tenant / realestate / housing / mansion の 4 部署が並ぶ。

### Task A2: Enum 作成（5本）

**Files:**
- Create: `app/Enums/MsOwnershipType.php`
- Create: `app/Enums/MsRoomStatus.php`
- Create: `app/Enums/MsParkingStatus.php`
- Create: `app/Enums/MsTenantType.php`
- Create: `app/Enums/MsContractStatus.php`

- [ ] **Step 1: MsOwnershipType**

```php
<?php

namespace App\Enums;

enum MsOwnershipType: string
{
    case SelfOwned = 'self_owned';
    case Managed = 'managed';

    public function label(): string
    {
        return match ($this) {
            self::SelfOwned => '自社所有',
            self::Managed => '管理受託',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::SelfOwned => 'background: #e0e7ff; color: #3730a3;',
            self::Managed => 'background: #fef3c7; color: #92400e;',
        };
    }
}
```

- [ ] **Step 2: MsRoomStatus**

要件定義書 §3.2 のバッジスタイル指定をそのまま使用する。

```php
<?php

namespace App\Enums;

enum MsRoomStatus: string
{
    case Vacant = 'vacant';
    case Occupied = 'occupied';
    case Negotiating = 'negotiating';
    case MoveOutPlanned = 'move_out_planned';

    public function label(): string
    {
        return match ($this) {
            self::Vacant => '空室',
            self::Occupied => '入居中',
            self::Negotiating => '申込み・仮押え',
            self::MoveOutPlanned => '退去予定',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::Vacant => 'background: #dbeafe; color: #1e40af;',
            self::Occupied => 'background: #d1fae5; color: #065f46;',
            self::Negotiating => 'background: #fed7aa; color: #9a3412;',
            self::MoveOutPlanned => 'background: #fce7f3; color: #9d174d;',
        };
    }
}
```

- [ ] **Step 3: MsParkingStatus**

```php
<?php

namespace App\Enums;

enum MsParkingStatus: string
{
    case Vacant = 'vacant';
    case Occupied = 'occupied';

    public function label(): string
    {
        return match ($this) {
            self::Vacant => '空き',
            self::Occupied => '使用中',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::Vacant => 'background: #dbeafe; color: #1e40af;',
            self::Occupied => 'background: #d1fae5; color: #065f46;',
        };
    }
}
```

- [ ] **Step 4: MsTenantType**

```php
<?php

namespace App\Enums;

enum MsTenantType: string
{
    case Resident = 'resident';
    case ParkingOnly = 'parking_only';

    public function label(): string
    {
        return match ($this) {
            self::Resident => '入居者',
            self::ParkingOnly => '駐車場利用のみ',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::Resident => 'background: #d1fae5; color: #065f46;',
            self::ParkingOnly => 'background: #e0e7ff; color: #3730a3;',
        };
    }
}
```

- [ ] **Step 5: MsContractStatus**

```php
<?php

namespace App\Enums;

enum MsContractStatus: string
{
    case Active = 'active';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Active => '契約中',
            self::Terminated => '解約済み',
        };
    }

    public function badgeStyle(): string
    {
        return match ($this) {
            self::Active => 'background: #d1fae5; color: #065f46;',
            self::Terminated => 'background: #f3f4f6; color: #6b7280;',
        };
    }
}
```

### Task A3: Eloquent Model 作成（8本）

**Files:**
- Create: `app/Models/MsProperty.php`
- Create: `app/Models/MsRoom.php`
- Create: `app/Models/MsParking.php`
- Create: `app/Models/MsTenant.php`
- Create: `app/Models/MsContract.php`
- Create: `app/Models/MsParkingContract.php`
- Create: `app/Models/MsContractRevision.php`
- Create: `app/Models/MsParkingContractRevision.php`

- [ ] **Step 1: MsProperty**

```php
<?php

namespace App\Models;

use App\Enums\MsOwnershipType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MsProperty extends Model
{
    protected $fillable = [
        'property_code', 'property_name', 'ownership_type', 'owner_name',
        'postal_code', 'address', 'total_units', 'total_floors',
        'structure', 'built_year_month', 'notes',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'ownership_type' => MsOwnershipType::class,
            'total_units' => 'integer',
            'total_floors' => 'integer',
        ];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(MsRoom::class, 'property_id');
    }

    public function parkings(): HasMany
    {
        return $this->hasMany(MsParking::class, 'property_id');
    }

    public function vacantRoomsCount(): int
    {
        return $this->rooms()->where('status', \App\Enums\MsRoomStatus::Vacant->value)->count();
    }

    public function occupiedRoomsCount(): int
    {
        return $this->rooms()->where('status', \App\Enums\MsRoomStatus::Occupied->value)->count();
    }

    public function occupancyRate(): float
    {
        $total = $this->rooms()->count();
        if ($total === 0) return 0;
        return round($this->occupiedRoomsCount() / $total * 100, 1);
    }
}
```

- [ ] **Step 2: MsRoom**

```php
<?php

namespace App\Models;

use App\Enums\MsRoomStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MsRoom extends Model
{
    protected $fillable = [
        'property_id', 'room_number', 'floor', 'room_type', 'area_sqm',
        'status', 'rent', 'common_fee', 'deposit', 'key_money', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => MsRoomStatus::class,
            'floor' => 'integer',
            'area_sqm' => 'decimal:2',
            'rent' => 'integer',
            'common_fee' => 'integer',
            'deposit' => 'integer',
            'key_money' => 'integer',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(MsProperty::class, 'property_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(MsContract::class, 'room_id');
    }

    public function activeContract()
    {
        return $this->hasOne(MsContract::class, 'room_id')->where('status', 'active');
    }
}
```

- [ ] **Step 3: MsParking**

```php
<?php

namespace App\Models;

use App\Enums\MsParkingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MsParking extends Model
{
    protected $fillable = [
        'property_id', 'parking_number', 'monthly_fee', 'status', 'has_roof', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => MsParkingStatus::class,
            'monthly_fee' => 'integer',
            'has_roof' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(MsProperty::class, 'property_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(MsParkingContract::class, 'parking_id');
    }

    public function activeContract()
    {
        return $this->hasOne(MsParkingContract::class, 'parking_id')->where('status', 'active');
    }
}
```

- [ ] **Step 4: MsTenant**

```php
<?php

namespace App\Models;

use App\Enums\MsTenantType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MsTenant extends Model
{
    protected $fillable = [
        'tenant_type', 'name', 'phone', 'email', 'workplace',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relation',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'tenant_type' => MsTenantType::class,
        ];
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(MsContract::class, 'tenant_id');
    }

    public function parkingContracts(): HasMany
    {
        return $this->hasMany(MsParkingContract::class, 'tenant_id');
    }

    public function activeContract()
    {
        return $this->hasOne(MsContract::class, 'tenant_id')->where('status', 'active');
    }

    public function activeParkingContracts()
    {
        return $this->hasMany(MsParkingContract::class, 'tenant_id')->where('status', 'active');
    }
}
```

- [ ] **Step 5: MsContract**

```php
<?php

namespace App\Models;

use App\Enums\MsContractStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class MsContract extends Model
{
    protected $fillable = [
        'room_id', 'tenant_id', 'status', 'contract_date', 'move_in_date', 'move_out_date',
        'rent', 'common_fee', 'deposit', 'key_money', 'staff_user_id', 'memo',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => MsContractStatus::class,
            'contract_date' => 'date',
            'move_in_date' => 'date',
            'move_out_date' => 'date',
            'rent' => 'integer',
            'common_fee' => 'integer',
            'deposit' => 'integer',
            'key_money' => 'integer',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(MsRoom::class, 'room_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(MsTenant::class, 'tenant_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function parkingContracts(): HasMany
    {
        return $this->hasMany(MsParkingContract::class, 'contract_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(MsContractRevision::class, 'contract_id')->orderByDesc('revision_date');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function isTerminated(): bool
    {
        return $this->status === MsContractStatus::Terminated;
    }
}
```

- [ ] **Step 6: MsParkingContract**

```php
<?php

namespace App\Models;

use App\Enums\MsContractStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MsParkingContract extends Model
{
    protected $fillable = [
        'parking_id', 'tenant_id', 'contract_id', 'status',
        'contract_date', 'start_date', 'end_date', 'monthly_fee', 'deposit',
        'staff_user_id', 'memo', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => MsContractStatus::class,
            'contract_date' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'monthly_fee' => 'integer',
            'deposit' => 'integer',
        ];
    }

    public function parking(): BelongsTo
    {
        return $this->belongsTo(MsParking::class, 'parking_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(MsTenant::class, 'tenant_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(MsContract::class, 'contract_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(MsParkingContractRevision::class, 'parking_contract_id')->orderByDesc('revision_date');
    }

    public function isTerminated(): bool
    {
        return $this->status === MsContractStatus::Terminated;
    }
}
```

- [ ] **Step 7: MsContractRevision**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MsContractRevision extends Model
{
    protected $fillable = [
        'contract_id', 'revision_date', 'new_rent', 'new_common_fee', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'revision_date' => 'date',
            'new_rent' => 'integer',
            'new_common_fee' => 'integer',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(MsContract::class, 'contract_id');
    }
}
```

- [ ] **Step 8: MsParkingContractRevision**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MsParkingContractRevision extends Model
{
    protected $fillable = [
        'parking_contract_id', 'revision_date', 'new_monthly_fee', 'reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'revision_date' => 'date',
            'new_monthly_fee' => 'integer',
        ];
    }

    public function parkingContract(): BelongsTo
    {
        return $this->belongsTo(MsParkingContract::class, 'parking_contract_id');
    }
}
```

### Task A4: サイドバー追加（3箇所）

**Files:**
- Modify: `resources/views/layouts/partials/sidebar.blade.php`

- [ ] **Step 1: アクセス変数追加**

ファイル冒頭の `@php` ブロック内、`$hasTenantAccess` / `$hasRealEstateAccess` の隣に追加:

```php
$hasMansionAccess = $isExecutive || $user->belongsToDepartment('mansion');
```

- [ ] **Step 2: PC展開サイドバーに賃貸マンショングループ追加**

`<x-sidebar-group label="テナント管理">` の直後に挿入。展開・折りたたみ・モバイルの3箇所に同様の追記が必要。

```blade
@if($hasMansionAccess)
    <x-sidebar-group label="賃貸マンション">
        <x-sidebar-item href="{{ route('mansion.dashboard') }}" :active="request()->routeIs('mansion.dashboard')">ダッシュボード</x-sidebar-item>
        <x-sidebar-item href="{{ route('mansion.properties.index') }}" :active="request()->routeIs('mansion.properties.*')">物件一覧</x-sidebar-item>
        <x-sidebar-item href="{{ route('mansion.tenants.index') }}" :active="request()->routeIs('mansion.tenants.*')">入居者管理</x-sidebar-item>
        <x-sidebar-item href="{{ route('mansion.contracts.index') }}" :active="request()->routeIs('mansion.contracts.*')">部屋契約一覧</x-sidebar-item>
        <x-sidebar-item href="{{ route('mansion.parking-contracts.index') }}" :active="request()->routeIs('mansion.parking-contracts.*')">駐車場契約一覧</x-sidebar-item>
    </x-sidebar-group>
@endif
```

PC折りたたみセクション・モバイルドロワーセクションの該当位置にも同じ構造（折りたたみは SVG アイコン仕様に合わせる）を追加する。

- [ ] **Step 3: 折りたたみ用 SVG アイコン作成**

折りたたみサイドバー内、各リンクは `<svg>` アイコン付きで表示する。マンション専用アイコンは Heroicons の `building-office-2` を採用。`<x-sidebar-item>` のスロット内に SVG を埋め込む。

### Task A5: Phase A コミット

- [ ] **Step 1: ブラウザ確認**

サイドバーに「賃貸マンション」グループが表示されること。リンク先はまだ存在しないため 404 で良い。

- [ ] **Step 2: コミット**

```bash
git add database/sql/create_mansion_tables.sql app/Enums/Ms*.php app/Models/Ms*.php resources/views/layouts/partials/sidebar.blade.php
git commit -m "賃貸マンション管理: DB スキーマ・Enum・Model・サイドバー追加（Phase A 基盤）"
```

---

## Phase B: 物件 CRUD（/mansion/properties）

### Task B1: ルート定義

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: mansion prefix グループ追加**

`Route::prefix('tenant')` グループの直後など適切な位置に追加:

```php
Route::prefix('mansion')->group(function () {
    // ダッシュボード（Phase H で実装）
    Route::get('/dashboard', [\App\Http\Controllers\Mansion\DashboardController::class, 'index'])
        ->name('mansion.dashboard');

    // 物件
    Route::get('/properties', [\App\Http\Controllers\Mansion\PropertyController::class, 'index'])
        ->name('mansion.properties.index');
    Route::get('/properties/{property}', [\App\Http\Controllers\Mansion\PropertyController::class, 'show'])
        ->name('mansion.properties.show');
    Route::middleware('role:executive,manager')->group(function () {
        Route::get('/properties/create', [\App\Http\Controllers\Mansion\PropertyController::class, 'create'])
            ->name('mansion.properties.create');
        Route::post('/properties', [\App\Http\Controllers\Mansion\PropertyController::class, 'store'])
            ->name('mansion.properties.store');
        Route::get('/properties/{property}/edit', [\App\Http\Controllers\Mansion\PropertyController::class, 'edit'])
            ->name('mansion.properties.edit');
        Route::put('/properties/{property}', [\App\Http\Controllers\Mansion\PropertyController::class, 'update'])
            ->name('mansion.properties.update');
        Route::delete('/properties/{property}', [\App\Http\Controllers\Mansion\PropertyController::class, 'destroy'])
            ->middleware('role:executive')
            ->name('mansion.properties.destroy');
    });
});
```

注意: `/properties/create` は `/properties/{property}` より下に書くと URL パラメータと衝突するため、**create は show より前に定義**する必要がある。または `where(['property' => '[0-9]+'])` で正規表現制約を付ける。本プロジェクトでは Tenant パターンに合わせて create を先に定義する形にすること。

### Task B2: PropertyController

**Files:**
- Create: `app/Http/Controllers/Mansion/PropertyController.php`

- [ ] **Step 1: ディレクトリ作成 + Controller**

```php
<?php

namespace App\Http\Controllers\Mansion;

use App\Enums\MsOwnershipType;
use App\Enums\MsRoomStatus;
use App\Http\Controllers\Controller;
use App\Models\MsProperty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PropertyController extends Controller
{
    public function index(Request $request)
    {
        $query = MsProperty::query()->withCount('rooms');

        if ($request->filled('ownership_type')) {
            $query->where('ownership_type', $request->ownership_type);
        }
        if ($request->filled('keyword')) {
            $query->where('property_name', 'like', '%' . $request->keyword . '%');
        }

        $properties = $query->orderBy('property_code')->paginate(20)->withQueryString();

        return view('mansion.properties.index', [
            'properties' => $properties,
            'ownershipTypes' => MsOwnershipType::cases(),
        ]);
    }

    public function create()
    {
        return view('mansion.properties.create', [
            'ownershipTypes' => MsOwnershipType::cases(),
            'nextCode' => $this->generateNextCode(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateInput($request);
        $validated['property_code'] = $this->generateNextCode();
        $validated['created_by'] = Auth::id();

        $property = MsProperty::create($validated);

        return redirect()->route('mansion.properties.show', $property)
            ->with('success', '物件を登録しました');
    }

    public function show(MsProperty $property)
    {
        $property->load([
            'rooms.activeContract.tenant',
            'parkings.activeContract.tenant',
        ]);

        return view('mansion.properties.show', compact('property'));
    }

    public function edit(MsProperty $property)
    {
        return view('mansion.properties.edit', [
            'property' => $property,
            'ownershipTypes' => MsOwnershipType::cases(),
        ]);
    }

    public function update(Request $request, MsProperty $property)
    {
        $validated = $this->validateInput($request);
        $validated['updated_by'] = Auth::id();
        $property->update($validated);

        return redirect()->route('mansion.properties.show', $property)
            ->with('success', '物件を更新しました');
    }

    public function destroy(MsProperty $property)
    {
        $property->delete();
        return redirect()->route('mansion.properties.index')
            ->with('success', '物件を削除しました');
    }

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'property_name' => 'required|string|max:100',
            'ownership_type' => 'required|in:self_owned,managed',
            'owner_name' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:10',
            'address' => 'required|string|max:200',
            'total_units' => 'nullable|integer|min:0',
            'total_floors' => 'nullable|integer|min:0',
            'structure' => 'nullable|string|max:50',
            'built_year_month' => 'nullable|string|max:7',
            'notes' => 'nullable|string',
        ]);
    }

    private function generateNextCode(): string
    {
        $last = MsProperty::orderByDesc('id')->first();
        $next = $last ? ((int) substr($last->property_code, 3)) + 1 : 1;
        return sprintf('MS-%03d', $next);
    }
}
```

### Task B3: 物件 Blade ビュー

**Files:**
- Create: `resources/views/mansion/properties/index.blade.php`
- Create: `resources/views/mansion/properties/create.blade.php`
- Create: `resources/views/mansion/properties/edit.blade.php`
- Create: `resources/views/mansion/properties/show.blade.php`
- Create: `resources/views/mansion/properties/_form.blade.php`

- [ ] **Step 1: index.blade.php**

`docs/mockups/mansion/properties/index.html` を Blade に変換する。変換ルール:
1. `<html>`/`<head>`/`<body>` を削除し `@extends('layouts.app')` + `@section('content')` で包む
2. テーブルの行を `@foreach($properties as $property)` でループ
3. ステータス・所有形態のバッジは `style="{{ $property->ownership_type->badgeStyle() }}"` で動的化
4. フィルター selectbox は `onchange="document.getElementById('filter-form').submit()"`
5. 詳細ボタンは `route('mansion.properties.show', $property)` に置換
6. ページネーション `{{ $properties->links() }}` を末尾に追加
7. 空状態（properties が0件）時の表示を `@if($properties->isEmpty())` で出し分け

- [ ] **Step 2: _form.blade.php**

物件登録/編集の共通フォーム。`$property` 変数（編集時はモデル、新規時は null）を受け取る:

- 全フォーム項目間隔は `margin-bottom: 26px`
- input class は `form-input`
- 必須項目は `<label>` に `<span style="color: #ef4444;">*</span>` を付ける
- 所有形態 select で `managed` 選択時のみ `owner_name` を Alpine `x-show` で表示
- 郵便番号からの住所自動入力（zipcloud API）は既存 `tenant/properties/_form.blade.php` の JS をコピー

- [ ] **Step 3: create.blade.php**

```blade
@extends('layouts.app')
@section('title', 'マンション物件登録')
@section('content')
<div style="max-width: 900px; margin: 0 auto;">
    <div class="mb-5">
        <a href="{{ route('mansion.properties.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← 物件一覧に戻る</a>
        <h1 class="text-lg font-bold mt-2">マンション物件登録</h1>
        <p class="text-xs text-gray-500 mt-1">物件番号: {{ $nextCode }}（自動採番）</p>
    </div>
    <form method="POST" action="{{ route('mansion.properties.store') }}">
        @csrf
        @include('mansion.properties._form', ['property' => null])
        <div class="flex justify-end gap-3 mt-6">
            <a href="{{ route('mansion.properties.index') }}" class="h-9 px-4 inline-flex items-center border border-gray-200 rounded-md text-sm">キャンセル</a>
            <button type="submit" class="h-9 px-4 bg-emerald-600 hover:bg-emerald-700 text-white rounded-md text-sm font-semibold">登録する</button>
        </div>
    </form>
</div>
@endsection
```

- [ ] **Step 4: edit.blade.php**

create.blade.php と同構造で `route('mansion.properties.update', $property)` + `@method('PUT')` + `['property' => $property]`。

- [ ] **Step 5: show.blade.php**

`docs/mockups/mansion/properties/show.html` を Blade 変換。基本情報→稼働サマリー→部屋一覧→駐車場一覧→収支サマリーの順。
- 部屋一覧テーブルの行は `@foreach($property->rooms as $room)`、入居者名は `$room->activeContract?->tenant?->name ?? '—'`
- 駐車場一覧も同様
- 「部屋を追加」「駐車場を追加」ボタンは role:manager 以上のみ表示

### Task B4: Phase B 検証 + コミット

- [ ] **Step 1: キャッシュクリア + ブラウザ確認**

```bash
sudo rm -f storage/framework/views/*.php
sudo systemctl restart apache2
```

ブラウザで `/manage/public/mansion/properties` を開き、テストデータ1件登録→詳細→編集→削除の動線確認。

- [ ] **Step 2: コミット**

```bash
git add app/Http/Controllers/Mansion/PropertyController.php resources/views/mansion/properties/ routes/web.php
git commit -m "賃貸マンション管理: 物件CRUD実装（Phase B）"
```

---

## Phase C: 部屋管理

### Task C1: ルート定義

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: 部屋ルート 5本を mansion グループ内に追加**

```php
Route::middleware('role:executive,manager')->group(function () {
    Route::get('/properties/{property}/rooms/create', [\App\Http\Controllers\Mansion\RoomController::class, 'create'])
        ->name('mansion.rooms.create');
    Route::post('/properties/{property}/rooms', [\App\Http\Controllers\Mansion\RoomController::class, 'store'])
        ->name('mansion.rooms.store');
    Route::get('/rooms/{room}/edit', [\App\Http\Controllers\Mansion\RoomController::class, 'edit'])
        ->name('mansion.rooms.edit');
    Route::put('/rooms/{room}', [\App\Http\Controllers\Mansion\RoomController::class, 'update'])
        ->name('mansion.rooms.update');
    Route::delete('/rooms/{room}', [\App\Http\Controllers\Mansion\RoomController::class, 'destroy'])
        ->middleware('role:executive')
        ->name('mansion.rooms.destroy');
});

// Ajax用
Route::patch('/rooms/{room}/status', [\App\Http\Controllers\Mansion\RoomController::class, 'updateStatus'])
    ->name('mansion.rooms.updateStatus');
```

### Task C2: RoomController

**Files:**
- Create: `app/Http/Controllers/Mansion/RoomController.php`

- [ ] **Step 1: Controller**

```php
<?php

namespace App\Http\Controllers\Mansion;

use App\Enums\MsRoomStatus;
use App\Http\Controllers\Controller;
use App\Models\MsProperty;
use App\Models\MsRoom;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    public function create(MsProperty $property)
    {
        return view('mansion.rooms.create', [
            'property' => $property,
            'statuses' => MsRoomStatus::cases(),
        ]);
    }

    public function store(Request $request, MsProperty $property)
    {
        $validated = $this->validateInput($request, $property->id);
        $validated['property_id'] = $property->id;
        MsRoom::create($validated);

        return redirect()->route('mansion.properties.show', $property)
            ->with('success', '部屋を登録しました');
    }

    public function edit(MsRoom $room)
    {
        return view('mansion.rooms.edit', [
            'room' => $room,
            'property' => $room->property,
            'statuses' => MsRoomStatus::cases(),
        ]);
    }

    public function update(Request $request, MsRoom $room)
    {
        $validated = $this->validateInput($request, $room->property_id, $room->id);
        $room->update($validated);
        return redirect()->route('mansion.properties.show', $room->property)
            ->with('success', '部屋を更新しました');
    }

    public function destroy(MsRoom $room)
    {
        $property = $room->property;
        $room->delete();
        return redirect()->route('mansion.properties.show', $property)
            ->with('success', '部屋を削除しました');
    }

    public function updateStatus(Request $request, MsRoom $room)
    {
        $request->validate(['status' => 'required|in:vacant,occupied,negotiating,move_out_planned']);
        if ($room->status === MsRoomStatus::Occupied && $request->status !== 'occupied') {
            return back()->withErrors(['status' => '入居中の部屋は契約解約以外で変更できません']);
        }
        $room->update(['status' => $request->status]);
        return back()->with('success', 'ステータスを更新しました');
    }

    private function validateInput(Request $request, int $propertyId, ?int $excludeId = null): array
    {
        $unique = "unique:ms_rooms,room_number,{$excludeId},id,property_id,{$propertyId}";
        return $request->validate([
            'room_number' => "required|string|max:20|{$unique}",
            'floor' => 'nullable|integer|min:0',
            'room_type' => 'nullable|string|max:20',
            'area_sqm' => 'nullable|numeric|min:0',
            'status' => 'required|in:vacant,occupied,negotiating,move_out_planned',
            'rent' => 'nullable|integer|min:0',
            'common_fee' => 'nullable|integer|min:0',
            'deposit' => 'nullable|integer|min:0',
            'key_money' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
        ]);
    }
}
```

### Task C3: 部屋 Blade ビュー

**Files:**
- Create: `resources/views/mansion/rooms/create.blade.php`
- Create: `resources/views/mansion/rooms/edit.blade.php`
- Create: `resources/views/mansion/rooms/_form.blade.php`

- [ ] **Step 1: _form.blade.php**

`docs/mockups/mansion/rooms/create.html` の form 部を抽出。注意点:
- 金額系入力 (`rent`, `common_fee`, `deposit`, `key_money`) は `value` 属性に `0` を入れない（CLAUDE.md ルール）
- ステータス select は `MsRoomStatus::cases()` で動的生成

- [ ] **Step 2: create.blade.php / edit.blade.php**

物件 Phase B Step 3 / 4 と同パターン。アクションは `route('mansion.rooms.store', $property)` / `route('mansion.rooms.update', $room)`。戻るリンクは `route('mansion.properties.show', $property)` に。

### Task C4: Phase C 検証 + コミット

- [ ] **Step 1: キャッシュクリア + ブラウザ確認**

物件詳細→部屋追加→保存→部屋編集→削除の動線確認。同じ号室番号で UNIQUE 制約エラーが出ることも確認。

- [ ] **Step 2: コミット**

```bash
git add app/Http/Controllers/Mansion/RoomController.php resources/views/mansion/rooms/ routes/web.php
git commit -m "賃貸マンション管理: 部屋管理CRUD実装（Phase C）"
```

---

## Phase D: 駐車場管理

### Task D1: ルート定義

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: 駐車場ルート 5本追加**

部屋ルートと同パターンで `/properties/{property}/parkings/create` 等を追加。Controller は `\App\Http\Controllers\Mansion\ParkingController`。

### Task D2: ParkingController

**Files:**
- Create: `app/Http/Controllers/Mansion/ParkingController.php`

- [ ] **Step 1: Controller（RoomController と同構造）**

差分のみ抜粋:

```php
private function validateInput(Request $request, int $propertyId, ?int $excludeId = null): array
{
    $unique = "unique:ms_parkings,parking_number,{$excludeId},id,property_id,{$propertyId}";
    return $request->validate([
        'parking_number' => "required|string|max:20|{$unique}",
        'monthly_fee' => 'required|integer|min:0',
        'status' => 'required|in:vacant,occupied',
        'has_roof' => 'nullable|boolean',
        'notes' => 'nullable|string',
    ]);
}
```

`updateStatus` は不要（駐車場は2状態のみで契約と直結するため）。

### Task D3: 駐車場 Blade ビュー

**Files:**
- Create: `resources/views/mansion/parkings/create.blade.php`
- Create: `resources/views/mansion/parkings/edit.blade.php`
- Create: `resources/views/mansion/parkings/_form.blade.php`

- [ ] **Step 1: _form.blade.php**

`docs/mockups/mansion/parkings/create.html` を変換。`has_roof` は checkbox で `<input type="checkbox" name="has_roof" value="1">`。

- [ ] **Step 2: create.blade.php / edit.blade.php**

部屋と同パターン。

### Task D4: Phase D 検証 + コミット

- [ ] **Step 1: ブラウザ確認**

物件詳細→駐車場追加→保存→編集→削除。

- [ ] **Step 2: コミット**

```bash
git add app/Http/Controllers/Mansion/ParkingController.php resources/views/mansion/parkings/ routes/web.php
git commit -m "賃貸マンション管理: 駐車場管理CRUD実装（Phase D）"
```

---

## Phase E: 入居者 CRUD + 入居申込書アップロード

### Task E1: ルート定義

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: 入居者ルート 7本 + 申込書アップロード 2本追加**

```php
Route::get('/tenants', [\App\Http\Controllers\Mansion\TenantController::class, 'index'])
    ->name('mansion.tenants.index');
Route::get('/tenants/{tenant}', [\App\Http\Controllers\Mansion\TenantController::class, 'show'])
    ->name('mansion.tenants.show');
Route::middleware('role:executive,manager')->group(function () {
    Route::get('/tenants/create', [\App\Http\Controllers\Mansion\TenantController::class, 'create'])
        ->name('mansion.tenants.create');
    Route::post('/tenants', [\App\Http\Controllers\Mansion\TenantController::class, 'store'])
        ->name('mansion.tenants.store');
    Route::get('/tenants/{tenant}/edit', [\App\Http\Controllers\Mansion\TenantController::class, 'edit'])
        ->name('mansion.tenants.edit');
    Route::put('/tenants/{tenant}', [\App\Http\Controllers\Mansion\TenantController::class, 'update'])
        ->name('mansion.tenants.update');
    Route::delete('/tenants/{tenant}', [\App\Http\Controllers\Mansion\TenantController::class, 'destroy'])
        ->middleware('role:executive')
        ->name('mansion.tenants.destroy');
    // 入居申込書アップロード
    Route::get('/tenants/{tenant}/application', [\App\Http\Controllers\Mansion\TenantController::class, 'showApplication'])
        ->name('mansion.tenants.application');
    Route::post('/tenants/{tenant}/application', [\App\Http\Controllers\Mansion\TenantController::class, 'uploadApplication'])
        ->name('mansion.tenants.application.upload');
});
```

### Task E2: TenantController

**Files:**
- Create: `app/Http/Controllers/Mansion/TenantController.php`

- [ ] **Step 1: Controller 本体**

```php
<?php

namespace App\Http\Controllers\Mansion;

use App\Enums\MsTenantType;
use App\Http\Controllers\Controller;
use App\Models\MsTenant;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function index(Request $request)
    {
        $query = MsTenant::query();
        if ($request->filled('tenant_type')) {
            $query->where('tenant_type', $request->tenant_type);
        }
        if ($request->filled('keyword')) {
            $query->where('name', 'like', '%' . $request->keyword . '%');
        }
        $tenants = $query->orderBy('name')->paginate(20)->withQueryString();
        return view('mansion.tenants.index', [
            'tenants' => $tenants,
            'tenantTypes' => MsTenantType::cases(),
        ]);
    }

    public function create()
    {
        return view('mansion.tenants.create', [
            'tenantTypes' => MsTenantType::cases(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateInput($request);
        $tenant = MsTenant::create($validated);
        return redirect()->route('mansion.tenants.show', $tenant)
            ->with('success', '入居者を登録しました');
    }

    public function show(MsTenant $tenant)
    {
        $tenant->load(['activeContract.room.property', 'activeParkingContracts.parking.property']);
        return view('mansion.tenants.show', compact('tenant'));
    }

    public function edit(MsTenant $tenant)
    {
        return view('mansion.tenants.edit', [
            'tenant' => $tenant,
            'tenantTypes' => MsTenantType::cases(),
        ]);
    }

    public function update(Request $request, MsTenant $tenant)
    {
        $tenant->update($this->validateInput($request));
        return redirect()->route('mansion.tenants.show', $tenant)
            ->with('success', '入居者を更新しました');
    }

    public function destroy(MsTenant $tenant)
    {
        $tenant->delete();
        return redirect()->route('mansion.tenants.index')
            ->with('success', '入居者を削除しました');
    }

    public function showApplication(MsTenant $tenant)
    {
        return view('mansion.tenants.application', compact('tenant'));
    }

    public function uploadApplication(Request $request, MsTenant $tenant)
    {
        // Attachment ポリモーフィックを利用（attachable_type='App\Models\MsTenant'）
        // 既存の AttachmentController または共通アップロードヘルパーを利用
        // 詳細実装は既存 housing/contracts のアップロード処理を参照
        return back()->with('success', '入居申込書をアップロードしました');
    }

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'tenant_type' => 'required|in:resident,parking_only',
            'name' => 'required|string|max:100',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'workplace' => 'nullable|string|max:100',
            'emergency_contact_name' => 'nullable|string|max:100',
            'emergency_contact_phone' => 'nullable|string|max:20',
            'emergency_contact_relation' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);
    }
}
```

### Task E3: 入居者 Blade ビュー

**Files:**
- Create: `resources/views/mansion/tenants/index.blade.php`
- Create: `resources/views/mansion/tenants/create.blade.php`
- Create: `resources/views/mansion/tenants/edit.blade.php`
- Create: `resources/views/mansion/tenants/show.blade.php`
- Create: `resources/views/mansion/tenants/application.blade.php`
- Create: `resources/views/mansion/tenants/_form.blade.php`

- [ ] **Step 1: index.blade.php**

`docs/mockups/mansion/tenants/index.html` を変換。フィルター: 利用者区分・キーワード。テーブル列: 氏名・区分・連絡先・勤務先・紐付け・入居日・詳細。

- [ ] **Step 2: _form.blade.php**

入居者登録/編集の共通フォーム。`tenant_type` ラジオボタンで `resident` / `parking_only` 選択。緊急連絡先 3項目はグループ表示。

- [ ] **Step 3: create.blade.php / edit.blade.php / show.blade.php**

モックを参照しながら Blade 化。show では `activeContract` から「マンション名 / 号室」、`activeParkingContracts` から「マンション名 / 駐車場番号」を表示。

- [ ] **Step 4: application.blade.php**

`docs/mockups/mansion/tenants/application.html` を変換。ドラッグ&ドロップ + ファイル選択 + アップロード済み一覧。Attachment モデルを `attachable_type='App\Models\MsTenant'` で利用。

### Task E4: Phase E 検証 + コミット

- [ ] **Step 1: ブラウザ確認**

入居者一覧→新規登録（resident と parking_only 両方）→詳細→編集→削除→入居申込書アップロード。

- [ ] **Step 2: コミット**

```bash
git add app/Http/Controllers/Mansion/TenantController.php resources/views/mansion/tenants/ routes/web.php
git commit -m "賃貸マンション管理: 入居者CRUD + 入居申込書アップロード（Phase E）"
```

---

## Phase F: 部屋契約 + 賃料改定 + 解約

### Task F1: ルート定義

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: 部屋契約ルート + Ajax 追加**

```php
Route::get('/contracts', [\App\Http\Controllers\Mansion\ContractController::class, 'index'])
    ->name('mansion.contracts.index');
Route::get('/contracts/{contract}', [\App\Http\Controllers\Mansion\ContractController::class, 'show'])
    ->name('mansion.contracts.show');
Route::middleware('role:executive,manager')->group(function () {
    Route::get('/contracts/create', [\App\Http\Controllers\Mansion\ContractController::class, 'create'])
        ->name('mansion.contracts.create');
    Route::post('/contracts', [\App\Http\Controllers\Mansion\ContractController::class, 'store'])
        ->name('mansion.contracts.store');
    Route::get('/contracts/{contract}/edit', [\App\Http\Controllers\Mansion\ContractController::class, 'edit'])
        ->name('mansion.contracts.edit');
    Route::put('/contracts/{contract}', [\App\Http\Controllers\Mansion\ContractController::class, 'update'])
        ->name('mansion.contracts.update');
    // 賃料改定
    Route::get('/contracts/{contract}/revise', [\App\Http\Controllers\Mansion\ContractController::class, 'showRevise'])
        ->name('mansion.contracts.revise.show');
    Route::post('/contracts/{contract}/revise', [\App\Http\Controllers\Mansion\ContractController::class, 'revise'])
        ->name('mansion.contracts.revise');
    // 解約
    Route::get('/contracts/{contract}/terminate', [\App\Http\Controllers\Mansion\ContractController::class, 'showTerminate'])
        ->name('mansion.contracts.terminate.show');
    Route::put('/contracts/{contract}/terminate', [\App\Http\Controllers\Mansion\ContractController::class, 'terminate'])
        ->name('mansion.contracts.terminate');
});

// Ajax
Route::get('/api/mansion/properties/{property}/vacant-rooms', [\App\Http\Controllers\Mansion\ContractController::class, 'vacantRooms'])
    ->name('api.mansion.vacant-rooms');
Route::get('/api/mansion/properties/{property}/vacant-parkings', [\App\Http\Controllers\Mansion\ContractController::class, 'vacantParkings'])
    ->name('api.mansion.vacant-parkings');
```

### Task F2: ContractController

**Files:**
- Create: `app/Http/Controllers/Mansion/ContractController.php`

- [ ] **Step 1: 一覧 + 詳細**

```php
<?php

namespace App\Http\Controllers\Mansion;

use App\Enums\MsContractStatus;
use App\Enums\MsRoomStatus;
use App\Enums\MsParkingStatus;
use App\Http\Controllers\Controller;
use App\Models\MsContract;
use App\Models\MsContractRevision;
use App\Models\MsParkingContract;
use App\Models\MsProperty;
use App\Models\MsRoom;
use App\Models\MsParking;
use App\Models\MsTenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ContractController extends Controller
{
    public function index(Request $request)
    {
        $query = MsContract::with(['room.property', 'tenant', 'parkingContracts']);
        if ($request->filled('property_id')) {
            $query->whereHas('room', fn($q) => $q->where('property_id', $request->property_id));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('fiscal_year')) {
            // 5月始まり
            $start = "{$request->fiscal_year}-05-01";
            $end = ($request->fiscal_year + 1) . "-04-30";
            $query->whereBetween('contract_date', [$start, $end]);
        }
        $contracts = $query->orderByDesc('contract_date')->paginate(20)->withQueryString();
        return view('mansion.contracts.index', [
            'contracts' => $contracts,
            'properties' => MsProperty::orderBy('property_code')->get(),
        ]);
    }

    public function show(MsContract $contract)
    {
        $contract->load(['room.property', 'tenant', 'staff', 'parkingContracts.parking', 'revisions']);
        return view('mansion.contracts.show', compact('contract'));
    }
}
```

- [ ] **Step 2: 登録（物件→空室連動 + 駐車場紐付け）**

```php
public function create(Request $request)
{
    return view('mansion.contracts.create', [
        'properties' => MsProperty::orderBy('property_code')->get(),
        'tenants' => MsTenant::where('tenant_type', 'resident')->orderBy('name')->get(),
        'staffUsers' => User::orderBy('name')->get(),
        'preselectedRoomId' => $request->room_id,
    ]);
}

public function store(Request $request)
{
    $validated = $this->validateInput($request);
    $validated['status'] = 'active';
    $validated['created_by'] = Auth::id();

    $parkingIds = $request->input('parking_ids', []);

    DB::transaction(function () use ($validated, $parkingIds, &$contract) {
        $contract = MsContract::create($validated);
        // 部屋ステータスを入居中に
        MsRoom::where('id', $validated['room_id'])
            ->update(['status' => MsRoomStatus::Occupied->value]);
        // 駐車場紐付け
        foreach ($parkingIds as $parkingId) {
            $parking = MsParking::findOrFail($parkingId);
            MsParkingContract::create([
                'parking_id' => $parking->id,
                'tenant_id' => $contract->tenant_id,
                'contract_id' => $contract->id,
                'status' => 'active',
                'contract_date' => $contract->contract_date,
                'start_date' => $contract->move_in_date,
                'monthly_fee' => $parking->monthly_fee,
                'staff_user_id' => $contract->staff_user_id,
                'created_by' => Auth::id(),
            ]);
            $parking->update(['status' => MsParkingStatus::Occupied->value]);
        }
    });

    return redirect()->route('mansion.contracts.show', $contract)
        ->with('success', '部屋契約を登録しました');
}
```

- [ ] **Step 3: 編集 + 更新**

```php
public function edit(MsContract $contract)
{
    if ($contract->isTerminated()) {
        return redirect()->route('mansion.contracts.show', $contract)
            ->with('error', '解約済みの契約は編集できません');
    }
    return view('mansion.contracts.edit', [
        'contract' => $contract,
        'tenants' => MsTenant::where('tenant_type', 'resident')->orderBy('name')->get(),
        'staffUsers' => User::orderBy('name')->get(),
    ]);
}

public function update(Request $request, MsContract $contract)
{
    if ($contract->isTerminated()) {
        return back()->with('error', '解約済みの契約は編集できません');
    }
    $validated = $this->validateInput($request, true);
    $validated['updated_by'] = Auth::id();
    $contract->update($validated);
    return redirect()->route('mansion.contracts.show', $contract)
        ->with('success', '契約を更新しました');
}
```

- [ ] **Step 4: 賃料改定**

```php
public function showRevise(MsContract $contract)
{
    if ($contract->isTerminated()) abort(403);
    return view('mansion.contracts.revise', compact('contract'));
}

public function revise(Request $request, MsContract $contract)
{
    if ($contract->isTerminated()) abort(403);
    $validated = $request->validate([
        'revision_date' => 'required|date',
        'new_rent' => 'nullable|integer|min:0',
        'new_common_fee' => 'nullable|integer|min:0',
        'reason' => 'nullable|string|max:200',
    ]);

    DB::transaction(function () use ($validated, $contract) {
        MsContractRevision::create([
            'contract_id' => $contract->id,
            'revision_date' => $validated['revision_date'],
            'new_rent' => $validated['new_rent'] ?? $contract->rent,
            'new_common_fee' => $validated['new_common_fee'] ?? $contract->common_fee,
            'reason' => $validated['reason'] ?? null,
            'created_by' => Auth::id(),
        ]);
        $contract->update([
            'rent' => $validated['new_rent'] ?? $contract->rent,
            'common_fee' => $validated['new_common_fee'] ?? $contract->common_fee,
            'updated_by' => Auth::id(),
        ]);
    });

    return redirect()->route('mansion.contracts.show', $contract)
        ->with('success', '賃料を改定しました');
}
```

- [ ] **Step 5: 解約処理**

```php
public function showTerminate(MsContract $contract)
{
    if ($contract->isTerminated()) abort(403);
    $contract->load('parkingContracts.parking');
    return view('mansion.contracts.terminate', compact('contract'));
}

public function terminate(Request $request, MsContract $contract)
{
    if ($contract->isTerminated()) abort(403);
    $validated = $request->validate([
        'move_out_date' => 'required|date',
        'terminate_parkings' => 'nullable|array',
    ]);

    DB::transaction(function () use ($validated, $contract) {
        $contract->update([
            'status' => MsContractStatus::Terminated->value,
            'move_out_date' => $validated['move_out_date'],
            'updated_by' => Auth::id(),
        ]);
        $contract->room->update(['status' => MsRoomStatus::Vacant->value]);

        // 紐付く駐車場契約の一括解約（チェックされたもののみ）
        $parkingIdsToTerminate = $validated['terminate_parkings'] ?? [];
        foreach ($contract->parkingContracts as $pc) {
            if (in_array($pc->id, $parkingIdsToTerminate)) {
                $pc->update([
                    'status' => MsContractStatus::Terminated->value,
                    'end_date' => $validated['move_out_date'],
                    'updated_by' => Auth::id(),
                ]);
                $pc->parking->update(['status' => MsParkingStatus::Vacant->value]);
            }
        }
    });

    return redirect()->route('mansion.contracts.show', $contract)
        ->with('success', '契約を解約しました');
}
```

- [ ] **Step 6: Ajax (vacantRooms / vacantParkings)**

```php
public function vacantRooms(MsProperty $property)
{
    $rooms = $property->rooms()
        ->whereIn('status', ['vacant', 'negotiating'])
        ->orderBy('room_number')
        ->get(['id', 'room_number', 'room_type', 'rent', 'common_fee', 'deposit', 'key_money', 'status']);
    return response()->json($rooms);
}

public function vacantParkings(MsProperty $property)
{
    $parkings = $property->parkings()
        ->where('status', 'vacant')
        ->orderBy('parking_number')
        ->get(['id', 'parking_number', 'monthly_fee', 'has_roof']);
    return response()->json($parkings);
}
```

- [ ] **Step 7: バリデーション共通化**

```php
private function validateInput(Request $request, bool $skipRoomTenant = false): array
{
    $rules = [
        'contract_date' => 'nullable|date',
        'move_in_date' => 'nullable|date',
        'rent' => 'nullable|integer|min:0',
        'common_fee' => 'nullable|integer|min:0',
        'deposit' => 'nullable|integer|min:0',
        'key_money' => 'nullable|integer|min:0',
        'staff_user_id' => 'nullable|exists:users,id',
        'memo' => 'nullable|string',
    ];
    if (!$skipRoomTenant) {
        $rules['room_id'] = 'required|exists:ms_rooms,id';
        $rules['tenant_id'] = 'required|exists:ms_tenants,id';
    } else {
        $rules['tenant_id'] = 'required|exists:ms_tenants,id';
    }
    return $request->validate($rules);
}
```

### Task F3: 部屋契約 Blade ビュー

**Files:**
- Create: `resources/views/mansion/contracts/index.blade.php`
- Create: `resources/views/mansion/contracts/create.blade.php`
- Create: `resources/views/mansion/contracts/edit.blade.php`
- Create: `resources/views/mansion/contracts/show.blade.php`
- Create: `resources/views/mansion/contracts/revise.blade.php`
- Create: `resources/views/mansion/contracts/terminate.blade.php`
- Create: `resources/views/mansion/contracts/_form.blade.php`

- [ ] **Step 1: index.blade.php**

`docs/mockups/mansion/contracts/index.html` を変換。フィルター: 物件・ステータス・年度。テーブル: 物件/号室・入居者・契約日・入居日・賃料・共益費・駐車場（紐付け台数）・ステータス・詳細。

- [ ] **Step 2: create.blade.php（物件→空室連動 + 駐車場チェックボックス）**

Alpine.js で物件選択時に Ajax で空室一覧と空き駐車場を取得。重要: アロー関数 `=>` は `x-data` 属性内で使わず `<script>` タグの named function で定義する（CLAUDE.md ルール）。

```html
<script>
function contractForm() {
    return {
        propertyId: '',
        rooms: [],
        parkings: [],
        selectedRoomId: '',
        selectedParkingIds: [],
        async loadRooms() {
            if (!this.propertyId) { this.rooms = []; this.parkings = []; return; }
            const res = await fetch(`/manage/public/api/mansion/properties/${this.propertyId}/vacant-rooms`);
            this.rooms = await res.json();
            const res2 = await fetch(`/manage/public/api/mansion/properties/${this.propertyId}/vacant-parkings`);
            this.parkings = await res2.json();
        }
    };
}
</script>

<div x-data="contractForm()" x-init="@if($preselectedRoomId) propertyId = '{{ optional(\App\Models\MsRoom::find($preselectedRoomId))->property_id }}'; loadRooms(); selectedRoomId = '{{ $preselectedRoomId }}' @endif">
    <select name="property_id" x-model="propertyId" @change="loadRooms()" class="form-input">...</select>
    <select name="room_id" x-model="selectedRoomId" class="form-input">
        <template x-for="room in rooms" :key="room.id">
            <option :value="room.id" x-text="`${room.room_number} (${room.room_type ?? ''})`"></option>
        </template>
    </select>
    <div>
        <label class="text-sm">駐車場の紐付け（任意・複数可）</label>
        <template x-for="p in parkings" :key="p.id">
            <label class="flex items-center gap-2">
                <input type="checkbox" name="parking_ids[]" :value="p.id" x-model="selectedParkingIds">
                <span x-text="`${p.parking_number} (${p.monthly_fee.toLocaleString()}円/月)`"></span>
            </label>
        </template>
    </div>
    <!-- 入居者・契約日・賃料等の通常入力 -->
</div>
```

- [ ] **Step 3: edit.blade.php**

部屋・駐車場連動は不要（編集時は変更不可）。賃料・共益費等の数値編集のみ。

- [ ] **Step 4: show.blade.php**

`docs/mockups/mansion/contracts/show.html` を変換。表示内容:
- 契約基本情報（物件/号室、入居者、契約日、入居日、賃料、共益費、敷金、礼金、担当者）
- 紐付く駐車場契約一覧（駐車場番号、月額料金、ステータス）
- 賃料改定履歴一覧（`$contract->revisions`）
- アクションボタン（active時のみ）: 編集 / 賃料改定 / 解約処理

- [ ] **Step 5: revise.blade.php**

`docs/mockups/mansion/contracts/revise.html` を変換。差分バッジ表示:
- 現在: 賃料 70,000円 / 共益費 5,000円
- 新賃料・新共益費入力欄
- 改定後の差分を Alpine で計算表示

- [ ] **Step 6: terminate.blade.php**

`docs/mockups/mansion/contracts/terminate.html` を変換。
- 退去日入力
- 紐付く駐車場契約一覧をチェックボックスで表示（デフォルトすべてチェック）
- 「以下の駐車場契約も同時に解約します」のメッセージ

### Task F4: Phase F 検証 + コミット

- [ ] **Step 1: ブラウザ確認シナリオ**

1. 部屋契約一覧表示
2. 新規登録: 物件選択→空室セレクト連動→空き駐車場が表示される→部屋・駐車場2台選択→保存
3. 部屋ステータスが「入居中」、駐車場2台が「使用中」に変わっていることを物件詳細で確認
4. 契約詳細で紐付く駐車場契約が2件表示
5. 賃料改定実行→`ms_contract_revisions` に履歴追加、契約の rent が更新される
6. 解約処理: 退去日入力、駐車場2件のうち1件のみチェックして解約→部屋＋チェックした駐車場のみ「空き」に戻る

- [ ] **Step 2: コミット**

```bash
git add app/Http/Controllers/Mansion/ContractController.php resources/views/mansion/contracts/ routes/web.php
git commit -m "賃貸マンション管理: 部屋契約CRUD + 賃料改定 + 解約処理（Phase F）"
```

---

## Phase G: 駐車場契約（単独契約 + 料金改定 + 解約）

### Task G1: ルート定義

**Files:**
- Modify: `routes/web.php`

- [ ] **Step 1: 駐車場契約ルート 7本 + 料金改定追加**

部屋契約と同パターンで `/parking-contracts` プレフィックス、Controller は `ParkingContractController`。

```php
Route::get('/parking-contracts', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'index'])
    ->name('mansion.parking-contracts.index');
Route::get('/parking-contracts/{parkingContract}', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'show'])
    ->name('mansion.parking-contracts.show');
Route::middleware('role:executive,manager')->group(function () {
    Route::get('/parking-contracts/create', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'create'])
        ->name('mansion.parking-contracts.create');
    Route::post('/parking-contracts', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'store'])
        ->name('mansion.parking-contracts.store');
    Route::get('/parking-contracts/{parkingContract}/edit', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'edit'])
        ->name('mansion.parking-contracts.edit');
    Route::put('/parking-contracts/{parkingContract}', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'update'])
        ->name('mansion.parking-contracts.update');
    Route::get('/parking-contracts/{parkingContract}/revise', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'showRevise'])
        ->name('mansion.parking-contracts.revise.show');
    Route::post('/parking-contracts/{parkingContract}/revise', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'revise'])
        ->name('mansion.parking-contracts.revise');
    Route::get('/parking-contracts/{parkingContract}/terminate', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'showTerminate'])
        ->name('mansion.parking-contracts.terminate.show');
    Route::put('/parking-contracts/{parkingContract}/terminate', [\App\Http\Controllers\Mansion\ParkingContractController::class, 'terminate'])
        ->name('mansion.parking-contracts.terminate');
});
```

### Task G2: ParkingContractController

**Files:**
- Create: `app/Http/Controllers/Mansion/ParkingContractController.php`

- [ ] **Step 1: 一覧 + 詳細**

```php
public function index(Request $request)
{
    $query = MsParkingContract::with(['parking.property', 'tenant', 'contract.room']);
    if ($request->filled('property_id')) {
        $query->whereHas('parking', fn($q) => $q->where('property_id', $request->property_id));
    }
    if ($request->filled('link_type')) {
        if ($request->link_type === 'linked') $query->whereNotNull('contract_id');
        if ($request->link_type === 'standalone') $query->whereNull('contract_id');
    }
    if ($request->filled('status')) $query->where('status', $request->status);
    $contracts = $query->orderByDesc('contract_date')->paginate(20)->withQueryString();
    return view('mansion.parking-contracts.index', [
        'contracts' => $contracts,
        'properties' => MsProperty::orderBy('property_code')->get(),
    ]);
}

public function show(MsParkingContract $parkingContract)
{
    $parkingContract->load(['parking.property', 'tenant', 'contract.room', 'staff', 'revisions']);
    return view('mansion.parking-contracts.show', compact('parkingContract'));
}
```

- [ ] **Step 2: 単独契約登録**

```php
public function create()
{
    return view('mansion.parking-contracts.create', [
        'properties' => MsProperty::orderBy('property_code')->get(),
        'tenants' => MsTenant::orderBy('name')->get(),  // resident と parking_only 両方
        'staffUsers' => User::orderBy('name')->get(),
    ]);
}

public function store(Request $request)
{
    $validated = $request->validate([
        'parking_id' => 'required|exists:ms_parkings,id',
        'tenant_id' => 'required|exists:ms_tenants,id',
        'contract_date' => 'nullable|date',
        'start_date' => 'nullable|date',
        'monthly_fee' => 'required|integer|min:0',
        'deposit' => 'nullable|integer|min:0',
        'staff_user_id' => 'nullable|exists:users,id',
        'memo' => 'nullable|string',
    ]);
    $validated['status'] = 'active';
    $validated['contract_id'] = null;  // 単独契約
    $validated['created_by'] = Auth::id();

    DB::transaction(function () use ($validated, &$parkingContract) {
        $parkingContract = MsParkingContract::create($validated);
        MsParking::where('id', $validated['parking_id'])
            ->update(['status' => MsParkingStatus::Occupied->value]);
    });

    return redirect()->route('mansion.parking-contracts.show', $parkingContract)
        ->with('success', '駐車場契約を登録しました');
}
```

- [ ] **Step 3: 編集 + 更新**

```php
public function edit(MsParkingContract $parkingContract)
{
    if ($parkingContract->isTerminated()) {
        return redirect()->route('mansion.parking-contracts.show', $parkingContract)
            ->with('error', '解約済みの契約は編集できません');
    }
    return view('mansion.parking-contracts.edit', [
        'parkingContract' => $parkingContract,
        'tenants' => MsTenant::orderBy('name')->get(),
        'staffUsers' => User::orderBy('name')->get(),
    ]);
}

public function update(Request $request, MsParkingContract $parkingContract)
{
    if ($parkingContract->isTerminated()) {
        return back()->with('error', '解約済みの契約は編集できません');
    }
    $validated = $request->validate([
        'tenant_id' => 'required|exists:ms_tenants,id',
        'contract_date' => 'nullable|date',
        'start_date' => 'nullable|date',
        'monthly_fee' => 'required|integer|min:0',
        'deposit' => 'nullable|integer|min:0',
        'staff_user_id' => 'nullable|exists:users,id',
        'memo' => 'nullable|string',
    ]);
    $validated['updated_by'] = Auth::id();
    $parkingContract->update($validated);
    return redirect()->route('mansion.parking-contracts.show', $parkingContract)
        ->with('success', '契約を更新しました');
}

public function showRevise(MsParkingContract $parkingContract)
{
    if ($parkingContract->isTerminated()) abort(403);
    return view('mansion.parking-contracts.revise', compact('parkingContract'));
}

public function showTerminate(MsParkingContract $parkingContract)
{
    if ($parkingContract->isTerminated()) abort(403);
    return view('mansion.parking-contracts.terminate', compact('parkingContract'));
}
```

- [ ] **Step 4: 料金改定**

```php
public function revise(Request $request, MsParkingContract $parkingContract)
{
    if ($parkingContract->isTerminated()) abort(403);
    $validated = $request->validate([
        'revision_date' => 'required|date',
        'new_monthly_fee' => 'required|integer|min:0',
        'reason' => 'nullable|string|max:200',
    ]);

    DB::transaction(function () use ($validated, $parkingContract) {
        MsParkingContractRevision::create([
            'parking_contract_id' => $parkingContract->id,
            'revision_date' => $validated['revision_date'],
            'new_monthly_fee' => $validated['new_monthly_fee'],
            'reason' => $validated['reason'] ?? null,
            'created_by' => Auth::id(),
        ]);
        $parkingContract->update([
            'monthly_fee' => $validated['new_monthly_fee'],
            'updated_by' => Auth::id(),
        ]);
    });
    return redirect()->route('mansion.parking-contracts.show', $parkingContract)
        ->with('success', '料金を改定しました');
}
```

- [ ] **Step 5: 解約処理**

```php
public function terminate(Request $request, MsParkingContract $parkingContract)
{
    if ($parkingContract->isTerminated()) abort(403);
    $validated = $request->validate(['end_date' => 'required|date']);

    DB::transaction(function () use ($validated, $parkingContract) {
        $parkingContract->update([
            'status' => MsContractStatus::Terminated->value,
            'end_date' => $validated['end_date'],
            'updated_by' => Auth::id(),
        ]);
        $parkingContract->parking->update(['status' => MsParkingStatus::Vacant->value]);
    });
    return redirect()->route('mansion.parking-contracts.show', $parkingContract)
        ->with('success', '駐車場契約を解約しました');
}
```

### Task G3: 駐車場契約 Blade ビュー

**Files:**
- Create: `resources/views/mansion/parking-contracts/index.blade.php`
- Create: `resources/views/mansion/parking-contracts/create.blade.php`
- Create: `resources/views/mansion/parking-contracts/edit.blade.php`
- Create: `resources/views/mansion/parking-contracts/show.blade.php`
- Create: `resources/views/mansion/parking-contracts/revise.blade.php`
- Create: `resources/views/mansion/parking-contracts/terminate.blade.php`
- Create: `resources/views/mansion/parking-contracts/_form.blade.php`

- [ ] **Step 1: 全6本**

`docs/mockups/mansion/parking-contracts/*.html` を順次変換。Phase F の部屋契約 Blade を参考にしつつ:
- index.blade.php: フィルターに「紐付け区分」追加（全て / 部屋契約と連動 / 外部単独）
- create.blade.php: 物件選択→空き駐車場連動 Ajax、利用者は resident + parking_only 両方から選択可
- show.blade.php: 紐付け表示（`$parkingContract->contract` があれば「部屋N号室」）
- revise.blade.php / terminate.blade.php: 部屋契約と同パターン

### Task G4: Phase G 検証 + コミット

- [ ] **Step 1: ブラウザ確認シナリオ**

1. 駐車場契約一覧表示
2. 新規単独契約登録: 物件選択→空き駐車場セレクト→parking_only の利用者選択→保存
3. 駐車場が「使用中」になることを物件詳細で確認
4. 料金改定→履歴追加、monthly_fee 更新
5. 解約→駐車場「空き」に戻る

- [ ] **Step 2: コミット**

```bash
git add app/Http/Controllers/Mansion/ParkingContractController.php resources/views/mansion/parking-contracts/ routes/web.php
git commit -m "賃貸マンション管理: 駐車場契約CRUD + 料金改定 + 解約処理（Phase G）"
```

---

## Phase H: ダッシュボード

### Task H1: DashboardController

**Files:**
- Create: `app/Http/Controllers/Mansion/DashboardController.php`

- [ ] **Step 1: ダッシュボード集計**

```php
<?php

namespace App\Http\Controllers\Mansion;

use App\Enums\MsRoomStatus;
use App\Enums\MsParkingStatus;
use App\Http\Controllers\Controller;
use App\Models\MsContract;
use App\Models\MsParkingContract;
use App\Models\MsProperty;
use App\Models\MsRoom;
use App\Models\MsParking;

class DashboardController extends Controller
{
    public function index()
    {
        // 部屋KPI
        $totalRooms = MsRoom::count();
        $occupiedRooms = MsRoom::where('status', MsRoomStatus::Occupied->value)->count();
        $vacantRooms = MsRoom::where('status', MsRoomStatus::Vacant->value)->count();
        $negotiatingRooms = MsRoom::where('status', MsRoomStatus::Negotiating->value)->count();
        $moveOutPlanned = MsRoom::where('status', MsRoomStatus::MoveOutPlanned->value)->count();
        $occupancyRate = $totalRooms > 0 ? round($occupiedRooms / $totalRooms * 100, 1) : 0;

        // 物件別稼働状況
        $properties = MsProperty::with('rooms')->orderBy('property_code')->get()->map(function ($p) {
            $total = $p->rooms->count();
            $occupied = $p->rooms->where('status', MsRoomStatus::Occupied)->count();
            $p->occupancy = $total > 0 ? round($occupied / $total * 100, 1) : 0;
            return $p;
        });

        // 空室一覧
        $vacantList = MsRoom::with('property')
            ->whereIn('status', [MsRoomStatus::Vacant->value, MsRoomStatus::Negotiating->value])
            ->orderBy('property_id')
            ->orderBy('room_number')
            ->get();

        // 空き駐車場一覧
        $vacantParkings = MsParking::with('property')
            ->where('status', MsParkingStatus::Vacant->value)
            ->orderBy('property_id')
            ->orderBy('parking_number')
            ->get();

        return view('mansion.dashboard', compact(
            'totalRooms', 'occupiedRooms', 'vacantRooms', 'negotiatingRooms',
            'moveOutPlanned', 'occupancyRate', 'properties', 'vacantList', 'vacantParkings'
        ));
    }
}
```

### Task H2: ダッシュボード Blade

**Files:**
- Create: `resources/views/mansion/dashboard.blade.php`

- [ ] **Step 1: ダッシュボード変換**

`docs/mockups/mansion/dashboard.html` を Blade 変換。構成:
1. 部屋 KPI 5枚（総戸数・入居中・空室・申込み・退去予定）
2. 入居率カード（大きく表示）
3. 物件別稼働状況テーブル
4. 空室カード一覧（左カラム）
5. 空き駐車場カード一覧（右カラム）

### Task H3: Phase H 検証 + コミット

- [ ] **Step 1: ブラウザ確認**

`/manage/public/mansion/dashboard` で全データが集計表示される。物件・部屋・契約・駐車場のいずれかを変更すると数値が連動することを確認。

- [ ] **Step 2: コミット**

```bash
git add app/Http/Controllers/Mansion/DashboardController.php resources/views/mansion/dashboard.blade.php
git commit -m "賃貸マンション管理: ダッシュボード実装（Phase H）"
```

---

## Phase I: 30点品質監査 + デプロイ

### Task I1: 品質チェックリスト

- [ ] CSS: 新規 Tailwind クラスを追加していない（Vite未ビルドのクラスはインライン化）
- [ ] Alpine: `x-data` 内に `=>` を含めていない（`<script>` 名前付き関数で分離）
- [ ] Alpine: 同一要素に `style=` と `:style=` を併用していない
- [ ] Blade: `@if/@else/@endif` が複数行
- [ ] Blade: `@json()` 内に PHP 関数を入れていない
- [ ] フォーム: 金額入力に `value="0"` を入れていない（空文字）
- [ ] フォーム: 同名 input の重複（`x-show` 内含む）がない
- [ ] 金額表示: `28,500,000円` 形式（`¥` 接頭辞は使わない）
- [ ] バッジ: インラインスタイル（`badgeStyle()`）使用
- [ ] 担当者表示: 苗字のみ
- [ ] 都道府県デフォルト: 愛媛県
- [ ] フィルター: `onchange` 即時送信
- [ ] ページネーション: 20件/ページ
- [ ] サイドバー: 3パターン（PC展開・PC折りたたみ・モバイル）すべて追記済み
- [ ] ルート: `/properties/create` が `/properties/{property}` より先に定義
- [ ] User クエリで `whereNull('deleted_at')` を使っていない
- [ ] 部屋契約: 物件→空室連動 Ajax 動作
- [ ] 駐車場契約: 物件→空き駐車場連動 Ajax 動作
- [ ] 部屋契約保存時: 部屋ステータス自動更新（→入居中）
- [ ] 駐車場契約保存時: 駐車場ステータス自動更新（→使用中）
- [ ] 部屋契約解約時: 紐付く駐車場契約を選択的に一括解約できる
- [ ] 賃料改定: 履歴テーブルに記録 + 契約の rent/common_fee 更新
- [ ] 駐車場料金改定: 履歴テーブルに記録 + 契約の monthly_fee 更新
- [ ] DB transaction で原子性確保（store, terminate, revise）
- [ ] ロールミドルウェア: 編集系は manager 以上、削除は executive のみ
- [ ] ms_tenants の tenant_type フィルター: resident と parking_only で分離可
- [ ] 入居申込書アップロード: Attachment ポリモーフィックで MsTenant に紐付け
- [ ] 物件詳細: 駐車場一覧表示
- [ ] 入居者詳細: 紐付く部屋契約 + 駐車場契約の両方表示
- [ ] ダッシュボード: 全数値が DB と一致

### Task I2: 不具合修正

- [ ] **Step 1:** チェックリストで NG が出た項目を1件ずつ修正

各修正ごとにブラウザ再確認 → コミット。

### Task I3: 最終コミット + PR

- [ ] **Step 1: 動作テストデータ削除（必要な場合）**

```sql
-- 必要に応じて
DELETE FROM ms_parking_contract_revisions;
DELETE FROM ms_contract_revisions;
DELETE FROM ms_parking_contracts;
DELETE FROM ms_contracts;
DELETE FROM ms_parkings;
DELETE FROM ms_rooms;
DELETE FROM ms_tenants;
DELETE FROM ms_properties;
```

- [ ] **Step 2: BACKLOG.md 更新**

`docs/BACKLOG.md` 「優先度1: 賃貸マンション管理」セクションを完了マークに変更。

- [ ] **Step 3: PR 作成**

```bash
git push -u origin feature/manage-system
gh pr create --title "賃貸マンション管理 Phase 2: Laravel 実装（テーブル8 + Controller6 + ルート43）" --body "$(cat <<'EOF'
## Summary
- 賃貸マンション管理モジュールを実装（要件定義書 v2 準拠）
- 物件・部屋・駐車場・入居者・部屋契約・駐車場契約・賃料改定・料金改定・解約処理・ダッシュボード

## Test plan
- [ ] 物件 CRUD
- [ ] 部屋 CRUD（物件配下、UNIQUE 制約）
- [ ] 駐車場 CRUD（物件配下、UNIQUE 制約）
- [ ] 入居者 CRUD（resident / parking_only 両区分）
- [ ] 入居申込書アップロード
- [ ] 部屋契約: 物件選択→空室セレクト連動 Ajax
- [ ] 部屋契約保存時に部屋ステータス自動「入居中」
- [ ] 駐車場紐付け（複数）+ 駐車場ステータス自動「使用中」
- [ ] 賃料改定: 履歴記録 + 契約更新
- [ ] 解約: 部屋ステータス「空室」 + 紐付く駐車場の選択的一括解約
- [ ] 駐車場契約: 単独契約 / 部屋連動契約の両方
- [ ] 駐車場料金改定 / 解約
- [ ] ダッシュボード: KPI / 物件別稼働 / 空室一覧 / 空き駐車場一覧

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## 実装ノート

**作業順序の依存関係:**
- Phase A は他すべての前提（DB がないと動かない）
- Phase B は Phase C, D, F の前提（物件がないと部屋・駐車場・契約が作れない）
- Phase E は Phase F, G の前提（入居者がないと契約が作れない）
- Phase F → Phase G の順で進めると Ajax / 改定 / 解約パターンが流用できる
- Phase H は最後（ダッシュボードは全データが揃ってから集計）

**コミット粒度:**
- 各 Phase 末でコミット
- Phase 内でも論理的区切りで小コミット可（Controller 完成 → ビュー完成 → 検証）

**既存パターン参照:**
- Tenant モジュール: `app/Http/Controllers/Tenant/ContractController.php`（賃料改定・解約パターン）
- Tenant モジュール: `app/Http/Controllers/Tenant/UnitController.php`（vacantUnits Ajax）
- Tenant モジュール: `resources/views/tenant/contracts/{revise,terminate}.blade.php`（差分バッジ UI）
- Tenant モジュール: `resources/views/tenant/properties/_form.blade.php`（zipcloud API）

**Vite ビルドの制約:**
- 新規 Tailwind クラスは反映されない（要 build。CLI なしで build 不可）
- `style="..."` または `<style>` で凌ぐ
- 既存ビルド済みクラスのリストは `docs/RULES.md` 参照
