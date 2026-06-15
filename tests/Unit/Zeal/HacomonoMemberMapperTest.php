<?php

namespace Tests\Unit\Zeal;

use App\Support\Zeal\HacomonoMemberMapper;
use PHPUnit\Framework\TestCase;

class HacomonoMemberMapperTest extends TestCase
{
    private function mapper(): HacomonoMemberMapper
    {
        // name => id / name => price(excl) / store name => id
        $planId = [
            'パーソナル&セミパーソナル通い放題（2枠）' => 1,
            'パーソナル&セミパーソナル通い放題（1枠）' => 2,
            'パーソナル&セミパーソナル月4回' => 3,
            'セミパーソナル通い放題' => 4,
            'ペアプラン' => 5,
        ];
        $planPrice = [
            'パーソナル&セミパーソナル通い放題（2枠）' => 24000,
            'パーソナル&セミパーソナル通い放題（1枠）' => 18000,
            'パーソナル&セミパーソナル月4回' => 13000,
            'セミパーソナル通い放題' => 9800,
            'ペアプラン' => 20700,
        ];
        $storeId = ['松山市駅前店' => 1];
        return new HacomonoMemberMapper($planId, $planPrice, $storeId, 1, 10.0);
    }

    public function test_in_scope_includes_member_and_suspended_only(): void
    {
        $this->assertTrue(HacomonoMemberMapper::isInScope(['状態' => '会員']));
        $this->assertTrue(HacomonoMemberMapper::isInScope(['状態' => '停止中']));
        $this->assertFalse(HacomonoMemberMapper::isInScope(['状態' => 'ビジター']));
        $this->assertFalse(HacomonoMemberMapper::isInScope(['状態' => '']));
    }

    public function test_resolve_plan_maps_all_variants(): void
    {
        $m = $this->mapper();
        $this->assertSame('セミパーソナル通い放題', $m->resolvePlan('（新）セミパーソナル通い放題', '')[0]);
        $this->assertSame('セミパーソナル通い放題', $m->resolvePlan('セミパーソナル通い放題（松山市駅前）（1年契約）', '')[0]);
        $this->assertSame('パーソナル&セミパーソナル月4回', $m->resolvePlan('パーソナル&セミパーソナル月4回（松山市駅前）', '')[0]);
        $this->assertSame('パーソナル&セミパーソナル通い放題（1枠）', $m->resolvePlan('【松山市駅前】パーソナル&セミパーソナル通い放題(1枠)', '')[0]);
        $this->assertSame('ペアプラン', $m->resolvePlan('ペアプラン', '')[0]);
    }

    public function test_resolve_plan_prefers_custom2_then_course(): void
    {
        $m = $this->mapper();
        // カスタム2 が空なら コース名前 を使う
        $this->assertSame('セミパーソナル通い放題', $m->resolvePlan('', '（新）セミパーソナル通い放題')[0]);
        // カスタム2 が NON_PLAN ラベルなら次へ（実データでは起きにくいが安全側）
        $this->assertSame('セミパーソナル通い放題', $m->resolvePlan('休会プラン', 'セミパーソナル通い放題（松山市駅前）')[0]);
    }

    public function test_resolve_plan_returns_null_for_unmatched(): void
    {
        $m = $this->mapper();
        [$name, $raw, $src] = $m->resolvePlan('チケット会員', '');
        $this->assertNull($name);
        $this->assertSame('チケット会員', $raw);
    }

    public function test_normalize_date(): void
    {
        $m = $this->mapper();
        $this->assertSame('2025-10-17', $m->normalizeDate('2025/10/17'));
        $this->assertSame('2026-04-01', $m->normalizeDate('2026/4/1'));
        $this->assertSame('2025-10-17', $m->normalizeDate('2025-10-17')); // 既にハイフン形式でも通る
        $this->assertNull($m->normalizeDate(''));
        $this->assertNull($m->normalizeDate('not-a-date'));
        $this->assertNull($m->normalizeDate('2025/13/45'));          // 無効な月日は checkdate で拒否
        $this->assertNull($m->normalizeDate('1990/2/31'));           // 2月31日は存在しない
        $this->assertNull($m->normalizeDate('2025/10/17 00:00:00')); // 時刻付きは reject
    }

    public function test_to_int(): void
    {
        $m = $this->mapper();
        $this->assertSame(9702, $m->toInt('9702'));
        $this->assertNull($m->toInt(''));
        $this->assertNull($m->toInt('abc'));
        $this->assertSame(0, $m->toInt('0'));
    }

    /** @return array<string,string> 使用列だけを持つ行（他は空） */
    private function row(array $over): array
    {
        return array_merge([
            'ID' => 'CL00000001', '状態' => '会員', '定期購入' => 'TRUE',
            '名前' => '山田 太郎', '名前カナ' => 'ヤマダ タロウ', '性別' => '男性',
            '生年月日' => '1990/1/2', '電話番号' => '', 'メールアドレス' => '',
            '郵便番号' => '', '住所' => '', '入会日' => '2025/10/17',
            'カスタム2' => '（新）セミパーソナル通い放題', 'コース 名前' => '（新）セミパーソナル通い放題',
            'コース 名前（内部）' => '', '変更後コース 名前' => '',
            '合計金額(2回目以降)' => '9702', 'コース 合計金額(2回目以降)' => '10780',
            '退会日' => '', '退会予定日' => '', '残チケット数' => '0',
            '紹介コード' => '', '顧客内部カルテ' => '', '店舗 名前' => 'ZEAL BOXING FITNESS 松山市駅前店',
        ], $over);
    }

    public function test_map_normal_active_member(): void
    {
        $r = $this->mapper()->map($this->row([]));
        $this->assertSame(HacomonoMemberMapper::KIND_ACTIVE, $r->kind);
        $this->assertFalse($r->hasErrors());
        $this->assertSame('セミパーソナル通い放題', $r->planName);
        // 9702(税込) / 1.1 = 8820(税抜)
        $this->assertSame(8820, $r->appliedPriceExcl);
        $this->assertSame('male', $r->memberAttributes['gender']);
        $this->assertSame(1, $r->memberAttributes['store_id']);
        $this->assertSame(4, $r->memberAttributes['current_plan_id']);
        $this->assertSame('2025-10-17', $r->memberAttributes['joined_on']);
        // 契約: 在籍は period_end=null
        $this->assertNotNull($r->contractAttributes);
        $this->assertSame(4, $r->contractAttributes['plan_id']);
        $this->assertNull($r->contractAttributes['period_end']);
        $this->assertSame(8820, $r->contractAttributes['applied_price_excl']);
        $this->assertSame('new_join', $r->contractAttributes['change_reason']);
        $this->assertSame('移行元ID: CL00000001', $r->memberAttributes['memo']);
    }

    public function test_map_blank_gender_is_warning_null(): void
    {
        $r = $this->mapper()->map($this->row(['性別' => '']));
        $this->assertFalse($r->hasErrors());
        $this->assertNull($r->memberAttributes['gender']);
        $this->assertNotEmpty($r->warnings);
    }

    public function test_map_missing_joined_on_is_error(): void
    {
        $r = $this->mapper()->map($this->row(['入会日' => '']));
        $this->assertTrue($r->hasErrors());
    }

    public function test_map_store_alias_and_fallback(): void
    {
        $r = $this->mapper()->map($this->row(['店舗 名前' => '不明な店舗']));
        $this->assertSame(1, $r->memberAttributes['store_id']); // フォールバック=defaultStoreId
    }

    public function test_map_invalid_gender_is_error(): void
    {
        $r = $this->mapper()->map($this->row(['性別' => '男'])); // GENDER_MAP外の値
        $this->assertTrue($r->hasErrors());
    }

    public function test_map_resolved_plan_missing_from_master_is_error(): void
    {
        // プラン名は解決できるが planIdMap に無い構成（マスタの表記ゆれ/未登録を模す）
        $m = new HacomonoMemberMapper(['ペアプラン' => 5], ['ペアプラン' => 20700], ['松山市駅前店' => 1], 1, 10.0);
        $r = $m->map($this->row([])); // カスタム2=（新）セミパーソナル通い放題 → 解決するが planIdMap に無い
        $this->assertTrue($r->hasErrors());
        $this->assertNull($r->contractAttributes); // 契約は作られない
    }

    public function test_map_suspended_is_withdrawn_with_plan_list_price(): void
    {
        // 停止中: コース名前は空、カスタム2 にプラン、退会日あり、合計金額0
        $r = $this->mapper()->map($this->row([
            '状態' => '停止中', 'カスタム2' => 'セミパーソナル通い放題（松山市駅前）',
            'コース 名前' => '', '合計金額(2回目以降)' => '0', '退会日' => '2026/6/1', '定期購入' => 'FALSE',
        ]));
        $this->assertSame(HacomonoMemberMapper::KIND_WITHDRAWN, $r->kind);
        $this->assertFalse($r->hasErrors());
        $this->assertSame(9800, $r->appliedPriceExcl); // プラン定価(税抜)
        $this->assertSame('2026-06-01', $r->memberAttributes['withdrew_on']);
        $this->assertSame('2026-06-01', $r->contractAttributes['period_end']);
        $this->assertSame(9800, $r->contractAttributes['applied_price_excl']);
    }

    public function test_map_member_with_past_withdraw_date_is_withdrawn(): void
    {
        $r = $this->mapper()->map($this->row(['状態' => '会員', '退会日' => '2026/4/1']));
        $this->assertSame(HacomonoMemberMapper::KIND_WITHDRAWN, $r->kind);
        $this->assertSame('2026-04-01', $r->memberAttributes['withdrew_on']);
    }

    public function test_map_dormant_uses_actual_dormancy_fee(): void
    {
        // 休会: コース名前=休会プラン, カスタム2 に実プラン, 合計金額=1100
        $r = $this->mapper()->map($this->row([
            'カスタム2' => 'セミパーソナル通い放題（松山市駅前）', 'コース 名前' => '休会プラン',
            '合計金額(2回目以降)' => '1100',
        ]));
        $this->assertSame(HacomonoMemberMapper::KIND_DORMANT, $r->kind);
        $this->assertSame('セミパーソナル通い放題', $r->planName);
        $this->assertSame(1000, $r->appliedPriceExcl); // 1100/1.1
        $this->assertNull($r->contractAttributes['period_end']); // 在籍
        $this->assertStringContainsString('休会', $r->memberAttributes['memo']);
    }

    public function test_map_ticket_member_has_no_plan_and_no_contract(): void
    {
        $r = $this->mapper()->map($this->row([
            '状態' => '会員', '定期購入' => 'FALSE', 'カスタム2' => '', 'コース 名前' => 'チケット会員',
            '合計金額(2回目以降)' => '0', '残チケット数' => '4',
        ]));
        $this->assertSame(HacomonoMemberMapper::KIND_TICKET, $r->kind);
        $this->assertFalse($r->hasErrors());
        $this->assertNull($r->memberAttributes['current_plan_id']);
        $this->assertNull($r->contractAttributes); // 契約なし
        $this->assertStringContainsString('チケット会員', $r->memberAttributes['memo']);
    }

    public function test_map_inactive_zero_uses_plan_list_price(): void
    {
        // 定期購入OFF・実請求0・プラン判明 → プラン定価
        $r = $this->mapper()->map($this->row([
            '状態' => '会員', '定期購入' => 'FALSE',
            'カスタム2' => 'パーソナル&セミパーソナル月4回（松山市駅前）',
            'コース 名前' => 'パーソナル&セミパーソナル月4回（松山市駅前）', '合計金額(2回目以降)' => '0',
        ]));
        $this->assertSame(HacomonoMemberMapper::KIND_INACTIVE_ZERO, $r->kind);
        $this->assertSame(13000, $r->appliedPriceExcl);
        $this->assertNull($r->contractAttributes['period_end']);
    }

    public function test_map_inactive_zero_missing_list_price_is_error(): void
    {
        // planId は解決できるが planPriceMap に定価が無い構成（定価NULL等を模す）
        $m = new HacomonoMemberMapper(['セミパーソナル通い放題' => 4], [], ['松山市駅前店' => 1], 1, 10.0);
        $r = $m->map($this->row([
            '状態' => '会員', '定期購入' => 'FALSE',
            'カスタム2' => '（新）セミパーソナル通い放題',
            'コース 名前' => '（新）セミパーソナル通い放題', '合計金額(2回目以降)' => '0',
        ]));
        $this->assertSame(HacomonoMemberMapper::KIND_INACTIVE_ZERO, $r->kind);
        $this->assertTrue($r->hasErrors()); // 定価不明はエラー（applied_price_excl は NOT NULL のため）
    }

    public function test_map_planned_dormant_is_active_not_dormant(): void
    {
        // 変更後コース=休会プラン（次回休会予定）だが現在は通常プランで在籍中
        $r = $this->mapper()->map($this->row([
            '状態' => '会員', 'カスタム2' => '（新）セミパーソナル通い放題',
            'コース 名前' => '（新）セミパーソナル通い放題', '変更後コース 名前' => '休会プラン',
            '合計金額(2回目以降)' => '9702', '定期購入' => 'TRUE',
        ]));
        $this->assertSame(HacomonoMemberMapper::KIND_ACTIVE, $r->kind); // 休会ではなく在籍
        $this->assertSame(8820, $r->appliedPriceExcl); // 通常月会費の税抜(9702/1.1)
        $this->assertNull($r->contractAttributes['period_end']); // 在籍契約（open）
        $this->assertStringContainsString('次回休会予定', $r->memberAttributes['memo']);
    }
}
