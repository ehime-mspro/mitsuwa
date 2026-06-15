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

    public function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $value = str_replace('/', '-', $value);
        if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
            return null;
        }
        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        // checkdate で論理的に無効な日付（2/31・13月等）を弾く。
        // 移行は一回限りで入会日の丸めが period_start を静かに破損するため、
        // strtotime の暗黙補正に頼らず明示的に拒否する。
        if (!checkdate($month, $day, $year)) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public function toInt(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^-?\d+$/', $value)) {
            return null;
        }
        return (int) $value;
    }

    /** @param array<string,string> $row */
    public function map(array $row): MappedMember
    {
        $get = fn (string $k): string => trim($row[$k] ?? '');
        $errors = [];
        $warnings = [];

        $sourceId = $get('ID');
        $status   = $get('状態');
        $name     = $get('名前');
        if ($name === '') {
            $errors[] = '氏名が空です';
        }

        $joinedRaw = $get('入会日');
        $joinedOn  = $this->normalizeDate($joinedRaw);
        if ($joinedOn === null) {
            $errors[] = $joinedRaw === '' ? '入会日が空です（必須）' : "入会日'{$joinedRaw}'の形式が不正";
        }

        $genderRaw = $get('性別');
        $gender = null;
        if ($genderRaw === '') {
            $warnings[] = '性別が空（null取込）';
        } elseif (isset(self::GENDER_MAP[$genderRaw])) {
            $gender = self::GENDER_MAP[$genderRaw];
        } else {
            $errors[] = "性別'{$genderRaw}'が不正";
        }

        $storeRaw  = $get('店舗 名前');
        $storeName = self::STORE_ALIAS[$storeRaw] ?? $storeRaw;
        $storeId   = $this->storeIdMap[$storeName] ?? $this->defaultStoreId;

        [$planName, $rawPlan] = $this->resolvePlan($get('カスタム2'), $get('コース 名前'));
        $planId = $planName !== null ? ($this->planIdMap[$planName] ?? null) : null;

        $paidIncl       = $this->toInt($get('合計金額(2回目以降)'));
        $courseListIncl = $this->toInt($get('コース 合計金額(2回目以降)'));
        $withdrewOn     = $this->normalizeDate($get('退会日'));
        $scheduledOn    = $get('退会予定日');

        // --- 区分判定（Task 5 で分岐を追加）---
        $kind = self::KIND_ACTIVE;
        $priceExcl = $this->taxExcl($paidIncl);
        if ($priceExcl === null) {
            $errors[] = '月会費(合計金額)が空/不正です';
        }

        $isScheduled = ($scheduledOn !== '');
        if ($isScheduled) {
            $warnings[] = "退会予定日 {$scheduledOn}";
        }

        $memo = $this->buildMemo($sourceId, $get, $scheduledOn, $kind);

        $contract = null;
        if ($planId !== null && $kind !== self::KIND_TICKET) {
            $contract = [
                'plan_id'            => $planId,
                'period_start'       => $joinedOn,
                'period_end'         => $kind === self::KIND_WITHDRAWN ? $withdrewOn : null,
                'applied_price_excl' => $priceExcl,
                'change_reason'      => 'new_join',
            ];
        }

        $member = [
            'store_id'           => $storeId,
            'name'               => $name,
            'name_kana'          => $get('名前カナ'),
            'gender'             => $gender,
            'birthday'           => $this->normalizeDate($get('生年月日')),
            'phone'              => $get('電話番号') ?: null,
            'email'              => $get('メールアドレス') ?: null,
            'postal_code'        => $get('郵便番号') ?: null,
            'address'            => $get('住所') ?: null,
            'joined_on'          => $joinedOn,
            'current_plan_id'    => $kind === self::KIND_TICKET ? null : $planId,
            'trainer_id'         => null,
            'acquisition_source' => null,
            'purpose'            => null,
            'withdrew_on'        => $kind === self::KIND_WITHDRAWN ? $withdrewOn : null,
            'withdraw_reason'    => null,
            'withdraw_note'      => $kind === self::KIND_WITHDRAWN ? '別システムより移管（退会済み）' : null,
            'memo'               => $memo,
        ];

        return new MappedMember(
            sourceId: $sourceId,
            displayName: $name,
            status: $status,
            kind: $kind,
            planName: $planName,
            rawPlan: $rawPlan,
            sourceAmountIncl: $paidIncl,
            courseListIncl: $courseListIncl,
            appliedPriceExcl: $priceExcl,
            withdrewOn: $kind === self::KIND_WITHDRAWN ? $withdrewOn : null,
            scheduledOn: $isScheduled ? $scheduledOn : null,
            memberAttributes: $member,
            contractAttributes: $contract,
            warnings: $warnings,
            errors: $errors,
        );
    }

    private function taxExcl(?int $incl): ?int
    {
        return $incl === null ? null : (int) round($incl / (1 + $this->taxRate / 100));
    }

    /** @param callable(string):string $get */
    private function buildMemo(string $sourceId, callable $get, string $scheduledOn, string $kind): string
    {
        $lines = [];
        if ($sourceId !== '') {
            $lines[] = "移行元ID: {$sourceId}";
        }
        if (preg_match('/割引名:\s*(.+)/u', $get('顧客内部カルテ'), $mm)) {
            $lines[] = '割引名: ' . trim($mm[1]);
        }
        if ($scheduledOn !== '') {
            $lines[] = "退会予定日: {$scheduledOn}";
        }
        if ($get('紹介コード') !== '') {
            $lines[] = '紹介コード: ' . $get('紹介コード');
        }
        return implode("\n", $lines);
    }
}
