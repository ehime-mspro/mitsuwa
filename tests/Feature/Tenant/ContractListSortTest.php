<?php

namespace Tests\Feature\Tenant;

use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\ParsesSortLinks;
use Tests\TestCase;

/**
 * テナント契約一覧の既定順と見出しの並び替え（設計書 2026-09-11）。
 *
 * ⚠ 既定の絞り込みは「契約中」。解約済みを含めて並びを見るときは ?status=all を付ける。
 * ⚠ 物件名は A館 / B館 / C館（先頭が ASCII 大文字）。SQLite のバイト順と本番 MySQL の
 *   utf8mb4_unicode_ci で順序が一致する（設計書 §4.4）。漢字で始まる名前にすると
 *   本番とテストで順が変わりうる。
 * ⚠ **作成順（＝ id 順）を、既定順・並び替えた順のどれとも食い違わせる。** SQLite は同点の行を
 *   id の昇順で返す（2026-09-11 実測）ので、揃えるとキーを 1 つ消す変異が「たまたま同じ順」で
 *   素通りする（UnitListSortTest::test_tied_rows_keep_the_default_order と同じ理屈）。
 *   各テストは作成順を assertSame で先に固定してから並びを見る。
 * ⚠ 経営層は department.access を素通りする（部門の紐付けは要らない）。
 */
class ContractListSortTest extends TestCase
{
    use ParsesForms;
    use ParsesSortLinks;
    use RefreshDatabase;

    private ?Customer $customer = null;

    private int $seq = 0;

    /** password.change を通過する経営層ユーザー */
    private function executive(): User
    {
        return User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /** 契約に要る顧客（テストの SQLite では customer_id が NOT NULL。1 件を使い回す） */
    private function customer(): Customer
    {
        return $this->customer ??= Customer::create([
            'code' => 'CUST-CS001',
            'name' => 'テスト商事',
            'customer_type' => 'corporation',
        ]);
    }

    /** ⚠ $name は SQLite と MySQL で順序が一致するもの（クラスの docblock） */
    private function makeProperty(string $name): Property
    {
        return Property::create([
            'code' => sprintf('T-CS%03d', ++$this->seq),
            'name' => $name,
            'property_type' => 'tenant',
            'department' => 'tenant',
            'operation_status' => 'active',
            'address' => '愛媛県松山市本町1-1',
        ]);
    }

    /** display_name は本番と同じ Unit::generateDisplayName() で作る（UNIQUE(property_id, display_name)） */
    private function makeUnit(Property $property, ?int $floor, string $room): Unit
    {
        return Unit::create([
            'property_id' => $property->id,
            'floor' => $floor,
            'room_number' => $room,
            'display_name' => Unit::generateDisplayName($floor, $room),
            'status' => 'occupied',
        ]);
    }

    /**
     * 契約を 1 件作る。家賃発生日は契約日と同じ（テストの SQLite では NOT NULL）。
     *
     * @param  array<string, mixed>  $attrs  rent / common_fee / status / store_name などの上書き
     */
    private function makeContract(Unit $unit, string $contractDate, array $attrs = []): Contract
    {
        return Contract::create(array_merge([
            'contract_number'  => sprintf('C-CS-%03d', ++$this->seq),
            'department'       => 'tenant',
            'property_id'      => $unit->property_id,
            'unit_id'          => $unit->id,
            'customer_id'      => $this->customer()->id,
            'status'           => 'active',
            'contract_date'    => $contractDate,
            'rent_start_date'  => $contractDate,
            'rent'             => 100000,
            'common_fee'       => 0,
            'garbage_fee'      => 0,
            'pest_control_fee' => 0,
        ], $attrs));
    }

    /** 作成順が id 順であることを固定する（崩れると各テストの「変異の検出力」の前提が成立しない） */
    private function assertCreatedInOrder(array $contracts): void
    {
        $this->assertSame(
            array_map(fn (Contract $c) => $c->id, $contracts),
            Contract::orderBy('id')->pluck('id')->all(),
            '作成順が id 順になっていない（テストの前提が崩れている）'
        );
    }

    /** 1 ページ目の契約 ID（表示順のまま） */
    private function listedIds(TestResponse $response): array
    {
        $response->assertOk();

        return $response->viewData('contracts')->pluck('id')->all();
    }

    /** 契約の ID の並び */
    private function ids(Contract ...$contracts): array
    {
        return array_map(fn (Contract $c) => $c->id, $contracts);
    }

    /**
     * 賃料収入の SQL 式と PHP アクセサが同じ値を出すこと（Bug #41。設計書 §7.1）。
     *
     * ⚠ 片方だけ直すと、画面の数字は正しいのに並び順だけが別の値で並ぶ。画面から気づけない。
     * ⚠ 共益費・ゴミ代・駆除代が NULL の行を必ず含める。0 で作ると COALESCE の経路を
     *   一度も通らず、COALESCE を外す変異が素通りする。
     * ⚠ (int) キャストは NULL を 0 に潰すので、キャストの前に NULL でないことを見る
     *   （UnitListSortTest と同じ。COALESCE を全部外す変異が緑のまま通った前例がある）。
     */
    public function test_the_income_sql_agrees_with_the_php_accessor(): void
    {
        $property = $this->makeProperty('A館');
        $this->makeContract($this->makeUnit($property, 1, 'A'), '2026-04-01', ['rent' => 285000, 'common_fee' => 25000, 'garbage_fee' => 3000, 'pest_control_fee' => 2000]);
        $this->makeContract($this->makeUnit($property, 1, 'B'), '2026-04-01', ['rent' => 180000, 'common_fee' => 18000, 'garbage_fee' => 3000, 'pest_control_fee' => 0]);
        $nulls = $this->makeContract($this->makeUnit($property, 2, 'A'), '2026-04-01', ['rent' => 95000, 'common_fee' => null, 'garbage_fee' => null, 'pest_control_fee' => null]);
        $this->makeContract($this->makeUnit($property, 2, 'B'), '2026-04-01', ['rent' => 120000, 'common_fee' => 0, 'garbage_fee' => null, 'pest_control_fee' => 700]);

        $fresh = $nulls->fresh();
        $this->assertNull($fresh->common_fee, '共益費が NULL のデータになっていない（COALESCE の経路を通らない）');
        $this->assertNull($fresh->garbage_fee);
        $this->assertNull($fresh->pest_control_fee);

        $fromSql = Contract::selectRaw('id, ' . Contract::MONTHLY_TOTAL_SQL . ' as total')->pluck('total', 'id')->all();

        $values = [];
        foreach (Contract::orderBy('id')->get() as $contract) {
            $this->assertNotNull($fromSql[$contract->id], 'COALESCE が外れて式が NULL になっている');
            $this->assertSame(
                $contract->monthly_total,
                (int) $fromSql[$contract->id],
                "契約 {$contract->contract_number} の月額合計が SQL 式と PHP アクセサで食い違う"
            );
            $values[] = $contract->monthly_total;
        }

        // ⚠ 値に分散が無いと「SQL を壊しても PHP と一致」で false-pass しうる（Bug #40）
        $this->assertGreaterThan(1, count(array_unique($values)), '月額合計に分散が無いデータでは検出力が出ない');
        $this->assertContains(315000, $values, '4 項目すべてを足していない（285000+25000+3000+2000）');
        $this->assertContains(95000, $values, 'NULL の項目を 0 として足していない');
        $this->assertContains(120700, $values, '駆除代を足していない（120000+0+NULL+700）');
    }

    /**
     * ページ送りのリンクを実際に辿って、全ページの契約 ID を順に集める。
     *
     * ⚠ **`?page=2` を自分で組み立ててはいけない。** リンクが壊れていても sort が付いた
     *   状態で届くので**必ず緑**になる（Bug #31）。$paginator->nextPageUrl() を辿ること。
     */
    private function collectIdsAcrossPages(User $user, string $url): array
    {
        $ids = [];
        $guard = 0;

        while ($url !== null) {
            $response = $this->actingAs($user)->get($url);
            $response->assertOk();

            $paginator = $response->viewData('contracts');
            foreach ($paginator as $contract) {
                $ids[] = $contract->id;
            }

            $url = $paginator->nextPageUrl();

            $this->assertLessThan(20, ++$guard, 'ページ送りが終わらない');
        }

        return $ids;
    }

    /**
     * 物件名の順と契約日の順が逆になる 3 件（B館 → C館 → A館 の順に作る）。
     *
     *   | 返り値 | 物件 | 契約日     | 作成順 |
     *   | [0] $a | A館  | 2024-04-01 | 3 |
     *   | [1] $b | B館  | 2025-04-01 | 1 |
     *   | [2] $c | C館  | 2026-04-01 | 2 |
     *
     *   既定（契約日の新しい順）: [$c, $b, $a]
     *   旧既定（物件名順）・契約日の古い順: [$a, $b, $c]   id の降順: [$a, $c, $b]
     *
     * @return array{0: Contract, 1: Contract, 2: Contract} [$a, $b, $c]
     */
    private function threeContractsWhoseNameOrderIsTheReverseOfTheDateOrder(): array
    {
        $b = $this->makeContract($this->makeUnit($this->makeProperty('B館'), 1, 'A'), '2025-04-01');
        $c = $this->makeContract($this->makeUnit($this->makeProperty('C館'), 1, 'A'), '2026-04-01');
        $a = $this->makeContract($this->makeUnit($this->makeProperty('A館'), 1, 'A'), '2024-04-01');

        $this->assertCreatedInOrder([$b, $c, $a]);

        return [$a, $b, $c];
    }

    /**
     * 既定順は契約日の新しい順（設計書 §4.4）。
     *
     * ⚠ **物件名の順と契約日の順を逆にしてある。** 旧既定（物件名 → 階数 → 号室）に戻す
     *   変異を確実に赤くする（設計書 §7.1）。作成順も両方と食い違わせてあるので、
     *   「新しい契約 ＝ id が大きい」と取り違えて id の降順だけで並べる変異も赤くなる。
     */
    public function test_the_default_order_is_the_contract_date_newest_first(): void
    {
        [$a, $b, $c] = $this->threeContractsWhoseNameOrderIsTheReverseOfTheDateOrder();

        $this->assertSame(
            $this->ids($c, $b, $a),
            $this->listedIds($this->actingAs($this->executive())->get(route('tenant.contracts.index'))),
            '既定順が契約日の新しい順になっていない'
        );
    }

    /**
     * 契約日の古い順（?sort=contract_date&dir=asc）。
     *
     * ⚠ 上の 3 件は使わない。あのデータの「契約日の古い順」は旧既定（物件名順）と同じ並びになり、
     *   **実装前から緑**になる（＝このテストが何も測らない）。物件 / 区画用の 4 件なら
     *   古い順 [$a2, $b1, $a3, $a1] ／ 旧既定 [$a1, $a2, $a3, $b1] ／ 既定 [$a1, $a3, $b1, $a2] ／
     *   id 順 [$b1, $a3, $a1, $a2] がすべて食い違う。
     */
    public function test_contract_date_can_be_sorted_oldest_first(): void
    {
        [$a1, $a2, $a3, $b1] = $this->fourContractsForThePropertyUnitColumn();

        $response = $this->actingAs($this->executive())
            ->get(route('tenant.contracts.index', ['sort' => 'contract_date', 'dir' => 'asc']));

        $this->assertSame($this->ids($a2, $b1, $a3, $a1), $this->listedIds($response), '契約日の古い順になっていない');
    }

    /**
     * 同じ契約日の中は 物件名 → 階数 → 号室 → 契約 ID の新しい順（設計書 §4.4）。
     *
     * ⚠ **4 つのキーそれぞれが単独で検出力を持つ**ように組んである（SortBarTest の
     *   既定順テストと同じ流儀。キーを 1 つ消すと必ず並びが変わる）:
     *
     *   | 変数  | 物件 | 階 | 号室 | 状態     | 作成順 |
     *   | $old  | A館  | 5  | B    | 解約済み | 1 | ← $new と同じ区画（旧契約）
     *   | $u5a  | A館  | 5  | A    | 契約中   | 2 |
     *   | $new  | A館  | 5  | B    | 契約中   | 3 | ← $old と同じ区画（新契約）
     *   | $u2c  | A館  | 2  | C    | 契約中   | 4 |
     *   | $b1a  | B館  | 1  | A    | 契約中   | 5 |
     *
     *   期待: [$u2c, $u5a, $new, $old, $b1a]
     *   - 物件名を消すと: 階の昇順で B館 1A が先頭 → [$b1a, $u2c, $u5a, $new, $old]
     *   - 階を消すと:     A館の中が号室順 → [$u5a, $new, $old, $u2c, $b1a]
     *   - 号室を消すと:   5 階の中が id の降順 → [$u2c, $new, $u5a, $old, $b1a]
     *   - id DESC を消すと: 同点の $old / $new を SQLite が id の昇順で返す → [$u2c, $u5a, $old, $new, $b1a]
     *     （2026-09-11 に同じ形の SQL で実測）
     *
     * ⚠ 同じ区画の旧契約と新契約は物件名・階数・号室が全部同点になる。これが並びを一意にする
     *   最後のキー（contracts.id DESC）が要る理由（設計書 §2.3）。解約済みを含めるので ?status=all。
     */
    public function test_contracts_on_the_same_date_follow_property_floor_room_then_the_newer_contract(): void
    {
        $a = $this->makeProperty('A館');
        $b = $this->makeProperty('B館');
        $unit5b = $this->makeUnit($a, 5, 'B');

        $old = $this->makeContract($unit5b, '2026-04-01', ['status' => 'terminated', 'contract_end_date' => '2026-06-30']);
        $u5a = $this->makeContract($this->makeUnit($a, 5, 'A'), '2026-04-01');
        $new = $this->makeContract($unit5b, '2026-04-01');
        $u2c = $this->makeContract($this->makeUnit($a, 2, 'C'), '2026-04-01');
        $b1a = $this->makeContract($this->makeUnit($b, 1, 'A'), '2026-04-01');

        $this->assertCreatedInOrder([$old, $u5a, $new, $u2c, $b1a]);

        $response = $this->actingAs($this->executive())->get(route('tenant.contracts.index', ['status' => 'all']));

        $this->assertSame(
            $this->ids($u2c, $u5a, $new, $old, $b1a),
            $this->listedIds($response),
            '同じ契約日の中が 物件名 → 階数 → 号室 → 契約 ID の新しい順になっていない'
        );
    }

    /**
     * 物件 / 区画で並べる 4 件（B館 1A → A館 2B → A館 1C → A館 2A の順に作る）。
     *
     *   | 返り値  | 物件 | 階 | 号室 | 契約日     | 作成順 |
     *   | [0] $a1 | A館  | 1  | C    | 2026-01-01 | 3 |
     *   | [1] $a2 | A館  | 2  | A    | 2024-01-01 | 4 |
     *   | [2] $a3 | A館  | 2  | B    | 2025-06-01 | 2 |
     *   | [3] $b1 | B館  | 1  | A    | 2025-01-01 | 1 |
     *
     *   昇順 [$a1, $a2, $a3, $b1] ／ 降順 [$b1, $a3, $a2, $a1] ／ 既定 [$a1, $a3, $b1, $a2]
     *   昇順でキーを 1 つ消すと:  物件名→[$b1, $a1, $a2, $a3]  階→[$a2, $a3, $a1, $b1]  号室→[$a1, $a3, $a2, $b1]
     *   降順で 1 キーだけ昇順に残すと: 階→[$b1, $a1, $a3, $a2]  号室→[$b1, $a2, $a3, $a1]
     *   ⚠ 号室の違いが階の違いと逆向き（1C / 2A）なので、階と号室を取り違えても赤くなる。
     *
     * @return array{0: Contract, 1: Contract, 2: Contract, 3: Contract} [$a1, $a2, $a3, $b1]
     */
    private function fourContractsForThePropertyUnitColumn(): array
    {
        $a = $this->makeProperty('A館');
        $b = $this->makeProperty('B館');

        $b1 = $this->makeContract($this->makeUnit($b, 1, 'A'), '2025-01-01');
        $a3 = $this->makeContract($this->makeUnit($a, 2, 'B'), '2025-06-01');
        $a1 = $this->makeContract($this->makeUnit($a, 1, 'C'), '2026-01-01');
        $a2 = $this->makeContract($this->makeUnit($a, 2, 'A'), '2024-01-01');

        $this->assertCreatedInOrder([$b1, $a3, $a1, $a2]);

        return [$a1, $a2, $a3, $b1];
    }

    /** 物件 / 区画は 物件名 → 階数 → 号室 の 3 キーとも同じ向きで並ぶ（降順は昇順の完全な逆順。設計書 §4.4） */
    public function test_property_unit_sorts_all_three_keys_in_the_same_direction(): void
    {
        [$a1, $a2, $a3, $b1] = $this->fourContractsForThePropertyUnitColumn();
        $user = $this->executive();

        $asc = $this->actingAs($user)->get(route('tenant.contracts.index', ['sort' => 'property_unit', 'dir' => 'asc']));
        $this->assertSame($this->ids($a1, $a2, $a3, $b1), $this->listedIds($asc), '物件 / 区画の昇順（物件名 → 階数 → 号室）になっていない');

        $desc = $this->actingAs($user)->get(route('tenant.contracts.index', ['sort' => 'property_unit', 'dir' => 'desc']));
        $this->assertSame($this->ids($b1, $a3, $a2, $a1), $this->listedIds($desc), '物件 / 区画の降順が昇順の完全な逆順になっていない（3 キーのどれかが昇順のまま）');
    }

    /**
     * 賃料収入で並べる 4 件。**4 項目のどれを式から落としても並びが変わる**ように組んである。
     *
     *   | 返り値 | 家賃   | 共益費 | ゴミ代 | 駆除代 | 合計   | 契約日     | 作成順 |
     *   | [0] $x | 100000 | 50000  | 0      | 0      | 150000 | 2024-04-01 | 2 |
     *   | [1] $w | 80000  | 0      | 0      | 60000  | 140000 | 2023-04-01 | 3 |
     *   | [2] $z | 90000  | 0      | 40000  | 0      | 130000 | 2025-04-01 | 1 |
     *   | [3] $y | 120000 | 0      | 0      | 0      | 120000 | 2026-04-01 | 4 |
     *
     *   多い順 [$x, $w, $z, $y] ／ 少ない順 [$y, $z, $w, $x] ／ 既定 [$y, $z, $x, $w]
     *   家賃だけで並べると [$y, $x, $z, $w]、共益費を落とすと [$w, $z, $y, $x]、
     *   ゴミ代を落とすと [$x, $w, $y, $z]、駆除代を落とすと [$x, $z, $y, $w]
     *
     * @return array{0: Contract, 1: Contract, 2: Contract, 3: Contract} [$x, $w, $z, $y]
     */
    private function fourContractsForTheIncomeColumn(): array
    {
        $property = $this->makeProperty('A館');

        $z = $this->makeContract($this->makeUnit($property, 1, 'A'), '2025-04-01', ['rent' => 90000,  'garbage_fee' => 40000]);
        $x = $this->makeContract($this->makeUnit($property, 1, 'B'), '2024-04-01', ['rent' => 100000, 'common_fee' => 50000]);
        $w = $this->makeContract($this->makeUnit($property, 2, 'A'), '2023-04-01', ['rent' => 80000,  'pest_control_fee' => 60000]);
        $y = $this->makeContract($this->makeUnit($property, 2, 'B'), '2026-04-01', ['rent' => 120000]);

        $this->assertCreatedInOrder([$z, $x, $w, $y]);

        return [$x, $w, $z, $y];
    }

    /** 賃料収入は 4 項目の合計で並ぶ（設計書 §4.4） */
    public function test_income_sorts_by_the_monthly_total_in_both_directions(): void
    {
        [$x, $w, $z, $y] = $this->fourContractsForTheIncomeColumn();
        $user = $this->executive();

        $desc = $this->actingAs($user)->get(route('tenant.contracts.index', ['sort' => 'income', 'dir' => 'desc']));
        $this->assertSame($this->ids($x, $w, $z, $y), $this->listedIds($desc), '賃料収入の多い順になっていない（4 項目の合計で並べていない）');
        $this->assertSame(
            [150000, 140000, 130000, 120000],
            $desc->viewData('contracts')->map(fn (Contract $c) => $c->monthly_total)->all(),
            '賃料収入の実値が想定と違う'
        );

        $asc = $this->actingAs($user)->get(route('tenant.contracts.index', ['sort' => 'income', 'dir' => 'asc']));
        $this->assertSame($this->ids($y, $z, $w, $x), $this->listedIds($asc), '賃料収入の少ない順になっていない');
    }

    /**
     * 並び替え中に値が同じ行は、既定順を丸ごと後ろに付けて並べる（設計書 §4.4 / 前例 §4.3-3）。
     *
     *   | 変数 | 物件 | 階 | 契約日     | 賃料収入 | 作成順 |
     *   | $r1  | B館  | 1  | 2025-01-01 | 100000   | 1 |
     *   | $r2  | A館  | 1  | 2025-01-01 | 100000   | 2 |
     *   | $r3  | A館  | 2  | 2026-01-01 | 100000   | 3 |
     *
     *   賃料収入は 3 件とも同点 → **多い順でも少ない順でも**既定順 [$r3, $r2, $r1]
     *   - 後ろの既定順を丸ごと落とすと id 順 [$r1, $r2, $r3]
     *   - 後ろに契約日しか付けないと 2025 年の 2 件が id 順 [$r3, $r1, $r2]
     *   契約日の古い順は 2025 年の 2 件が同点 → 物件名順 [$r2, $r1, $r3]（後ろを落とすと [$r1, $r2, $r3]）
     */
    public function test_rows_tied_on_the_sorted_column_keep_the_whole_default_order(): void
    {
        $a = $this->makeProperty('A館');
        $b = $this->makeProperty('B館');

        $r1 = $this->makeContract($this->makeUnit($b, 1, 'A'), '2025-01-01');
        $r2 = $this->makeContract($this->makeUnit($a, 1, 'A'), '2025-01-01');
        $r3 = $this->makeContract($this->makeUnit($a, 2, 'A'), '2026-01-01');

        $this->assertCreatedInOrder([$r1, $r2, $r3]);

        $user = $this->executive();

        foreach (['desc', 'asc'] as $dir) {
            $response = $this->actingAs($user)->get(route('tenant.contracts.index', ['sort' => 'income', 'dir' => $dir]));
            $this->assertSame($this->ids($r3, $r2, $r1), $this->listedIds($response), "賃料収入が同点の行が既定順になっていない（{$dir}）");
        }

        $byDate = $this->actingAs($user)->get(route('tenant.contracts.index', ['sort' => 'contract_date', 'dir' => 'asc']));
        $this->assertSame($this->ids($r2, $r1, $r3), $this->listedIds($byDate), '契約日が同点の行が既定順（物件名順）になっていない');
    }

    /** 不正な sort は 500 にせず既定順、不正な dir は降順（設計書 §4.2 / ListSort::fromRequest() の既存仕様） */
    public function test_invalid_sort_parameters_fall_back_to_the_default_order(): void
    {
        $property = $this->makeProperty('A館');
        $y = $this->makeContract($this->makeUnit($property, 1, 'A'), '2025-04-01', ['rent' => 300000]);
        $x = $this->makeContract($this->makeUnit($property, 1, 'B'), '2026-04-01', ['rent' => 100000]);
        $z = $this->makeContract($this->makeUnit($property, 1, 'C'), '2024-04-01', ['rent' => 200000]);

        $this->assertCreatedInOrder([$y, $x, $z]);

        $user = $this->executive();

        foreach ([
            '?sort=name',            // 許可リストに無い（店舗名・状態は並び替えない）
            '?sort[]=income',        // 配列で来る
            '?sort=%3Cscript%3E',    // 手入力・古いブックマーク
            '?sort=',                // 空
        ] as $queryString) {
            $this->assertSame(
                $this->ids($x, $y, $z),
                $this->listedIds($this->actingAs($user)->get(route('tenant.contracts.index') . $queryString)),
                "{$queryString} で既定順に落ちていない"
            );
        }

        // dir だけ不正なら降順（多い順）
        $this->assertSame(
            $this->ids($y, $z, $x),
            $this->listedIds($this->actingAs($user)->get(route('tenant.contracts.index') . '?sort=income&dir=up')),
            '不正な dir が降順として扱われていない'
        );
    }

    /** 絞り込み（ステータス・物件・キーワード）は並び替え中も効く（設計書 §7.1） */
    public function test_filters_still_apply_while_sorted(): void
    {
        $a = $this->makeProperty('A館');
        $b = $this->makeProperty('B館');

        $a1 = $this->makeContract($this->makeUnit($a, 1, 'A'), '2024-01-01', ['rent' => 150000, 'store_name' => 'カフェ本町']);
        $a2 = $this->makeContract($this->makeUnit($a, 2, 'A'), '2023-01-01', ['rent' => 120000, 'store_name' => '本町書店', 'status' => 'terminated', 'contract_end_date' => '2025-12-31']);
        $a3 = $this->makeContract($this->makeUnit($a, 3, 'A'), '2025-01-01', ['rent' => 130000, 'store_name' => '本町薬局']);
        $b1 = $this->makeContract($this->makeUnit($b, 1, 'A'), '2026-01-01', ['rent' => 110000, 'store_name' => 'カフェ湊町']);
        $b2 = $this->makeContract($this->makeUnit($b, 2, 'A'), '2022-01-01', ['rent' => 140000, 'store_name' => '湊町書店', 'status' => 'terminated', 'contract_end_date' => '2025-12-31']);

        $user = $this->executive();

        // ステータス: 解約済みだけを多い順（既定順なら [$a2, $b2]）
        $this->assertSame(
            $this->ids($b2, $a2),
            $this->listedIds($this->actingAs($user)->get(route('tenant.contracts.index', ['status' => 'terminated', 'sort' => 'income', 'dir' => 'desc']))),
            'ステータスの絞り込みと賃料収入の並び替えが両立していない'
        );

        // 物件: A館の契約中だけを物件 / 区画の昇順（既定順なら [$a3, $a1]）
        $this->assertSame(
            $this->ids($a1, $a3),
            $this->listedIds($this->actingAs($user)->get(route('tenant.contracts.index', ['property_id' => $a->id, 'sort' => 'property_unit', 'dir' => 'asc']))),
            '物件の絞り込みと物件 / 区画の並び替えが両立していない'
        );

        // キーワード: 「カフェ」の契約中だけを契約日の古い順（既定順なら [$b1, $a1]）
        $this->assertSame(
            $this->ids($a1, $b1),
            $this->listedIds($this->actingAs($user)->get(route('tenant.contracts.index', ['keyword' => 'カフェ', 'sort' => 'contract_date', 'dir' => 'asc']))),
            'キーワードの絞り込みと契約日の並び替えが両立していない'
        );
    }

    /**
     * ページをまたいでも行が重複せず・消えず・全体を通して並んでいること（設計書 §7.1）。
     *
     * ⚠ **1 ページ目だけでは測れない。** 1 ページ目の 10 件が並ぶことは
     *   「ページを切ってから並べ替える」壊れ方でも成立する（前例 §3.1）。
     * ⚠ 23 件 ＝ 3 ページ。日付 12 通り・賃料 4 通りで**同点だらけ**にしてある。
     * ⚠ withQueryString() を外すと 2 ページ目以降で sort が落ち、並びが途中で既定に戻る。
     */
    public function test_paging_through_a_sorted_list_yields_every_contract_exactly_once(): void
    {
        $properties = [$this->makeProperty('A館'), $this->makeProperty('B館'), $this->makeProperty('C館')];
        $incomeById = [];
        $dateById = [];

        for ($i = 1; $i <= 23; $i++) {
            // 物件ごとに階が重ならない（UNIQUE(property_id, display_name)）
            $unit = $this->makeUnit($properties[$i % 3], intdiv($i, 3) + 1, 'A');
            $date = sprintf('2025-%02d-01', ($i * 5) % 12 + 1);
            $rent = 100000 + ($i % 4) * 10000;

            $contract = $this->makeContract($unit, $date, ['rent' => $rent]);
            $incomeById[$contract->id] = $rent;
            $dateById[$contract->id] = $date;
        }

        $user = $this->executive();

        $cases = [
            '既定'             => [route('tenant.contracts.index'), fn (int $id) => $dateById[$id], 'desc'],
            '賃料収入 多い順'   => [route('tenant.contracts.index', ['sort' => 'income', 'dir' => 'desc']), fn (int $id) => $incomeById[$id], 'desc'],
            '賃料収入 少ない順' => [route('tenant.contracts.index', ['sort' => 'income', 'dir' => 'asc']), fn (int $id) => $incomeById[$id], 'asc'],
        ];

        foreach ($cases as $label => [$url, $valueOf, $direction]) {
            $ids = $this->collectIdsAcrossPages($user, $url);

            $this->assertCount(23, $ids, "{$label}: ページ送りで行が消えている");
            $this->assertCount(23, array_unique($ids), "{$label}: ページ送りで行が重複している");
            $this->assertEqualsCanonicalizing(Contract::pluck('id')->all(), $ids, "{$label}: 全件が出ていない");

            $values = array_map($valueOf, $ids);
            $expected = $values;
            $direction === 'desc' ? rsort($expected) : sort($expected);
            $this->assertSame($expected, $values, "{$label}: ページをまたいで並んでいない（1 ページ目の中だけで並んでいる）");
        }
    }

    /**
     * 表の見出しの文字列（左から順に。タグと空白の揺れを落とす）。
     *
     * ⚠ このページの表は 1 つだけ（最初の <thead>）。並び替え見出しはリンクと矢印の SVG を含むが、
     *   strip_tags で文字だけになる。
     */
    private function headerTexts(string $html): array
    {
        $this->assertMatchesRegularExpression('/<thead\b[^>]*>(.*?)<\/thead>/su', $html, '表の見出し行が見つからない');
        preg_match('/<thead\b[^>]*>(.*?)<\/thead>/su', $html, $thead);
        preg_match_all('/<th\b[^>]*>(.*?)<\/th>/su', $thead[1], $cells);

        return array_map(fn (string $cell) => $this->plainText($cell), $cells[1]);
    }

    /**
     * 表の本体の各行のセルの文字列（上から順に。各行は左から順に）。
     *
     * @return list<list<string>>
     */
    private function bodyRows(string $html): array
    {
        $this->assertMatchesRegularExpression('/<tbody\b[^>]*>(.*?)<\/tbody>/su', $html, '表の本体が見つからない');
        preg_match('/<tbody\b[^>]*>(.*?)<\/tbody>/su', $html, $tbody);
        preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/su', $tbody[1], $rows);

        return array_map(function (string $row) {
            preg_match_all('/<td\b[^>]*>(.*?)<\/td>/su', $row, $cells);

            return array_map(fn (string $cell) => $this->plainText($cell), $cells[1]);
        }, $rows[1]);
    }

    private function plainText(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));
    }

    /**
     * 契約日が**各行の先頭セル**に Y/m/d で出る（設計書 §4.1 / §7.1）。
     *
     * ⚠ ページ全体の文字列一致で見てはいけない。同じ文字が別の場所に出ても通ってしまう。
     *   行ごとに先頭の <td> を見る。
     * ⚠ 期待値は**リテラルで書く**（viewData から組み立てると、表示と並びが一緒に壊れても一致しうる）。
     * ⚠ 見出しの並びも固定する。セルだけ動かす／見出しだけ動かす、のどちらも落とす。
     */
    public function test_each_row_starts_with_its_contract_date(): void
    {
        $this->threeContractsWhoseNameOrderIsTheReverseOfTheDateOrder();

        $html = $this->actingAs($this->executive())->get(route('tenant.contracts.index'))->getContent();

        $this->assertSame(
            ['契約日', '物件 / 区画', '店舗名', '賃料収入', '状態', '操作'],
            $this->headerTexts($html),
            '見出しの並びが設計書 §4.1 と違う（契約日が先頭でない）'
        );

        $rows = $this->bodyRows($html);

        $this->assertSame(
            ['2026/04/01', '2025/04/01', '2024/04/01'],
            array_map(fn (array $cells) => $cells[0] ?? null, $rows),
            '各行の先頭セルが契約日（Y/m/d）になっていない'
        );

        foreach ($rows as $i => $cells) {
            $this->assertCount(6, $cells, ($i + 1) . ' 行目のセルの数が見出しの数と違う');
        }

        // 2 列目は物件 / 区画のまま（契約日の列を足しただけで、既存の列の中身は変えない）
        $this->assertSame('C館 / 1A', $rows[0][1], '2 列目が物件 / 区画でない');
    }

    /** 契約が 0 件のときの行が全列にまたがる（列を足したら colspan も揃える） */
    public function test_the_empty_row_spans_every_column(): void
    {
        $html = $this->actingAs($this->executive())->get(route('tenant.contracts.index'))->getContent();

        $pattern = '/<td colspan="(\d+)"[^>]*>\s*契約データがありません。/u';
        $this->assertMatchesRegularExpression($pattern, $html, '0 件の行が見つからない');
        preg_match($pattern, $html, $matches);

        $this->assertSame(count($this->headerTexts($html)), (int) $matches[1], '0 件の行の colspan が見出しの数と違う');
    }
}
