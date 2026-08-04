<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 契約 3 テーブルへの**更新**がクエリビルダ経由になっていないことを固定する（設計書 §7.4）。
 *
 * ⚠ Eloquent の saved イベントは `Model::where(...)->update(...)` /
 *   `DB::table('...')->update(...)` を**通らない**。買主ランクの自動成約はこのイベントに
 *   乗っているので、そういう書き込みが 1 つ増えるとその経路だけ無音で漏れる（Bug #41 / #44）。
 *
 * ⚠ **このテストが見ているのは app/ のソース文字列だけ。** 動的に組み立てたテーブル名や
 *   リレーション経由の update（`$parent->relation()->update(...)`）は拾えない。
 *   「これで全部」とは言えない（Bug #45 ①）。対象を広げるときは必ず先に実測すること。
 *
 * ⚠ 文の切り出しはトークン列を歩いて **'(' '[' の深さが 0 の ';'** で区切っている
 *   （素朴な explode(';', $source) だと、根（ReContract:: 等）と ->update( の間に
 *   クロージャが挟まる形（`DeletionBlockers::forProject()` が実際にこの形を持つ）や、
 *   ';' を含む文字列リテラルがあると、両者が別の断片に分かれて検出できなくなる。
 *   docs/RULES.md Bug #45 ④「ブロック抽出は括弧の対応で行う」と同じ教訓）。
 *   **'{' '}' は深さに含めない。** レビューで最初に提示された実装は '{' '}' も数えており、
 *   クラス／メソッド／if・foreach 本体の '{' '}' まで深さに含めてしまうため、
 *   ファイルの内容（実質クラス全体）が「1 文」に潰れて、無関係なメソッドにある ->update(
 *   （例: `ReContractController.php` 内の `ReProjectLot::where(...)->update(...)`）が、
 *   同じクラスのどこかに根パターンがあるというだけで誤検出されることを実測で確認した
 *   （修正前のコードを `ReContractController.php` に適用し、クラス全体が長さ 20646 文字の
 *   1 文に潰れて偽陽性になることを確認 → '(' '[' のみを数える方式に変更し、
 *   同じファイルが 182 文に正しく分かれ偽陽性が消えることを確認済み）。
 *   それでも「1 文の中に根と ->update( が同居しているか」しか見ていないので、
 *   根と更新が別の文に分かれる形（変数に代入してから更新する等）は拾えない。
 *
 * ⚠ `::where(...)` 自体は読み取りで正当（一覧・年度算出・採番が使っている）。
 *   判定は「同一文の中に ->update( があるか」で行う。
 *
 * 2026-08-04 実測（本テスト自身のロジックで計測。手順は
 * `grep -rn "ReContract::\|HsContract::\|HsCustomOrder::\|DB::table('re_contracts'\|..." app`
 * を土台に、コメント除去＋トークンベースの文単位へ正規化）:
 *   scannedFiles = 130 / rootStatements = 21 / offenders = 0
 *   （素朴な ';' 区切りのときと同じ数値。今回の対象範囲には根と ->update( がクロージャを
 *   跨いで同居する実例が無いため、文の切り出し方法を直しても総数は変わらない）。
 *   21 件はすべて `::get()` `::min()` `::value()` `::whereIn()->count()` 等の読み取り、
 *   または `ReContract::create()` / `HsContract::create()` / `HsCustomOrder::create()`
 *   （Eloquent インスタンス生成＝ `save()` 経由で saved イベントを通るので安全）。
 *   下限値はここから余裕を持たせている（走査が壊れて 0 件近くに縮んだときに落ちればよい。
 *   通常のコード増減で頻繁に赤くなる値ではない）。
 */
class ContractModelEventPathTest extends TestCase
{
    /** 契約モデル／テーブルを根に持つ文を検出するためのパターン */
    private const ROOT_PATTERNS = [
        'ReContract::',
        'HsContract::',
        'HsCustomOrder::',
        "DB::table('re_contracts')",
        "DB::table('hs_contracts')",
        "DB::table('hs_custom_orders')",
    ];

    public function test_contract_models_are_never_updated_through_the_query_builder(): void
    {
        $offenders      = [];
        $rootStatements = 0;
        $scannedFiles   = 0;

        foreach ($this->phpFilesUnderApp() as $path) {
            // app/Models/ 配下は丸ごと対象外。対象 3 モデル自身の self 参照を拾わないためだが、
            // 実際には他モデルからの参照（HsProperty の hasOne(HsContract::class) など 3 箇所）も
            // 一緒に外れている。それらは ::class のリレーション定義だけで ->update( を伴わないが、
            // **将来モデル間で ->update( を書いても検出されない**。
            if (str_contains($path, '/app/Models/')) {
                continue;
            }

            $scannedFiles++;

            foreach ($this->statementsOf($path) as $statement) {
                $hasRoot = false;
                foreach (self::ROOT_PATTERNS as $pattern) {
                    if (str_contains($statement, $pattern)) {
                        $hasRoot = true;
                        break;
                    }
                }
                if (! $hasRoot) {
                    continue;
                }

                $rootStatements++;

                if (str_contains($statement, '->update(')) {
                    $offenders[] = $path . ' :: ' . trim(preg_replace('/\s+/', ' ', $statement));
                }
            }
        }

        // 走査が空振りして緑になる事故を防ぐ（Bug #32 / #35 と同じ流儀）。
        // 2026-08-04 実測: scannedFiles=130 / rootStatements=21（下限はそこから余裕を持たせた値）。
        $this->assertGreaterThanOrEqual(100, $scannedFiles, 'app/ の走査が空振りしている');
        $this->assertGreaterThanOrEqual(
            15,
            $rootStatements,
            '契約モデルを参照する文が見つからない。クラス名が変わったならこのテストのパターンも直すこと',
        );

        $this->assertSame(
            [],
            $offenders,
            "契約モデルをクエリビルダで更新している箇所がある。saved イベントを通らないため\n"
            . "買主ランクの自動成約が漏れる。モデル経由（\$model->update(...)）に直すこと:\n"
            . implode("\n", $offenders),
        );
    }

    /** @return list<string> */
    private function phpFilesUnderApp(): array
    {
        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * ファイルを「文」の配列に切り出す。
     *
     * ⚠ 素朴な explode(';', $source) にしないこと。根（ReContract:: 等）と ->update( の間に
     *   クロージャが挟まる形（`DeletionBlockers::forProject()` が同型の構造を持つ）や、
     *   ';' を含む文字列リテラルがあると、両者が別の断片に分かれて**検出できなくなる**。
     *   docs/RULES.md Bug #45 ④「ブロック抽出は括弧の対応で行う」と同じ教訓。
     *
     * ⚠ 深さは **'(' '[' の対応だけ**で数える。**'{' '}' は数えない。**
     *   文字列リテラルは token_get_all が単一トークンにするので、ここに来る '(' '[' ';' は
     *   すべて構文上のものであり、リテラル内の ';' で切れる事故は起きない。
     *   '{' '}' をあえて数えないのは、数えるとクラス／メソッド／if・foreach 本体の
     *   '{' '}' まで深さに含んでしまい、ファイル全体が実質「1 文」に潰れて、
     *   無関係なメソッドの ->update( が同じクラスに根パターンがあるだけで誤検出される
     *   ため（実測で確認。クラス docblock 参照）。一方、クロージャを引数として渡す形
     *   （`->where(function () use (...) { ...; ...; })->get();`）は、外側の呼び出しの
     *   '(' が最後まで閉じないため、'(' '[' だけを数えても正しく 1 文としてまとまる
     *   （クロージャの '{' '}' 自体を特別扱いする必要がない）。
     *
     * ⚠ コメントと docblock は落とす。落とさないと、自分が書いた注意書き
     *   （「⚠ ->update( は使わない」等）に一致して false-pass / false-fail する（Bug #42 ②）。
     *
     * @return list<string>
     */
    private function statementsOf(string $path): array
    {
        $statements = [];
        $buffer     = '';
        $depth      = 0;

        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $buffer .= $token[1];
                continue;
            }

            if ($token === '(' || $token === '[') {
                $depth++;
            } elseif ($token === ')' || $token === ']') {
                $depth--;
            }

            if ($token === ';' && $depth === 0) {
                $statements[] = $buffer;
                $buffer       = '';
                continue;
            }

            $buffer .= $token;
        }

        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }

        return $statements;
    }
}
