<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 周辺ビル（恒久情報）。
 *
 * ⚠ 論理削除。調査回とテナントは FK ON DELETE CASCADE だが、SoftDeletes ではビル行が
 *   残るので子は消えない（復元可能にするための意図どおりの挙動。設計 §8）。
 */
class AreaBuilding extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'address',
        'latitude',
        'longitude',
        'total_floors',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'latitude'     => 'decimal:7',
            'longitude'    => 'decimal:7',
            'total_floors' => 'integer',
        ];
    }

    /**
     * ⚠ 空白だけの住所は null に寄せる。読み取り側（pendingGeocodeQuery）で弾くのではなく
     *   書き込み側で正規化する（読み取りで隠すと DB に嘘の値が残り続ける。Bug #38）。
     *   ⚠ 全角スペース（U+3000）は MySQL の PAD SPACE 照合でも '' と等しくならないため、
     *     クエリ側の <> '' では本番でも取りこぼす（実測）。
     */
    protected static function booted(): void
    {
        static::saving(function (AreaBuilding $building): void {
            if ($building->address !== null && self::normalizeName($building->address) === '') {
                $building->address = null;
            }

            // ⚠ 緯度・経度は必ず対で持つ。片方だけの行は
            //   ①`hasCoordinates()` が false ＝ 地図リンクも出ない
            //   ②`pendingGeocodeQuery()` は latitude だけを見るので
            //     「経度だけある行」は一括取得の対象に入り、手入力の経度が上書きされる／
            //     「緯度だけある行」は対象にも入らない**詰み行**になる
            //   という非対称を生む。読み取り側で隠さず**書き込み側で正規化**する（Bug #38）。
            if ($building->latitude === null || $building->longitude === null) {
                $building->latitude  = null;
                $building->longitude = null;
            }
        });
    }

    // ============================================================
    // リレーション
    // ============================================================

    public function surveys(): HasMany
    {
        return $this->hasMany(AreaBuildingSurvey::class, 'area_building_id');
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(AreaBuildingTenant::class, 'area_building_id');
    }

    /** 現況の入居テナント（退去済みを除く） */
    public function activeTenants(): HasMany
    {
        return $this->tenants()->whereNull('moved_out_on');
    }

    /** ⚠ User は SoftDeletes（app/Models/User.php:16）。退職者が消えないよう withTrashed */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    // ============================================================
    // 表示ヘルパー
    // ============================================================

    /**
     * ⚠ N+1 に注意: 一覧で行ごとに呼ぶと 1+N クエリになる。一覧では使わず、
     *   相関サブクエリで最新調査回を引くこと（設計 §5.3 / Task 6 の AreaBuildingListService）。
     *   詳細画面など単発呼び出し専用。
     */
    public function latestSurvey(): ?AreaBuildingSurvey
    {
        return $this->surveys()->orderByDesc('surveyed_month')->orderByDesc('id')->first();
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * 別タブで開く Google マップの URL。
     * ⚠ 詳細画面に埋め込み地図を置かず、このリンクで済ませる（課金ゼロ。設計 §6.0）。
     */
    public function googleMapsUrl(): ?string
    {
        if (! $this->hasCoordinates()) {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query=' . $this->latitude . ',' . $this->longitude;
    }

    public function totalFloorsLabel(): string
    {
        return $this->total_floors === null ? '—' : $this->total_floors . '階';
    }

    /**
     * 一覧に出す座標の有無バッジ（設計 §7.4）。
     *
     * ⚠ 一括取得で失敗した棟を**一覧から特定できる**ようにするためのもの。印が無いと、
     *   恒久的にジオコードできない住所をボタンが拾い続け、押すたびに再課金する。
     * ⚠ バッジは Tailwind クラスでなく inline style を返す（プロジェクト規約）。
     *
     * @return array{label: string, style: string}
     */
    public function coordinateBadge(): array
    {
        return $this->hasCoordinates()
            ? ['label' => '取得済', 'style' => 'background:#d1fae5; color:#065f46; border:1px solid #a7f3d0;']
            : ['label' => '未取得', 'style' => 'background:#fef3c7; color:#92400e; border:1px solid #fde68a;'];
    }

    // ============================================================
    // Excel 取込 / 座標一括取得
    // ============================================================

    /**
     * Excel 取込のビル名突合キー。
     *
     * 前後の空白を落とし、全角空白（U+3000）は半角に、連続空白は 1 個に潰す。
     * ⚠ 内部の空白まで消さないこと。「ミツワ ビル」と「ミツワビル」を同一視すると、
     *   別のビルの調査回を誤って同じビルにぶら下げる。重複して登録されるほうがまだ直せる。
     */
    public static function normalizeName(?string $name): string
    {
        // ⚠ /u は PCRE2_UCP も立てるので、下の \s+ だけで U+3000 も半角空白に潰せる
        //   （PHP 8.3 / PCRE 10.47 で実測）。この str_replace は冗長だが、
        //   UCP 無効なビルドでも同じ挙動になるよう残している。
        $s = str_replace("\u{3000}", ' ', (string) $name);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * 座標未取得のビル（住所があるものだけ）。
     *
     * ⚠ latitude IS NULL に限定するのが二重課金の防止そのもの。何度実行しても
     *   未設定分しか Google に投げない（設計 §7.4）。住所が空の行は最初から対象外。
     *
     * @return Collection<int, static>
     */
    public static function pendingGeocode(int $limit): Collection
    {
        return static::pendingGeocodeQuery()
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'address']);
    }

    public static function pendingGeocodeCount(): int
    {
        return static::pendingGeocodeQuery()->count();
    }

    private static function pendingGeocodeQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return static::query()
            ->whereNull('latitude')
            ->whereNotNull('address')
            ->where('address', '<>', '');
    }
}
