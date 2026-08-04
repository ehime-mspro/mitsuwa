<?php

namespace App\Models;

use App\Enums\BuyerRank;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Buyer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'last_name',
        'first_name',
        'last_name_kana',
        'first_name_kana',
        'birth_date',
        'birth_era',
        'family_adults',
        'family_children',
        'postal_code',
        'prefecture',
        'city',
        'address_detail',
        'building_name',
        'phone',
        'email',
        'occupation',
        'employer',
        'years_employed',
        'memo',
    ];

    protected $casts = [
        'birth_date'      => 'date',
        'family_adults'   => 'integer',
        'family_children' => 'integer',
        'years_employed'  => 'integer',
    ];

    /* ========== リレーション ========== */

    public function departments()
    {
        return $this->hasMany(BuyerDepartmentPivot::class, 'buyer_id');
    }

    public function surveys()
    {
        return $this->hasMany(BuyerSurvey::class, 'buyer_id');
    }

    /* ========== アクセサ ========== */

    public function getFullNameAttribute(): string
    {
        return $this->last_name . ' ' . $this->first_name;
    }

    public function getFullNameKanaAttribute(): string
    {
        $kana = trim(($this->last_name_kana ?? '') . ' ' . ($this->first_name_kana ?? ''));
        return $kana ?: '';
    }

    public function getFullAddressAttribute(): string
    {
        $parts = [];
        if ($this->postal_code) {
            $parts[] = '〒' . $this->postal_code;
        }
        if ($this->prefecture) {
            $parts[] = $this->prefecture;
        }
        if ($this->city) {
            $parts[] = $this->city;
        }
        if ($this->address_detail) {
            $parts[] = $this->address_detail;
        }
        if ($this->building_name) {
            $parts[] = $this->building_name;
        }
        return implode(' ', $parts);
    }

    public function getBirthDateDisplayAttribute(): string
    {
        if (!$this->birth_date) {
            return '';
        }
        $era = $this->birth_era ? $this->birth_era : '';
        $y = $this->birth_date->format('Y');
        $m = (int) $this->birth_date->format('m');
        $d = (int) $this->birth_date->format('d');

        // 元号年の計算
        $eraYear = $y;
        if ($era === 'S') {
            $eraYear = $y - 1925;
        } elseif ($era === 'H') {
            $eraYear = $y - 1988;
        } elseif ($era === 'R') {
            $eraYear = $y - 2018;
        }

        if ($era) {
            return "{$era}.{$eraYear}年 {$m}月 {$d}日";
        }
        return "{$y}年 {$m}月 {$d}日";
    }

    /* ========== スコープ ========== */

    /**
     * 指定部署に所属する顧客のみ
     */
    public function scopeOfDepartment($query, string $dept)
    {
        return $query->whereHas('departments', function ($q) use ($dept) {
            $q->where('department', $dept);
        });
    }

    /**
     * キーワード検索（氏名・フリガナ・電話番号）
     */
    public function scopeKeywordSearch($query, ?string $keyword)
    {
        if (!$keyword) {
            return $query;
        }
        $kw = '%' . $keyword . '%';
        return $query->where(function ($q) use ($kw) {
            $q->whereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", [$kw])
              ->orWhereRaw("CONCAT(last_name_kana, ' ', first_name_kana) LIKE ?", [$kw])
              ->orWhere('phone', 'like', $kw);
        });
    }

    /**
     * ランクフィルター（buyer_departments経由）
     */
    public function scopeOfRank($query, string $dept, $ranks)
    {
        if (!$ranks) {
            return $query;
        }
        if (!is_array($ranks)) {
            $ranks = [$ranks];
        }
        return $query->whereHas('departments', function ($q) use ($dept, $ranks) {
            $q->where('department', $dept)->whereIn('rank', $ranks);
        });
    }

    /* ========== メソッド ========== */

    /**
     * 指定部署に所属しているか判定
     */
    public function belongsToDepartment(string $dept): bool
    {
        return $this->departments()->where('department', $dept)->exists();
    }

    /**
     * 部署を追加
     */
    public function addToDepartment(string $dept, string $acquiredDate, string $rank = 'C'): BuyerDepartmentPivot
    {
        return $this->departments()->create([
            'department'    => $dept,
            'acquired_date' => $acquiredDate,
            'rank'          => $rank,
        ]);
    }

    /**
     * 該当部署のピボットレコードを取得
     */
    public function getDepartmentPivot(string $dept): ?BuyerDepartmentPivot
    {
        return $this->departments()->where('department', $dept)->first();
    }

    /**
     * 指定部署の顧客ランクを「成約」にする。
     *
     * - 対象部署の行が無ければ、その部署を成約ランクで追加する
     *   (取得日は $acquiredDate、無ければ当日。buyer_departments.acquired_date は NOT NULL)。
     * - 既にある行は rank だけ上書きする。A〜D だけでなく**他決・追客不可も対象**
     *   (契約したという事実が最も強いため。設計書 §4)。
     *
     * ⚠ 既存行の acquired_date は書き換えない。取得日は「いつ獲得した顧客か」という
     *    独立した実データで、契約日で潰すと獲得経路の履歴が失われる。
     *
     * ⚠ 既に contracted の行は UPDATE を出さずに抜ける。これは無駄な UPDATE を避けるだけで
     *    **挙動は同じ**なので、この早期 return を消しても回帰テストは緑のまま
     *    (＝テストで守られていない。消さないこと)。
     */
    public function markContracted(string $department, ?string $acquiredDate = null): void
    {
        $pivot = $this->getDepartmentPivot($department);

        if ($pivot === null) {
            $this->addToDepartment(
                $department,
                $acquiredDate ?: now()->toDateString(),
                BuyerRank::Contracted->value,
            );
            return;
        }

        if ($pivot->rank === BuyerRank::Contracted) {
            return;
        }

        $pivot->update(['rank' => BuyerRank::Contracted->value]);
    }
}
