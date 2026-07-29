<?php

namespace Tests\Feature\RealEstate;

use App\Enums\UserRole;
use App\Models\ReProcurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\TestCase;

/**
 * Ajax の JSON 取得がセッションの「直前 URL」を汚染しないことの検証。
 *
 * Laravel の StartSession::storeCurrentUrl() は「GET・非 Ajax」のリクエストごとに
 * セッションの直前 URL を上書きする。$request->ajax() は X-Requested-With ヘッダーの
 * 有無だけで決まるため、ヘッダー無しの fetch で JSON API を GET すると、
 * その URL が「直前 URL」になってしまう。
 * するとバリデーションエラー時の back() がフォームでなく JSON エンドポイントへ飛び、
 * ユーザーは入力を全部失う（本番で発生していた既存バグ）。
 *
 * ⚠ 挙動テストはヘッダーを PHP から手で送るので、Blade 側からヘッダーが消えても緑のまま。
 *    そこで「Blade が実際にヘッダーを送っていること」を走査で対にして固定する
 *    （docs/RULES.md Bug #28 の教訓: 呼び出し側と定義側を必ず対で検証する）。
 */
class AjaxFetchSessionGuardTest extends TestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    /**
     * 走査で拾えるはずの呼び出し箇所の下限。走査が空振りして緑になる事故を防ぐ。
     *
     * 実数は 8（契約 create 3 + edit 3 + 仕入れ先ピッカー 2）。
     * 下限を実数ちょうどにすると、正当に fetch を 1 つ減らしただけで
     * 「走査が壊れた」という誤った理由で落ちるので余裕を持たせる。
     * 走査ロジックが壊れれば 0 になるため、この値でも検知できる。
     */
    private const MIN_CALL_SITES = 5;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    private function executive(): User
    {
        return User::factory()->create([
            'role'                 => UserRole::Executive->value,
            'must_change_password' => false,
        ]);
    }

    /**
     * 契約登録画面: 原価取得 Ajax を挟んでもバリデーションエラーがフォームへ戻る。
     */
    public function test_contract_create_back_survives_cost_lookup(): void
    {
        $user = $this->executive();

        $proc = ReProcurement::create([
            'procurement_code'   => 'PRC-001',
            'property_type'      => 'used_house',
            'transaction_type'   => 'purchase',
            'status'             => 'selling',
            'property_name'      => '物件A',
            'address'            => '愛媛県松山市1-1-1',
            'info_obtained_date' => '2026-06-01',
            'created_by'         => $user->id,
        ]);

        $this->actingAs($user)->get('/realestate/contracts/create')->assertOk();

        // contracts/create.blade.php の fetch を再現(Blade 側と同じヘッダーを送ること)
        $this->actingAs($user)->get('/api/realestate/procurement-cost/' . $proc->id, [
            'Accept'           => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->assertOk();

        $this->actingAs($user)->post('/realestate/contracts', [])
            ->assertRedirect(route('realestate.contracts.create'));
    }

    /**
     * resources/views/ 全体を走査し、/api/realestate/ を叩く fetch が
     * すべて X-Requested-With を送っていること。
     */
    public function test_all_realestate_api_fetches_send_ajax_header(): void
    {
        $offenders = [];
        $callSites = 0;

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            $offset = 0;

            while (($pos = strpos($source, 'fetch(', $offset)) !== false) {
                $offset = $pos + 6;

                // fetch( から最初の .then( までが引数部分。この書式はプロジェクト全体で共通
                $end   = strpos($source, '.then(', $pos);
                $block = $end !== false ? substr($source, $pos, $end - $pos) : substr($source, $pos, 500);

                if (! str_contains($block, '/api/realestate/')) {
                    continue;
                }

                $callSites++;

                if (! str_contains($block, 'X-Requested-With')) {
                    $line = substr_count(substr($source, 0, $pos), "\n") + 1;
                    $offenders[] = $file->getRelativePathname() . ':' . $line;
                }
            }
        }

        // 走査が壊れて 0 件になったまま緑になる事故を防ぐ
        $this->assertGreaterThanOrEqual(
            self::MIN_CALL_SITES,
            $callSites,
            'fetch の走査が機能していない(拾えた呼び出し箇所が少なすぎる)'
        );

        $this->assertSame(
            [],
            $offenders,
            "X-Requested-With を送っていない fetch があります。\n"
            . "セッションの直前 URL が JSON エンドポイントで上書きされ、\n"
            . "バリデーションエラー時の back() がフォームに戻らなくなります:\n"
            . implode("\n", $offenders)
        );
    }
}
