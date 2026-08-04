<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * layouts/app.blade.php の @stack('styles') が @push('styles') の中身を実際に <head> へ出力することを検証する。
 *
 * 【背景】2026-07-26 に @stack('scripts') を追加した時点でも styles スタックは無いままで、
 * @push('styles') は Laravel が警告も例外も出さずに捨て続けていた（docs/RULES.md Bug #28 の
 * 積み残し）。tenant/units/revise.blade.php には「push すると消えるので <style> を直接置く」
 * という回避コメントが実際に残っており、mansion/properties/index.blade.php には
 * 中身が空の @push('styles') が置かれていた。2026-08-04 に stack を追加して解消した。
 *
 * ⚠ **「HTML に出るか」だけでなく「<head> に出るか」まで見る。**
 *   stack を <body> 側に置いてしまっても CSS は効いてしまうため、位置を固定しないと
 *   「動くが意図と違う」状態を検出できない。@vite より後・</head> より前が正しい位置で、
 *   これによりページ側が Vite バンドルを上書きできる。
 *
 * ⚠ @push('styles') を使うビューを新設したら、ここに 1 本足すこと。
 *   実使用箇所は `grep -rc "^\s*@push('styles')" resources/views` で数える。
 *
 * 対になる scripts 側の検証は LayoutScriptStackTest。
 */
class LayoutStyleStackTest extends TestCase
{
    use RefreshDatabase;

    /** password.change を通過する経営層ユーザー（経営層は department.access を素通り） */
    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    private function makeUnit(): Unit
    {
        $property = Property::create([
            'code'          => 'PROP-STYLE-001',
            'name'          => 'スタイル検証ビル',
            'property_type' => 'tenant',
            'department'    => 'tenant',
            'address'       => '愛媛県松山市本町1-1',
        ]);

        return Unit::create([
            'property_id'  => $property->id,
            'room_number'  => 'A',
            'display_name' => 'A',
            'status'       => 'vacant',
            'rent'         => 100000,
            'common_fee'   => 10000,
            'deposit'      => 50000,
        ]);
    }

    /**
     * 区画の募集家賃改定フォームが push した CSS が <head> に出力される。
     *
     * 到達経路: GET /tenant/units/{unit}/revise
     * このページは坪単価計算ウィジェット用の .calc-input / .tsubo-box を push している。
     */
    public function test_pushed_styles_are_rendered_inside_head(): void
    {
        $unit = $this->makeUnit();

        $res = $this->actingAs($this->executive())->get(route('tenant.units.revise', $unit));

        $res->assertOk();

        $html = $res->getContent();

        // push した CSS が出力されている（stack が無いと丸ごと消えるのでここで落ちる）
        $this->assertStringContainsString('.tsubo-box {', $html, '@push(\'styles\') の中身が出力されていない');

        // かつ <head> の中にある（stack を body 側に置く誤りを検出する）
        $headEnd  = strpos($html, '</head>');
        $cssStart = strpos($html, '.tsubo-box {');
        $this->assertNotFalse($headEnd, '</head> が見つからない');
        $this->assertLessThan(
            $headEnd,
            $cssStart,
            'push した CSS が </head> より後に出ている（@stack(\'styles\') の位置が誤り）',
        );
    }

    /**
     * @stack('styles') は @vite より後・</head> より前に置かれている。
     *
     * ⚠ **HTML では検証できない** — tests/TestCase.php が withoutVite() を呼ぶので
     *   テスト環境の @vite は何も出力しない。よってレンダリング結果ではなく
     *   Blade ソース上の位置を固定する（docs/RULES.md Bug #42 の「構造テスト」）。
     *
     * ⚠ **Blade コメントを必ず落としてから測る。** レイアウトの注意書きには
     *   エスケープした @@stack('styles') が書いてある。素朴に検索すると
     *   実体を消してもコメント側の文字列に一致して false-pass する（Bug #42 ② と同型）。
     *
     *   これは**実測済み**（2026-08-04）。レイアウトから @stack('styles') の行だけを消し、
     *   コメントは残した状態で測ると:
     *     - コメント除去あり（現行）… 赤（正しく検出）
     *     - コメント除去なし（旧）  … **緑**（実体が無いのに通ってしまう）
     *   preg_replace の行を消すとこの防御が失われる。
     */
    public function test_styles_stack_sits_after_vite_and_inside_head(): void
    {
        $path = resource_path('views/layouts/app.blade.php');
        $src  = file_get_contents($path);

        // Blade コメント {{-- ... --}} を除去（複数行あるので /s）
        $src = preg_replace('/\{\{--.*?--\}\}/s', '', $src);

        $vitePos    = strpos($src, '@vite(');
        $stackPos   = strpos($src, "@stack('styles')");
        $headEndPos = strpos($src, '</head>');

        $this->assertNotFalse($vitePos, '@vite( が見つからない');
        $this->assertNotFalse($stackPos, "@stack('styles') が見つからない（コメント除去後）");
        $this->assertNotFalse($headEndPos, '</head> が見つからない');

        $this->assertLessThan(
            $stackPos,
            $vitePos,
            "@stack('styles') が @vite より前にある（ページ側の CSS がバンドルに負ける）",
        );
        $this->assertLessThan(
            $headEndPos,
            $stackPos,
            "@stack('styles') が </head> の外にある",
        );
    }

    /**
     * 何も push していないページでは @stack('styles') が余計な出力を足さない。
     *
     * @stack は約 200 ルートが通る共有レイアウトに入るため、
     * 「追加しても既存ページの出力は変わらない」ことを固定する。
     */
    public function test_stack_adds_nothing_on_pages_without_pushed_styles(): void
    {
        $res = $this->actingAs($this->executive())->get('/tenant/properties');

        $res->assertOk();

        $html = $res->getContent();

        // このページは push していないので、押し出された CSS は 1 つも無い
        $this->assertStringNotContainsString('.tsubo-box {', $html);
        // Blade コメントが素通しで出ていないこと（{{-- --}} はコンパイル時に消える）
        $this->assertStringNotContainsString("@push('styles')", $html);
        $this->assertStringNotContainsString("@stack('styles')", $html);
    }
}
