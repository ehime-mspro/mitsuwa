<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStatus;
use App\Enums\UserStatus;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalType;
use App\Models\User;

/**
 * 提出（出し直し）の条件（要件 4.3 のケース 4・5.1・設計書 D4・D10・D12・§5.8）。
 *
 * 理由をすべて集めて返す（1 つずつ直して出し直させない）。画面は編集画面の上にまとめて出す。
 * 形の検査（長さ・金額の範囲・関連する決裁No の形と数）は、保存のときに RequestController の入力の検査が見る
 * （提出は必ず保存を経る）。状態と申請者本人かどうかは RequestPermissions が先に見る。
 */
final class SubmitChecker
{
    /** @return list<string> 提出できない理由（空なら提出できる） */
    public static function reasons(ApprovalRequest $request, User $user): array
    {
        $reasons = [];

        if ($user->isApprovalPresident()) {
            $reasons[] = '社長に指定されている人は申請できません。';
        }

        $department = $request->department;

        if (! $user->approvalDepartments()->exists()) {
            $reasons[] = '所属部門が未設定です。管理者に連絡してください。';
        } elseif ($department === null) {
            $reasons[] = '申請部門を選んでください。';
        } elseif (! $user->approvalDepartments()->whereKey($department->id)->exists()) {
            $reasons[] = "申請部門「{$department->name}」に所属していません。申請部門を選び直してください。";
        } elseif ($department->head_user_id === null) {
            $reasons[] = "部門「{$department->name}」の部門長が未設定です。管理者に連絡してください。";
        }

        $type = $request->type;

        if ($type === null) {
            $reasons[] = '申請の種類を選んでください。';
        } else {
            // 停止した種類: 下書きは選び直し、差戻し中はそのまま出し直せる（D10）。
            // 選び直せば変わる審査部門の理由は並べない（要らない連絡をさせない）
            if (! $type->is_active && $request->status === ApprovalStatus::Draft) {
                $reasons[] = "申請の種類「{$type->name}」は使えなくなりました。種類を選び直してください。";
            } elseif (! self::hasOtherReviewer($type, $user)) {
                $reasons[] = "審査部門「{$type->reviewDepartment->name}」に、申請者本人以外の審査担当者がいません。管理者に連絡してください。";
            }
        }

        if (ApprovalSetting::current()->president_user_id === null) {
            $reasons[] = '社長が未設定です。管理者に連絡してください。';
        }

        // ⚠ 全角の空白も落とす（trim() は落とさない。画面からの値はミドルウェアが先に落とすが、ここはそれに頼らない）
        if (preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', (string) $request->subject) === '') {
            $reasons[] = '件名を入力してください。';
        }

        if (BodyTemplate::isBlank($request->body)) {
            $reasons[] = '重点ポイント（5W2H）が見出しのままです。中身を書いてください。';
        }

        return $reasons;
    }

    /** 審査部門に、申請者本人以外の有効な審査担当者がいるか（4.3 のケース 4） */
    private static function hasOtherReviewer(ApprovalType $type, User $user): bool
    {
        return $type->reviewDepartment->reviewers()
            ->where('users.id', '!=', $user->id)
            ->where('users.status', UserStatus::Active->value)
            ->exists();
    }
}
