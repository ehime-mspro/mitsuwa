<?php

namespace Tests\Feature\Admin\Master;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\DrivesAlpineFetch;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * システム管理のマスタ（用途・構造・用途地域・原価項目・DAD 専門分野・ZEAL 試算表の項目・アンケート設問）の
 * 画面のテストの土台。どのマスタも経営層だけが開ける（`role:executive`）。
 *
 * ⚠ 送る値は描いた画面から取る（Bug #47 の往復）。一覧の中で追加・編集・削除する画面は、Alpine が隠しフォームの
 *   送り先と値を入れて `$refs.….submit()` する。そのフォームは DrivesAlpineFetch が JS の状態で評価して返す
 *   （`submitted`）ので、それをそのまま送る。
 * ⚠ 成功・失敗の文はレイアウトの帯（`text-emerald-800` / `text-red-800` の span）に出る。文言だけで見ると、同じ名前が
 *   一覧の行にも出ているので取り違える（Bug #43）。帯の要素ごと見る（assertFlash()）。
 */
abstract class MasterScreenTestCase extends TestCase
{
    use RefreshDatabase;
    use ParsesForms;
    use DrivesAlpineFetch;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role' => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    protected function htmlOf(string $url): string
    {
        return $this->actingAs($this->user)->get($url)->assertOk()->getContent();
    }

    /**
     * $url の画面の JS（$function）を $steps で操り、JS が送ったフォーム（ちょうど 1 つ）を、その画面から送ったものとして送る。
     *
     * @return array{form: array{ref: string, method: string, action: string, fields: array<string, string>}, response: TestResponse}
     */
    protected function submitFromScreen(string $url, string $function, string $steps): array
    {
        $html = $this->htmlOf($url);
        $run = $this->driveAlpine($html, $function, $this->xData($html, $function), $steps);
        $this->assertCount(1, $run['submitted'], '画面の JS がフォームを 1 つ送らなかった');
        $form = $run['submitted'][0];
        $this->assertNotSame('', $form['fields']['_token'] ?? '', 'JS が送ったフォームに @csrf が無い');

        return ['form' => $form, 'response' => $this->actingAs($this->user)->from($url)->sendSubmitted($form)];
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
        $this->assertMatchesRegularExpression(
            '/<span class="text-sm ' . $class . '">\s*' . preg_quote(e($message), '/') . '\s*<\/span>/u',
            $html,
            "帯（{$type}）に「{$message}」が出ていない"
        );
    }

    /**
     * ドラッグの開始に渡すイベント（並び替え）。dataTransfer と preventDefault だけを持つ。
     */
    protected function dragEventJs(): string
    {
        return '{ dataTransfer: { effectAllowed: "", dropEffect: "", setData: function () {} }, preventDefault: function () {} }';
    }
}
