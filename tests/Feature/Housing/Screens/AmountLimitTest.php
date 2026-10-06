<?php

namespace Tests\Feature\Housing\Screens;

use App\Models\HsContract;
use App\Models\HsCustomOrder;
use App\Models\HsProperty;

/**
 * 住宅事業の金額・消費税率の入力が、本番の列（金額はどれも符号付き INT、消費税率は DECIMAL(4,2)。2026-10-06 に読み取りで確認）に
 * 入らない値を入力エラーで断る（Bug #73 と同じ形）。
 *
 * ⚠ テストの SQLite は INT や DECIMAL(4,2) の範囲を見ないので、上限を外すと「入力エラーにならず保存される」で落ちる。
 *   本番の MySQL（strict）では保存の時点で 500 になる。
 * ⚠ 期待する文言は trans() で組む（項目名は画面ごとの和名。Bug #49）。
 */
class AmountLimitTest extends HousingScreenTestCase
{
    private function tooLarge(string $attribute, ?string $max = null): string
    {
        return trans('validation.max.numeric', ['attribute' => $attribute, 'max' => $max ?? (string) self::INT_MAX]);
    }

    private function propertyEditForm(HsProperty $property): array
    {
        return $this->browserForm($this->htmlOf(route('housing.properties.edit', $property)), 'action="' . route('housing.properties.update', $property) . '"', 'housingPropertyForm');
    }

    public function test_a_property_amount_up_to_the_limit_is_saved(): void
    {
        $property = $this->property();
        $form = $this->fill($this->propertyEditForm($property), ['building_cost' => (string) self::INT_MAX, 'target_selling_price_building' => (string) self::INT_MAX]);

        $html = $this->landed($this->submit($form, route('housing.properties.edit', $property)));

        $this->assertFlash($html, 'success', '建売物件「HS-001」を更新しました。');
        $this->assertSame([self::INT_MAX, self::INT_MAX], [$property->fresh()->building_cost, $property->fresh()->target_selling_price_building]);
    }

    public function test_a_property_amount_over_the_limit_is_refused(): void
    {
        $property = $this->property();
        $form = $this->fill($this->propertyEditForm($property), ['building_cost' => (string) (self::INT_MAX + 1), 'target_selling_price_building' => (string) (self::INT_MAX + 1)]);

        $html = $this->landed($this->submit($form, route('housing.properties.edit', $property)));

        $this->assertErrorItem($html, $this->tooLarge('建築費'));
        $this->assertErrorItem($html, $this->tooLarge('建物予定販売価格'));
        $this->assertNull($property->fresh()->building_cost);
    }

    private function orderEditForm(HsCustomOrder $order, string $steps = ''): array
    {
        return $this->browserForm($this->htmlOf(route('housing.custom-orders.edit', $order)), 'action="' . route('housing.custom-orders.update', $order) . '"', 'customOrderForm', $steps);
    }

    public function test_a_custom_order_amount_over_the_limit_is_refused(): void
    {
        $order = $this->customOrder(['land_source_type' => 'procurement', 're_procurement_id' => $this->procurement()->id]);
        $form = $this->orderEditForm($order, 'data.isLandCostManual = true; data.landCost = "' . (self::INT_MAX + 1) . '";');
        $form = $this->fill($form, ['building_contract_price' => (string) (self::INT_MAX + 1)]);

        $html = $this->landed($this->submit($form, route('housing.custom-orders.edit', $order)));

        $this->assertErrorItem($html, $this->tooLarge('建物請負金額'));
        $this->assertErrorItem($html, $this->tooLarge('土地原価'));
        $this->assertNull($order->fresh()->building_contract_price);
    }

    public function test_a_tax_rate_that_does_not_fit_the_column_is_refused(): void
    {
        $order = $this->customOrder();
        $form = $this->fill($this->orderEditForm($order), ['tax_rate' => '100']);

        $html = $this->landed($this->submit($form, route('housing.custom-orders.edit', $order)));

        $this->assertErrorItem($html, $this->tooLarge('消費税率', '99.99'));
        $this->assertSame('10.00', (string) $order->fresh()->tax_rate);
    }

    public function test_a_tax_rate_of_99_99_is_saved(): void
    {
        $order = $this->customOrder();
        $form = $this->fill($this->orderEditForm($order), ['tax_rate' => '99.99']);

        $html = $this->landed($this->submit($form, route('housing.custom-orders.edit', $order)));

        $this->assertFlash($html, 'success', '注文住宅案件「CO-001」を更新しました。');
        $this->assertSame('99.99', (string) $order->fresh()->tax_rate);
    }

    public function test_a_building_contract_amount_over_the_limit_is_refused(): void
    {
        $property = $this->property();
        $buyer = $this->buyer();
        $url = route('housing.contracts.create', $property);
        $form = $this->browserForm($this->htmlOf($url), 'action="' . route('housing.contracts.store', $property) . '"', 'buyerSelect', $this->chooseBuyer($buyer));
        $form = $this->fill($form, ['selling_price_land' => (string) (self::INT_MAX + 1), 'selling_price_building' => '20000000', 'tax_rate' => '100', 'contract_date' => '2026-10-01']);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, $this->tooLarge('土地販売価格'));
        $this->assertErrorItem($html, $this->tooLarge('消費税率', '99.99'));
        $this->assertSame(0, HsContract::count());
    }

    public function test_an_amount_over_the_limit_is_refused_on_the_contract_edit_screens(): void
    {
        $property = $this->property(['building_cost' => 15000000]);
        $contract = $this->contract($property, $this->buyer());
        $url = route('housing.contracts.edit-building', $contract);
        $action = route('housing.contracts.update-building', $contract);
        $html = $this->htmlOf($url);
        preg_match('/action="' . preg_quote($action, '/') . '"[^>]*\bx-data="(\{[^"]*\})"/', $html, $m);
        $form = $this->composedForm($html, $action, null, html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), ['datePicker' => '', 'buyerSelect' => '']);
        $form = $this->fill($form, ['building_cost' => (string) (self::INT_MAX + 1), 'selling_price_building' => (string) (self::INT_MAX + 1)]);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, $this->tooLarge('建築原価'));
        $this->assertErrorItem($html, $this->tooLarge('建物販売価格'));
        $this->assertSame(15000000, $property->fresh()->building_cost);

        $order = $this->customOrder(['status' => 'contracted', 'contract_date' => '2026-09-10', 'customer_id' => $this->buyer('佐藤', '花子')->id, 'building_contract_price' => 1, 'building_cost' => 1]);
        $url = route('housing.contracts.edit-custom-order', $order);
        $form = $this->composedForm($this->htmlOf($url), route('housing.contracts.update-custom-order', $order), 'customOrderEditForm', null, ['datePicker' => '', 'buyerSelect' => '']);
        $form = $this->fill($form, ['land_source_type' => 'customer_land', 'building_contract_price' => (string) (self::INT_MAX + 1)]);

        $html = $this->landed($this->submit($form, $url));

        $this->assertErrorItem($html, $this->tooLarge('建物請負金額'));
        $this->assertSame(1, $order->fresh()->building_contract_price);
    }

    // ============================================================
    // 走査: 新しく足した整数・数値の入力にも上限がある（全件。Top trap #13）
    // ============================================================

    public function test_every_integer_and_numeric_rule_in_the_housing_controllers_has_an_upper_bound(): void
    {
        $files = glob(app_path('Http/Controllers/Housing/*.php'));
        $this->assertGreaterThanOrEqual(7, count($files), '住宅事業のコントローラを拾えていない');
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
                // 配列で書いた規則（`['nullable', 'integer', 'min:0', 'max:' . …]`）は、同じ配列の残りの要素に上限があるかを見る。
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
                    } elseif ($depth === 0 && $t === ';') {
                        break;
                    }
                }
                if (! $ok) {
                    $unbounded[] = basename($file) . ':' . $token[2] . ' ' . $rule;
                }
            }
        }
        $this->assertGreaterThanOrEqual(30, $checked, '整数・数値の入力チェックを拾えていない');
        $this->assertSame([], $unbounded, '上限の無い整数・数値の入力がある（本番の MySQL では列に入らない値で 500）');
    }
}
