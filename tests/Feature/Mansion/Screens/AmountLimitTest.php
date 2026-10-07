<?php

namespace Tests\Feature\Mansion\Screens;

use App\Models\MsContract;
use App\Models\MsRoom;

/**
 * 賃貸マンションの金額・数の入力が、本番の列（金額はどれも INT UNSIGNED・総戸数 SMALLINT UNSIGNED・階 TINYINT UNSIGNED・
 * 専有面積 DECIMAL(8,2)。2026-10-07 に読み取りで確認）に入らない値を入力エラーで断る（Bug #73 と同じ形）。
 *
 * ⚠ テストの SQLite は列の範囲を見ないので、上限を外すと「入力エラーにならず保存される」で落ちる。本番の MySQL（strict）では 500 になる。
 * ⚠ 期待する文言は trans() で組む（項目名は画面ごとの和名。Bug #49）。
 */
class AmountLimitTest extends MansionScreenTestCase
{
    private function tooLarge(string $attribute, string|int $max = self::UINT_MAX): string
    {
        return trans('validation.max.numeric', ['attribute' => $attribute, 'max' => (string) $max]);
    }

    private function roomCreateForm(): array
    {
        return $this->parseForm($this->htmlOf(route('mansion.rooms.create', $this->building)), 'action="' . route('mansion.rooms.store', $this->building) . '"');
    }

    public function test_room_amounts_up_to_the_column_limit_are_saved(): void
    {
        $form = $this->fill($this->roomCreateForm(), ['room_number' => '101', 'floor' => '255', 'area_sqm' => '999999.99',
            'rent' => (string) self::UINT_MAX, 'common_fee' => (string) self::UINT_MAX, 'deposit' => (string) self::UINT_MAX, 'key_money' => (string) self::UINT_MAX]);

        $this->assertFlash($this->landed($this->submit($form, route('mansion.rooms.create', $this->building))), 'success', '部屋を登録しました');
        $room = MsRoom::firstOrFail();
        $this->assertSame([255, '999999.99', self::UINT_MAX], [$room->floor, $room->area_sqm, $room->rent]);
    }

    public function test_room_amounts_over_the_column_limit_are_refused(): void
    {
        $over = (string) (self::UINT_MAX + 1);
        $form = $this->fill($this->roomCreateForm(), ['room_number' => '101', 'floor' => '256', 'area_sqm' => '1000000',
            'rent' => $over, 'common_fee' => $over, 'deposit' => $over, 'key_money' => $over]);

        $html = $this->landed($this->submit($form, route('mansion.rooms.create', $this->building)));

        foreach ([['階数', 255], ['専有面積', '999999.99'], ['募集賃料', self::UINT_MAX], ['共益費', self::UINT_MAX], ['敷金', self::UINT_MAX], ['礼金', self::UINT_MAX]] as [$attribute, $max]) {
            $this->assertErrorItem($html, $this->tooLarge($attribute, $max));
        }
        $this->assertSame(0, MsRoom::count());
    }

    public function test_property_counts_over_the_column_limit_are_refused(): void
    {
        $url = route('mansion.properties.edit', $this->building);
        $form = $this->composedForm($this->htmlOf($url), route('mansion.properties.update', $this->building), 'propertyForm', null, ['monthPicker' => '']);
        $form = $this->fill($form, ['total_units' => '65536', 'total_floors' => '256']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, $this->tooLarge('総戸数', 65535));
        $this->assertErrorItem($html, $this->tooLarge('階数', 255));
        $this->assertNull($this->building->fresh()->total_units);
    }

    public function test_parking_and_contract_amounts_over_the_column_limit_are_refused(): void
    {
        $over = (string) (self::UINT_MAX + 1);
        $tenant = $this->tenant();
        $parking = $this->parking('A-1');
        $url = route('mansion.parkings.edit', $parking);
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . route('mansion.parkings.update', $parking) . '"'), ['monthly_fee' => $over]);
        $this->assertErrorItem($this->landed($this->submit($form, $url)), $this->tooLarge('月額料金'));

        $contract = $this->contract($this->room('101'), $tenant);
        $url = route('mansion.contracts.edit', $contract);
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . route('mansion.contracts.update', $contract) . '"'),
            ['rent' => $over, 'common_fee' => $over, 'deposit' => $over, 'key_money' => $over]);
        $html = $this->landed($this->submit($form, $url));
        foreach (['賃料', '共益費', '敷金', '礼金'] as $attribute) {
            $this->assertErrorItem($html, $this->tooLarge($attribute));
        }

        $pc = $this->parkingContract($this->parking('A-2'), $tenant);
        $url = route('mansion.parking-contracts.edit', $pc);
        $form = $this->fill($this->parseForm($this->htmlOf($url), 'action="' . route('mansion.parking-contracts.update', $pc) . '"'),
            ['monthly_fee' => $over, 'deposit' => $over]);
        $html = $this->landed($this->submit($form, $url));
        $this->assertErrorItem($html, $this->tooLarge('月額料金'));
        $this->assertErrorItem($html, $this->tooLarge('敷金'));
        $this->assertSame([70000, 5000], [$contract->fresh()->rent, $pc->fresh()->monthly_fee]);
    }

    public function test_revision_and_settlement_amounts_over_the_column_limit_are_refused(): void
    {
        $over = self::UINT_MAX + 1;
        $tenant = $this->tenant();
        $contract = $this->contract($this->room('101'), $tenant);
        $url = route('mansion.contracts.revise.show', $contract);
        $form = $this->composedForm($this->htmlOf($url), route('mansion.contracts.revise', $contract), 'rentRevise', null, ['datePicker' => ''],
            "data.newRent = {$over}; data.newFee = {$over};");
        $html = $this->landed($this->submit($form, $url));
        $this->assertErrorItem($html, $this->tooLarge('新賃料'));
        $this->assertErrorItem($html, $this->tooLarge('新共益費'));

        $url = route('mansion.contracts.terminate.show', $contract);
        $form = $this->composedForm($this->htmlOf($url), route('mansion.contracts.terminate', $contract), 'terminateContract', null, ['datePicker' => ''],
            "data.restorationCost = {$over}; data.cleaningCost = {$over}; data.otherDeductions.push({ name: '違約金', amount: {$over} });");
        $html = $this->landed($this->submit($form, $url));
        $this->assertErrorItem($html, $this->tooLarge('原状回復費'));
        $this->assertErrorItem($html, $this->tooLarge('清掃費'));
        $this->assertErrorItem($html, $this->tooLarge('差引金額'));
        $this->assertSame('active', $contract->fresh()->status->value);

        $pc = $this->parkingContract($this->parking('A-1'), $tenant);
        $url = route('mansion.parking-contracts.revise.show', $pc);
        $form = $this->composedForm($this->htmlOf($url), route('mansion.parking-contracts.revise', $pc), 'reviseParkingForm', null, ['datePicker' => ''], "data.newFee = {$over};");
        $this->assertErrorItem($this->landed($this->submit($form, $url)), $this->tooLarge('新月額料金'));
        $this->assertSame(5000, $pc->fresh()->monthly_fee);
        $this->assertSame(0, MsContract::find($contract->id)->revisions()->count());
    }

    // ============================================================
    // 走査: 新しく足した整数・数値の入力にも上限がある（全件。Top trap #13）
    // ============================================================

    public function test_every_integer_and_numeric_rule_in_the_mansion_controllers_has_an_upper_bound(): void
    {
        $files = glob(app_path('Http/Controllers/Mansion/*.php'));
        $this->assertGreaterThanOrEqual(7, count($files), '賃貸マンションのコントローラを拾えていない');
        $bounded = fn (string $rule) => (bool) preg_match('/(^|\|)(max:|between:|exists:)/', $rule);
        $checked = 0;
        $unbounded = [];
        foreach ($files as $file) {
            $tokens = token_get_all(file_get_contents($file));
            foreach ($tokens as $i => $token) {
                if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }
                $rule = substr($token[1], 1, -1);
                if (! preg_match('/(^|\|)(integer|numeric)(\||$)/', $rule)) {
                    continue;
                }
                $checked++;
                // 上限は max（定数を連結するので文字列は `max:` で終わる）・between・既存の行（exists）のどれか
                $ok = $bounded($rule);
                // 配列で書いた規則（`['integer', Rule::exists(…)]`）は、同じ配列の残りの要素に上限（max・exists の文字列か Rule::exists）があるかを見る。
                // ⚠ `|` でつないだ規則の文字列は自分の中だけで見る（後ろを見ると、次の項目の上限に当たって素通りする）
                for ($j = $i + 1, $depth = 0; ! $ok && ! str_contains($rule, '|') && $j < count($tokens); $j++) {
                    $t = $tokens[$j];
                    if ($t === '[' || $t === '(') {
                        $depth++;
                    } elseif ($t === ']' || $t === ')') {
                        if ($depth-- === 0) {
                            break;
                        }
                    } elseif ($depth === 0 && is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
                        $ok = $bounded(substr($t[1], 1, -1));
                    } elseif ($depth === 0 && is_array($t) && $t[0] === T_STRING && $t[1] === 'exists' && ($tokens[$j - 1][0] ?? null) === T_DOUBLE_COLON) {
                        $ok = true;
                    } elseif ($depth === 0 && $t === ';') {
                        break;
                    }
                }
                if (! $ok) {
                    $unbounded[] = basename($file) . ':' . $token[2] . ' ' . $rule;
                }
            }
        }
        $this->assertGreaterThanOrEqual(20, $checked, '整数・数値の入力チェックを拾えていない');
        $this->assertSame([], $unbounded, '上限の無い整数・数値の入力がある（本番の MySQL では列に入らない値で 500）');
    }
}
