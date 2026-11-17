<?php

namespace Tests\Feature\RealEstate\Screens;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\ReProcurementCost;
use App\Models\ReProjectCost;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 原価のカード（表の保存・試算表取込・削除）で、ログインが切れた・無効にされた・権限が無いときに出す文。
 *
 * 直す前（2026-11-17 の実測）は、表の保存が「サーバーエラーが発生しました（419）。何も保存していません。」、取込が
 * 「取込に失敗しました: CSRF token mismatch.」、削除が「CSRF token mismatch.」と、理由の分からない文を出していた。
 *
 * ⚠ 応答は手で組まない。画面の JS が組んだ要求を、ログインが切れた・無効・権限の無い状態で本当に送り、
 *   返った応答（419・401・403）をもう一度 JS に戻して出る文を全文で見る（Bug #47・#49）。
 * ⚠ 419 は CSRF の鍵の確かめをテストでも効かせて出す（enforceCsrfToken）。ログインが切れると新しいセッションに
 *   なり、画面に描いた鍵が合わなくなる（ほかの画面でログアウトしたときも鍵は作り直される）。
 * ⚠ 404 は今の文のまま（ログインとは関係の無い断りまで、この文に変えない）。
 */
class CostCardAccessErrorTest extends RealEstateScreenTestCase
{
    private const DETAIL_SCRIPTS = ['function costExcelImporterFactory(', 'function costGridFactory('];

    private const EXPIRED = '画面を開いてから時間がたったか、ほかの画面でログアウトしたため、';

    /** 操作ごとの [できなかったこと, 何もしていない, 次にすること] */
    private const WORDS = [
        'save'   => ['保存できませんでした（何も保存していません）。', '何も保存していません。', '画面を開き直すと表に入れた内容は消えるので、控えてから開き直してください。'],
        'import' => ['取り込めませんでした（何も取り込んでいません）。', '何も取り込んでいません。', '画面を開き直してから、もう一度取り込んでください。'],
        'delete' => ['削除できませんでした（何も削除していません）。', '何も削除していません。', '画面を開き直してから、もう一度削除してください。'],
    ];

    public static function cases(): array
    {
        $cases = [];
        foreach (['procurement' => '仕入れ案件', 'project' => '分譲地'] as $kind => $kindName) {
            foreach (['save' => '表の保存', 'import' => '取込', 'delete' => '削除'] as $op => $opName) {
                foreach (['expired' => 'ログインが切れた', 'signedOut' => 'ログインが無い', 'inactive' => '無効にされた', 'forbidden' => '権限が無い'] as $case => $caseName) {
                    $cases["{$kindName}・{$opName}・{$caseName}"] = [$kind, $op, $case];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_the_card_says_why_nothing_was_done(string $kind, string $op, string $case): void
    {
        [$owner, $html] = $this->openDetail($kind);
        $run = $this->drive($kind, $html, $this->steps($op));
        $this->assertCount(1, $run['requests'], '要求を送っていない');

        $request = $run['requests'][0];
        $expected = match ($case) {
            'expired' => (function () use (&$request, $op) {
                $this->enforceCsrfToken();
                // 新しいセッションでは、画面に描いた鍵が合わない
                $request['headers']['X-CSRF-TOKEN'] = 'token-of-the-old-session';

                return self::EXPIRED . self::WORDS[$op][0] . self::WORDS[$op][2];
            })(),
            'signedOut' => (function () use ($op) {
                auth()->logout();

                return self::EXPIRED . self::WORDS[$op][0] . self::WORDS[$op][2];
            })(),
            'inactive' => (function () use ($op) {
                $this->user->status = UserStatus::Inactive;
                $this->user->save();

                return 'このアカウントは無効になっています。管理者にお問い合わせください。' . self::WORDS[$op][1];
            })(),
            'forbidden' => (function () use ($op) {
                // 不動産の部署の人の役割が一般担当に変わった（部署が無いと、部署の確かめが先に別の文で断る）
                $this->user->departments()->attach(Department::firstOrCreate(['code' => 'realestate'], ['name' => '不動産事業'])->id);
                $this->user->role = UserRole::Staff;
                $this->user->save();

                return 'このページへのアクセス権限がありません。' . self::WORDS[$op][1];
            })(),
        };

        $response = $this->sendCaptured($request);
        $response->assertStatus(['expired' => 419, 'signedOut' => 401, 'inactive' => 401, 'forbidden' => 403][$case]);

        $this->assertShown($kind, $html, $op, $response, $expected);
        $this->assertSame(1, $this->costsOf($kind, $owner)->count(), '何かが変わった');
        $this->assertSame(500000, $this->costsOf($kind, $owner)->first()->estimated_amount, '何かが変わった');
    }

    public static function unrelated(): array
    {
        $cases = [];
        foreach (['procurement' => '仕入れ案件', 'project' => '分譲地'] as $kind => $kindName) {
            foreach (['save' => '表の保存', 'import' => '取込', 'delete' => '削除'] as $op => $opName) {
                $cases["{$kindName}・{$opName}"] = [$kind, $op];
            }
        }

        return $cases;
    }

    /** ログインと関係の無い断り（開いたあとで案件が消えた 404）は、今の文のまま */
    #[DataProvider('unrelated')]
    public function test_other_failures_keep_the_old_message(string $kind, string $op): void
    {
        [$owner, $html] = $this->openDetail($kind);
        $run = $this->drive($kind, $html, $this->steps($op));
        $this->costsOf($kind, $owner)->delete();
        $owner->delete();

        $response = $this->sendCaptured($run['requests'][0]);
        $response->assertNotFound();
        $message = (string) json_decode((string) $response->getContent(), true)['message'];
        $expected = match ($op) {
            'save'   => 'サーバーエラーが発生しました（404）。何も保存していません。',
            'import' => '取込に失敗しました: ' . $message,
            'delete' => $message,
        };

        $this->assertShown($kind, $html, $op, $response, $expected);
    }

    // ============================================================
    // 助け
    // ============================================================

    /** @return array{0: Model, 1: string} [案件（測量費 500,000 円の原価の行つき）, 詳細画面の HTML] */
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

    private function costsOf(string $kind, Model $owner)
    {
        return $kind === 'procurement'
            ? ReProcurementCost::where('procurement_id', $owner->id)
            : ReProjectCost::where('project_id', $owner->id);
    }

    private function steps(string $op): string
    {
        return match ($op) {
            'save' => 'data.openCostGrid("estimated"); var r = data.costGrid.rows.filter(function (r) { return r.name === "測量費"; })[0]; '
                . 'r.draft.amount = "600000"; data.saveCostGrid();',
            'import' => 'data.costExcelImport.mode = "append"; data.costExcelImport.previewRows = ['
                . '{ skip: false, costItemId: ' . $this->surveyItem->id . ', estimated: 700000, actual: "", notes: "" }]; data.commitCostImport();',
            'delete' => 'data.deleteCost(data.costs[0]);',
        };
    }

    private function drive(string $kind, string $html, string $steps, array $responses = [], array $evaluate = []): array
    {
        $function = $kind === 'procurement' ? 'procurementDetail' : 'projectDetail';

        return $this->driveAlpine($html, $function, $function . '()', $steps, $responses, true, $evaluate, self::DETAIL_SCRIPTS);
    }

    /** 応答を JS に戻して、出る文（表の保存は表の上の赤い文・取込と削除は alert）が $expected の全文 */
    private function assertShown(string $kind, string $html, string $op, $response, string $expected): void
    {
        $after = $this->drive($kind, $html, $this->steps($op), [$this->asFetchResponse($response)], [
            'costGrid.error', 'costGrid.mode', 'costs.length', 'costMessage',
        ]);
        [$gridError, $gridMode, $costs, $message] = $after['evaluated'];

        if ($op === 'save') {
            $this->assertSame($expected, $gridError);
            $this->assertSame('estimated', $gridMode, '表が閉じた（入れた内容が消えた）');
            $this->assertSame([], $after['alerts']);
        } else {
            $this->assertSame([$expected], $after['alerts']);
            $this->assertSame(1, $costs, '一覧から行が消えた');
        }
        $this->assertSame('', $message, '成功の文が出た');
    }
}
