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

    /** 氏名に含まれていたら本部のテスト用アカウントと見なす語（大小を区別せず部分一致） */
    public const TEST_NAME_MARKERS = ['テスト', 'ﾃｽﾄ', 'test'];

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
    ) {
        // 本番ZealPlan.nameは全角＆/半角&が混在するため、照合キーを正規化して保持する
        $this->planIdMap    = self::normalizePlanKeys($planIdMap);
        $this->planPriceMap = self::normalizePlanKeys($planPriceMap);
    }

    /** プラン名の全角＆を半角&へ正規化（本番マスタの表記ゆれ対策） */
    private static function normalizePlanName(string $name): string
    {
        return str_replace('＆', '&', $name);
    }

    /**
     * プラン名をキーにした連想配列のキーを正規化する。
     * @param array<string,int> $map
     * @return array<string,int>
     */
    private static function normalizePlanKeys(array $map): array
    {
        $out = [];
        foreach ($map as $name => $value) {
            $out[self::normalizePlanName($name)] = $value;
        }
        return $out;
    }

    /**
     * 氏名が本部のテスト／管理用アカウントか判定する。
     *
     * 本部の会員システムには店舗ごとの動作確認用アカウント（例:「MS 二宮／テスト」）が
     * 混じっている。実会員として取り込むと在籍数・性別内訳・プラン別集計・
     * 経営試算表の実績連動がすべて 1 件ぶん狂うため、取込の水際で落とす。
     */
    public static function isTestAccountName(string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return false;
        }

        foreach (self::TEST_NAME_MARKERS as $marker) {
            if (mb_stripos($name, $marker) !== false) {
                return true;
            }
        }

        // 「MS 二宮」のように MS を接頭トークンに持つ本部アカウント。
        // 「MSK 田中」のような実氏名を巻き込まないよう、直後の区切り文字まで含めて見る。
        return preg_match('/^MS[ 　]/u', $name) === 1;
    }

    /** @param array<string,string> $row */
    public static function isInScope(array $row): bool
    {
        if (!in_array(trim($row['状態'] ?? ''), ['会員', '停止中'], true)) {
            return false;
        }

        return !self::isTestAccountName($row['名前'] ?? '');
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
        // 桁区切りカンマを除去（hacomono の金額に "9,702" 形式が来ても解釈できるように）
        $value = str_replace(',', '', trim($value));
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
        $planId = null;
        if ($planName !== null) {
            $planId = $this->planIdMap[self::normalizePlanName($planName)] ?? null;
            if ($planId === null) {
                // プラン名は解決できたが自社マスタ(ZealPlan)に無い＝表記ゆれ/未登録。
                // 在籍会員が契約なしで静かに取り込まれるのを防ぐためエラーで止める。
                $errors[] = "プラン名'{$planName}'がマスタに未登録（契約を作成できません）";
            }
        }

        $paidIncl       = $this->toInt($get('合計金額(2回目以降)'));
        $courseListIncl = $this->toInt($get('コース 合計金額(2回目以降)'));
        $withdrewOn     = $this->normalizeDate($get('退会日'));
        $scheduledOn    = $get('退会予定日');

        // --- 区分判定（優先順）---
        // CSV「定期購入」列は文字列 "TRUE"/"FALSE"。空や他値は OFF とみなさない。
        $teikiOff   = strtoupper($get('定期購入')) === 'FALSE';
        // 休会は「現在のコース名前=休会プラン」のみ。変更後コース=休会プランは
        // 「次回休会予定」で現在は在籍（通常月会費が発生中）なので休会にしない。
        $isDormant  = $get('コース 名前') === '休会プラン';
        $paidIsZero = ($paidIncl === null || $paidIncl === 0);

        if ($status === '停止中' || $withdrewOn !== null) {
            // 1. 退会済み → プラン定価(税抜)・契約クローズ
            $kind = self::KIND_WITHDRAWN;
            $priceExcl = $planName !== null ? ($this->planPriceMap[self::normalizePlanName($planName)] ?? null) : null;
            if ($priceExcl === null) {
                $errors[] = "退会者だがプラン未解決: '{$rawPlan}'";
            }
            if ($withdrewOn === null) {
                // 停止中だが退会日が空。withdrew_on も period_end も null になり
                // UI上「在籍」と矛盾するため dry-run で気づけるよう警告する。
                $warnings[] = '停止中だが退会日が空（withdrew_on/period_end=nullで取込）';
            }
        } elseif ($isDormant) {
            // 2. 休会 → 実際の休会費(税抜)
            $kind = self::KIND_DORMANT;
            if ($planName === null) {
                $errors[] = "休会だが実プラン未解決: '{$rawPlan}'";
            }
            if ($paidIncl === null) {
                $warnings[] = '休会：月会費フィールドが空のため0円で取込';
            }
            $priceExcl = $this->toMonthlyExcl($paidIncl) ?? 0;
        } elseif ($planName === null) {
            // 3. チケット会員/未対応プランの在籍 → 契約なし
            $kind = self::KIND_TICKET;
            $priceExcl = null;
            $warnings[] = "プラン未対応（'{$rawPlan}'）→会員のみ作成・契約なし";
        } elseif ($teikiOff && $paidIsZero) {
            // 4. 定期購入OFF・実請求0 → プラン定価(税抜)
            $kind = self::KIND_INACTIVE_ZERO;
            $priceExcl = $this->planPriceMap[self::normalizePlanName($planName)] ?? null;
            if ($priceExcl === null) {
                // 契約の applied_price_excl は NOT NULL。定価不明のまま commit すると
                // DB エラーになるため dry-run 時点でエラーとして検出する。
                $errors[] = "定期購入なしだがプラン定価が不明: '{$planName}'";
            }
            $warnings[] = '定期購入なし（実請求0）→プラン定価';
        } else {
            // 5. 通常在籍 → 実請求(税抜)
            $kind = self::KIND_ACTIVE;
            $priceExcl = $this->toMonthlyExcl($paidIncl);
            if ($priceExcl === null) {
                $errors[] = '月会費(合計金額)が空/不正です';
            }
        }

        $isScheduled = ($scheduledOn !== '');
        if ($isScheduled) {
            $warnings[] = "退会予定日 {$scheduledOn}";
        }

        // 次回休会予定（在籍のまま取込。休会区分とは区別する）
        if ($get('変更後コース 名前') === '休会プラン') {
            $warnings[] = '次回休会予定（変更後コース=休会プラン）→在籍として取込';
        }

        $memo = $this->buildMemo($sourceId, $get, $scheduledOn, $kind);

        $contract = null;
        if ($planId !== null && $kind !== self::KIND_TICKET) {
            $contract = [
                'plan_id'            => $planId,
                'period_start'       => $joinedOn,
                'period_end'         => $kind === self::KIND_WITHDRAWN ? $withdrewOn : null,
                'applied_price_excl' => $priceExcl,
                'change_reason'      => 'new_join', // = ZealContractChangeReason::NewJoin->value（Mapperは DB非依存のため文字列で返す）
            ];
        }

        $member = [
            'store_id'           => $storeId,
            'name'               => $name,
            'name_kana'          => $get('名前カナ') ?: null,
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

    /**
     * 月会費の税抜額を返す。hacomonoは会員のコース表記により金額を税込/税抜
     * どちらでも持つ（税込表記コース: 定価10780等 / 税抜表記コース: 定価9800等）。
     * 金額が「税抜×(1+税率)」ちょうど（整数の税込）なら税抜換算し、そうでなければ
     * 金額自体が税抜表記とみなしそのまま返す（一律÷1.1で税抜表記の会員が過小になるのを防ぐ）。
     */
    private function toMonthlyExcl(?int $amount): ?int
    {
        if ($amount === null) {
            return null;
        }
        $denom = 100 + (int) $this->taxRate; // 税率10% → 110
        return ($amount * 100) % $denom === 0 ? intdiv($amount * 100, $denom) : $amount;
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
        if ($get('変更後コース 名前') === '休会プラン') {
            $lines[] = '次回休会予定（移管時点で在籍）';
        }
        if ($get('紹介コード') !== '') {
            $lines[] = '紹介コード: ' . $get('紹介コード');
        }
        if ($kind === self::KIND_DORMANT) {
            $lines[] = '区分: 休会中（移管時点）';
        }
        if ($kind === self::KIND_TICKET) {
            $tickets = $get('残チケット数');
            $lines[] = '区分: チケット会員（残' . ($tickets !== '' ? $tickets : '不明') . '枚・定期購入なし）';
        }
        if ($kind === self::KIND_INACTIVE_ZERO) {
            $lines[] = '区分: 定期購入なし（移管時・チケット残' . $get('残チケット数') . '）';
        }
        return implode("\n", $lines);
    }
}
