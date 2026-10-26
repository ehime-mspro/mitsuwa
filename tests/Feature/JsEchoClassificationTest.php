<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ScansBladeJsEchoes;
use Tests\TestCase;

/**
 * 画面の JavaScript の場所（`<script>` の中と、`x-*` `:` `@` `on*` の属性の中）に書く `{{ }}`・`{!! !!}` を全件分類する（Bug #76 の形）。
 *
 * ⚠ `'{{ $x }}'` と JS に直に書くと、`{{ }}` は HTML 用の逃がしなので JS の文字列としては守られない。DB に保存された自由な文字
 *   （アンケートの選択肢・回答など）を書くと、`'` や `\` で画面の JS が止まり、属性の中ではコードとして動く。
 *   引用符で囲まない `{{ $x }}` は、空なら構文エラー（Bug #84）、文字ならそのままコードになる。
 * ⚠ 壊れない書き方（`Js::from` などの 1 つの呼び出し）でない値は、分類表 CLASSIFIED に理由つきで載っていなければ落とす。
 *   新しく JS に値を書くときは、`{{ \Illuminate\Support\Js::from(…) }}` か `@json(…)` で書くか、ここに理由つきで足す。
 * ⚠ 分類は書き方の分類。値が本当に安全かは、載せる人が値の出どころを読んで決める（DB に保存された自由な文字は載せず、Js::from にする）。
 *   「サーバ・呼び出し側が決めた名前」と「部品に渡す JS の式」は、出どころをビューごとの注記に書く。
 * ⚠ 両方向で見る: 載っているのに画面に無い式（古い分類）も落とす。
 * ⚠ 決裁の画面（`approvals/`）は「社内決裁申請」の会話の担当なので見ない（数で持たないので、決裁の作業を止めない）。
 * ⚠ `route()` で作った URL は `'` `\` が `%27` `%5C` になるので壊れない書き方に数える（`<script>` の中の `&` が `&amp;` になる働きの件は範囲外）。
 * ⚠ ビューの読み方は Tests\Concerns\ScansBladeJsEchoes（見本は JsStringEchoScanTest）。
 */
class JsEchoClassificationTest extends TestCase
{
    use ScansBladeJsEchoes;

    /** 見ないビュー（「社内決裁申請」の会話の担当） */
    private const EXCLUDED = ['approvals/'];

    /** 走査が空振りして緑になるのを防ぐ下限（2026-10-26 の実測: 分類表に当たった所 148〈101 組〉・壊れない書き方 215・走査したビュー 253） */
    private const MIN_CLASSIFIED_HITS = 140;

    private const MIN_SAFE_HITS = 200;

    /** 壊れない書き方に数える 1 つの呼び出し（URL・トークン・数を返す） */
    private const SAFE_CALL = '/^(?:route|url|asset|secure_url|action|csrf_token|count|number_format)\s*\(/';

    // 理由
    private const ENUM = '区分（enum）の値・ラベル・バッジ（コードが決めた文字）';

    private const ID = '番号（整数の id）';

    private const NAME = 'サーバや呼び出し側がコードで決めた名前（タブ・部署・欄の接頭辞など。出どころは注記）';

    private const JS_EXPRESSION = '部品に渡す JS の式（呼び出し側がコードに書いた固定の文字。出どころは注記）';

    private const NUMBER = '数（(int) でそろえた値・NOT NULL の列・計算した数）';

    private const LAT_LNG = '緯度・経度（decimal の列・@if で値があるときだけ描く）';

    private const DATE = '日付・年月（format の結果）';

    private const SYSTEM_NUMBER = 'システムが付ける番号（入力欄が無い）';

    private const URL = 'route() で作った URL を入れた変数';

    private const JSON = 'JSON（HTML の文字をすべて逃がす json_encode を {!! !!} で出す）';

    /** ビュー => 式 => 理由（式は前後の空白を落とし、続く空白を 1 つにしたもの） */
    private const CLASSIFIED = [
        '_partials/_schedule_board.blade.php' => [
            '$axis[\'initialPct\']' => self::NUMBER,
            '$axis[\'trackWidthPx\']' => self::NUMBER,
        ],
        // 部署は入力チェック（in:housing,realestate）を通った値だけを渡す（CustomerImportController::execute）
        'admin/customers/import.blade.php' => [
            '$department ?? "housing"' => self::NAME,
        ],
        // $fileNamesKey は index の @include がコードに書いた固定の文字
        'admin/mansion-import/_form.blade.php' => [
            '$fileNamesKey' => self::NAME,
        ],
        // $activeTab は画面にあるタブ名だけ（MansionImportController::showForm・WHERE_TO_CHECK のキー）
        'admin/mansion-import/index.blade.php' => [
            '$activeTab ?? "property"' => self::NAME,
        ],
        'admin/master/survey-questions/_question_item.blade.php' => [
            '$q->id' => self::ID,
        ],
        // $department は housing か realestate だけ（SurveyQuestionController::index）
        'admin/master/survey-questions/index.blade.php' => [
            '$department' => self::NAME,
        ],
        // $tab は index の @include がコードに書いた固定の文字
        'admin/tenant-import/_form.blade.php' => [
            '$tab' => self::NAME,
        ],
        // $activeTab は画面にあるタブ名だけ（TenantImportController::showForm・WHERE_TO_CHECK のキー）
        'admin/tenant-import/index.blade.php' => [
            '$activeTab ?? "property"' => self::NAME,
        ],
        'admin/users/index.blade.php' => [
            '$u->id' => self::ID,
            '$u->role->value' => self::ENUM,
            '$u->status->value' => self::ENUM,
            'json_encode($usersJsonData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)' => self::JSON,
            '$dept->id' => self::ID,
        ],
        // $department は URL の部署（resolveDepartment）
        'buyers/_form.blade.php' => [
            '$department' => self::NAME,
            '$isEdit && $buyer ? $buyer->id : ""' => self::ID,
        ],
        'buyers/_survey_form.blade.php' => [
            '$qId' => self::ID,
        ],
        // $department は URL の部署（resolveDepartment）
        'buyers/index.blade.php' => [
            '$r->value' => self::ENUM,
            '$buyer->id' => self::ID,
            '$department' => self::NAME,
        ],
        // $show は呼び出し側が渡す Alpine の変数名（既定 showDeleteModal）
        'components/delete-confirm-modal.blade.php' => [
            '$show' => self::JS_EXPRESSION,
        ],
        // $section はサイドバーがコードに書いた固定の文字
        'components/sidebar-group.blade.php' => [
            '$section' => self::NAME,
        ],
        // 式は _form の @include がコードに書いた固定の文字（a.start_date など）
        'dad/projects/_date-picker-row.blade.php' => [
            '$assignExpr' => self::JS_EXPRESSION,
            '$nameExpr' => self::JS_EXPRESSION,
            '$valueExpr' => self::JS_EXPRESSION,
        ],
        'dad/projects/show.blade.php' => [
            '$project->contract_amount ?? \'null\'' => self::NUMBER,
            '$project->latitude' => self::LAT_LNG,
            '$project->longitude' => self::LAT_LNG,
        ],
        // $bsDepartment は呼び出し側の部署（既定 housing）
        'housing/contracts/_buyer-select.blade.php' => [
            '\\App\\Support\\JapanTime::today()->format(\'Y-m-d\')' => self::DATE,
            '$bsDepartment' => self::NAME,
        ],
        // $lt は同じビューの $landTypes（コードに書いた表）
        'housing/custom-orders/_form.blade.php' => [
            '$lt[\'value\']' => self::NAME,
        ],
        'housing/custom-orders/show.blade.php' => [
            '$cat->value' => self::ENUM,
        ],
        'housing/properties/_form.blade.php' => [
            '$p->id' => self::ID,
        ],
        'housing/properties/index.blade.php' => [
            '$prop->id' => self::ID,
            '$prop->getDisplayBadgeStyle()' => self::ENUM,
            '$prop->getDisplayStatusLabel()' => self::ENUM,
            '$prop->isSold() ? \'sold\' : $prop->status->value' => self::ENUM,
        ],
        'housing/properties/show.blade.php' => [
            '$cat->value' => self::ENUM,
        ],
        'mansion/contracts/revise.blade.php' => [
            '$currentRent' => self::NUMBER,
            '$currentCommon' => self::NUMBER,
        ],
        'mansion/contracts/terminate.blade.php' => [
            '$depositAmount' => self::NUMBER,
            '$oldRestoration' => self::NUMBER,
            '$oldCleaning' => self::NUMBER,
            '$pc->id' => self::ID,
        ],
        'mansion/parking-contracts/revise.blade.php' => [
            '$currentFee' => self::NUMBER,
        ],
        'realestate/contracts/edit.blade.php' => [
            '$contract->contract_type->value' => self::ENUM,
        ],
        // $prefix は _form の @include がコードに書いた固定の文字
        'realestate/procurements/_price_row.blade.php' => [
            '$prefix' => self::NAME,
        ],
        // $row->kind は一覧のサービスが付ける procurement / project
        'realestate/procurements/index.blade.php' => [
            '$row->id' => self::ID,
            '$row->kind' => self::NAME,
            '$row->status->badgeClass()' => self::ENUM,
            '$row->status->label()' => self::ENUM,
            '$row->status->value' => self::ENUM,
            '$row->latitude' => self::LAT_LNG,
            '$row->longitude' => self::LAT_LNG,
        ],
        'realestate/procurements/show.blade.php' => [
            '$procurement->getTargetSellingPriceTotal() ?? 0' => self::NUMBER,
            '$procurement->latitude' => self::LAT_LNG,
            '$procurement->longitude' => self::LAT_LNG,
        ],
        'realestate/projects/index.blade.php' => [
            '$pj->latitude' => self::LAT_LNG,
            '$pj->longitude' => self::LAT_LNG,
            '$pj->id' => self::ID,
            '$pj->status->badgeClass()' => self::ENUM,
            '$pj->status->label()' => self::ENUM,
            '$pj->status->value' => self::ENUM,
        ],
        'realestate/projects/show.blade.php' => [
            '$project->target_selling_price ?? 0' => self::NUMBER,
            '$lotSellingTotal' => self::NUMBER,
            '$project->latitude' => self::LAT_LNG,
            '$project->longitude' => self::LAT_LNG,
        ],
        // $prefix は呼び出し側の @include がコードに書いた固定の文字
        'tenant/analysis/_charts.blade.php' => [
            '$prefix' => self::NAME,
        ],
        'tenant/area-buildings/_map.blade.php' => [
            '$index' => self::NUMBER,
        ],
        'tenant/area-buildings/import.blade.php' => [
            '\\App\\Support\\JapanTime::today()->format(\'Y-m\')' => self::DATE,
        ],
        'tenant/area-buildings/index.blade.php' => [
            '$pendingGeocodeCount' => self::NUMBER,
        ],
        'tenant/contracts/create.blade.php' => [
            '$presetInquiry->id' => self::ID,
            '$presetInquiry->inquiry_number' => self::SYSTEM_NUMBER,
            '$presetInquiry->status->value' => self::ENUM,
            '$presetInquiry->status->label()' => self::ENUM,
            '$presetInquiry->inquiry_date->format("Y/m/d")' => self::DATE,
        ],
        'tenant/contracts/terminate.blade.php' => [
            '$contract->rent' => self::NUMBER,
            '$contract->common_fee ?? 0' => self::NUMBER,
            '$contract->garbage_fee ?? 0' => self::NUMBER,
            '$contract->pest_control_fee ?? 0' => self::NUMBER,
            '$monthlyTotal' => self::NUMBER,
        ],
        'tenant/inquiries/edit.blade.php' => [
            '$historyCount' => self::NUMBER,
        ],
        // $key は同じビューの $tabs（コードに書いた表）
        'tenant/properties/show.blade.php' => [
            '$key' => self::NAME,
            '$s->value' => self::ENUM,
            '$inq->status->value' => self::ENUM,
        ],
        'tenant/units/index.blade.php' => [
            '$prop->id' => self::ID,
        ],
        'tenant/units/revise.blade.php' => [
            '$areaTsubo' => self::NUMBER,
            '$initDeposit' => self::NUMBER,
            '$initFee' => self::NUMBER,
            '$initRent' => self::NUMBER,
        ],
        // $key は同じビューの $tabs（コードに書いた表）
        'tenant/units/show.blade.php' => [
            '$key' => self::NAME,
        ],
        // $aggKey は Q1〜Q4・$ym は年月（コードで作る）
        'zeal/simulations/_table.blade.php' => [
            '$cat->id' => self::ID,
            '$ym' => self::DATE,
            '$aggKey' => self::NAME,
        ],
        'zeal/simulations/index.blade.php' => [
            '$sim->fiscal_year' => self::NUMBER,
        ],
        'zeal/simulations/show.blade.php' => [
            '$simulation->id' => self::ID,
            '$applyUrl' => self::URL,
            '$currentYm' => self::DATE,
            '$previewUrl' => self::URL,
        ],
        'zeal/stores/index.blade.php' => [
            '$nextOrder' => self::NUMBER,
        ],
        'zeal/trainers/index.blade.php' => [
            '$nextOrder' => self::NUMBER,
        ],
    ];

    public function test_every_value_written_into_javascript_is_safe_or_classified(): void
    {
        $unclassified = [];
        foreach ($this->scannedViews() as $relative => $source) {
            $found = $this->jsEchoes($source);
            $this->assertSame([], $found['unreadable'], "{$relative} を読めない（括弧が閉じていない）: " . implode(' / ', $found['unreadable']));
            foreach ($found['echoes'] as $echo) {
                if (! self::isSafeToWrite($echo['expr']) && ! isset(self::CLASSIFIED[$relative][$echo['expr']])) {
                    $unclassified[] = "resources/views/{$relative}:{$echo['line']}  {$echo['echo']}";
                }
            }
        }

        $this->assertSame([], $unclassified,
            "画面の JS に分類していない値を書いている。`{{ \\Illuminate\\Support\\Js::from(…) }}` か `@json(…)` で書くか、値の出どころを読んで CLASSIFIED に理由つきで足す:\n"
            . implode("\n", $unclassified));
    }

    public function test_every_classified_value_is_still_written(): void
    {
        $written = [];
        foreach ($this->scannedViews() as $relative => $source) {
            foreach ($this->jsEchoes($source)['echoes'] as $echo) {
                $written[$relative][$echo['expr']] = true;
            }
        }

        $stale = [];
        foreach (self::CLASSIFIED as $relative => $expressions) {
            foreach (array_keys($expressions) as $expression) {
                if (! isset($written[$relative][$expression])) {
                    $stale[] = "{$relative}  {$expression}";
                }
            }
        }

        $this->assertSame([], $stale, "CLASSIFIED に載っているのに画面の JS に無い（直したか消したなら、分類からも外す）:\n" . implode("\n", $stale));
    }

    public function test_the_classification_did_not_come_up_empty(): void
    {
        $classified = $safe = 0;
        foreach ($this->scannedViews() as $relative => $source) {
            foreach ($this->jsEchoes($source)['echoes'] as $echo) {
                if (self::isSafeToWrite($echo['expr'])) {
                    $safe++;
                } elseif (isset(self::CLASSIFIED[$relative][$echo['expr']])) {
                    $classified++;
                }
            }
        }

        $this->assertGreaterThanOrEqual(self::MIN_CLASSIFIED_HITS, $classified, '分類表に当たった所が少なすぎる（走査が壊れていないか）');
        $this->assertGreaterThanOrEqual(self::MIN_SAFE_HITS, $safe, '壊れない書き方の所が少なすぎる（判定が壊れていないか）');
    }

    /**
     * 壊れない書き方の見本（式 => 壊れない書き方か）。
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function safeSamples(): array
    {
        return [
            'Js::from'                   => ['\Illuminate\Support\Js::from($x->name)', true],
            'Js::from のあとに続ける'    => ['Js::from($a) . $b', false],
            '(int) の変数'               => ['(int) $x->rent', true],
            '(int) の 1 つの呼び出し'    => ["(int) old('a', 0)", true],
            '(int) のあとに続ける'       => ['(int) $a . $b->name', false],
            '文字だけの三項演算'         => ["\$a ? 'true' : 'false'", true],
            'route() の 1 つの呼び出し'  => ["route('x.show', \$x)", true],
            'route() のあとに続ける'     => ["route('x') . '?q=' . \$q", false],
            'csrf_token()'               => ['csrf_token()', true],
            'count()'                    => ['count($rows ?? [])', true],
            'number_format()'            => ["number_format(\$x, 2, '.', '')", true],
            '番号'                       => ['$x->id', false],
            '名前'                       => ['$x->name', false],
            '関数の名前の一部'           => ['routeName($x)', false],
        ];
    }

    #[DataProvider('safeSamples')]
    public function test_the_safe_forms_are_recognised(string $expression, bool $expected): void
    {
        $this->assertSame($expected, self::isSafeToWrite($expression), "壊れない書き方かの判定が違う: {$expression}");
    }

    public function test_the_approval_views_are_left_to_their_own_conversation(): void
    {
        $views = array_keys($this->scannedViews());
        $this->assertSame([], array_values(array_filter($views, fn ($v) => str_starts_with($v, 'approvals/'))), '決裁の画面を走査している');
        $this->assertContains('buyers/_survey_form.blade.php', $views, '決裁のほかの画面を走査していない');
    }

    // ============================================================
    // 走査
    // ============================================================

    /** @return array<string, string> 決裁の画面を除くビュー */
    private function scannedViews(): array
    {
        return array_filter($this->bladeViews(), function (string $relative): bool {
            foreach (self::EXCLUDED as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    return false;
                }
            }

            return true;
        }, ARRAY_FILTER_USE_KEY);
    }

    /** 壊れない書き方か（Js::from などの 1 つの呼び出し・(int) の変数・URL や数を返す 1 つの呼び出し） */
    private static function isSafeToWrite(string $expression): bool
    {
        $e = trim($expression);
        if (self::isSafeForm($e) || preg_match('/^\((?:int|float)\)\s*\$\w+(?:->\w+|\[[^\]]*\])*$/', $e) === 1) {
            return true;
        }
        if (preg_match(self::SAFE_CALL, $e, $m) === 1) {
            return self::closingParen($e, strlen($m[0]) - 1) === strlen($e) - 1;
        }

        return false;
    }
}
