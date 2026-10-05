<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * 画面のテストの共通の手順（描いた画面のフォームを送り、転送をたどって着いた画面で文言を見る）。
 * テナントと不動産の画面のテストの土台が使う（複製すると drift する）。
 *
 * ⚠ 使う側は `$this->user`（送る人）を持つ。ParsesForms / DrivesAlpineFetch と一緒に使う。
 * ⚠ 成功・失敗の文はレイアウトの帯（`text-emerald-800` / `text-red-800` の span）に出る。文言だけで見ると、
 *   同じ名前が画面の別の場所にも出ているので取り違える（Bug #43）。帯の要素ごと見る（assertFlash()）。
 */
trait SubmitsScreenForms
{
    protected function htmlOf(string $url): string
    {
        return $this->actingAs($this->user)->get($url)->assertOk()->getContent();
    }

    /**
     * 利用者が欄に打ち込む（Alpine の値を持たない素の欄）。画面に無い項目は足さない（足すと、画面から欄が消えても緑になる）。
     */
    protected function fill(array $form, array $values): array
    {
        foreach ($values as $name => $value) {
            $this->assertArrayHasKey($name, $form['fields'], "画面のフォームに「{$name}」の欄が無い");
            $form['fields'][$name] = $value;
        }

        return $form;
    }

    /** 画面から送る（$from は送った画面＝入力エラーで戻る先） */
    protected function submit(array $form, string $from): TestResponse
    {
        return $this->actingAs($this->user)->from($from)
            ->call($form['method'] === 'GET' ? 'GET' : 'POST', $form['action'], $form['fields']);
    }

    /** 転送をたどって着いた画面の HTML（Bug #63: 行き先の URL だけでなく、着いた画面で文言を見る） */
    protected function landed(TestResponse $response): string
    {
        return $this->followRedirects($response)->assertOk()->getContent();
    }

    /** レイアウトの帯に $message が出ている（$type は success / error） */
    protected function assertFlash(string $html, string $type, string $message): void
    {
        $class = $type === 'success' ? 'text-emerald-800' : 'text-red-800';
        // 一致したかだけを見る（失敗したときに画面の HTML を丸ごと出さない）
        $pattern = '/<span class="text-sm ' . $class . '">\s*' . preg_quote(e($message), '/') . '\s*<\/span>/u';
        $this->assertSame(1, preg_match($pattern, $html), "帯（{$type}）に「{$message}」が出ていない");
    }
}
