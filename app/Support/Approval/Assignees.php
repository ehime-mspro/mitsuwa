<?php

namespace App\Support\Approval;

use App\Enums\UserStatus;
use App\Models\ApprovalMailDomain;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * 担当に選べる人（部門長・審査担当者・部門長の確認の付け替え先。設計書 D7・§5.4・§5.14）。
 *
 * 有効でメールアドレスのある人（基幹を使う人・決裁のみ利用者のどちらも。所属は問わない。D6）。
 * 許可していないドメインの人も選べるが、通知メールが届かないので画面で注意を出す（mail_allowed）。
 * ⚠ 部門の管理と付け替えで同じ決まりを使う（2 か所に書かない）。
 */
final class Assignees
{
    /** 入力の検査の規則（削除した人・無効の人・メールの無い人を断る） */
    public static function rule(): Exists
    {
        return Rule::exists('users', 'id')
            ->whereNull('deleted_at')
            ->where('status', UserStatus::Active->value)
            ->whereNotNull('email');
    }

    /**
     * 選択肢（名前の順）。許可していないドメインの人は mail_allowed が false
     *
     * @return Collection<int, User>
     */
    public static function candidates(): Collection
    {
        $allowed = ApprovalMailDomain::pluck('domain')->all();

        return User::where('status', UserStatus::Active->value)->whereNotNull('email')
            ->orderBy('name')->get(['id', 'name', 'email', 'employee_number'])
            ->map(function (User $user) use ($allowed) {
                $user->mail_allowed = in_array(mb_strtolower(substr((string) $user->email, strrpos((string) $user->email, '@') + 1), 'UTF-8'), $allowed, true);

                return $user;
            });
    }
}
