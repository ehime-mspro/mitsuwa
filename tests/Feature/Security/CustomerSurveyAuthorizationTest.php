<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\CustomerSurveyController;
use App\Models\Buyer;
use App\Models\BuyerSurvey;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * 顧客アンケートの部署ベース認可（IDOR 対策）テスト。
 *
 * 注意: buyers / buyer_surveys / buyer_department_pivot は Laravel マイグレーション
 * 管理外で、テスト DB（SQLite in-memory）に存在しない（AttachmentAuthorizationTest 参照）。
 * そのためルート経由ではなく、認可の中核ロジックである
 * CustomerSurveyController::assertSurveyScope() を Reflection で直接検証する。
 * belongsToDepartment はメモリ上の匿名サブクラスでスタブし、
 * buyer_department_pivot への到達を避ける（DB 非依存）。
 */
class CustomerSurveyAuthorizationTest extends TestCase
{
    /** assertSurveyScope() を Reflection 経由で実行する */
    private function invokeScope(Buyer $buyer, BuyerSurvey $survey, string $department): void
    {
        $method = new ReflectionMethod(CustomerSurveyController::class, 'assertSurveyScope');
        $method->setAccessible(true);
        $method->invoke(new CustomerSurveyController(), $buyer, $survey, $department);
    }

    /** belongsToDepartment の戻り値を固定した Buyer を生成（DB 非依存） */
    private function fakeBuyer(int $id, bool $inDepartment): Buyer
    {
        $buyer = new class extends Buyer
        {
            public bool $inDepartmentStub = true;

            public function belongsToDepartment(string $dept): bool
            {
                return $this->inDepartmentStub;
            }
        };
        $buyer->id = $id;
        $buyer->inDepartmentStub = $inDepartment;

        return $buyer;
    }

    /** メモリ上の BuyerSurvey を生成（保存しない） */
    private function fakeSurvey(int $buyerId, string $department): BuyerSurvey
    {
        return new BuyerSurvey(['buyer_id' => $buyerId, 'department' => $department]);
    }

    /** 指定条件で 404 が投げられることを表明する */
    private function assertAborts404(Buyer $buyer, BuyerSurvey $survey, string $department): void
    {
        try {
            $this->invokeScope($buyer, $survey, $department);
            $this->fail('404 が投げられませんでした');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_valid_same_department_survey_passes(): void
    {
        $buyer  = $this->fakeBuyer(5, true);
        $survey = $this->fakeSurvey(5, 'housing');

        // 例外が投げられなければ通過（表明が無いと risky 扱いになるため明示）
        $this->invokeScope($buyer, $survey, 'housing');
        $this->assertTrue(true);
    }

    public function test_buyer_not_in_url_department_is_blocked(): void
    {
        // 呼出者の部署に属さない買主 → 404
        $buyer  = $this->fakeBuyer(5, false);
        $survey = $this->fakeSurvey(5, 'housing');

        $this->assertAborts404($buyer, $survey, 'housing');
    }

    public function test_survey_of_other_department_is_blocked(): void
    {
        // 兼務買主（realestate には所属）でも housing アンケートには触れない → 404
        $buyer  = $this->fakeBuyer(5, true);
        $survey = $this->fakeSurvey(5, 'housing');

        $this->assertAborts404($buyer, $survey, 'realestate');
    }

    public function test_survey_of_other_buyer_is_blocked(): void
    {
        // URL の買主に紐付かないアンケート（連番 id 列挙）→ 404
        $buyer  = $this->fakeBuyer(5, true);
        $survey = $this->fakeSurvey(999, 'housing');

        $this->assertAborts404($buyer, $survey, 'housing');
    }
}
