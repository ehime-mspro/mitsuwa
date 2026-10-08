<?php

namespace App\Support\Approval;

use App\Enums\ApprovalStatus;
use App\Models\ApprovalRequest;
use App\Models\ApprovalSetting;
use App\Models\ApprovalType;
use App\Models\User;

/**
 * 提出（出し直し）の条件（要件 4.3 のケース 4・5.1・5.5・設計書 D4・D10・D12・§5.8・段階5 設計書 D2・D13）。
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

            // 使える部門（段階5 D13）。差戻し中は、種類と申請部門を前の提出から変えていなければそのまま出し直せる（停止した種類と同じ考え）
            if ($department !== null && ! $type->isUsableIn($department->id) && self::followsUsableDepartments($request)) {
                $reasons[] = "申請の種類「{$type->name}」は申請部門「{$department->name}」では使えません。種類か申請部門を選び直してください。";
            }
        }

        if (ApprovalSetting::current()->president_user_id === null) {
            $reasons[] = '社長が未設定です。管理者に連絡してください。';
        }

        // ⚠ 全角の空白も落とす（FormInput::trim。画面からの値はミドルウェアが先に落とすが、ここはそれに頼らない）。
        // 決まり文句だけの件名（前半を入れていない）は、台帳と PDF に施主名の無い件名が載るので断る（段階5。点検の I-4）
        $subject = FormInput::trim($request->subject);
        $suffix  = FormInput::trim($type?->subject_suffix);
        if ($subject === '') {
            $reasons[] = '件名を入力してください。';
        } elseif ($suffix !== '' && $subject === $suffix) {
            $reasons[] = "件名の前半（「{$suffix}」の前）を入力してください。";
        }

        if ($type?->usesTable()) {
            // 明細表の種類（段階5 D2）: 台帳の金額（合計金額の販売金額）が必ず入る・名前のない行の金額は項目名が要る。補足（本文）は任意
            $table = $request->amount_table ?? [];
            if (AmountTable::amount($table) === null) {
                $reasons[] = '明細表の合計金額の販売金額を入れてください（0 円より大きい金額）。';
            }
            if (AmountTable::hasUnnamedAmount($table)) {
                $reasons[] = '明細表の名前のない行に金額が入っています。項目名を入れてください。';
            }
        } elseif (BodyTemplate::isBlank($request->body)) {
            // 「■」の行は後ろに書き足しても見出しとして扱う（D12）ので、どこに書けばよいかを添える（Task 19 の C6）
            $reasons[] = '重点ポイント（5W2H）が見出しのままです。中身は「■」の行の後ろではなく、下の「・」の行に書いてください。';
        }

        // 種類が使う欄のうち、提出に必須の欄（担当者・契約予定日。段階5 D2）
        foreach (array_intersect(RequestExtras::REQUIRED, RequestExtras::usedBy($type)) as $key) {
            if (FormInput::trim((string) ($request->getAttributes()[$key] ?? null)) === '') {
                $reasons[] = RequestExtras::FIELDS[$key] . 'を入力してください。';
            }
        }

        return $reasons;
    }

    /**
     * 使える部門の決まりに従うか（段階5 D13）。下書きは従う。差戻し中は、種類か申請部門を前の提出（最後に提出した控え）から
     * 変えたときだけ従う（回っている途中で管理者が使える部門を変えても、行き止まりにしない）
     */
    private static function followsUsableDepartments(ApprovalRequest $request): bool
    {
        if ($request->status !== ApprovalStatus::Returned) {
            return true;
        }

        $snapshot = $request->submittedRevision()?->snapshot ?? [];

        return (int) ($snapshot['type']['id'] ?? 0) !== $request->type_id
            || (int) ($snapshot['department']['id'] ?? 0) !== $request->department_id;
    }

    /** 審査部門に、申請者本人以外の有効な審査担当者がいるか（4.3 のケース 4） */
    private static function hasOtherReviewer(ApprovalType $type, User $user): bool
    {
        return $type->reviewDepartment->activeReviewers()
            ->where('users.id', '!=', $user->id)
            ->exists();
    }
}
