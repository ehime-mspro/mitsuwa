<?php

namespace Tests\Concerns;

/**
 * 描画されたフォームの往復（Bug #28 / Bug #47: 呼び出し側と実体を対で固定する）。
 *
 * ⚠ 2026-08-17 に `tests/Feature/Tenant/AreaBuildingTestCase.php` から**そのまま**抽出した。
 *   認証まわりでも同じ往復が要るためで、書き直すと下の注意書き（実測で得た罠 4 件）が
 *   失われ、複製すると drift する。**中身を変えるときは両方の利用者で測り直すこと。**
 */
trait ParsesForms
{
    /**
     * 画面が実際に描画したフォームを、ブラウザと同じように分解する。
     *
     * これが無いと、HTTP メソッド（`@method`）・送信先（`action`）・選択肢が**片側だけ**の
     * 状態になる。実測（2026-08-17）で、`@method('PUT')` や `@method('DELETE')` を消しても
     * `action` を store 側へ向けても、テストは全部緑のままだった。
     *
     * 使い方（返り値をそのまま送り返すのが「ブラウザと同じ」）:
     *   $form = $this->parseForm($html, 'action="' . route('...update', [$a, $b]) . '"');
     *   $this->post($form['action'], $form['fields']);   // _method で PUT / DELETE へ化ける
     *
     * ⚠ **$needle は `action="…"` まで含める形で渡すこと。** 素の URL で探すと
     *   destroy の URL（`…/surveys/1`）が編集リンク（`…/surveys/1/edit`）に前方一致し、
     *   その手前にある**別のフォーム**を掴む。誤用を黙って通さないよう、needle が
     *   開始タグの中にあることを下でアサートしている。
     *
     * ⚠ `@csrf` の欠落は Feature テストでは**原理的に挙動から検出できない**
     *   （VerifyCsrfToken が runningUnitTests() で素通りする）。描画された `_token` hidden の
     *   存在をアサートするのが唯一の手なので、fields に `_token` を残している。
     *
     * ⚠ **`<select>` の往復テストは「先頭 option 以外」の値で測ること。**
     *   下の selectedValue() は「`selected` が 1 つも無ければ**先頭 option**」を返す
     *   （ブラウザ挙動として正しい）。空の先頭 option を持たないセレクトで、たまたま
     *   先頭 case のデータを使うと、**正しい描画と壊れた描画が同じ値になり false-pass する**。
     *   実測（2026-08-17）: `AreaTenantStatus::cases()` の先頭は Operating なので、
     *   `tenants/_form.blade.php` から `$tenant?->status?->value ??` を落としても
     *   26 本全部が緑だった（同じ壊し方を `old('floor', …)` にすると赤になる。**select だけが例外**）。
     *   固定するときは Vacant / Unknown のような**先頭以外の case**で往復させる
     *   （`AreaBuildingTenantCrudTest::test_edit_form_preselects_the_stored_status`）。
     *
     * ⚠ **未チェックの checkbox は fields に入らない**（ブラウザと同じ）。よって
     *   `assertArrayNotHasKey('x', $form['fields'])` は「**チェック済みで出ている**」場合しか
     *   検出できず、「その画面に出してはいけない項目が出ていないこと」の証明にはならない。
     *   実測（2026-08-17）: 「保存して続けて登録」ブロックを edit 画面へ貼り付けても
     *   662 テスト全部が緑だった。**「無いこと」は生 HTML で見る**
     *   （`assertStringNotContainsString('name="keep_adding"', $html)`）。
     *
     * @return array{method: string, action: string, fields: array<string, string>}
     */
    protected function parseForm(string $html, string $needle): array
    {
        $pos = strpos($html, $needle);
        $this->assertNotFalse($pos, "フォームが見つからない: {$needle}");

        $open = strrpos(substr($html, 0, $pos), '<form');
        $this->assertNotFalse($open, "{$needle} を含む <form> の開始タグが見つからない");

        $close = strpos($html, '</form>', $pos);
        $this->assertNotFalse($close, "{$needle} を含む <form> が閉じていない");

        $form    = substr($html, $open, $close - $open);
        $openTag = substr($form, 0, strpos($form, '>') + 1);

        $this->assertStringContainsString(
            $needle,
            $openTag,
            "{$needle} が <form> の開始タグの中に無い。素の URL ではなく action=\"…\" 込みで指定すること"
        );

        $fields = [];

        preg_match_all('/<input\b[^>]*>/i', $form, $inputs);
        foreach ($inputs[0] as $tag) {
            $name = $this->htmlAttr($tag, 'name');
            $type = strtolower($this->htmlAttr($tag, 'type') ?? 'text');

            if ($name === null || in_array($type, ['submit', 'button', 'reset', 'image', 'file'], true)) {
                continue;
            }
            // 未チェックの checkbox / radio はブラウザも送らない
            if (in_array($type, ['checkbox', 'radio'], true) && ! preg_match('/\bchecked\b/i', $tag)) {
                continue;
            }

            $fields[$name] = $this->htmlAttr($tag, 'value') ?? '';
        }

        preg_match_all('/<textarea\b([^>]*)>(.*?)<\/textarea>/is', $form, $areas, PREG_SET_ORDER);
        foreach ($areas as $area) {
            $name = $this->htmlAttr('<textarea' . $area[1] . '>', 'name');
            if ($name !== null) {
                $fields[$name] = html_entity_decode($area[2], ENT_QUOTES, 'UTF-8');
            }
        }

        preg_match_all('/<select\b([^>]*)>(.*?)<\/select>/is', $form, $selects, PREG_SET_ORDER);
        foreach ($selects as $select) {
            $name = $this->htmlAttr('<select' . $select[1] . '>', 'name');
            if ($name === null) {
                continue;
            }
            $fields[$name] = $this->selectedValue($select[2]);
        }

        return [
            // 実効メソッド。@method('PUT') が消えれば POST に落ちるので、ここで差が出る
            'method' => strtoupper($fields['_method'] ?? $this->htmlAttr($openTag, 'method') ?? 'GET'),
            'action' => $this->htmlAttr($openTag, 'action') ?? '',
            'fields' => $fields,
        ];
    }

    /** ブラウザと同じく、selected な option（無ければ先頭）の value を送る */
    private function selectedValue(string $optionsHtml): string
    {
        preg_match_all('/<option\b[^>]*>/i', $optionsHtml, $options);

        foreach ($options[0] as $option) {
            if (preg_match('/\bselected\b/i', $option)) {
                return $this->htmlAttr($option, 'value') ?? '';
            }
        }

        return isset($options[0][0]) ? ($this->htmlAttr($options[0][0], 'value') ?? '') : '';
    }

    /**
     * タグから属性値を取り出す。
     * ⚠ `\bname=` だと `data-name=` にも当たる（`-` の直後は語境界）。
     *   語構成文字とハイフンの直後を除外する。
     * ⚠ **Alpine のバインド属性も除外する。** `:value="payload()"` / `x-bind:value="…"` /
     *   `@change="…"` は素の属性ではないのに `(?<![\w-])value="…"` に一致してしまい、
     *   **Alpine の式文字列がそのまま「フォームの値」として返る**（2026-08-17 実測で
     *   `kind => 'kind'`、`rows => 'payload()'` が返った）。ブラウザが描画直後に持つ値は
     *   空なので、`:` `.` `@` の直後も除外して空を返させる。
     */
    private function htmlAttr(string $tag, string $name): ?string
    {
        $pattern = '/(?<![\w:.@-])' . preg_quote($name, '/') . '="([^"]*)"/i';

        return preg_match($pattern, $tag, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : null;
    }
}
