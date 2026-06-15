<?php

namespace App\Support\Zeal;

class HacomonoMemberMapper
{
    public const KIND_ACTIVE        = 'active';        // 通常在籍
    public const KIND_WITHDRAWN     = 'withdrawn';     // 退会済み（停止中・過去退会日）
    public const KIND_DORMANT       = 'dormant';       // 休会
    public const KIND_TICKET        = 'ticket';        // チケット会員/未対応プランの在籍（契約なし）
    public const KIND_INACTIVE_ZERO = 'inactive_zero'; // 定期購入OFF・実請求0の在籍

    /** 別システム表記 => 既存プラン名 */
    public const PLAN_ALIAS = [
        '（新）パーソナル＆セミパーソナル通い放題（2枠）' => 'パーソナル&セミパーソナル通い放題（2枠）',
        '【松山市駅前】パーソナル&セミパーソナル通い放題(1枠)' => 'パーソナル&セミパーソナル通い放題（1枠）',
        '（新）パーソナル＆セミパーソナル月4回' => 'パーソナル&セミパーソナル月4回',
        'パーソナル&セミパーソナル月4回（松山市駅前）' => 'パーソナル&セミパーソナル月4回',
        '（新）セミパーソナル通い放題' => 'セミパーソナル通い放題',
        'セミパーソナル通い放題（松山市駅前）' => 'セミパーソナル通い放題',
        'セミパーソナル通い放題（松山市駅前）（1年契約）' => 'セミパーソナル通い放題',
        'ペアプラン' => 'ペアプラン',
    ];

    /** プランではない課金ラベル（プラン解決時に読み飛ばす） */
    public const NON_PLAN_LABELS = ['休会プラン', 'チケット会員', 'スタッフ用アカウント'];

    public const STORE_ALIAS = ['ZEAL BOXING FITNESS 松山市駅前店' => '松山市駅前店'];

    public const GENDER_MAP = ['男性' => 'male', '女性' => 'female', 'その他' => 'other'];

    /**
     * @param array<string,int> $planIdMap    プラン名 => id
     * @param array<string,int> $planPriceMap プラン名 => 税抜定価
     * @param array<string,int> $storeIdMap   店舗名 => id
     */
    public function __construct(
        private array $planIdMap,
        private array $planPriceMap,
        private array $storeIdMap,
        private int $defaultStoreId,
        private float $taxRate = 10.0,
    ) {}

    /** @param array<string,string> $row */
    public static function isInScope(array $row): bool
    {
        return in_array(trim($row['状態'] ?? ''), ['会員', '停止中'], true);
    }

    /**
     * カスタム2 を主、空/NON_PLAN なら コース名前 を従として既存プラン名へ解決。
     * @return array{0:?string,1:string,2:string} [プラン名(未解決はnull), 元表記, 取得元]
     */
    public function resolvePlan(string $custom2, string $course): array
    {
        foreach (['カスタム2' => $custom2, 'コース名前' => $course] as $src => $raw) {
            $raw = trim($raw);
            if ($raw === '' || in_array($raw, self::NON_PLAN_LABELS, true)) {
                continue;
            }
            return [self::PLAN_ALIAS[$raw] ?? null, $raw, $src];
        }
        $raw = trim($custom2) !== '' ? trim($custom2) : trim($course);
        return [null, $raw, 'none'];
    }
}
