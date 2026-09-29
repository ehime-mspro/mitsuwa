<?php

namespace Tests\Feature;

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
