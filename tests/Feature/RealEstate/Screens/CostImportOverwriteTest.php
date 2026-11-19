<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Enums\UserRole;
use App\Models\ReProcurementCost;
use App\Models\ReProjectCost;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 試算表の取込の「既存原価を上書き」は経営層だけ（Bug #140）。
 *
 * 上書きは物件購入費以外の保存済みの原価をまとめて消す。原価の表では管理者は保存済みの行を消せない（経営層だけ）のに、
 * 取込の上書きは管理者も使えた。詳細画面では管理者に上書きの選択肢を出さず、サーバーも断る。
 * 新規登録の原価欄の上書きは、まだ保存していない画面の中の行を入れ替えるだけ（サーバーへ送らない）なので、今どおり誰でも使える。
 */
class CostImportOverwriteTest extends RealEstateScreenTestCase
{
    private const DETAIL_SCRIPTS = ['function costExcelImporterFactory(', 'function costGridFactory('];

    private const NOTE = '※ 既存原価の上書き（物件購入費以外を削除して置き換え）は経営層だけが使えます。';

    private const OVERWRITE_RADIO = '<input type="radio" x-model="costExcelImport.mode" value="overwrite">';

    public static function kinds(): array
    {
        return ['仕入れ案件' => ['procurement'], '分譲地' => ['project']];
    }

    private function asManager(): void
    {
        $this->user = $this->userWithRole(UserRole::Manager);
    }

    /** @return array{0: Model, 1: string} [案件（測量費 500,000 円の原価の行つき）, 今の利用者で描いた詳細画面] */
    private function openDetail(string $kind): array
    {
        if ($kind === 'procurement') {
            $owner = $this->procurement();
            ReProcurementCost::create(['procurement_id' => $owner->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => 500000]);
        } else {
            $owner = $this->project();
            ReProjectCost::create(['project_id' => $owner->id, 'cost_item_id' => $this->surveyItem->id, 'estimated_amount' => 500000]);
        }

        return [$owner, $this->htmlOf(route("realestate.{$kind}s.show", $owner))];
    }

    /** 物件購入費を除いた原価の行の見込み額（案件の保存で写される物件購入費の行は数えない） */
    private function amounts(string $kind, Model $owner): array
    {
        $query = $kind === 'procurement'
            ? ReProcurementCost::where('procurement_id', $owner->id)
            : ReProjectCost::where('project_id', $owner->id);

        return $query->where('cost_item_id', '!=', $this->purchaseItem->id)->orderBy('id')->pluck('estimated_amount')->all();
    }

    /** 試算表を読んだあとのプレビューの行から取り込む（ファイルの読み込みは SheetJS なので、読んだ結果から始める） */
    private function importSteps(string $mode): string
    {
        return 'data.costExcelImport.mode = ' . json_encode($mode) . '; data.costExcelImport.previewRows = ['
            . '{ skip: false, costItemId: ' . $this->surveyItem->id . ', estimated: 700000, actual: "", notes: "" }]; data.commitCostImport();';
    }

    private function drive(string $kind, string $html, string $steps, array $responses = []): array
    {
        $function = $kind === 'procurement' ? 'procurementDetail' : 'projectDetail';

        return $this->driveAlpine($html, $function, $function . '()', $steps, $responses, true, [], self::DETAIL_SCRIPTS);
    }

    // ============================================================
    // 画面の選択肢
    // ============================================================

    #[DataProvider('kinds')]
    public function test_the_detail_screen_offers_overwrite_only_to_an_executive(string $kind): void
    {
        [$owner, $html] = $this->openDetail($kind);
        $this->assertStringContainsString(self::OVERWRITE_RADIO, $html, '経営層に上書きの選択肢が無い');
        $this->assertStringNotContainsString(self::NOTE, $html, '経営層に「経営層だけ」の注記が出ている');

        $this->asManager();
        $html = $this->htmlOf(route("realestate.{$kind}s.show", $owner));
        $this->assertStringNotContainsString(self::OVERWRITE_RADIO, $html, '管理者に上書きの選択肢が出ている');
        $this->assertStringContainsString(self::NOTE, $html, '管理者に「経営層だけ」の注記が無い');
    }

    /** 新規登録の原価欄の上書きは画面の中の行を入れ替えるだけなので、管理者にも出す */
    #[DataProvider('kinds')]
    public function test_the_create_screen_still_offers_overwrite_to_a_manager(string $kind): void
    {
        $this->asManager();

        $html = $this->htmlOf(route("realestate.{$kind}s.create"));

        $this->assertStringContainsString(self::OVERWRITE_RADIO, $html);
        $this->assertStringNotContainsString(self::NOTE, $html);
    }

    // ============================================================
    // サーバー
    // ============================================================

    /** 管理者が上書きを送ると（古い画面・手で組んだ送信）、何も消さずに断り、理由が出る */
    #[DataProvider('kinds')]
    public function test_an_overwrite_by_a_manager_is_refused(string $kind): void
    {
        $this->asManager();
        [$owner, $html] = $this->openDetail($kind);

        $request = $this->drive($kind, $html, $this->importSteps('overwrite'))['requests'][0];
        $response = $this->sendCaptured($request)->assertForbidden();

        $this->assertSame([500000], $this->amounts($kind, $owner), '断ったのに原価が消えた・増えた');
        $after = $this->drive($kind, $html, $this->importSteps('overwrite'), [$this->asFetchResponse($response)]);
        $this->assertSame(['既存原価の上書きは経営層だけが使えます。「既存原価に追加」で取り込んでください。何も取り込んでいません。'], $after['alerts']);
    }

    /** 管理者の「追加」は今どおり通る（直しすぎを止める） */
    #[DataProvider('kinds')]
    public function test_an_append_by_a_manager_still_works(string $kind): void
    {
        $this->asManager();
        [$owner, $html] = $this->openDetail($kind);

        $request = $this->drive($kind, $html, $this->importSteps('append'))['requests'][0];
        $this->sendCaptured($request)->assertOk();

        $this->assertSame([500000, 700000], $this->amounts($kind, $owner));
    }

    /** 経営層の上書きは今どおり（保存済みの行を入れ替える） */
    #[DataProvider('kinds')]
    public function test_an_overwrite_by_an_executive_still_works(string $kind): void
    {
        [$owner, $html] = $this->openDetail($kind);

        $request = $this->drive($kind, $html, $this->importSteps('overwrite'))['requests'][0];
        $this->sendCaptured($request)->assertOk();

        $this->assertSame([700000], $this->amounts($kind, $owner));
    }
}
