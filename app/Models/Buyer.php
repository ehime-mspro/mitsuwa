<?php

namespace App\Models;

use App\Enums\BuyerRank;
use App\Support\JapanTime;
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
     * 住宅事業の契約・注文住宅の画面の買主の選択肢（住宅事業の買主＋今の買主〈削除済み・住宅事業にいなくても残す〉）。
     * 登録は今の買主が無いので住宅事業の買主だけ。
     *
     * 画面（建売の契約の登録・編集・契約一覧から開く建売と注文住宅の契約の編集・注文住宅の登録と編集）と、その保存の入力チェック
     * （Rule::in）が同じ集合を使う＝手で番号を書き換えた送信で、選択肢に無い買主（ほかの部署・削除済み）を紐付けさせない
     * （紐付くと契約で住宅事業に足され、住宅事業の画面に名前と連絡先が出る。docs/RULES.md Bug #144・#149）。
     * 今の買主を残すのは、保存で黙って別の人に変えないため（Bug #58・#113 と同じ形）。
     *
     * @return \Illuminate\Support\Collection<int, Buyer>
     */
    public static function selectableForHousing(?int $currentBuyerId = null): \Illuminate\Support\Collection
    {
        $buyers = static::ofDepartment('housing')->orderBy('last_name_kana')->get();
        if ($currentBuyerId !== null && ! $buyers->contains('id', $currentBuyerId)) {
            $current = static::withTrashed()->find($currentBuyerId);
            if ($current) {
                $buyers->push($current);
            }
        }

        return $buyers->toBase();
    }

    /**
     * 不動産の契約の画面の買主の選択肢（削除していない買主〈部署で絞らない〉＋今の買主〈削除済みでも先頭に残す〉）。
     * 登録は今の買主が無いので、削除していない買主だけ。
     *
     * 画面（契約の登録・編集）と、その保存の入力チェック（Rule::in）が同じ集合を使う＝手で番号を書き換えた送信で、
     * 今の買主でない削除済みの買主を紐付けさせない（`exists:buyers,id` は論理削除を見ない。紐付くと契約の保存のフックが
     * その人を不動産の部署で「成約」にする。docs/RULES.md Bug #149 の追記）。
     * 今の買主を残すのは、保存で黙って別の人に変えないため（Bug #58・#113 と同じ形）。
     * ⚠ 列を絞らない（画面が trashed() で「（削除済み）」を出す。Bug #58）。
     *
     * @return \Illuminate\Support\Collection<int, Buyer>
     */
    public static function selectableForRealEstateContract(?int $currentBuyerId = null): \Illuminate\Support\Collection
    {
        $buyers = static::orderBy('last_name_kana')->orderBy('first_name_kana')->get();
        if ($currentBuyerId !== null && ! $buyers->contains('id', $currentBuyerId)) {
            $current = static::withTrashed()->find($currentBuyerId);
            if ($current) {
                $buyers->prepend($current);
            }
        }

        return $buyers->toBase();
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
     *
     * ⚠ 新規作成の分岐は SELECT → INSERT なので、同一買主・同一部署の「初回」保存が
     *    真に同時に走ると、負けた側が uq_buyer_department の重複キーで QueryException になる。
     *    **意図的に握り潰していない**——データは UNIQUE 制約が守り、再送すれば
     *    (行が既に在るので) update 分岐に入って成功する。社内ツールで同一買主の初回契約が
     *    同時に 2 本入る確率は極めて低く、ここに catch を足すとテストで固定できない分岐が
     *    増えるほうが害が大きいと判断した (Bug #45)。頻発するようなら重複キーを
     *    catch して update へフォールバックすること。
     */
    public function markContracted(string $department, ?string $acquiredDate = null): void
    {
        $pivot = $this->getDepartmentPivot($department);

        if ($pivot === null) {
            $this->addToDepartment(
                $department,
                $acquiredDate ?: JapanTime::today()->toDateString(),
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
