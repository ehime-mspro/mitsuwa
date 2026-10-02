<?php

namespace Tests\Feature\Tenant;

use App\Enums\AreaTenantStatus;
use App\Models\AreaBuildingTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 周辺ビルのテナント明細の上げ直しで二重にしない（設計書 docs/superpowers/specs/2026-10-01-area-tenant-reimport-design.md）。
 *
 * 見分けのキーは ビル・階・部屋番号・テナント名（部屋番号と名前は AreaBuilding::normalizeName() で比べる）。
 * 登録済みの行（退去済みも含む）と同じキーの行は、登録済みの行の数までスキップし、登録済みの行は書き換えない。
 *
 * ⚠ 取込は importTenants()（AreaBuildingTestCase）で送る。毎回取込の画面を開き直して、画面が描いた鍵で送るので、
 *   2 回送る場面は「上げ直し」になる（同じ画面からの 2 回目は Bug #69 の鍵が断る。AreaBuildingImportTest の別のテスト）。
 * ⚠ 「書き込まなかった」は行数だけでなく creating の回数でも見る（Bug #48）。
 * ⚠ 完了のメッセージは件数の並びを全文で見る（summary()。部分一致の false-pass を避ける。Bug #43）。
 */
class AreaBuildingTenantReimportTest extends AreaBuildingTestCase
{
    use RefreshDatabase;

    /** 完了のメッセージの件数の並び（AreaBuildingImportController::importTenants() と同じ文） */
    private function summary(int $created, int $registered, int $blank = 0, int $invalid = 0, int $unmatched = 0): string
    {
        return "取込が完了しました。テナント登録 {$created} 件 / 登録済みのためスキップ {$registered} 件 / "
            . "ビル名が空でスキップ {$blank} 件 / 値が不正でスキップ {$invalid} 件 / 台帳に無いビルでスキップ {$unmatched} 行";
    }

    /** AreaBuildingTenant の creating の回数を数える（Bug #48） */
    private function watchCreates(): object
    {
        $seen = new class
        {
            public int $creates = 0;
        };

        AreaBuildingTenant::creating(function () use ($seen): void {
            $seen->creates++;
        });

        return $seen;
    }

    // ============================================================
    // ① 同じファイル・一部が重なるファイル ② 別のビルのファイル
    // ============================================================

    /** 直す前: 2 回目も 3 件が入り、6 件になった */
    public function test_uploading_the_same_file_again_skips_every_row(): void
    {
        $this->makeBuilding('アルファビル');
        $this->makeBuilding('ベータビル');
        $rows = [
            ['building_name' => 'アルファビル', 'floor' => '3', 'room_number' => '301', 'name' => '大街道珈琲', 'industry' => '飲食', 'status' => '営業中'],
            ['building_name' => 'アルファビル', 'floor' => 'B1', 'room_number' => 'B101', 'status' => '空室'],
            ['building_name' => 'ベータビル', 'floor' => '2', 'room_number' => '201', 'name' => '花屋', 'industry' => '小売', 'status' => '営業'],
        ];

        $this->importTenants($rows)->assertRedirect(route('tenant.area-buildings.index'));
        $this->assertSame(3, AreaBuildingTenant::count(), '1 回目で取り込まれていない（測定が無効）');

        $seen = $this->watchCreates();
        $this->importTenants($rows)->assertRedirect(route('tenant.area-buildings.index'));

        $this->assertSame(3, AreaBuildingTenant::count(), '上げ直しで行が二重になった');
        $this->assertSame(0, $seen->creates, '上げ直しで書き込みが走った');
        $this->assertStringContainsString($this->summary(0, 3), session('success'));
        // スキップした行のビルも現況テナント数に並べる（全部スキップしたときも出る）
        $this->assertStringContainsString('取込後の現況テナント数: アルファビル 2 件 / ベータビル 1 件', session('success'));
    }

    public function test_only_the_new_rows_of_an_overlapping_file_are_added(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $first    = [
            ['building_name' => 'アルファビル', 'floor' => '1', 'room_number' => '101', 'name' => '甲商店', 'status' => '営業'],
            ['building_name' => 'アルファビル', 'floor' => '2', 'room_number' => '201', 'name' => '乙商店', 'status' => '営業'],
        ];
        $this->importTenants($first)->assertRedirect();

        $this->importTenants(array_merge($first, [
            ['building_name' => 'アルファビル', 'floor' => '3', 'room_number' => '301', 'name' => '丙商店', 'status' => '営業'],
        ]))->assertRedirect();

        $this->assertSame(['甲商店', '乙商店', '丙商店'], $building->tenants()->orderBy('id')->pluck('name')->all());
        $this->assertStringContainsString($this->summary(1, 2), session('success'));
    }

    public function test_a_file_for_another_building_is_added_even_with_the_same_floor_room_and_name(): void
    {
        $alpha = $this->makeBuilding('アルファビル');
        $beta  = $this->makeBuilding('ベータビル');
        $this->makeTenant($alpha, ['floor' => 3, 'room_number' => '301', 'name' => '大街道珈琲']);

        $this->importTenants([
            ['building_name' => 'ベータビル', 'floor' => '3', 'room_number' => '301', 'name' => '大街道珈琲', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, $beta->tenants()->count(), '別のビルの同じ階・部屋番号・名前の行とスキップした');
        $this->assertStringContainsString($this->summary(1, 0), session('success'));
    }

    /**
     * ビルもキーの要素（1 つのファイルに 2 棟が混じるとき）。
     * ⚠ 上のテストは、先読みがファイルに出るビルだけを読むので、キーからビルを外しても緑になる（2026-10-01 の先測りで実測）。
     *   2 棟を同じファイルに入れ、登録済みでないビルの行を先に置くと、キーからビルを外したときに取り違えて落ちる
     */
    public function test_the_building_is_part_of_the_key_when_one_file_has_two_buildings(): void
    {
        $alpha = $this->makeBuilding('アルファビル');
        $beta  = $this->makeBuilding('ベータビル');
        $this->makeTenant($alpha, ['floor' => 3, 'room_number' => '301', 'name' => '大街道珈琲']);

        $this->importTenants([
            ['building_name' => 'ベータビル', 'floor' => '3', 'room_number' => '301', 'name' => '大街道珈琲', 'status' => '営業'],
            ['building_name' => 'アルファビル', 'floor' => '3', 'room_number' => '301', 'name' => '大街道珈琲', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, $alpha->tenants()->count(), '登録済みのビルの行が入った');
        $this->assertSame(1, $beta->tenants()->count(), '別のビルの行をスキップした');
        $this->assertStringContainsString($this->summary(1, 1), session('success'));
    }

    // ============================================================
    // 数で扱う（空き区画は並ぶのが普通）
    // ============================================================

    /** 登録済み「3 階・部屋番号なし・名前なし」1 行・ファイルに 2 行 → 1 行目はスキップ・2 行目は登録（設計書 §4.4 の例）*/
    public function test_rows_beyond_the_registered_count_are_added(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 3, 'status' => 'vacant']);
        $vacancy = ['building_name' => 'アルファビル', 'floor' => '3', 'status' => '空室'];

        $this->importTenants([$vacancy, $vacancy])->assertRedirect();

        $this->assertSame(2, $building->tenants()->where('floor', 3)->count());
        $this->assertStringContainsString($this->summary(1, 1), session('success'));
    }

    /** ⚠ この取込で入れた行を残りに足すと、2 行目がスキップされて 1 行しか入らない */
    public function test_identical_rows_in_one_file_are_all_added_when_none_is_registered(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $vacancy  = ['building_name' => 'アルファビル', 'floor' => '3', 'status' => '空室'];

        $this->importTenants([$vacancy, $vacancy])->assertRedirect();

        $this->assertSame(2, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(2, 0), session('success'));
    }

    /** ⚠ 残りを減らさない（あるかないかだけで見る）と、3 行とも消える */
    public function test_each_registered_row_absorbs_one_identical_row(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 3, 'status' => 'vacant']);
        $this->makeTenant($building, ['floor' => 3, 'status' => 'vacant']);
        $vacancy = ['building_name' => 'アルファビル', 'floor' => '3', 'status' => '空室'];

        $this->importTenants([$vacancy, $vacancy, $vacancy])->assertRedirect();

        $this->assertSame(3, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(1, 2), session('success'));
    }

    // ============================================================
    // 突き合わせる相手・書き換えない
    // ============================================================

    /** 古いファイルを上げ直しても、退去したテナントが現況に戻らない（案 A）*/
    public function test_moved_out_rows_are_matched_and_stay_moved_out(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $tenant   = $this->makeTenant($building, ['floor' => 1, 'room_number' => '101', 'name' => '甲商店', 'moved_out_on' => '2026-03-31']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '1', 'room_number' => '101', 'name' => '甲商店', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, $building->tenants()->count(), '退去済みの行と同じ行が現況として入った');
        $this->assertSame('2026-03-31', $tenant->fresh()->moved_out_on->format('Y-m-d'));
        $this->assertStringContainsString($this->summary(0, 1), session('success'));
        $this->assertStringContainsString('取込後の現況テナント数: アルファビル 0 件', session('success'));
    }

    public function test_a_skipped_row_does_not_overwrite_the_registered_row(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $tenant   = $this->makeTenant($building, [
            'floor' => 2, 'room_number' => '201', 'name' => '乙商店', 'industry' => '飲食', 'status' => 'operating',
            'confirmed_on' => '2026-05-01', 'notes' => '画面で確かめた',
        ]);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '2', 'room_number' => '201', 'name' => '乙商店', 'industry' => '小売', 'status' => '空室'],
        ])->assertRedirect();

        $tenant = $tenant->fresh();
        $this->assertSame(1, $building->tenants()->count());
        $this->assertSame('飲食', $tenant->industry);
        $this->assertSame(AreaTenantStatus::Operating, $tenant->status);
        $this->assertSame('2026-05-01', $tenant->confirmed_on->format('Y-m-d'));
        $this->assertNull($tenant->moved_out_on);
        $this->assertSame('画面で確かめた', $tenant->notes);
        $this->assertStringContainsString($this->summary(0, 1), session('success'));
    }

    // ============================================================
    // 見分けのキー
    // ============================================================

    /** @return array<string, array{0: string, 1: string, 2: string}> [階, 部屋番号, テナント名] */
    public static function oneElementDiffers(): array
    {
        return [
            '階が違う'       => ['2', '301', '大街道珈琲'],
            '部屋番号が違う' => ['3', '302', '大街道珈琲'],
            '名前が違う'     => ['3', '301', '別の店'],
        ];
    }

    #[DataProvider('oneElementDiffers')]
    public function test_each_key_element_tells_rows_apart(string $floor, string $room, string $name): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 3, 'room_number' => '301', 'name' => '大街道珈琲']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => $floor, 'room_number' => $room, 'name' => $name, 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(2, $building->tenants()->count(), '1 つの要素が違う行をスキップした');
        $this->assertStringContainsString($this->summary(1, 0), session('success'));
    }

    /** 空欄（null）と 0 階は別のキー（'0' '0F' は 0 として保存される。設計書 §2.3）*/
    public function test_a_blank_floor_and_floor_zero_are_different(): void
    {
        $alpha = $this->makeBuilding('アルファビル');
        $beta  = $this->makeBuilding('ベータビル');
        $this->makeTenant($alpha, ['floor' => null, 'room_number' => 'A', 'name' => '甲商店']);
        $this->makeTenant($beta, ['floor' => 0, 'room_number' => 'A', 'name' => '甲商店']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '0', 'room_number' => 'A', 'name' => '甲商店', 'status' => '営業'],
            ['building_name' => 'ベータビル', 'floor' => '', 'room_number' => 'A', 'name' => '甲商店', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame([null, 0], $alpha->tenants()->orderBy('id')->pluck('floor')->all());
        $this->assertSame([0, null], $beta->tenants()->orderBy('id')->pluck('floor')->all());
        $this->assertStringContainsString($this->summary(2, 0), session('success'));
    }

    /**
     * 値の中に区切り文字があっても、別の行どうしが同じキーにならない（キーを JSON にする理由。設計書 §4.2）。
     * ⚠ `|` でつなぐと、部屋番号「A|B」・名前「C」と、部屋番号「A」・名前「B|C」がどちらも「A|B|C」になってスキップされる
     */
    public function test_a_separator_inside_a_value_does_not_merge_two_rows(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 1, 'room_number' => 'A|B', 'name' => 'C']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '1', 'room_number' => 'A', 'name' => 'B|C', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(2, $building->tenants()->count(), '区切り文字の位置だけが違う別の行をスキップした');
        $this->assertStringContainsString($this->summary(1, 0), session('success'));
    }

    /** @return array<string, array{0: string, 1: string}> [ファイルの部屋番号, ファイルのテナント名]（登録済みは '301' / '大街道 珈琲'）*/
    public static function whitespaceVariants(): array
    {
        return [
            '部屋番号の前後に全角空白'   => ["\u{3000}301\u{3000}", '大街道 珈琲'],
            '名前の間が全角空白'         => ['301', "大街道\u{3000}珈琲"],
            '名前の間に空白が続く'       => ['301', "大街道 \u{3000} 珈琲"],
        ];
    }

    /** 全角空白・続く空白・前後の空白の違いは同じ行とみなす（normalizeName）*/
    #[DataProvider('whitespaceVariants')]
    public function test_whitespace_differences_are_ignored(string $room, string $name): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 3, 'room_number' => '301', 'name' => '大街道 珈琲']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '3', 'room_number' => $room, 'name' => $name, 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, $building->tenants()->count(), '空白の違いだけの行を別の行として入れた');
        $this->assertStringContainsString($this->summary(0, 1), session('success'));
    }

    /** @return array<string, array{0: string, 1: string}> [ファイルの部屋番号, ファイルのテナント名]（登録済みは 'A301' / 'ABC商店'）*/
    public static function widthAndCaseVariants(): array
    {
        return [
            '部屋番号の数字が全角'   => ['A３０１', 'ABC商店'],
            '部屋番号の英字が全角'   => ['Ａ301', 'ABC商店'],
            '名前の英字が小文字'     => ['A301', 'abc商店'],
        ];
    }

    /** 英数字の全角・半角と、英字の大文字・小文字は区別する（ビル名の突き合わせと同じ扱い）*/
    #[DataProvider('widthAndCaseVariants')]
    public function test_width_and_case_differences_are_not_ignored(string $room, string $name): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 3, 'room_number' => 'A301', 'name' => 'ABC商店']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '3', 'room_number' => $room, 'name' => $name, 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(2, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(1, 0), session('success'));
    }

    /** @return array<string, array{0: ?string, 1: string}> [登録済みの部屋番号・名前, ファイルの部屋番号・名前] */
    public static function blankVariants(): array
    {
        return [
            '登録済みは null・ファイルは全角空白だけ' => [null, "\u{3000}"],
            '登録済みは全角空白だけ・ファイルは空欄'   => ["\u{3000}", ''],
        ];
    }

    /**
     * null と「空白だけ」の値は、どちらも空欄として同じとみなす。
     * ⚠ 取込は全角空白だけのセルを null にせず '　' のまま保存する（nullableString() の trim() は半角の空白だけを落とす。設計書 §7）
     */
    #[DataProvider('blankVariants')]
    public function test_a_blank_only_value_matches_a_blank(?string $registered, string $file): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 3, 'room_number' => $registered, 'name' => $registered, 'status' => 'vacant']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '3', 'room_number' => $file, 'name' => $file, 'status' => '空室'],
        ])->assertRedirect();

        $this->assertSame(1, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(0, 1), session('success'));
    }

    // ============================================================
    // 削除したビル・検査の順番・長い文字列・画面で登録した行
    // ============================================================

    /** 削除したビルの行とは照合しない（同じ名前で作り直したビルに入る）*/
    public function test_rows_of_a_deleted_building_are_not_matched(): void
    {
        $old = $this->makeBuilding('アルファビル');
        $this->makeTenant($old, ['floor' => 1, 'room_number' => '101', 'name' => '甲商店']);
        $old->delete();
        $new = $this->makeBuilding('アルファビル');

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '1', 'room_number' => '101', 'name' => '甲商店', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, $new->tenants()->count());
        $this->assertStringContainsString($this->summary(1, 0), session('success'));
    }

    /**
     * 階が読めない行は、照合の前に「値が不正」に数える（設計書 §4.4）。
     * ⚠ 登録済みを 0 階にしておく。読めない階（false）は int の引数へ渡ると 0 になるので、照合を階の検査より前へ動かすと
     *   この行が「登録済み」に数えられて落ちる
     */
    public function test_an_unreadable_floor_is_invalid_even_if_the_rest_matches(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 0, 'room_number' => 'A', 'name' => '甲商店']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => 'ペントハウス', 'room_number' => 'A', 'name' => '甲商店', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(0, 0, invalid: 1), session('success'));
    }

    /** 1 回目に切って入った長い値と、同じファイルの上げ直しの値が同じキーになる（切ってから比べる。設計書 §4.2）*/
    public function test_long_values_are_compared_after_truncation(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $rows     = [[
            'building_name' => 'アルファビル', 'floor' => '1',
            'room_number'   => str_repeat('あ', 80), 'name' => str_repeat('い', 400), 'status' => '営業',
        ]];
        $this->importTenants($rows)->assertRedirect();
        $this->assertSame(50, mb_strlen($building->tenants()->firstOrFail()->room_number), '1 回目で切られていない（測定が無効）');

        $this->importTenants($rows)->assertRedirect();

        $this->assertSame(1, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(0, 1), session('success'));
    }

    // ============================================================
    // 計画の Review Focus（2026-10-01-area-tenant-reimport.md）
    // ============================================================

    /** ビル名の空白の書き方が台帳と違っても同じビルとして照合する（先読みの対象も normalizeName で拾う）*/
    public function test_building_name_spacing_differences_still_match(): void
    {
        $building = $this->makeBuilding('アルファ ビル');
        $this->makeTenant($building, ['floor' => 1, 'room_number' => '101', 'name' => '甲商店']);

        $this->importTenants([
            ['building_name' => "アルファ\u{3000}\u{3000}ビル", 'floor' => '1', 'room_number' => '101', 'name' => '甲商店', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(0, 1), session('success'));
    }

    /** @return array<string, array{0: int, 1: string}> [登録済みの階, ファイルの階] */
    public static function floorNotations(): array
    {
        return [
            '3F'      => [3, '3F'],
            '３階'    => [3, '３階'],
            'B1'      => [-1, 'B1'],
            '地下1階' => [-1, '地下1階'],
        ];
    }

    /** 階の書き方が違っても、読んだあとの階が同じなら同じ行（FloorNumber::parse()）*/
    #[DataProvider('floorNotations')]
    public function test_floor_notation_differences_are_the_same_floor(int $registered, string $file): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => $registered, 'room_number' => '1', 'name' => '甲商店']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => $file, 'room_number' => '1', 'name' => '甲商店', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(0, 1), session('success'));
    }

    /** 1 つのファイルに 5 種類の行が混じっても、件数がそれぞれの欄に入る（メッセージ全体を固定する）*/
    public function test_the_summary_counts_every_kind_of_row(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 1, 'room_number' => '101', 'name' => '甲商店']);

        $this->importTenants([
            ['building_name' => '', 'floor' => '1', 'room_number' => '101', 'name' => '甲商店', 'status' => '営業'],
            ['building_name' => '知らないビル', 'floor' => '1', 'room_number' => '101', 'name' => '甲商店', 'status' => '営業'],
            ['building_name' => 'アルファビル', 'floor' => 'ペントハウス', 'room_number' => '101', 'name' => '甲商店', 'status' => '営業'],
            ['building_name' => 'アルファビル', 'floor' => '1', 'room_number' => '101', 'name' => '甲商店', 'status' => '営業'],
            ['building_name' => 'アルファビル', 'floor' => '2', 'room_number' => '201', 'name' => '乙商店', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(2, $building->tenants()->count());
        $this->assertSame(
            $this->summary(1, 1, 1, 1, 1) . '（1 棟: 知らないビル） 取込後の現況テナント数: アルファビル 2 件',
            session('success')
        );
    }

    /**
     * 空き区画に入居したあとの新しい調査のファイルは、名前が違うので新しい行として入り、空き区画の行は残る。
     * ⚠ 調査し直して現況を置き換える（同期）は範囲外（設計書 §1・§6）。空き区画の行は画面から退去日を入れるか削除する
     */
    public function test_a_vacancy_that_got_a_tenant_is_added_as_a_new_row(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $vacancy  = $this->makeTenant($building, ['floor' => 3, 'room_number' => '301', 'status' => 'vacant']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '3', 'room_number' => '301', 'name' => '新しい店', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(2, $building->tenants()->whereNull('moved_out_on')->count());
        $this->assertNull($vacancy->fresh()->name, '空き区画の行が書き換えられた');
        $this->assertStringContainsString($this->summary(1, 0), session('success'));
    }

    /** 画面で削除した行（行ごと消える）は、取り込み直すと入る */
    public function test_a_row_deleted_on_the_screen_is_added_again(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $rows     = [['building_name' => 'アルファビル', 'floor' => '1', 'room_number' => '101', 'name' => '甲商店', 'status' => '営業']];
        $this->importTenants($rows)->assertRedirect();
        $building->tenants()->firstOrFail()->delete();

        $this->importTenants($rows)->assertRedirect();

        $this->assertSame(1, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(1, 0), session('success'));
    }

    /** 取込でなく画面から 1 件ずつ登録した行とも照合する */
    public function test_rows_registered_on_the_screen_are_matched(): void
    {
        $building = $this->makeBuilding('アルファビル');
        $this->makeTenant($building, ['floor' => 2, 'room_number' => '201', 'name' => '花屋', 'industry' => '小売']);

        $this->importTenants([
            ['building_name' => 'アルファビル', 'floor' => '2F', 'room_number' => '201', 'name' => '花屋', 'industry' => '小売', 'status' => '営業'],
        ])->assertRedirect();

        $this->assertSame(1, $building->tenants()->count());
        $this->assertStringContainsString($this->summary(0, 1), session('success'));
    }
}
