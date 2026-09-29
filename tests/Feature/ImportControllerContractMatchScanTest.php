<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\Concerns\ScansImportControllers;
use Tests\TestCase;

/**
 * 契約を作る取込は、登録済みの同じ契約を照合してスキップすること（上げ直しても二重にしない。
 * 設計書 2026-09-29-contract-reimport-design.md §5.2）。
 *
 * ⚠ 全件分類（Top trap #13）。列挙はほかの取込の走査テストと同じ `ScansImportControllers` を共用する
 *   （別々に列挙すると、片方だけ範囲が変わって見落としが生まれる）。取込のコントローラの**どのメソッドでも**
 *   （public に限らない。作成を private の部品へ切り出しても見逃さない）、契約のモデル（名前が Contract で終わるクラス）を
 *   作る呼び出しを持てば集め、下の 2 つの表のどちらかに入っていなければ落とす。表に古い名前が残っても落とす。
 * ⚠ コメントを落としてから探す（docblock に `Contract::create(` と書いてあると、実体を消しても緑になる。Bug #42 ②）。
 * ⚠ 見えないもの（死角）: トレイト・サービスへ切り出した作成 ／ `*ImportController.php` という名前でない取込 ／
 *   照合が行の検査の正しい位置（日付の検査のあと・金額の検査の前）にあるか（これは振る舞いのテスト
 *   TenantContractReimportTest・MansionContractReimportTest が見る）／ `$warnings` という名前でない一覧へ直接積む書き方。
 * ⚠ 検出器（CREATES_CONTRACT）に見えない作り方（死角。下の自己テストの「死角:」の見本が、当たらないことを固定している）:
 *   リレーション・クエリビルダ越しの作成（`$unit->contracts()->create(`・`Contract::query()->create(`）／
 *   `DB::table('contracts')->insert(` ／ 先頭に \ の付いた完全な名前での new（`new \App\Models\Contract(`。
 *   `\App\Models\Contract::create(` のほうは見える）／ 括弧の無い `new Contract;` ／ `Contract::make(...)->save()` ／
 *   別名で use したクラス（`use App\Models\Contract as Lease;` の `Lease::create(`）／ クラス名を変数・文字列に入れて呼ぶ形
 *   （`$model = Contract::class;` や `$model = 'App\Models\Contract';` のあとの `$model::create(`）／ 一覧に無い作成のメソッド
 *   （`insertGetId`・`insertOrIgnore`・`upsert`・`createQuietly`・`createOrFirst`・`forceCreateQuietly`）。
 * ⚠ 落とすのはコメントだけで、文字列の中は落とさない: 文字列に `Contract::create(` と書いてあるだけでも、そのメソッドを集める
 *   （分類を求められるので、うるさいほうに倒れる）。照合のメソッドの名前が文字列の中にあるだけでも「呼んでいる」とみなす
 *   （test_the_methods_that_need_matching_call_the_matcher の死角。黙って緑になるほう）。
 */
class ImportControllerContractMatchScanTest extends TestCase
{
    use ScansImportControllers;

    /** 照合が要るメソッド => 照合のメソッド */
    private const MATCHED = [
        'App\Http\Controllers\Admin\MansionImportController::executeParkingContract' => 'findRegisteredParkingContract',
        'App\Http\Controllers\Admin\MansionImportController::executeRoomContract'    => 'findRegisteredRoomContract',
        'App\Http\Controllers\Admin\TenantImportController::executeContract'         => 'findRegisteredContract',
        'App\Http\Controllers\Admin\TenantImportController::executePastContract'     => 'findRegisteredContract',
    ];

    /** 照合しない（対象外の）メソッド => 理由 */
    private const NOT_MATCHED = [
        'App\Http\Controllers\Admin\ZealMemberImportController::execute' => 'ZealMemberContract は会員と一緒にしか作らない。会員は氏名＋入会日の重複で飛ばす（isDuplicate()）ので、上げ直しても契約は二重にならない',
    ];

    /** 見つかるメソッドの数の下限（2026-09-29 実測 5）。下回ったら走査が空振りしている */
    private const MIN_CONTRACT_CREATORS = 5;

    /** 契約のモデル（名前が Contract で終わるクラス。素の Contract も含む）を作る呼び出し */
    private const CREATES_CONTRACT = '/\b(?:[A-Z]\w*)?Contract::(?:create|forceCreate|firstOrCreate|updateOrCreate|insert)\s*\(|\bnew\s+(?:[A-Z]\w*)?Contract\s*\(/';

    public function test_every_import_method_that_creates_contracts_is_classified(): void
    {
        $found = array_keys($this->contractCreators());

        $classified = array_merge(array_keys(self::MATCHED), array_keys(self::NOT_MATCHED));
        sort($classified);

        $this->assertSame(
            $classified,
            $found,
            '契約を作る取込のメソッドと分類の表がそろっていない（新しい取込は、照合するか、理由をつけて NOT_MATCHED に足す。表に古い名前を残さない）'
        );
    }

    public function test_the_methods_that_need_matching_call_the_matcher(): void
    {
        $bodies = $this->contractCreators();

        foreach (self::MATCHED as $method => $matcher) {
            $this->assertArrayHasKey($method, $bodies, "{$method} が見つからない（分類が古い）");
            $this->assertStringContainsString(
                '$this->' . $matcher . '(',
                $bodies[$method],
                "{$method} が {$matcher}() を呼んでいない（同じ CSV を上げ直すと、契約が二重に入る）"
            );
        }
    }

    public function test_the_methods_that_match_collect_warnings_per_row(): void
    {
        $bodies = $this->contractCreators();

        foreach (array_keys(self::MATCHED) as $method) {
            $this->assertArrayHasKey($method, $bodies, "{$method} が見つからない（分類が古い）");
            $this->assertSame(
                0,
                preg_match_all('/\$warnings\s*\[\s*\]\s*=|array_push\(\s*\$warnings\b/', $bodies[$method]),
                "{$method} が画面の警告の一覧へ直接積んでいる（行の中の \$rowWarnings に貯める。直接積むと、スキップ・エラーの行にまた出る）"
            );
            $this->assertStringContainsString(
                '$warnings = array_merge($warnings, $rowWarnings);',
                $bodies[$method],
                "{$method} が、取り込むと決めた行の警告を画面の一覧へ移していない"
            );
        }
    }

    /**
     * 検出器（CREATES_CONTRACT）の見本。実物の取込は `::create(` しか使わないので、見本の無い枝は消しても緑になる
     * （Bug #61 ③ の規約。2026-09-29 のレビューで実測）。枝ごと（作る呼び出し 5 つ・new・名前の前の語の有無・
     * 括弧の前の空白）に当たる見本と、当たってはいけない見本（契約のモデルでないクラス・読むだけの呼び出し）と、
     * 死角（クラスの docblock。見えるように直したら、見本を「当たる」へ移し、docblock の一覧からも消す）を通す。
     *
     * @return array<string, array{0: string, 1: bool}> [コード, 当たるか]
     */
    public static function detectorSamples(): array
    {
        return [
            // 当たる（枝ごとに 1 つ以上）
            '::create（素の Contract）'                  => ['Contract::create([', true],
            '::forceCreate（名前の前に語がある）'        => ['MsContract::forceCreate([', true],
            '::firstOrCreate'                            => ['ZealMemberContract::firstOrCreate([', true],
            '::updateOrCreate'                           => ['MsParkingContract::updateOrCreate([', true],
            '::insert'                                   => ['Contract::insert([', true],
            '::create の括弧の前に空白'                  => ['Contract::create ([', true],
            '先頭に \\ の付いた完全な名前の ::create'    => ['\\App\\Models\\Contract::create([', true],
            'new（素の Contract）'                       => ['new Contract([', true],
            'new（名前の前に語がある）'                  => ['new MsParkingContract([', true],
            'new の括弧の前に空白'                       => ['new Contract ([', true],
            // 当たらない（契約のモデルでない・作らない）
            'ContractRevision'                           => ['ContractRevision::create([', false],
            'MsContractDeduction'                        => ['MsContractDeduction::create([', false],
            'ContractService'                            => ['ContractService::create([', false],
            'DadSubcontractor（小文字の contract）'      => ['DadSubcontractor::create([', false],
            '読むだけ（::where）'                        => ["Contract::where('unit_id', 1)", false],
            // 死角（クラスの docblock）
            '死角: リレーション越しの作成'               => ['$unit->contracts()->create([', false],
            '死角: クエリビルダ越しの作成'               => ['Contract::query()->create([', false],
            '死角: DB::table の insert'                  => ["DB::table('contracts')->insert([", false],
            '死角: 先頭に \\ の付いた完全な名前の new'   => ['new \\App\\Models\\Contract([', false],
            '死角: 括弧の無い new'                       => ['new Contract;', false],
            '死角: make して save'                       => ['Contract::make([])->save()', false],
            '死角: 別名で use したクラス'                => ['use App\\Models\\Contract as Lease; Lease::create([', false],
            '死角: クラス名を変数に入れる'               => ['$model = Contract::class; $model::create([', false],
            '死角: クラス名を文字列に入れる'             => ["\$model = 'App\\Models\\Contract'; \$model::create([", false],
            '死角: insertGetId'                          => ['Contract::insertGetId([', false],
            '死角: insertOrIgnore'                       => ['Contract::insertOrIgnore([', false],
            '死角: upsert'                               => ['Contract::upsert([', false],
            '死角: createQuietly'                        => ['Contract::createQuietly([', false],
            '死角: createOrFirst'                        => ['Contract::createOrFirst([', false],
            '死角: forceCreateQuietly'                   => ['Contract::forceCreateQuietly([', false],
        ];
    }

    #[DataProvider('detectorSamples')]
    public function test_the_detector_matches_exactly_its_samples(string $code, bool $matches): void
    {
        // 走査と同じ正規表現そのもの（self::CREATES_CONTRACT）を当てる。写しを別に書くと、走査と見本が別々に変わる
        $this->assertSame(
            $matches ? 1 : 0,
            preg_match(self::CREATES_CONTRACT, $code),
            $matches
                ? "検出器が「{$code}」を見逃した（この形の枝が効いていない）"
                : "検出器が「{$code}」を拾った（契約のモデルでない形か、docblock に死角と書いた形。見えるように直したなら、見本と docblock を直す）"
        );
    }

    /**
     * 契約のモデルを作る呼び出しを持つメソッド。
     *
     * @return array<string, string> 「クラス::メソッド」=> コメントを落とした本体（キーの順）
     */
    private function contractCreators(): array
    {
        $found = [];

        foreach ($this->importControllerFiles() as $relative => $path) {
            $class = 'App\\' . str_replace('/', '\\', substr($relative, strlen('app/'), -strlen('.php')));
            $lines = file($path);

            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                if ($method->class !== $class) {
                    continue;
                }
                $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
                $body = $this->withoutComments($source);
                if (preg_match(self::CREATES_CONTRACT, $body) === 1) {
                    $found[$class . '::' . $method->name] = $body;
                }
            }
        }

        ksort($found);

        $this->assertGreaterThanOrEqual(
            self::MIN_CONTRACT_CREATORS,
            count($found),
            '走査が空振りしている（契約を作る取込のメソッドが少なすぎる）'
        );

        return $found;
    }

    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all('<?php ' . $source) as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
