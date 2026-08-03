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
     * 実数は 22（不動産 7 + テナント 6 + 住宅事業 6 + 賃貸マンション 3）。
     * 下限を実数ちょうどにすると、正当に fetch を 1 つ減らしただけで
     * 「走査が壊れた」という誤った理由で落ちるので余裕を持たせる。
     * 走査ロジックが壊れれば 0 になるため、この値でも検知できる。
     */
    private const MIN_CALL_SITES = 15;

    /**
     * `fetch(` の呼び出し全体を括弧の対応で切り出す。
     *
     * ⚠ 「最初の `.then(` まで」で切ってはいけない。`Promise.all` 内の `await fetch(…)`
     *    には近くに `.then(` が無く、固定長のフォールバックに落ちて**別のハンドラの**
     *    `X-Requested-With` を拾い、**誤って緑になる**（mansion/contracts/create が該当）。
     */
    private static function fetchCall(string $source, int $pos): string
    {
        $depth = 0;

        for ($i = $pos + 5, $len = strlen($source); $i < $len; $i++) {
            if ($source[$i] === '(') {
                $depth++;
            } elseif ($source[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $pos, $i - $pos + 1);
                }
            }
        }

        return substr($source, $pos, 500);
    }

    /**
     * `$pos` の直前 `$n` 行を返す。
     *
     * ⚠ `var url = '{{ url("/api/…") }}';` と組み立ててから `fetch(url, …)` する形は、
     *    呼び出し本体だけを見ても URL が分からず**原理的に拾えない**
     *    （housing/properties/_form と mansion の計 4 箇所が該当）。
     *    URL の判定材料としてのみ使い、ヘッダーの有無は呼び出し本体だけで判定する。
     */
    private static function precedingLines(string $source, int $pos, int $n): string
    {
        $start = $pos;

        for ($i = 0; $i <= $n; $i++) {
            $prev = strrpos(substr($source, 0, $start), "\n");
            if ($prev === false) {
                return substr($source, 0, $pos);
            }
            $start = $prev;
        }

        return substr($source, $start, $pos - $start);
    }

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
     * resources/views/ 全体を走査し、**自社 API を GET で叩く** fetch が
     * すべて X-Requested-With を送っていること。
     *
     * ⚠ **GET だけが対象。** `storeCurrentUrl()` は GET のリクエストしか直前 URL を
     *    上書きしないので、POST / PUT / DELETE の fetch は無関係（並び替えや
     *    trainers / stores の CRUD にヘッダーが無くてもこの欠陥は起きない）。
     * ⚠ **外部 API は対象外。** zipcloud は自社セッションを通らない。
     *
     * ⚠ 2026-08-03 に `/api/realestate/` 限定から全モジュールへ広げた。
     *    限定していた間、テナント・住宅事業・賃貸マンションの 15 箇所が
     *    **原理的に検出できないまま**残っていた。
     */
    public function test_all_same_origin_get_fetches_send_ajax_header(): void
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

                $call = self::fetchCall($source, $pos);
                $ctx  = self::precedingLines($source, $pos, 5) . $call;

                if (str_contains($ctx, '://')) {
                    continue;   // 外部 API（zipcloud）
                }
                if (! preg_match('#/api/|url\([\'"]api/|route\([\'"]api\.#', $ctx)) {
                    continue;   // 自社 API を叩いていない
                }
                if (preg_match('/method:\s*[\'"](?!GET)/', $call)) {
                    continue;   // GET 以外は storeCurrentUrl の対象外
                }

                $callSites++;

                if (! str_contains($call, 'X-Requested-With')) {
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
