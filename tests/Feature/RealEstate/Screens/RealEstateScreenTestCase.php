<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\ReCostItem;
use App\Models\ReProcurement;
use App\Models\ReProject;
use App\Models\ReProjectLot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Concerns\CreatesSurveyQuestionSchema;
use Tests\Concerns\DrivesAlpineFetch;
use Tests\Concerns\ParsesForms;
use Tests\Concerns\SubmitsScreenForms;
use Tests\TestCase;

/**
 * 不動産の画面のテストの土台（登録・編集・削除を、描いた画面から送る往復で見る）。
 *
 * ⚠ 送る値は描いた画面から取る（Bug #47）。Alpine が値を入れる欄は DrivesAlpineFetch::browserForm()、
 *   Ajax の画面（原価・区画・ステータスの小窓）は driveAlpine() で JS が組んだ要求をそのまま送り、応答を JS に戻す。
 * ⚠ 入力エラーは各画面の上の赤い箱に 1 件ずつ出る（`<li>` か `<p class="text-sm text-red-800">`。画面で書き方が違う）。
 *   項目名だけで見るとラベルに一致して素通りするので、1 件の全文で見る（Bug #49。assertErrorItem()）。
 * ⚠ 帯の文言・送り方は SubmitsScreenForms（テナントの画面のテストと共用）。
 */
abstract class RealEstateScreenTestCase extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use DrivesAlpineFetch;
    use CreatesRealEstateSchema;
    use CreatesSurveyQuestionSchema;
    use SubmitsScreenForms;

    protected User $user;

    protected ReCostItem $purchaseItem;

    protected ReCostItem $surveyItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
        // 顧客の登録・編集画面が設問マスタを読む（テスト用スキーマは raw SQL の鏡）
        $this->createSurveyQuestionSchema();

        $this->user = User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
        // 「物件購入費」は購入価格から自動で写される原価の行（ReProcurement / ReProject の saved）
        $this->purchaseItem = ReCostItem::create(['name' => '物件購入費', 'sort_order' => 1]);
        $this->surveyItem = ReCostItem::create(['name' => '測量費', 'sort_order' => 2]);
    }

    /** 画面の上の入力エラーの箱に $message が出ている（1 件の全文） */
    protected function assertErrorItem(string $html, string $message): void
    {
        $text = preg_quote(e($message), '/');
        $pattern = '/<li>\s*' . $text . '\s*<\/li>|<p class="text-sm text-red-800">\s*' . $text . '\s*<\/p>/u';
        $this->assertSame(1, preg_match($pattern, $html), "入力エラーに「{$message}」が出ていない");
    }

    /**
     * 仕入れ案件・分譲地の登録画面のフォームを、ブラウザが送る項目で組む。
     * フォームの中に原価欄（`x-data="costSectionFormController()"`）が入れ子のコンポーネントとしてあるので、
     * 親（$function）と原価欄を別々に JS の状態で評価して足す（ブラウザは 1 つのフォームとして両方を送る）。
     *
     * @return array{method: string, action: string, fields: array<string, mixed>}
     */
    protected function formWithCostSection(string $html, string $action, string $function, string $steps, string $costSteps = ''): array
    {
        $open = '<div x-data="costSectionFormController()"';
        $start = strpos($html, $open);
        $this->assertNotFalse($start, '登録画面に原価欄が無い');
        $section = $this->balancedElement($html, $start, 'div');

        $needle = 'action="' . $action . '"';
        $main = $this->browserForm(str_replace($section, '', $html), $needle, $function, $steps, [], null, ['function supplierPicker(']);

        preg_match_all('/<script\b[^>]*>.*?<\/script>/s', $html, $scripts);
        $costHtml = '<form method="POST" ' . $needle . '>' . $section . '</form>' . implode("\n", $scripts[0]);
        $costs = $this->browserForm($costHtml, $needle, 'costSectionFormController', $costSteps, [], null, ['function costExcelImporterFactory(']);

        return ['method' => $main['method'], 'action' => $main['action'], 'fields' => $main['fields'] + $costs['fields']];
    }

    /** $start から始まる $tag の要素を、入れ子の同じ要素を数えて閉じタグまで切り出す */
    protected function balancedElement(string $html, int $start, string $tag): string
    {
        preg_match_all('/<' . $tag . '\b[^>]*>|<\/' . $tag . '>/i', $html, $tokens, PREG_OFFSET_CAPTURE, $start);
        $depth = 0;
        foreach ($tokens[0] as [$token, $offset]) {
            $depth += str_starts_with($token, '</') ? -1 : 1;
            if ($depth === 0) {
                return substr($html, $start, $offset + strlen($token) - $start);
            }
        }
        $this->fail("<{$tag}> が閉じていない");
    }

    /** JS の fetch に、PHP の JSON の API の応答をそのまま返す（X-Requested-With つきで叩く。叩いたあとはヘッダーを戻す） */
    protected function apiResponse(string $url): array
    {
        $response = $this->actingAs($this->user)
            ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->get($url);
        $this->flushHeaders();
        $response->assertOk();

        return $this->asFetchResponse($response);
    }

    protected function procurement(array $overrides = []): ReProcurement
    {
        return ReProcurement::create(array_merge([
            'procurement_code' => 'RE-PRC-001',
            'property_type' => 'used_house',
            'transaction_type' => 'purchase',
            'status' => 'selling',
            'property_name' => '勝山の家',
            'address' => '愛媛県松山市勝山町1-1',
            'created_by' => $this->user->id,
        ], $overrides));
    }

    protected function project(array $overrides = []): ReProject
    {
        return ReProject::create(array_merge([
            'project_code' => 'RE-PRJ-001',
            'project_name' => '平井分譲地',
            'status' => 'selling',
            'address' => '愛媛県松山市平井町1',
            'created_by' => $this->user->id,
        ], $overrides));
    }

    protected function lot(ReProject $project, int $number, int $price, string $status = 'on_sale'): ReProjectLot
    {
        return ReProjectLot::create([
            'project_id' => $project->id,
            'lot_number' => $number,
            'area_sqm' => 200,
            'area_tsubo' => 60.5,
            'selling_price' => $price,
            'status' => $status,
        ]);
    }

    /** 不動産の部署に属する買主（顧客の画面は部署の紐付けが無いと 404） */
    protected function buyer(string $last = '山田', string $first = '太郎'): Buyer
    {
        $buyer = Buyer::create(['last_name' => $last, 'first_name' => $first, 'prefecture' => '愛媛県', 'city' => '松山市']);
        $buyer->addToDepartment('realestate', '2026-09-01');

        return $buyer;
    }
}
