<?php

namespace Tests\Feature\RealEstate;

use App\Enums\UserRole;
use App\Models\ReCostItem;
use App\Models\ReProcurementCost;
use App\Models\ReProjectCost;
use App\Models\User;
use App\Support\RealEstateCostGrid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\RealEstate\Screens\RealEstateScreenTestCase;

/**
 * 原価の表をまとめて保存する受け口（PUT …/costs/batch。仕入れ案件と分譲地で同じ）。
 *
 * ⚠ 受け口が断るのは「手で組んだ送信」と「表を開いたあとで誰かが変えた」場合。
 *   どの断りも、何も書かないこと（行の数・値が変わらないこと）まで見る。
 */
class CostGridSaveTest extends RealEstateScreenTestCase
{
    private ReCostItem $otherItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->otherItem = ReCostItem::create(['name' => 'その他', 'sort_order' => 3]);
    }

    public static function owners(): array
    {
        return ['仕入れ案件' => ['procurement'], '分譲地' => ['project']];
    }

    /** 価格を持たない案件（物件購入費の自動の行ができない） */
    private function ownerOf(string $kind): Model
    {
        return $kind === 'procurement' ? $this->procurement() : $this->project();
    }

    private function costClass(string $kind): string
    {
        return $kind === 'procurement' ? ReProcurementCost::class : ReProjectCost::class;
    }

    private function ownerKey(string $kind): string
    {
        return $kind === 'procurement' ? 'procurement_id' : 'project_id';
    }

    private function costOf(string $kind, Model $owner, ReCostItem $item, int $est, ?int $act = null, ?string $notes = null): Model
    {
        $class = $this->costClass($kind);

        return $class::create([
            $this->ownerKey($kind) => $owner->id,
            'cost_item_id' => $item->id,
            'estimated_amount' => $est,
            'actual_amount' => $act,
            'notes' => $notes,
        ]);
    }

    private function rowFor(?Model $cost, ReCostItem $item, ?int $amount, ?string $notes): array
    {
        return [
            'id' => $cost?->id,
            'cost_item_id' => $item->id,
            'amount' => $amount,
            'notes' => $notes,
            'original' => [
                'estimated_amount' => $cost?->estimated_amount,
                'actual_amount' => $cost?->actual_amount,
                'notes' => $cost?->notes,
            ],
        ];
    }

    private function putGrid(string $kind, Model $owner, string $mode, array $rows, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->putJson(route("realestate.{$kind}s.costs.batch", $owner), ['mode' => $mode, 'rows' => $rows]);
    }

    /** DB の原価の行を [見込み額, 確定額, 備考] の配列で（id の順） */
    private function stored(string $kind, Model $owner): array
    {
        $class = $this->costClass($kind);

        return $class::where($this->ownerKey($kind), $owner->id)->orderBy('id')->get()
            ->map(fn ($c) => [$c->estimated_amount, $c->actual_amount, $c->notes])->all();
    }

    private function assertOnlyErrors($response, array $errors): void
    {
        $response->assertStatus(422);
        $this->assertSame($errors, $response->json('errors'));
    }

    #[DataProvider('owners')]
    public function test_an_estimate_creates_a_row_without_an_actual(string $kind): void
    {
        $owner = $this->ownerOf($kind);

        $response = $this->putGrid($kind, $owner, 'estimated', [$this->rowFor(null, $this->surveyItem, 300000, '現況測量')]);

        $response->assertOk();
        $this->assertSame([[300000, null, '現況測量']], $this->stored($kind, $owner));
        $this->assertSame('見込み額を保存しました（追加 1 件・変更 0 件）。', $response->json('message'));
        $this->assertCount(1, $response->json('costs'));
        $this->assertTrue($response->json('success'));
    }

    #[DataProvider('owners')]
    public function test_an_estimate_updates_only_the_estimate_and_notes(string $kind): void
    {
        $owner = $this->ownerOf($kind);
        $cost = $this->costOf($kind, $owner, $this->surveyItem, 500000, 480000, 'a');

        $response = $this->putGrid($kind, $owner, 'estimated', [$this->rowFor($cost, $this->surveyItem, 520000, 'b')]);

        $response->assertOk();
        $this->assertSame([[520000, 480000, 'b']], $this->stored($kind, $owner));
        $this->assertSame('見込み額を保存しました（追加 0 件・変更 1 件）。', $response->json('message'));
    }

    #[DataProvider('owners')]
    public function test_clearing_an_estimate_deletes_the_row_for_an_executive(string $kind): void
    {
        $owner = $this->ownerOf($kind);
        $cost = $this->costOf($kind, $owner, $this->surveyItem, 500000);

        $response = $this->putGrid($kind, $owner, 'estimated', [$this->rowFor($cost, $this->surveyItem, null, null)]);

        $response->assertOk();
        $this->assertSame([], $this->stored($kind, $owner));
        $this->assertSame('見込み額を保存しました（追加 0 件・変更 0 件・削除 1 件）。', $response->json('message'));
        $this->assertSame([], $response->json('costs'));
    }

    #[DataProvider('owners')]
    public function test_clearing_an_estimate_of_a_row_with_an_actual_is_refused(string $kind): void
    {
        $owner = $this->ownerOf($kind);
        $cost = $this->costOf($kind, $owner, $this->surveyItem, 500000, 480000);

        $response = $this->putGrid($kind, $owner, 'estimated', [$this->rowFor($cost, $this->surveyItem, null, null)]);

        $this->assertOnlyErrors($response, ['rows.0.amount' => [RealEstateCostGrid::MESSAGES['hasActual']]]);
        $this->assertSame([[500000, 480000, null]], $this->stored($kind, $owner));
    }

    #[DataProvider('owners')]
    public function test_a_manager_cannot_clear_a_saved_estimate(string $kind): void
    {
        $owner = $this->ownerOf($kind);
        $cost = $this->costOf($kind, $owner, $this->surveyItem, 500000);
        $manager = $this->userWithRole(UserRole::Manager);

        $response = $this->putGrid($kind, $owner, 'estimated', [$this->rowFor($cost, $this->surveyItem, null, null)], $manager);

        $this->assertOnlyErrors($response, ['rows.0.amount' => [RealEstateCostGrid::MESSAGES['onlyExecutive']]]);
        $this->assertSame([[500000, null, null]], $this->stored($kind, $owner));
    }

    public static function notesOnly(): array
    {
        $cases = [];
        foreach (self::owners() as $label => [$kind]) {
            $cases["{$label}・見込み額"] = [$kind, 'estimated', 'notesOnlyEstimated'];
            $cases["{$label}・確定額"] = [$kind, 'actual', 'notesOnlyActual'];
        }

        return $cases;
    }

    #[DataProvider('notesOnly')]
    public function test_notes_without_an_amount_are_refused(string $kind, string $mode, string $messageKey): void
    {
        $owner = $this->ownerOf($kind);

        $response = $this->putGrid($kind, $owner, $mode, [$this->rowFor(null, $this->surveyItem, null, 'x')]);

        $this->assertOnlyErrors($response, ['rows.0.amount' => [RealEstateCostGrid::MESSAGES[$messageKey]]]);
        $this->assertSame([], $this->stored($kind, $owner));
    }

    #[DataProvider('owners')]
    public function test_an_actual_on_an_item_without_a_row_creates_a_zero_estimate_row(string $kind): void
    {
        $owner = $this->ownerOf($kind);

        $response = $this->putGrid($kind, $owner, 'actual', [$this->rowFor(null, $this->surveyItem, 750000, null)]);

        $response->assertOk();
        $this->assertSame([[0, 750000, null]], $this->stored($kind, $owner));
        $this->assertSame('確定額を保存しました（追加 1 件・変更 0 件）。', $response->json('message'));
    }

    #[DataProvider('owners')]
    public function test_an_actual_updates_only_the_actual_and_notes(string $kind): void
    {
        $owner = $this->ownerOf($kind);
        $cost = $this->costOf($kind, $owner, $this->surveyItem, 500000, null, 'a');

        $response = $this->putGrid($kind, $owner, 'actual', [$this->rowFor($cost, $this->surveyItem, 480000, 'b')]);

        $response->assertOk();
        $this->assertSame([[500000, 480000, 'b']], $this->stored($kind, $owner));
    }

    #[DataProvider('owners')]
    public function test_clearing_an_actual_sets_it_back_to_undecided(string $kind): void
    {
        $owner = $this->ownerOf($kind);
        $cost = $this->costOf($kind, $owner, $this->surveyItem, 500000, 480000, 'a');

        $response = $this->putGrid($kind, $owner, 'actual', [$this->rowFor($cost, $this->surveyItem, null, null)]);

        $response->assertOk();
        $this->assertSame([[500000, null, null]], $this->stored($kind, $owner));
    }

    #[DataProvider('owners')]
    public function test_one_bad_row_saves_nothing(string $kind): void
    {
        $owner = $this->ownerOf($kind);
        $good = $this->costOf($kind, $owner, $this->surveyItem, 500000);
        $bad = $this->costOf($kind, $owner, $this->otherItem, 100000, 90000);

        $response = $this->putGrid($kind, $owner, 'estimated', [
            $this->rowFor($good, $this->surveyItem, 520000, null),
            $this->rowFor($bad, $this->otherItem, null, null),
        ]);

        $this->assertOnlyErrors($response, ['rows.1.amount' => [RealEstateCostGrid::MESSAGES['hasActual']]]);
        $this->assertSame([[500000, null, null], [100000, 90000, null]], $this->stored($kind, $owner));
    }

    public static function changedBySomeoneElse(): array
    {
        $cases = [];
        foreach (self::owners() as $label => [$kind]) {
            foreach (['見込み額', '確定額', '備考'] as $what) {
                $cases["{$label}・{$what}"] = [$kind, $what];
            }
        }

        return $cases;
    }

    #[DataProvider('changedBySomeoneElse')]
    public function test_a_change_by_someone_else_after_opening_is_refused(string $kind, string $what): void
    {
        $owner = $this->ownerOf($kind);
        $opened = $this->costOf($kind, $owner, $this->surveyItem, 500000, 480000, 'a');
        $other = $this->costOf($kind, $owner, $this->otherItem, 100000);
        $rows = [
            $this->rowFor($opened, $this->surveyItem, 520000, 'a'),
            $this->rowFor($other, $this->otherItem, 110000, null),
        ];

        // 表を開いたあとで、ほかの人が書き換えた
        $class = $this->costClass($kind);
        $fresh = $class::find($opened->id);
        match ($what) {
            '見込み額' => $fresh->update(['estimated_amount' => 999]),
            '確定額' => $fresh->update(['actual_amount' => 999]),
            '備考' => $fresh->update(['notes' => 'ほかの人']),
        };
        $before = $this->stored($kind, $owner);

        $response = $this->putGrid($kind, $owner, 'estimated', $rows);

        $this->assertOnlyErrors($response, ['rows' => [RealEstateCostGrid::MESSAGES['conflict']]]);
        $this->assertSame($before, $this->stored($kind, $owner));
    }

    public static function newRowConflicts(): array
    {
        $cases = [];
        foreach (self::owners() as $label => [$kind]) {
            $cases["{$label}・あいだに同じ項目の行ができた"] = [$kind, 'existing'];
            $cases["{$label}・同じ項目の新しい行が 2 つ"] = [$kind, 'twice'];
        }

        return $cases;
    }

    #[DataProvider('newRowConflicts')]
    public function test_a_new_row_for_an_item_that_already_has_one_is_refused(string $kind, string $case): void
    {
        $owner = $this->ownerOf($kind);
        if ($case === 'existing') {
            $this->costOf($kind, $owner, $this->surveyItem, 1000);
            $rows = [$this->rowFor(null, $this->surveyItem, 300000, null)];
        } else {
            $rows = [
                $this->rowFor(null, $this->surveyItem, 300000, null),
                $this->rowFor(null, $this->surveyItem, 400000, null),
            ];
        }
        $before = $this->stored($kind, $owner);

        $response = $this->putGrid($kind, $owner, 'estimated', $rows);

        $this->assertOnlyErrors($response, ['rows' => [RealEstateCostGrid::MESSAGES['conflict']]]);
        $this->assertSame($before, $this->stored($kind, $owner));
    }

    public static function foreignRows(): array
    {
        $cases = [];
        foreach (self::owners() as $label => [$kind]) {
            foreach (['missing', 'other', 'twice'] as $case) {
                $cases["{$label}・{$case}"] = [$kind, $case];
            }
        }

        return $cases;
    }

    #[DataProvider('foreignRows')]
    public function test_a_row_deleted_or_owned_by_someone_else_is_refused(string $kind, string $case): void
    {
        $owner = $this->ownerOf($kind);
        $mine = $this->costOf($kind, $owner, $this->surveyItem, 500000);
        $otherOwner = $kind === 'procurement'
            ? $this->procurement(['procurement_code' => 'RE-PRC-002'])
            : $this->project(['project_code' => 'RE-PRJ-002']);
        $theirs = $this->costOf($kind, $otherOwner, $this->surveyItem, 700000);

        $rows = match ($case) {
            'missing' => [array_replace($this->rowFor($mine, $this->surveyItem, 520000, null), ['id' => 99999])],
            'other' => [$this->rowFor($theirs, $this->surveyItem, 710000, null)],
            'twice' => [
                $this->rowFor($mine, $this->surveyItem, 520000, null),
                $this->rowFor($mine, $this->surveyItem, 530000, null),
            ],
        };
        $beforeMine = $this->stored($kind, $owner);
        $beforeTheirs = $this->stored($kind, $otherOwner);

        $response = $this->putGrid($kind, $owner, 'estimated', $rows);

        $this->assertOnlyErrors($response, ['rows' => [RealEstateCostGrid::MESSAGES['conflict']]]);
        $this->assertSame($beforeMine, $this->stored($kind, $owner));
        $this->assertSame($beforeTheirs, $this->stored($kind, $otherOwner));
    }

    public static function propertyPurchaseCases(): array
    {
        $cases = [];
        foreach (self::owners() as $label => [$kind]) {
            $cases["{$label}・保存済みの行"] = [$kind, true];
            $cases["{$label}・新しい行"] = [$kind, false];
        }

        return $cases;
    }

    #[DataProvider('propertyPurchaseCases')]
    public function test_the_property_purchase_row_cannot_be_changed(string $kind, bool $saved): void
    {
        $owner = $this->ownerOf($kind);
        // 新しい行の場合は、保存済みの物件購入費の行が無いまま行の決まり（断り）まで届かせる
        $cost = $saved ? $this->costOf($kind, $owner, $this->purchaseItem, 1000000) : null;
        $before = $this->stored($kind, $owner);

        $response = $this->putGrid($kind, $owner, 'estimated', [$this->rowFor($cost, $this->purchaseItem, 1100000, null)]);

        $this->assertOnlyErrors($response, ['rows.0.amount' => [RealEstateCostGrid::MESSAGES['propertyPurchase']]]);
        $this->assertSame($before, $this->stored($kind, $owner));
    }

    public static function amounts(): array
    {
        $cases = [];
        foreach (self::owners() as $label => [$kind]) {
            $cases["{$label}・-1"] = [$kind, -1, 'notNumber'];
            $cases["{$label}・abc"] = [$kind, 'abc', 'notNumber'];
            $cases["{$label}・1.5"] = [$kind, 1.5, 'notNumber'];
            $cases["{$label}・2147483648"] = [$kind, 2147483648, 'tooLarge'];
            $cases["{$label}・2147483647"] = [$kind, 2147483647, null];
        }

        return $cases;
    }

    #[DataProvider('amounts')]
    public function test_amounts_must_be_whole_numbers_within_the_column(string $kind, mixed $amount, ?string $messageKey): void
    {
        $owner = $this->ownerOf($kind);
        $row = $this->rowFor(null, $this->surveyItem, null, null);
        $row['amount'] = $amount;

        $response = $this->putGrid($kind, $owner, 'estimated', [$row]);

        if ($messageKey === null) {
            $response->assertOk();
            $this->assertSame([[2147483647, null, null]], $this->stored($kind, $owner));

            return;
        }
        $this->assertOnlyErrors($response, ['rows.0.amount' => [RealEstateCostGrid::MESSAGES[$messageKey]]]);
        $this->assertSame([], $this->stored($kind, $owner));
    }

    public static function missingKeys(): array
    {
        $cases = [];
        foreach (self::owners() as $label => [$kind]) {
            $cases["{$label}・id"] = [$kind, 'id', '原価の行'];
            $cases["{$label}・amount"] = [$kind, 'amount', '金額'];
            $cases["{$label}・notes"] = [$kind, 'notes', '備考'];
        }

        return $cases;
    }

    /** キーそのものが無い送信（手で組んだ送信）。500 でなく断り、何も書かない（Bug #92）。amount は「空＝消す」と取り違えない */
    #[DataProvider('missingKeys')]
    public function test_a_row_without_the_id_amount_or_notes_key_is_refused(string $kind, string $key, string $attribute): void
    {
        $owner = $this->ownerOf($kind);
        $cost = $this->costOf($kind, $owner, $this->surveyItem, 500000);
        $row = $this->rowFor($cost, $this->surveyItem, null, null);
        unset($row[$key]);
        $before = $this->stored($kind, $owner);

        $response = $this->putGrid($kind, $owner, 'estimated', [$row]);

        $this->assertOnlyErrors($response, ["rows.0.{$key}" => ["{$attribute}が送信されていません。"]]);
        $this->assertSame($before, $this->stored($kind, $owner));
    }

    #[DataProvider('owners')]
    public function test_notes_up_to_200_characters_are_saved(string $kind): void
    {
        $owner = $this->ownerOf($kind);

        $this->putGrid($kind, $owner, 'estimated', [$this->rowFor(null, $this->surveyItem, 1000, str_repeat('あ', 200))])->assertOk();
        $this->assertSame([[1000, null, str_repeat('あ', 200)]], $this->stored($kind, $owner));

        $response = $this->putGrid($kind, $owner, 'estimated', [$this->rowFor(null, $this->otherItem, 1000, str_repeat('あ', 201))]);
        $this->assertOnlyErrors($response, ['rows.0.notes' => [trans('validation.max.string', ['attribute' => '備考', 'max' => 200])]]);
        $this->assertCount(1, $this->stored($kind, $owner));
    }

    #[DataProvider('owners')]
    public function test_saved_notes_with_surrounding_spaces_are_not_a_conflict(string $kind): void
    {
        $owner = $this->ownerOf($kind);
        $cost = $this->costOf($kind, $owner, $this->surveyItem, 500000, null, '  境界  ');
        // 画面が送る original.notes は TrimStrings で削られて届く（DB の値には空白がある）
        $row = $this->rowFor($cost, $this->surveyItem, 520000, '境界');

        $response = $this->putGrid($kind, $owner, 'estimated', [$row]);

        $response->assertOk();
        $this->assertSame(520000, $this->costClass($kind)::find($cost->id)->estimated_amount);
    }

    #[DataProvider('owners')]
    public function test_nothing_changes_when_the_sent_values_are_already_saved(string $kind): void
    {
        $owner = $this->ownerOf($kind);
        $cost = $this->costOf($kind, $owner, $this->surveyItem, 500000, 480000, 'a');

        $response = $this->putGrid($kind, $owner, 'estimated', [$this->rowFor($cost, $this->surveyItem, 500000, 'a')]);

        $response->assertOk();
        $this->assertSame('変更はありませんでした。', $response->json('message'));
        $this->assertSame([[500000, 480000, 'a']], $this->stored($kind, $owner));
    }

    #[DataProvider('owners')]
    public function test_a_staff_member_cannot_save(string $kind): void
    {
        $owner = $this->ownerOf($kind);
        $staff = $this->userWithRole(UserRole::Staff);

        $response = $this->putGrid($kind, $owner, 'estimated', [$this->rowFor(null, $this->surveyItem, 300000, null)], $staff);

        $response->assertStatus(403);
        $this->assertSame([], $this->stored($kind, $owner));
    }

    #[DataProvider('owners')]
    public function test_the_batch_url_reaches_the_grid_action(string $kind): void
    {
        $owner = $this->ownerOf($kind);

        $route = app('router')->getRoutes()->match(Request::create(route("realestate.{$kind}s.costs.batch", $owner), 'PUT'));

        $this->assertSame("realestate.{$kind}s.costs.batch", $route->getName());
    }

    public function test_the_save_locks_the_owner_and_its_costs(): void
    {
        $source = file_get_contents(base_path('app/Support/RealEstateCostGrid.php'));
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        // 順に流すテストではロックの有無が見えない（Bug #102 と同じ）ので、親と原価の行の 2 か所を構造で固定する
        $this->assertGreaterThanOrEqual(2, substr_count($code, '->lockForUpdate()'));
        // ロックはトランザクションの中でしか持たれない（外すとロックが何も待たせず、書き込みの一括もなくなる）
        $this->assertSame(1, substr_count($code, 'DB::transaction('), '保存がトランザクションで包まれていない');
    }
}
