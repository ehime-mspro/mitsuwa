<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 検証エラーを出しうるフォーム画面は、**理由を画面に出す手段を持つこと**。
 *
 * ⚠ **`layouts/app.blade.php` は `session('success')` / `session('error')` しか描画せず、
 *   `$errors` を描画しない。** つまり各フォーム画面が自前でエラー表示を持つしかなく、
 *   書き忘れた画面は**無音**になる（差し戻されて `old()` で入力は戻るのに理由が出ない＝
 *   利用者からは「ボタンが効かない」に見える）。2026-08-17 に本番で
 *   `zeal/simulations/edit` がまさにその状態だった。
 *
 * ⚠ **「直したファイルを並べる」形にしない**（Bug #45 ①）。それだと次に増えるフォームが
 *   無音でも通る。**対象を全件分類**し、表示手段が無く EXEMPT にも載っていなければ落とす。
 *
 * ⚠ **`@csrf` だけで列挙すると `_form.blade.php` が対象外になる**（`<form>` は create/edit 側に
 *   あり partial 側には `@csrf` が無い）。実際にこの見落としで
 *   `admin/master/zeal-simulation-categories` を一度「無音」と誤判定した。
 *   **列挙は `@csrf` を持つビュー、判定は `@include` をたどったチェーン全体**で行う。
 */
class ValidationErrorFeedbackTest extends TestCase
{
    /**
     * エラー表示を持たないが、それが正しい画面。**理由を必ず添えること。**
     *
     * ⚠ ここに足すのは「検証エラーが起きえない」か「別の手段で理由を出している」場合だけ。
     *   面倒だからという理由で足すと分類器の意味が無くなる。
     *
     * @var array<string, string> ビュー相対パス => 理由
     */
    private const EXEMPT = [
        // 検証そのものが無いフォーム
        'layouts/partials/header.blade.php'
            => 'ログアウトの POST のみ。検証する入力が無い',

        // ⚠ 取込画面は「独自の警告 UI があるから除外してよい」ではない。
        //   行単位の警告と `validate()` の失敗は**別物**で、後者は $errors に入る。
        //   2026-08-17 に実測（不正な拡張子を投げて差し戻し先を描画）した結果、
        //   customers / tenant-import / mansion-import の 3 つは**無音だった**ので
        //   サマリを足した。下の 2 つは確定した確認ステップで、直前の画面が
        //   表示手段を持つため除外する。
        'admin/zeal-member-import/preview.blade.php'
            => 'アップロード後の確認画面。csv_file の検証は前段の index で行い、'
             . 'index 側がエラー表示を持つ',
        'zeal/simulations/sheet-import/preview.blade.php'
            => '取込前の確認画面。URL の検証は sheet-urls の編集画面で行い、そちらが表示を持つ',

        // 詳細画面に載るインライン操作。入力欄がほぼ無く、失敗理由は flash で返す
        'tenant/inquiries/show.blade.php'
            => '詳細画面のインライン操作（ステータス変更）。理由は flash で返す',
        'tenant/units/show.blade.php'
            => '詳細画面のインライン操作。理由は flash で返す',
        'zeal/members/show.blade.php'
            => '詳細画面のインライン操作（プラン変更・退会）。理由は flash で返す',
        'zeal/simulations/show.blade.php'
            => '経営試算表マトリクス。理由は Ajax の応答と flash で返す',

        // JS 駆動。理由は進捗欄に出す（Bug #43 の対応で「失敗 N 件」を表示する）
        'tenant/area-buildings/index.blade.php'
            => '座標一括取得の POST。理由は #geocode-progress に出す',
    ];

    /** 検証エラーを出しうるフォームを持つビューを機械的に列挙する */
    private function formViews(): array
    {
        $found = [];
        $root  = resource_path('views');
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($it as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $src = file_get_contents($file->getPathname());

            // ⚠ `@csrf` の数が `@method('DELETE')` の数を超えるときだけ「検証されうる
            //   フォームがある」と見なす。削除専用の画面は検証エラーを出さない
            $csrf   = preg_match_all('/@csrf\b/', $src);
            $delete = preg_match_all("/@method\(\s*['\"]DELETE['\"]\s*\)/i", $src);

            if ($csrf === 0 || $csrf <= $delete) {
                continue;
            }

            $found[] = str_replace($root . '/', '', $file->getPathname());
        }

        sort($found);

        return $found;
    }

    /**
     * ビューと、それが `@include` するビューを再帰的に集める。
     *
     * ⚠ 1 段だけだと `create → _form → _price_row` のような多段構成を取りこぼす。
     */
    private function chain(string $view, array $seen = []): array
    {
        if (isset($seen[$view])) {
            return $seen;
        }

        $path = resource_path('views/' . $view);
        if (! is_file($path)) {
            return $seen;
        }

        $seen[$view] = file_get_contents($path);

        if (preg_match_all("/@include(?:If|When|First)?\(\s*'([^']+)'/", $seen[$view], $m)) {
            foreach ($m[1] as $name) {
                $seen = $this->chain(str_replace('.', '/', $name) . '.blade.php', $seen);
            }
        }

        return $seen;
    }

    /** ソースにエラー表示（サマリ or 項目別）があるか */
    private function displaysErrors(string $src): bool
    {
        return preg_match('/\$errors\s*->\s*(any|has|first)\b/', $src) === 1
            || str_contains($src, '@error');
    }

    /**
     * このビューを `@include` している親ビューを列挙する。
     *
     * ⚠ **上方向もたどらないと `_form.blade.php` を誤って「無音」と判定する。**
     *   `<form>`＋`@csrf` は partial 側にあり、エラー表示は親（index / create）側にある、
     *   という構成が実在する（取込画面など）。実測でこの見落としにより
     *   4 本のパーシャルを不要に EXEMPT へ入れかけた。
     *
     * @return list<string>
     */
    private function includers(string $view): array
    {
        static $map = null;

        if ($map === null) {
            $map  = [];
            $root = resource_path('views');
            $it   = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($it as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }
                $parent = str_replace($root . '/', '', $file->getPathname());
                $src    = file_get_contents($file->getPathname());

                if (preg_match_all("/@include(?:If|When|First)?\(\s*'([^']+)'/", $src, $m)) {
                    foreach ($m[1] as $name) {
                        $map[str_replace('.', '/', $name) . '.blade.php'][] = $parent;
                    }
                }
            }
        }

        return $map[$view] ?? [];
    }

    /** 自分のチェーン、または自分を include する親のチェーンに表示があるか */
    private function hasErrorDisplay(string $view): bool
    {
        foreach ($this->chain($view) as $src) {
            if ($this->displaysErrors($src)) {
                return true;
            }
        }

        foreach ($this->includers($view) as $parent) {
            foreach ($this->chain($parent) as $src) {
                if ($this->displaysErrors($src)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** 走査が空振りして緑になる事故を防ぐ */
    public function test_the_scanner_finds_the_form_views(): void
    {
        // ⚠ 現値ちょうどにしない（正当な掃除で誤って赤になる）
        $this->assertGreaterThanOrEqual(
            50,
            count($this->formViews()),
            'フォーム画面の走査が機能していない（空振り防止）'
        );
    }

    /**
     * 全件分類: エラー表示を持つか、EXEMPT に理由つきで載っているか、のどちらか。
     */
    public function test_every_form_view_can_show_why_it_was_rejected(): void
    {
        $silent = [];

        foreach ($this->formViews() as $view) {
            if ($this->hasErrorDisplay($view)) {
                continue;
            }
            if (array_key_exists($view, self::EXEMPT)) {
                continue;
            }
            $silent[] = $view;
        }

        $this->assertSame(
            [],
            $silent,
            "検証エラーの理由を画面に出せないフォームがある（差し戻されても利用者に理由が届かない）。\n"
            . "サマリ（\$errors->any()）か項目別（@error）を足すか、\n"
            . "別の手段で理由を出しているなら EXEMPT に**理由つきで**登録すること。\n"
            . '該当: ' . implode(', ', $silent)
        );
    }

    /** EXEMPT に実在しない行が残っていないこと（消えたビューが残ると分類器が緩む） */
    public function test_the_exempt_list_has_no_stale_entries(): void
    {
        $stale = [];

        foreach (self::EXEMPT as $view => $_reason) {
            if (! is_file(resource_path('views/' . $view))) {
                $stale[] = $view . '（ビューが存在しない）';
                continue;
            }
            // 表示手段が付いたなら EXEMPT から外すべき
            if ($this->hasErrorDisplay($view)) {
                $stale[] = $view . '（エラー表示が付いたので行を消すこと）';
            }
        }

        $this->assertSame([], $stale, "EXEMPT の棚卸しが必要:\n" . implode("\n", $stale));
    }

    /** EXEMPT の理由が空でないこと（理由なしの除外を許さない） */
    public function test_every_exemption_states_a_reason(): void
    {
        $blank = [];

        foreach (self::EXEMPT as $view => $reason) {
            if (trim($reason) === '') {
                $blank[] = $view;
            }
        }

        $this->assertSame([], $blank, '理由の無い除外がある: ' . implode(', ', $blank));
        $this->assertNotEmpty(self::EXEMPT, 'EXEMPT が空になったら分類器の形を見直すこと');
    }
}
