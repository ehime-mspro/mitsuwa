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
 * ⚠ `::where(...)` 自体は読み取りで正当（一覧・年度算出・採番が使っている）。
 *   判定は「同一文の中に ->update( があるか」で行う。
 *
 * 2026-08-04 実測（本テスト自身のロジックで計測。手順は
 * `grep -rn "ReContract::\|HsContract::\|HsCustomOrder::\|DB::table('re_contracts'\|..." app`
 * を土台に、コメント除去＋';' 区切りの文単位へ正規化）:
 *   scannedFiles = 130 / rootStatements = 21 / offenders = 0。
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
            // app/Models/ 自身はモデル定義（self 参照）なので対象外
            if (str_contains($path, '/app/Models/')) {
                continue;
            }

            $scannedFiles++;
            $source = $this->sourceWithoutComments($path);

            // PHP の文は ';' で終わる。文単位に切って「根」と '->update(' の同居を見る。
            foreach (explode(';', $source) as $statement) {
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
     * コメントと docblock を落としたソースを返す。
     *
     * ⚠ 落とさないと、自分が書いた注意書き（「⚠ ->update( は使わない」等）に一致して
     *   false-pass / false-fail する（Bug #42 ② で実際に踏んだ）。
     */
    private function sourceWithoutComments(string $path): string
    {
        $out = '';
        foreach (token_get_all(file_get_contents($path)) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }
}
