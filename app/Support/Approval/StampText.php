<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStepKind;
use App\Models\ApprovalDepartment;
use App\Models\ApprovalStep;
use App\Models\User;

/**
 * 印の上段と下段の文字を決める（要件 9.1・段階4 設計書 D6・D8）。**規則はここ 1 か所。**
 *
 * - 上段: 部門長は段階の部門（申請部門の控え）の略称・審査担当者は段階の部門（審査部門）の略称・社長は「社長」
 * - 下段: 利用者の「印に使う文字」。空なら氏名の最初の空白（全角・半角）より前。空白の無い氏名は氏名全体
 *
 * ⚠ 判断したときに `Workflow::finishStep()` がここで決めた文字を段階に控える（D3）。控えたあとは設定を変えても印は変わらない。
 */
final class StampText
{
    public const PRESIDENT_LABEL = '社長';

    /** 氏名から決める既定の下段 */
    public static function defaultFor(string $name): string
    {
        $trimmed = preg_replace('/^[\s　]+|[\s　]+$/u', '', $name) ?? $name;

        return preg_split('/[\s　]+/u', $trimmed, 2)[0];
    }

    /** その人の下段（「印に使う文字」が空なら氏名から） */
    public static function for(User $user): string
    {
        $text = $user->approvalMember?->stamp_text;

        return $text !== null && $text !== '' ? $text : self::defaultFor($user->name);
    }

    /** その段階の上段（今の部門の略称。控える前に呼ぶ） */
    public static function labelFor(ApprovalStep $step): string
    {
        if ($step->kind === ApprovalStepKind::President) {
            return self::PRESIDENT_LABEL;
        }

        return (string) ApprovalDepartment::whereKey($step->department_id)->value('short_name');
    }
}
