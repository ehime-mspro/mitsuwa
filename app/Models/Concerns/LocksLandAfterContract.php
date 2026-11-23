<?php

namespace App\Models\Concerns;

use App\Enums\HousingLandSourceType;

/**
 * 契約のある建売・注文住宅の土地の紐付け（種別・区画・仕入れ案件）は変えさせない（判断待ち ④・案 B）。
 *
 * 契約のあとで区画を変える・お客様の土地に変えると、区画の「販売済」がついてこない
 * （前の区画は販売済のまま・新しい区画は販売中のまま＝同じ区画を 2 回売れる形）。
 *
 * ⚠ 今と同じ紐付けなら通す — 比べるのは「種別・その種別で使う紐付け先」だけで、使わない欄の残り
 *   （種別がお客様の土地なのに区画の id が残っている等）は見ない。何も変えない保存を断ると、
 *   ほかの欄だけ直したいときも保存できない（Bug #130 の形）。
 * ⚠ 使う側: 土地の欄を持つ保存の経路（建売の編集・注文住宅の編集・注文住宅の契約の編集）が、
 *   入力チェックの after のフックから refusesLandChange() を呼ぶ（ほかの誤りと一度に出す。Bug #112）。
 */
trait LocksLandAfterContract
{
    /** 契約があるため土地の紐付けを固定するか */
    abstract public function isLandLockedByContract(): bool;

    /**
     * 送られた紐付けへ変えるのを断るか。$input は送られた値そのまま（無いキーは空とみなす）。
     */
    public function refusesLandChange(array $input): bool
    {
        return $this->isLandLockedByContract() && $this->landLinkDiffers($input);
    }

    /**
     * 送られた紐付けが今（保存済み）と違うか。契約の有無に関係なく比べる
     * （土地原価の自動の値を計算し直すかの判定も使う。App\Support\HousingLandCost::whenAutomatic()）。
     */
    public function landLinkDiffers(array $input): bool
    {
        return self::landLink($input['land_source_type'] ?? null, $input['re_project_lot_id'] ?? null, $input['re_procurement_id'] ?? null, $input['re_procurement_lot_id'] ?? null)
            !== self::landLink($this->land_source_type?->value, $this->re_project_lot_id, $this->re_procurement_id, $this->re_procurement_lot_id);
    }

    /**
     * 土地を変えられない画面（読み取り専用）から区画が空で届いたとき、補う保存済みの区画の id（補わないなら null）。
     * 補うのは、保存済みの種別が仕入れ案件で保存済みの区画があり、送られた種別が仕入れ案件・送られた仕入れ案件が保存済みと同じ・
     * 送られた区画が空（null か ''）のときだけ。土地を変えられない画面から区画が空で届くのは、区画を足して何かを
     * つないだ（Bug #146）前に開いた画面だけなので、紐づいた区画を残して保存を通す
     * （設計書 2026-11-23-lot-link-followups の B3・利用者の決定 案 1）。
     * ⚠ 呼ぶのは土地を変えられない画面の保存だけ（建売の編集・契約済みの注文住宅の 2 つの編集）。区画を選べる画面に使わない。
     */
    public function lotKeptFor(array $input): ?int
    {
        $savedLot = self::idText($this->re_procurement_lot_id);
        if ($this->land_source_type !== HousingLandSourceType::Procurement || $savedLot === null) {
            return null;
        }

        $sentLot = $input['re_procurement_lot_id'] ?? null;
        $kept = ($input['land_source_type'] ?? null) === HousingLandSourceType::Procurement->value
            && self::idText($input['re_procurement_id'] ?? null) === self::idText($this->re_procurement_id)
            && ($sentLot === null || $sentLot === '');

        return $kept ? (int) $savedLot : null;
    }

    /**
     * 比べる形: [種別, 種別が分譲地区画のときだけ区画 id, 種別が仕入れ案件のときだけ仕入れ案件 id,
     *           種別が仕入れ案件のときだけ土地の区画 id]（区画だけを変えるのも「紐付けが違う」）
     *
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string}
     */
    private static function landLink(mixed $type, mixed $lotId, mixed $procurementId, mixed $procurementLotId = null): array
    {
        $type = is_string($type) && $type !== '' ? $type : null;

        return [
            $type,
            $type === HousingLandSourceType::ProjectLot->value ? self::idText($lotId) : null,
            $type === HousingLandSourceType::Procurement->value ? self::idText($procurementId) : null,
            $type === HousingLandSourceType::Procurement->value ? self::idText($procurementLotId) : null,
        ];
    }

    /** id を比べる文字に（数字だけの文字は先頭の 0 を落とす。手で組んだ送信の「05」も 5 と同じ） */
    private static function idText(mixed $id): ?string
    {
        if (is_int($id)) {
            return (string) $id;
        }
        if (! is_string($id) || $id === '') {
            return null;
        }

        return ctype_digit($id) ? (string) (int) $id : $id;
    }
}
