<?php

namespace App\Support;

use App\Enums\ScheduleStepCategory;

/**
 * ANDPAD の大工程名を工程分類（＝ガントの色）へ機械的に寄せる（設計書 §3.1 G）。
 *
 * ⚠ **分類は色分け以外の意味を持たない**（`ScheduleStepCategory` の注記）。
 *   集計にも権限にも使わないので、寄せ損なっても壊れるのは見た目だけ。
 *
 * ⚠ **`Sale` へ寄せる分岐は置かない。** 設計書 §3.1 G が定めているのは
 *   permit / survey / work / other の 4 つだけで、実データ 21 種にも販売系の
 *   大工程名は無い。`販売` や `分譲` を足すと `分譲地造成工事` のような語を
 *   work でなく sale に倒しかねず、確かめようのない分岐が増える。
 *   手入力の工程では `Sale` を選べるので、enum 側の選択肢は残っている。
 */
final class AndpadCategory
{
    /**
     * 判定の順序（上から当てる）。
     *
     * ⚠ **順序が意味を持つ。** 検査を先に見るので、`基礎工事検査` のように
     *   「工事」と「検査」の両方を含む大工程名は permit に落ちる（検査は工事の色でなく
     *   許認可の色で見たい）。順序は AndpadCategoryTest が固定している。
     */
    private const RULES = [
        ['検査', '申請', '許可'],
        ['測量', '登記'],
        ['工事'],
    ];

    private const RESULTS = [
        ScheduleStepCategory::Permit,
        ScheduleStepCategory::Survey,
        ScheduleStepCategory::Work,
    ];

    public static function forGroup(string $group): ScheduleStepCategory
    {
        $group = trim($group);

        foreach (self::RULES as $i => $words) {
            foreach ($words as $word) {
                if (str_contains($group, $word)) {
                    return self::RESULTS[$i];
                }
            }
        }

        // ⚠ 実データでは `材料搬入` がここに落ちる（「工事」を含まないので work にならない）
        return ScheduleStepCategory::Other;
    }
}
