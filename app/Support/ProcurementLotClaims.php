<?php

namespace App\Support;

use App\Enums\CustomOrderStatus;
use App\Enums\HousingLandSourceType;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;
use App\Models\ReContract;
use App\Models\ReProcurement;
use App\Models\ReProcurementLot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * 土地の仕入れ案件の区画が「取られている」かの判定（設計書 §5.3）。契約・建売・注文住宅のどの経路も
 * この 1 か所を使う（選択肢・入力チェック・ステータスの変更で判定を分けない。Bug #41）。
 *
 * 1 つの区画を取れるのは、不動産の契約・建売（契約の前から）・契約以降の注文住宅のうち 1 件だけ。
 * 見積り以前の注文住宅は同じ区画に何件付いてもよい（「契約」へ進むときに断る＝refusal() の $selfTakes）。
 *
 * ⚠ 取られているかは**紐付け**で決め、区画のステータスでは決めない（区画のステータスは区画の表で手で変えられ、
 *   紐付けと食い違うことがあるため。区画を選んでいない契約などがある土地に区画を足すときは、
 *   ProcurementLotLinks がそのうち 1 つを足す区画につなぐ＝Bug #146）。
 * ⚠ 「持ち主」（$current・$except）は ReContract・HsProperty・HsCustomOrder。列名の違い
 *   （契約は procurement_id / procurement_lot_id、住宅事業は re_procurement_id / re_procurement_lot_id）はこのクラスの中で吸収する。
 * ⚠ 持ち主の「今」は保存済みの値（getRawOriginal()）で読む。fill() しただけの値で「変えていない」とみなさない。
 */
final class ProcurementLotClaims
{
    /** 区画がほかに取られているときの断り（:taker は takerLabel() の形） */
    public const TAKEN = 'この区画（:number号地）は:takerに紐づいています。ほかの区画を選んでください。';

    /**
     * 指定した仕入れ案件の区画のうち、取られている区画の id（昇順）。$except 自身は数えない。
     *
     * @param  array<int, mixed>  $procurementIds
     * @return array<int, int>
     */
    public static function takenLotIds(array $procurementIds, ReContract|HsProperty|HsCustomOrder|null $except = null): array
    {
        $ids = array_values(array_filter(array_map(self::digits(...), $procurementIds), fn (?int $id) => $id !== null));
        if ($ids === []) {
            return [];
        }

        $lotIds = ReProcurementLot::whereIn('procurement_id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($lotIds === []) {
            return [];
        }

        [$contracts, $properties, $orders] = self::takers($lotIds, $except);

        return $contracts->pluck('procurement_lot_id')
            ->merge($properties->pluck('re_procurement_lot_id'))
            ->merge($orders->pluck('re_procurement_lot_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * その区画を取っているものの名前（例: 契約「勝山の土地（山田 太郎 様）」・建売物件「HS-001 勝山の建売」）。
     * 名前の形は削除の歯止めと同じ（DeletionBlockers::assemble()）。取っているものが無ければ null。$except 自身は数えない。
     */
    public static function takerLabel(int $lotId, ReContract|HsProperty|HsCustomOrder|null $except = null): ?string
    {
        [$contracts, $properties, $orders] = self::takers([$lotId], $except);

        $blockers = DeletionBlockers::assemble(
            $contracts->with('buyer')->orderBy('id')->limit(1)->get(),
            $properties->orderBy('id')->limit(1)->get(),
            $orders->orderBy('id')->limit(1)->get(),
        );

        if ($blockers === []) {
            return null;
        }

        return $blockers[0]['label'] . '「' . $blockers[0]['items'][0]['name'] . '」';
    }

    /**
     * 選択欄に出す区画: 取られていない区画 ＋ $current が今持っている区画（号地の順）。
     * 今の区画は、あとでほかに取られていても残す（そのまま保存できるように。Bug #130）。
     *
     * @return Collection<int, ReProcurementLot>
     */
    public static function selectableLots(ReProcurement $procurement, ReContract|HsProperty|HsCustomOrder|null $current): Collection
    {
        $taken = self::takenLotIds([$procurement->id], $current);
        $held = $current === null ? null : self::savedLotId($current);

        return $procurement->lots()->get()
            ->reject(fn (ReProcurementLot $lot) => in_array((int) $lot->id, $taken, true) && (int) $lot->id !== $held)
            ->values();
    }

    /**
     * その仕入れ案件を選んだら区画が要るか（数字の id で、その案件に区画があるとき）。
     * ただし $current の保存済みの仕入れ案件が同じで、保存済みの区画が空なら要らない
     * （#21 の形: 区画の無い土地で作ったレコードを、区画を足したあとも何も変えずに保存できるように。Bug #130）。
     */
    public static function requiresLot(mixed $procurementId, ReContract|HsProperty|HsCustomOrder|null $current): bool
    {
        $id = self::digits($procurementId);
        if ($id === null || ! ReProcurementLot::where('procurement_id', $id)->exists()) {
            return false;
        }

        if ($current !== null && $current->exists
            && self::savedProcurementId($current) === $id
            && self::savedLotId($current) === null) {
            return false;
        }

        return true;
    }

    /**
     * 区画を選んで保存するときの断り（TAKEN の文）。断らなければ null。
     * 区画がほかに取られていて、かつ「新しく選んだ・変えた」か $selfTakes（今の区画のまま、この保存で区画を取る＝
     * 見積りの注文住宅が「契約」へ進む など）のとき断る。今持っている区画をそのまま保存するだけなら断らない（Bug #130）。
     * 数字でない・無い区画は断らない（入力チェックが断る）。
     */
    public static function refusal(mixed $lotId, ReContract|HsProperty|HsCustomOrder|null $current, bool $selfTakes): ?string
    {
        $id = self::digits($lotId);
        $lot = $id === null ? null : ReProcurementLot::find($id);
        if ($lot === null) {
            return null;
        }

        $changed = $current === null || ! $current->exists || self::savedLotId($current) !== (int) $lot->id;
        if (! $changed && ! $selfTakes) {
            return null;
        }

        $taker = self::takerLabel((int) $lot->id, $current);
        if ($taker === null) {
            return null;
        }

        return strtr(self::TAKEN, [':number' => (string) $lot->lot_number, ':taker' => $taker]);
    }

    /**
     * その土地を土地元にしていて、区画を選んでいない「区画を取るもの」（不動産の契約・建売（契約の前も）・契約以降の注文住宅）。
     * 顔ぶれは takers() と同じ（見積り以前の注文住宅は入れない）。住宅事業は保存済みの種別が「仕入れ案件」のものだけ。
     * 並びは契約・建売・注文住宅の順、それぞれ id の順。
     * 区画を足すとき（ProcurementLotLinks）が使う（設計書 2026-11-22-lot-link-on-add の「言葉」）。
     *
     * @return array<int, ReContract|HsProperty|HsCustomOrder>
     */
    public static function unlinkedTakers(ReProcurement $procurement): array
    {
        $contracts = ReContract::query()
            ->where('procurement_id', $procurement->id)
            ->whereNull('procurement_lot_id')
            ->orderBy('id')
            ->get();
        $properties = HsProperty::query()
            ->where('land_source_type', HousingLandSourceType::Procurement->value)
            ->where('re_procurement_id', $procurement->id)
            ->whereNull('re_procurement_lot_id')
            ->orderBy('id')
            ->get();
        $orders = HsCustomOrder::query()
            ->where('land_source_type', HousingLandSourceType::Procurement->value)
            ->where('re_procurement_id', $procurement->id)
            ->whereNull('re_procurement_lot_id')
            ->whereIn('status', self::contractedOrderStatuses())
            ->orderBy('id')
            ->get();

        return [...$contracts->all(), ...$properties->all(), ...$orders->all()];
    }

    /**
     * 保存済みの土地元が区画のある土地なのに、保存済みの区画が空か（区画を選んでいない注文住宅を「契約」へ進めさせない。
     * 設計書 2026-11-22-lot-link-on-add の決まり 7）。保存済みの値で読む（fill() しただけの値は見ない）。
     */
    public static function lotMissing(ReContract|HsProperty|HsCustomOrder $owner): bool
    {
        $procurementId = self::savedProcurementId($owner);
        if ($procurementId === null || self::savedLotId($owner) !== null) {
            return false;
        }

        return ReProcurementLot::where('procurement_id', $procurementId)->exists();
    }

    /**
     * 区画を取っているものを引く問い合わせ [契約, 建売, 契約以降の注文住宅]（$except 自身は除く）。
     *
     * @param  array<int, int>  $lotIds
     * @return array{0: Builder, 1: Builder, 2: Builder}
     */
    private static function takers(array $lotIds, ReContract|HsProperty|HsCustomOrder|null $except): array
    {
        $contracts = ReContract::query()->whereIn('procurement_lot_id', $lotIds);
        $properties = HsProperty::query()->whereIn('re_procurement_lot_id', $lotIds);
        $orders = HsCustomOrder::query()
            ->whereIn('re_procurement_lot_id', $lotIds)
            ->whereIn('status', self::contractedOrderStatuses());

        if ($except !== null && $except->exists) {
            match (true) {
                $except instanceof ReContract => $contracts->whereKeyNot($except->getKey()),
                $except instanceof HsProperty => $properties->whereKeyNot($except->getKey()),
                $except instanceof HsCustomOrder => $orders->whereKeyNot($except->getKey()),
            };
        }

        return [$contracts, $properties, $orders];
    }

    /** @return array<int, string> 契約以降の注文住宅のステータスの値 */
    private static function contractedOrderStatuses(): array
    {
        return array_values(array_map(
            fn (CustomOrderStatus $status) => $status->value,
            array_filter(CustomOrderStatus::cases(), fn (CustomOrderStatus $status) => $status->isContractedOrLater()),
        ));
    }

    /** 持ち主が保存済みで持っている土地の区画の id */
    private static function savedLotId(Model $owner): ?int
    {
        return self::digits($owner->getRawOriginal($owner instanceof ReContract ? 'procurement_lot_id' : 're_procurement_lot_id'));
    }

    /**
     * 持ち主の保存済みの仕入れ案件の id。住宅事業は保存済みの種別が「仕入れ案件」のときだけ
     * （ほかの種別に残った re_procurement_id は紐付けではない。LocksLandAfterContract と同じ見方）。
     */
    private static function savedProcurementId(Model $owner): ?int
    {
        if ($owner instanceof ReContract) {
            return self::digits($owner->getRawOriginal('procurement_id'));
        }

        if ($owner->getRawOriginal('land_source_type') !== HousingLandSourceType::Procurement->value) {
            return null;
        }

        return self::digits($owner->getRawOriginal('re_procurement_id'));
    }

    /** int と数字だけの文字を id に（「05」も 5。それ以外・空は null） */
    private static function digits(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '' && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
