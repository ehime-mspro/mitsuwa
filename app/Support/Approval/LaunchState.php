<?php

namespace App\Support\Approval;

use App\Enums\ApprovalLaunchStage;
use App\Enums\UserStatus;
use App\Models\ApprovalMember;
use App\Models\ApprovalSetting;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * 「この人に申請を回す画面を見せるか」と「今は 準備中／試す期間／使い始めた のどれか」を決める 1 か所
 * （段階6 設計書 §5.1・§5.2・計画 §0.2）。
 *
 * 設定の使い始めた日時を読むのは、`ApprovalSetting` とここだけ（`LaunchStateScanTest` が `app/` と `resources/views/` を
 * 走査して止める。コメントにも当たる）。門番・ホーム・2 つのサイドバー・基幹のメニューとベル・⑫・Notifier・催促のコマンドは
 * みなここを読む。
 *
 * | 状態       | 条件                                    | 申請を回す画面・お知らせ・メール・催促 |
 * |------------|-----------------------------------------|----------------------------------------|
 * | 準備中     | 使い始める前 かつ 試す人が 0 人         | だれにも出ない・届かない（今と同じ）   |
 * | 試す期間   | 使い始める前 かつ 試す人が 1 人以上     | 試す人にだけ                           |
 * | 使い始めた | 使い始めた                              | 全員に（試す人の印は関係ない）         |
 *
 * ⚠ **準備中は今とまったく同じ。** 試しの印と提出の確かめは「使い始める前」ではなく「試す期間」だけで効かせる
 *   （準備中は門番がだれも通さないので要らない。準備中に効かせると、使い始める前に Workflow で提出している今のテストが
 *   提出を断られて赤になる）。
 * ⚠ **使い始めたあとは今とまったく同じ。** 試す人の印が残っていても、全員に見え、試しの印も提出の確かめも付かない。
 * ⚠ **基幹の画面で問い合わせを増やさない。** `opensFor()` は設定の行を作らずに読み（`launched()`）、試す人かは
 *   `$user->approvalMember` から読む。基幹の画面はヘッダーのベルとサイドバーが同じ `Auth::user()` を読むので、
 *   `approvalMember` は 1 リクエストに 1 回（ベルの `opensFor()` が先に読み、サイドバーの `isApprovalAdmin()` が使い回す）。
 *   使い始めたあとは関係を読まない（`launched()` が先）。`stage()` の「試す人がいるか」は 1 本の `exists` を毎回打つので、
 *   基幹の画面では呼ばない（決裁の管理の画面⑫・⑬と催促・提出の確かめ・PDF・Excel が読む）。
 */
final class LaunchState
{
    /** 使い始めたか。設定の行を作らずに読む（基幹の画面は件数によらず問い合わせの本数を同じに保つ。2b で実測） */
    public static function launched(): bool
    {
        return ApprovalSetting::launchedForMenu();
    }

    /**
     * 試す人か。印があり、有効で、削除していない人だけ（段階6 設計書 §5.3）。
     * 印だけ残った無効の人・削除した人は、画面も知らせも届かない。
     */
    public static function isTester(User $user): bool
    {
        return ! $user->trashed()
            && $user->status === UserStatus::Active
            && (bool) $user->approvalMember?->is_tester;
    }

    /**
     * この人に申請を回す画面を見せるか。使い始めたあとは全員、使い始める前は試す人だけ。
     *
     * ⚠ 順番を変えない。使い始めたあとは `approvalMember` を読まない（基幹の画面の問い合わせを増やさない）。
     */
    public static function opensFor(?User $user): bool
    {
        return $user !== null && (self::launched() || self::isTester($user));
    }

    public static function stage(): ApprovalLaunchStage
    {
        if (self::launched()) {
            return ApprovalLaunchStage::Launched;
        }

        return ApprovalMember::testers()->exists() ? ApprovalLaunchStage::Trial : ApprovalLaunchStage::Preparing;
    }

    /** 試す期間か（試しの印・提出の確かめ・PDF の「試し」・Excel の「試し_」を付けるか） */
    public static function inTrial(): bool
    {
        return self::stage() === ApprovalLaunchStage::Trial;
    }

    /**
     * 使い始めた日時（⑬ の「使い始めました（…）」）。
     *
     * ⚠ ⑬ は決裁の管理の画面なので、設定の行が無ければ作ってよい（`current()`）。基幹の画面では呼ばない。
     */
    public static function launchedAt(): ?CarbonInterface
    {
        return ApprovalSetting::current()->launched_at;
    }
}
