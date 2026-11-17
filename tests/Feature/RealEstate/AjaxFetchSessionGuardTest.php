<?php

namespace Tests\Feature\RealEstate;

use App\Enums\UserRole;
use App\Models\ReProcurement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesRealEstateSchema;
use Tests\Concerns\ScansSameOriginGetAjax;
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
    use ScansSameOriginGetAjax;

    /**
     * 走査で拾えるはずの呼び出し箇所の下限。走査が空振りして緑になる事故を防ぐ。
     *
     * 実数は 24（2026-10-04。URL に `/api/` を含む 21 ＋ 含まない 3: 工程表の部品・決裁の申請・ZEAL の試算表の確認）。
     * 下限を実数ちょうどにすると、正当に fetch を 1 つ減らしただけで
     * 「走査が壊れた」という誤った理由で落ちるので余裕を持たせる。
     * 走査ロジックが壊れれば 0 になるため、この値でも検知できる。
     */
    private const MIN_CALL_SITES = 20;

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
     * resources/views/ 全体を走査し、**自社へ GET で送る** fetch が
     * すべて X-Requested-With を送っていること（URL に `/api/` を含むかどうかを問わない）。
     *
     * ⚠ **GET だけが対象。** `storeCurrentUrl()` は GET のリクエストしか直前 URL を
     *    上書きしないので、POST / PUT / DELETE の fetch は無関係（並び替えや
     *    trainers / stores の CRUD にヘッダーが無くてもこの欠陥は起きない）。
     * ⚠ **外部 API は対象外。** zipcloud は自社セッションを通らない。
     *
     * ⚠ 2026-08-03 に `/api/realestate/` 限定から全モジュールへ広げた。
     *    限定していた間、テナント・住宅事業・賃貸マンションの 15 箇所が
     *    **原理的に検出できないまま**残っていた。
     * ⚠ 2026-10-04 に「URL に `/api/` を含むもの」限定から、自社への GET すべてへ広げた。
     *    ZEAL の試算表の「実績を反映」の確認（`/zeal/simulations/{id}/sync-actuals/preview`）は
     *    `/api/` を含まず、URL も変数（`fetch(previewUrl, …)`）なので拾えず、ヘッダーが無いまま残っていた
     *    （docs/RULES.md Bug #75）。外部かどうかは `://` で見る（zipcloud は URL を直書きしている）。
     */
    public function test_all_same_origin_get_fetches_send_ajax_header(): void
    {
        $offenders = [];
        $callSites = 0;

        foreach (self::sameOriginGetFetches() as $fetch) {
            $callSites++;
            if (! str_contains($fetch['call'], 'X-Requested-With')) {
                $offenders[] = $fetch['file'] . ':' . $fetch['line'];
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

    /**
     * 走査で拾えるはずの XMLHttpRequest の GET の下限（2026-10-07 の実数は 2: 賃貸マンションの物件フォームと買主のフォームの「住所→〒」）。
     * 走査が壊れれば 0 になる。
     */
    private const MIN_XHR_CALL_SITES = 2;

    /**
     * XMLHttpRequest で自社へ GET するものも X-Requested-With を送る（fetch と同じ理由。Top trap #9）。
     *
     * ⚠ 2026-10-07 まで、上の走査は `fetch(` しか見ていなかった。賃貸マンションの物件フォームと買主のフォーム（住宅事業・不動産で共用）の
     *   「住所→〒」は `xhr.open('GET', '{{ route("api.reverse-zip") }}…')` で、ヘッダー無しのまま見逃されていた。
     * `.open('GET', …)` から次の `.send(` までに `setRequestHeader('X-Requested-With'` があるかを見る（外部 API の zipcloud は対象外）。
     */
    public function test_all_same_origin_get_xhrs_send_ajax_header(): void
    {
        $offenders = [];
        $callSites = 0;

        foreach (self::sameOriginGetXhrOpens() as $xhr) {
            $callSites++;
            if (! preg_match('/setRequestHeader\(\s*[\'"]X-Requested-With[\'"]/', $xhr['block'])) {
                $offenders[] = $xhr['file'] . ':' . $xhr['line'];
            }
        }

        $this->assertGreaterThanOrEqual(self::MIN_XHR_CALL_SITES, $callSites, 'XMLHttpRequest の走査が機能していない（拾えた呼び出し箇所が少なすぎる）');
        $this->assertSame([], $offenders, "X-Requested-With を送っていない XMLHttpRequest の GET があります:\n" . implode("\n", $offenders));
    }

    // ============================================================
    // 保存の Ajax（GET 以外）は JSON を求める（Bug #137）
    // ============================================================

    /**
     * 拾えるはずの、自社への GET 以外の fetch と XMLHttpRequest の下限（2026-11-17 の実数は fetch 39〈決裁の 2 を含む〉・XHR 3）。
     * 走査が壊れれば 0 になる。正当に 1 つ減らしただけで落ちないよう余裕を持たせる。
     */
    private const MIN_SAVE_FETCH_CALL_SITES = 30;

    private const MIN_SAVE_XHR_CALL_SITES = 2;

    /** fetch の本体が JSON を求めるヘッダーを持つ（`'Accept': 'application/json'` も `Accept: 'application/json'` も） */
    private const FETCH_ASKS_FOR_JSON = '/[\'"]?Accept[\'"]?\s*:\s*[\'"]application\/json[\'"]/i';

    /** XMLHttpRequest が `.open(…)` から `.send(` までに JSON を求めるヘッダーを付ける */
    private const XHR_ASKS_FOR_JSON = '/setRequestHeader\(\s*[\'"]Accept[\'"]\s*,\s*[\'"]application\/json[\'"]/i';

    /**
     * `$source`（Blade 1 本）の、自社への GET 以外の fetch・XMLHttpRequest を数え、JSON を求めないものの行番号を返す。
     * fetch は `method:` が文字の GET でなければ（変数の `method: method` も）GET 以外として数える。`method:` を書かないものは GET。
     * 外部 API（URL に `://` を含む。zipcloud など）は数えない。
     *
     * ⚠ 死角（見落とす向き。2026-11-17 に resources/views を数え、どれも実物は 0 件。見本で今の動きを固定している）:
     *   - fetch の呼び出しの中の JS コメントに書いた `'Accept': 'application/json'` も、求めているとみなす
     *   - オプションを変数で渡す `fetch(url, opts)` は、`method:` が見えないので GET とみなして数えない
     *   - `xhr.open(変数, …)` は数えない（メソッドを引用符の文字で書いたものだけを見る）
     * ⚠ 厳しすぎる向き: `'Accept': 'application/json, text/plain'` のように型を並べると、求めていないとみなして断る
     *
     * @return array{fetches: int, xhrs: int, offenders: array<int, int>}
     */
    private static function saveAjaxWithoutJson(string $source): array
    {
        $fetches = 0;
        $xhrs = 0;
        $offenders = [];

        $offset = 0;
        while (($pos = strpos($source, 'fetch(', $offset)) !== false) {
            $offset = $pos + 6;
            $call = self::fetchCall($source, $pos);
            if (str_contains(self::precedingLines($source, $pos, 5) . $call, '://')) {
                continue;   // 外部 API
            }
            if (! preg_match('/\bmethod\s*:\s*([^,}\n]+)/', $call, $m) || preg_match('/^([\'"])GET\1$/i', trim($m[1]))) {
                continue;   // GET（method を書かない・文字の GET）
            }
            $fetches++;
            if (! preg_match(self::FETCH_ASKS_FOR_JSON, $call)) {
                $offenders[] = substr_count(substr($source, 0, $pos), "\n") + 1;
            }
        }

        preg_match_all('/\.open\(\s*([\'"])(?!GET\1)[A-Z]+\1\s*,([^\n]*)/i', $source, $opens, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($opens as $open) {
            if (str_contains($open[2][0], '://')) {
                continue;   // 外部 API
            }
            $xhrs++;
            $pos = $open[0][1];
            $send = strpos($source, '.send(', $pos);
            $block = $send === false ? substr($source, $pos) : substr($source, $pos, $send - $pos);
            if (! preg_match(self::XHR_ASKS_FOR_JSON, $block)) {
                $offenders[] = substr_count(substr($source, 0, $pos), "\n") + 1;
            }
        }

        return ['fetches' => $fetches, 'xhrs' => $xhrs, 'offenders' => $offenders];
    }

    /**
     * 自社への GET 以外の fetch・XMLHttpRequest は `Accept: application/json` を送る。
     * 送らないと、ログインが切れた 419 が日本語の理由の JSON（bootstrap/app.php）でなく HTML の画面（errors/419）で返り、
     * 画面の JS が読めずに「通信エラー」などへ落ちる。決裁の画面も含めて見る（読むだけ）。
     */
    public function test_all_same_origin_saves_ask_for_json(): void
    {
        $fetches = 0;
        $xhrs = 0;
        $offenders = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $found = self::saveAjaxWithoutJson(file_get_contents($file->getPathname()));
            $fetches += $found['fetches'];
            $xhrs += $found['xhrs'];
            foreach ($found['offenders'] as $line) {
                $offenders[] = $file->getRelativePathname() . ':' . $line;
            }
        }

        $this->assertGreaterThanOrEqual(self::MIN_SAVE_FETCH_CALL_SITES, $fetches, 'GET 以外の fetch の走査が機能していない（拾えた呼び出し箇所が少なすぎる）');
        $this->assertGreaterThanOrEqual(self::MIN_SAVE_XHR_CALL_SITES, $xhrs, 'GET 以外の XMLHttpRequest の走査が機能していない（拾えた呼び出し箇所が少なすぎる）');
        $this->assertSame([], $offenders, "JSON を求めない（Accept: application/json の無い）保存の Ajax があります。ログインが切れたときの理由が画面に届きません:\n" . implode("\n", $offenders));
    }

    /** 走査の分かれ目（実物に出てこない書き方も見本で固定する） */
    public function test_the_save_scanner_reads_each_way_of_writing(): void
    {
        $cases = [
            // [見本, fetch の数, XHR の数, JSON を求めない行]
            'method を書かない fetch は GET'              => ['fetch(url, { headers: {} })', 0, 0, []],
            '文字の GET は GET（大文字小文字を問わない）'   => ['fetch(url, { method: "get", headers: {} })', 0, 0, []],
            'POST で JSON を求める'                       => ["fetch(url, { method: 'POST', headers: { 'Accept': 'application/json' } })", 1, 0, []],
            'キーを引用符で囲まない Accept'               => ["fetch(url, { method: 'PUT', headers: { Accept: 'application/json' } })", 1, 0, []],
            'DELETE で JSON を求めない'                   => ["x\nfetch(url, { method: 'DELETE', headers: {} })", 1, 0, [2]],
            '変数の method は GET 以外として見る'         => ['fetch(url, { method: verb, headers: {} })', 1, 0, [1]],
            '外部 API は数えない'                         => ["fetch('https://example.com/x', { method: 'POST' })", 0, 0, []],
            'XHR の POST で JSON を求める'                => ["x.open('POST', url);\nx.setRequestHeader('Accept', 'application/json');\nx.send(b);", 0, 1, []],
            'XHR の PATCH の .send( の後ろのヘッダーは数えない' => ["x.open('PATCH', url);\nx.send(b);\ny.setRequestHeader('Accept', 'application/json');", 0, 1, [1]],
            'XHR の GET は数えない'                       => ["x.open('GET', url);\nx.send();", 0, 0, []],
            'XHR の外部 API は数えない'                   => ["x.open('POST', 'https://example.com/x');\nx.send(b);", 0, 0, []],
            // 死角（見落とす向き）と厳しすぎる向き。今の動きを固定する（直すときはこの見本も変える。saveAjaxWithoutJson() の docblock）
            '死角: fetch の中のコメントの Accept も求めているとみなす' => ["fetch(url, { method: 'POST', /* 'Accept': 'application/json' */ headers: {} })", 1, 0, []],
            '死角: オプションを変数で渡す fetch は GET とみなす'       => ['fetch(url, opts)', 0, 0, []],
            '死角: XHR の open(変数) は数えない'                       => ["x.open(verb, url);\nx.send(b);", 0, 0, []],
            '厳しすぎる向き: Accept に型を並べると求めていないとみなす' => ["fetch(url, { method: 'POST', headers: { 'Accept': 'application/json, text/plain' } })", 1, 0, [1]],
        ];

        foreach ($cases as $label => [$source, $fetches, $xhrs, $offenders]) {
            $this->assertSame(['fetches' => $fetches, 'xhrs' => $xhrs, 'offenders' => $offenders], self::saveAjaxWithoutJson($source), $label);
        }
    }
}
