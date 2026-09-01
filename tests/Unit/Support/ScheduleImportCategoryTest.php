<?php

namespace Tests\Unit\Support;

use App\Enums\ScheduleStepCategory;
use App\Support\ScheduleImportCategory;
use PHPUnit\Framework\TestCase;

/**
 * 書き出しファイルの大工程名 → 工程分類（プラン Task 5 / 設計書 §3.1 G）。
 *
 * ⚠ **実データ 21 種を全部名指しで置く。** 「代表的な数件」だと、
 *   語彙を 1 つ落としたときにその 1 種だけが静かに other へ倒れて気づけない。
 *
 * ⚠ 分類が**実装から実際に呼ばれている**ことは
 *   `ScheduleImportSheetTest::test_it_assigns_categories_from_the_group_name` が
 *   実ファイルの内訳（work 55 / permit 6 / other 4）で固定している。
 *   このクラスだけだと「マッピングは正しいが誰も呼んでいない」を検出できない。
 */
class ScheduleImportCategoryTest extends TestCase
{
    /**
     * 2026-09-01 に実ファイル（65 工程）から実測した 21 種。
     *
     * @return array<string, array{string, ScheduleStepCategory}>
     */
    public static function realGroupNames(): array
    {
        return [
            '仮設工事'       => ['仮設工事', ScheduleStepCategory::Work],
            '基礎工事'       => ['基礎工事', ScheduleStepCategory::Work],
            '防蟻工事'       => ['防蟻工事', ScheduleStepCategory::Work],
            '足場工事'       => ['足場工事', ScheduleStepCategory::Work],
            '大工工事'       => ['大工工事', ScheduleStepCategory::Work],
            'サッシ工事'     => ['サッシ工事', ScheduleStepCategory::Work],
            '屋根工事'       => ['屋根工事', ScheduleStepCategory::Work],
            '外壁工事'       => ['外壁工事', ScheduleStepCategory::Work],
            '塗装・防水工事' => ['塗装・防水工事', ScheduleStepCategory::Work],
            '樋工事'         => ['樋工事', ScheduleStepCategory::Work],
            '電気工事'       => ['電気工事', ScheduleStepCategory::Work],
            '給排水設備工事' => ['給排水設備工事', ScheduleStepCategory::Work],
            'クロス・床工事' => ['クロス・床工事', ScheduleStepCategory::Work],
            '左官工事'       => ['左官工事', ScheduleStepCategory::Work],
            'タイル工事'     => ['タイル工事', ScheduleStepCategory::Work],
            '設備工事'       => ['設備工事', ScheduleStepCategory::Work],
            '美装工事'       => ['美装工事', ScheduleStepCategory::Work],
            '雑工事'         => ['雑工事', ScheduleStepCategory::Work],
            '外構工事'       => ['外構工事', ScheduleStepCategory::Work],
            // ⚠ 「工事」を含まないので work に落ちない
            '材料搬入'       => ['材料搬入', ScheduleStepCategory::Other],
            '検査'           => ['検査', ScheduleStepCategory::Permit],
        ];
    }

    /**
     * ⚠ 列挙はテスト本体で回す（このプロジェクトはデータプロバイダを使っていない）。
     *   走査が空振りして緑になる事故を防ぐため件数も併せて固定する。
     */
    public function test_it_maps_every_group_name_seen_in_the_real_file(): void
    {
        $map = self::realGroupNames();

        $this->assertCount(21, $map, '実測した 21 種を全部並べていること');

        foreach ($map as [$group, $expected]) {
            $this->assertSame($expected, ScheduleImportCategory::forGroup($group), "大工程名「{$group}」の分類");
        }
    }

    /**
     * ⚠ **順序が意味を持つ。** 検査を工事より先に見ないと、両方を含む語が work に倒れる。
     *   判定順を入れ替える変異はここでだけ赤くなる。
     */
    public function test_inspection_wins_over_construction(): void
    {
        $this->assertSame(ScheduleStepCategory::Permit, ScheduleImportCategory::forGroup('基礎工事検査'));
        $this->assertSame(ScheduleStepCategory::Permit, ScheduleImportCategory::forGroup('工事完了検査'));
    }

    public function test_permit_and_survey_vocabulary(): void
    {
        $this->assertSame(ScheduleStepCategory::Permit, ScheduleImportCategory::forGroup('建築確認申請'));
        $this->assertSame(ScheduleStepCategory::Permit, ScheduleImportCategory::forGroup('開発許可'));
        $this->assertSame(ScheduleStepCategory::Survey, ScheduleImportCategory::forGroup('確定測量'));
        $this->assertSame(ScheduleStepCategory::Survey, ScheduleImportCategory::forGroup('表示登記'));
    }

    /** 未知の語や空文字で落ちない（分類は色分けにしか使わないので other で十分） */
    public function test_unknown_group_names_fall_back_to_other(): void
    {
        $this->assertSame(ScheduleStepCategory::Other, ScheduleImportCategory::forGroup('◯◯'));
        $this->assertSame(ScheduleStepCategory::Other, ScheduleImportCategory::forGroup(''));
        $this->assertSame(ScheduleStepCategory::Other, ScheduleImportCategory::forGroup('   '));
    }

    /**
     * ⚠ **販売系へ寄せる分岐は置いていない**（設計書 §3.1 G が定めていない）。
     *   足すと `分譲地造成工事` のような語を work でなく sale に倒しかねない。
     *   この期待値は「意図的に other」であることの記録。
     */
    public function test_sales_vocabulary_is_deliberately_not_mapped(): void
    {
        $this->assertSame(ScheduleStepCategory::Other, ScheduleImportCategory::forGroup('販売開始'));
        $this->assertSame(ScheduleStepCategory::Work, ScheduleImportCategory::forGroup('分譲地造成工事'));
    }
}
