<?php

namespace Tests\Feature;

use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ScansImportControllers;
use Tests\TestCase;

/**
 * 取込のコントローラが、入力チェックの例外を「戻り先を固定して投げ直す」形で包んでいることを全件分類で守る
 * （docs/RULES.md Bug #64・Top trap #13。設計書 docs/superpowers/specs/2026-09-25-import-validation-scan-design.md）。
 *
 * 取込の確認画面は POST の応答で、その URL は多くが POST 専用（`…/preview` など）。そこに載ったフォームから送って
 * 入力チェックで断られると、既定の戻り先はリファラー（`url()->previous()`）＝その URL で、GET で開いて 405 になる。
 * コードに `back(` が現れないので `ImportControllerReturnPathScanTest` には見えない。ここでは、入力チェックの例外を
 * 投げる呼び出しをトークンで探し、1 つずつ次の A か B を満たすかを見る:
 *
 *   A. 囲む try の catch のうち ValidationException を受け止める最初のものが、ValidationException を名指しし
 *      （`\Exception`・`\Throwable` が先に受け止めるなら不合格）、本体が `throw $<catch の変数>->redirectTo(…);` の 1 文だけ
 *   B. 例外を作る呼び出し（`::withMessages(`・`new ValidationException`）が throw の直後（括弧で括ってもよい）にあり、
 *      その後ろのメソッドの連鎖に `->redirectTo(` がある（`throw ValidationException::withMessages([...])->redirectTo(…);`）。
 *      `->validate(` などは例外を返さないので B では見ない（`withMessages()` の引数の中に書いた入力チェックは A で判定する）。
 *      連鎖の外（`,`・`??`・`:` など）の後ろの `->redirectTo(` は、投げる式のものではないので数えない
 *
 * ⚠ A は内側の try から順に見て、受け止める最初の catch で決める（外側の try は見ない）。内側の catch が `throw $e;` と
 *   投げ直し、外側の catch がそれを受けて `redirectTo()` する形も不合格にする（厳しめの規則。設計書 §4.4）。
 *
 * FormRequest（既定の戻り先がリファラー）を受ける public メソッドは Reflection で探し、1 つでもあれば落とす。
 *
 * ⚠ 分担: `redirectTo()` に渡す戻り先の中身（`back()`・`url()->previous()`・今の URL）はここでは見ない。
 *   それは `ImportControllerReturnPathScanTest` が見る。
 * ⚠ 対象は `ScansImportControllers` が列挙する `*ImportController.php`（新しい取込は自動で検査対象に入る）。
 *   包んでいない呼び出しを許すのは ALLOWED に載せたもの（件数と理由つき）だけ。件数が合わない・
 *   実在しないファイルが載っている、も落とす。
 * ⚠ 正規表現でなく `token_get_all()` のトークンで読む（コメントや文字列の中の `->validate(` を拾わない・
 *   try と catch の入れ子と catch の並び順を取り違えない。Bug #45 ④）。解析できない書き方（まとめた use
 *   ＝ `use A\{B, C};` と `use A\B, C\D;`・名前の続かない冒頭の use・namespace が 2 つ以上・`namespace X { … }` の形・
 *   波括弧の対応が取れない）は推測せずに落とす。クラス名もメソッド名も、PHP と同じく大文字小文字を区別しない。
 *
 * ⚠ 見えないもの:
 *   - トレイト・親クラス・サービスに移した入力チェック（2026-09-25 時点で 0 件）
 *   - `*ImportController.php` という名前でない取込（仕入れ案件・分譲地の原価の一括取込。詳細画面（GET）から
 *     JS で送るので 405 の形ではない。設計書 §2.5）
 *   - try の中で作って、try の外で呼ばれるクロージャ（字面では包まれて見える）
 *   - 見本に無い書き方（検出する形 ＝ `->validate(`・`?->validate(`・`->validateWithBag(`・`->validated(`・`->safe(`・
 *     `->validateWith(`・`::validate(`・`::withMessages(`・`::validateWithBag(`・`new ValidationException`・
 *     `ValidationException::class` のほかの投げ方。`namespace\ValidationException` のような相対名も含む）
 * ⚠ 拾いすぎるもの: 呼び出し元のメソッドで包んだ形（メソッドをまたぐと見えない）・`fails()` を確かめた後の
 *   `validated()`・別の物の `validate()`。呼び出しのすぐ外で包む形に直すか、ALLOWED に理由つきで載せる
 *   （検出を緩めない）。
 */
class ImportControllerValidationRedirectScanTest extends TestCase
{
    use ScansImportControllers;

    /** 入力チェックの例外（小文字で比べる。PHP のクラス名は大文字小文字を区別しない） */
    private const VALIDATION_EXCEPTION = 'illuminate\validation\validationexception';

    /** 入力チェックの例外も受け止めてしまう総称の型 */
    private const GENERIC_CATCHES = ['exception', 'throwable'];

    /** 入力チェックの例外を投げる呼び出しの総数の下限（2026-09-25 実測 10 件）。下回ったら検出が空振りしている */
    private const MIN_THROWING_CALLS = 10;

    /** @var array<string, array{0: int, 1: string}> 相対パス => [包んでいない呼び出しの件数, 理由] */
    private const ALLOWED = [
        'app/Http/Controllers/Admin/CustomerImportController.php' => [
            1,
            '確認画面の URL が取込の画面と同じ（GET と POST がどちらも /admin/customers/import）。'
                . 'リファラーへ戻ると取込の画面が GET で開く（2026-09-24 実測 200）',
        ],
        'app/Http/Controllers/Tenant/AreaBuildingImportController.php' => [
            1,
            '確認は画面の中（SheetJS）で行い、確定の送信元は GET の取込の画面。'
                . 'リファラーへ戻ると取込の画面が開く（2026-09-24 実測 200）',
        ],
        'app/Http/Controllers/Zeal/SheetImportController.php' => [
            1,
            'updateUrls（PUT）の送信元は GET の URL 編集画面（editUrls が描く zeal/simulations/sheet-import/urls.blade.php）だけ。'
                . 'リファラーへ戻ると編集画面が GET で開く（2026-09-25 にルートとビューで確認）',
        ],
    ];

    // ================================================================
    // 実物の取込のコントローラ
    // ================================================================

    public function test_the_scan_finds_the_calls_that_throw_validation_exceptions(): void
    {
        $counts = array_map(fn ($calls) => is_array($calls) ? count($calls) : 0, $this->scan());

        $this->assertGreaterThanOrEqual(
            self::MIN_THROWING_CALLS,
            array_sum($counts),
            '入力チェックの例外を投げる呼び出しが少なすぎる（検出が空振りしている）: '
                . json_encode($counts, JSON_UNESCAPED_SLASHES)
        );
    }

    public function test_import_controllers_send_every_validation_failure_to_a_fixed_page(): void
    {
        $scan = $this->scan();
        $problems = [];

        foreach ($scan as $path => $calls) {
            if (is_string($calls)) {
                $problems[] = "{$path}: 解析できない（{$calls}）";

                continue;
            }

            $unwrapped = array_values(array_filter($calls, fn (array $call) => $call['reason'] !== null));
            $expected = self::ALLOWED[$path][0] ?? 0;

            if (count($unwrapped) !== $expected) {
                $problems[] = "{$path}: 包んでいない呼び出しが " . count($unwrapped) . " 件（分類は {$expected}）: "
                    . implode(' / ', array_map(fn (array $call) => ":{$call['line']} {$call['form']}{$call['reason']}", $unwrapped));
            }
        }

        foreach (array_keys(self::ALLOWED) as $path) {
            if (! array_key_exists($path, $scan)) {
                $problems[] = "{$path}: 分類にあるのに、そのファイルが無い（古い項目）";
            }
        }

        $this->assertSame([], $problems, '取込のコントローラが入力チェックの例外を包んでいない（既定の戻り先はリファラーで、'
            . '確認画面の URL が POST 専用だと 405。try で包み catch (ValidationException $e) { throw $e->redirectTo(route(…)); } '
            . "で取込の画面へ戻す。docs/RULES.md Bug #64）:\n" . implode("\n", $problems));
    }

    public function test_import_controllers_take_no_form_request(): void
    {
        $found = [];

        foreach (array_keys($this->importControllerFiles()) as $path) {
            $this->assertStringStartsWith('app/', $path);
            $class = 'App\\' . str_replace(['/', '.php'], ['\\', ''], substr($path, strlen('app/')));
            $this->assertTrue(class_exists($class), "{$path}: クラス {$class} が見つからない");

            foreach ($this->formRequestParameters(new \ReflectionClass($class)) as $parameter) {
                $found[] = "{$path}: {$parameter}";
            }
        }

        $this->assertSame([], $found, '取込のコントローラが FormRequest を受けている（FormRequest の既定の戻り先はリファラーで、'
            . "確認画面の URL が POST 専用だと 405。docs/RULES.md Bug #64）:\n" . implode("\n", $found));
    }

    // ================================================================
    // 走査の自己テスト（見本の無い分かれ目は、消しても全テストが緑になる。Bug #61 ③）
    // ================================================================

    /** @return array<string, array{0: string, 1: list<string>}> 見本 => 拾う形 */
    public static function throwingCallSamples(): array
    {
        return [
            '->validate('                  => [self::sample('$request->validate([\'a\' => \'required\']);'), ['->validate(']],
            '?->validate('                 => [self::sample('$request?->validate([\'a\' => \'required\']);'), ['?->validate(']],
            '->validateWithBag('           => [self::sample('$request->validateWithBag(\'import\', [\'a\' => \'required\']);'), ['->validateWithBag(']],
            '->validated('                 => [self::sample('$data = $validator->validated();'), ['->validated(']],
            '::validate('                  => [self::sample('Validator::validate($data, [\'a\' => \'required\']);'), ['::validate(']],
            '::withMessages('              => [self::sample('throw ValidationException::withMessages([\'a\' => \'x\']);'), ['::withMessages(']],
            'new ValidationException'      => [self::sample('throw new ValidationException($validator);'), ['new ValidationException']],
            'ValidationException::class'   => [self::sample('throw_if($validator->fails(), ValidationException::class, $validator);'), ['ValidationException::class']],
            '大文字の ->Validate('          => [self::sample('$request->Validate([]);'), ['->Validate(']],
            '$this->validate('             => [self::sample('$this->validate($request, []);'), ['->validate(']],
            'make() の後の ->validate('     => [self::sample('Validator::make($data, [])->validate();'), ['->validate(']],
            '空白をはさむ'                 => [self::sample('$request -> validate ( [] );'), ['->validate(']],
            'コメントをはさむ'             => [self::sample('$request->/* 注 */validate([]);'), ['->validate(']],
            '完全な名前の new'             => [self::sample('throw new \Illuminate\Validation\ValidationException($v);', ''), ['new \Illuminate\Validation\ValidationException']],
            'as の別名の new'              => [self::sample('throw new InvalidInput($v);', "use Illuminate\\Validation\\ValidationException as InvalidInput;\n"), ['new InvalidInput']],
            '小文字のクラス名の new'       => [self::sample('throw new validationexception($v);'), ['new validationexception']],
            '先頭に \ のある use'           => [self::sample('throw new ValidationException($v);', "use \\Illuminate\\Validation\\ValidationException;\n"), ['new ValidationException']],
            '->safe(（中で validated() を呼ぶ）' => [self::sample('$ok = Validator::make($data, [])->safe()->only([\'a\']);'), ['->safe(']],
            '->validateWith('               => [self::sample('$this->validateWith($validator, $request);'), ['->validateWith(']],
            '::validateWithBag(（ファサード）' => [self::sample('Request::validateWithBag(\'import\', [\'a\' => \'required\']);'), ['::validateWithBag(']],
        ];
    }

    #[DataProvider('throwingCallSamples')]
    public function test_the_detector_finds_each_form_of_call_that_throws(string $source, array $forms): void
    {
        $this->assertSame($forms, array_column($this->analyze($source), 'form'));
    }

    /** @return array<string, array{0: string}> 拾ってはいけない見本 */
    public static function notThrowingSamples(): array
    {
        return [
            '名前の違うメソッド validateSales(' => [self::sample('$this->client->validateSales($parsed);')],
            '名前の違うメソッド validateRow('   => [self::sample('$errors = $this->validateRow($row);')],
            '変数 $validated'                   => [self::sample('$rows = $validated[\'rows\'];')],
            '文字列の中'                        => [self::sample('$s = \'$request->validate([])\';')],
            '// コメントの中'                   => [self::sample('// $request->validate([]);')],
            '/** */ の中'                       => [self::sample('/** $request->validate([]) */')],
            '呼び出しでないプロパティ'          => [self::sample('$rule = $request->validate;')],
            'Validator::make( だけ'             => [self::sample('$v = Validator::make($data, []);')],
            'use の無い new'                    => [self::sample('throw new ValidationException($v);', '')],
            'use の無い ::class'                => [self::sample('throw_if($x, ValidationException::class);', '')],
            'メソッドの宣言'                    => ["<?php\n\nnamespace App\\Http\\Controllers\\Admin;\n\nclass SampleImportController\n{\n    public function validate(\$request)\n    {\n    }\n}\n"],
        ];
    }

    #[DataProvider('notThrowingSamples')]
    public function test_the_detector_ignores_what_does_not_throw(string $source): void
    {
        $this->assertSame([], $this->analyze($source));
    }

    /** @return array<string, array{0: string}> 包んであるとみなす見本 */
    public static function wrappedSamples(): array
    {
        return [
            '今の書き方（catch の中の // コメント）' => [self::sample(<<<'PHP'
                try {
                    $request->validate(['a' => 'required']);
                } catch (ValidationException $e) {
                    // 取込の画面へ戻す
                    throw $e->redirectTo(route('admin.sample-import'));
                }
                PHP)],
            '完全な名前で書いた catch' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (\Illuminate\Validation\ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP, '')],
            'as の別名の catch' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (InvalidInput $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP, "use Illuminate\\Validation\\ValidationException as InvalidInput;\n")],
            'A | B の catch' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (CsvImportException | ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP, "use App\\Support\\CsvImportException;\nuse Illuminate\\Validation\\ValidationException;\n")],
            '内側の try が別の例外しか受けない入れ子' => [self::sample(<<<'PHP'
                try {
                    try {
                        $request->validate([]);
                    } catch (CsvImportException $e) {
                        return null;
                    }
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP, "use App\\Support\\CsvImportException;\nuse Illuminate\\Validation\\ValidationException;\n")],
            'use の無い Exception の catch が先（名前空間の中では別のクラス）' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (Exception $e) {
                    report($e);
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP)],
            'withMessages() に続く ->redirectTo(' => [self::sample(<<<'PHP'
                throw ValidationException::withMessages(['a' => 'x'])->redirectTo(route('admin.sample-import'));
                PHP)],
            '(new ValidationException()) に続く ->redirectTo(' => [self::sample(<<<'PHP'
                throw (new ValidationException($validator))->redirectTo(route('admin.sample-import'));
                PHP)],
            'catch の中の /** */ コメント' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    /** 取込の画面へ戻す */
                    throw $e->redirectTo('/sample');
                }
                PHP)],
            '小文字のクラス名と REDIRECTTO' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (\illuminate\validation\validationexception $e) {
                    throw $e->REDIRECTTO('/sample');
                }
                PHP, '')],
            'redirectTo() の後に続く呼び出し' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample')->errorBag('import');
                }
                PHP)],
            '文字列の中の {$…} と ${…} をはさむ' => [self::sample(<<<'PHP'
                try {
                    $label = "{$name} と ${name}";
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP)],
            'クロージャの use をはさむ（冒頭の use と取り違えない）' => [self::sample(<<<'PHP'
                try {
                    $rows = array_map(function ($row) use ($request) {
                        return $row;
                    }, []);
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP)],
            'use function・use const のあるファイル' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP, "use function sprintf;\nuse const PHP_EOL;\nuse Illuminate\\Validation\\ValidationException;\n")],
            '名前空間の一部を use した修飾名の catch' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (Validation\ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP, "use Illuminate\\Validation;\n")],
            'redirectTo の引数の中の ;（クロージャ）' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e->redirectTo(value(function () { return '/sample'; }));
                }
                PHP)],
            'B: 連鎖の途中の呼び出しをはさむ（->errorBag(…)->redirectTo(…)）' => [self::sample(<<<'PHP'
                throw ValidationException::withMessages(['a' => 'x'])->errorBag(strtolower('Import'))->redirectTo(route('admin.sample-import'));
                PHP)],
            'B: 引数の無い new ValidationException' => [self::sample(<<<'PHP'
                throw (new ValidationException)->redirectTo('/sample');
                PHP)],
        ];
    }

    #[DataProvider('wrappedSamples')]
    public function test_the_judge_accepts_the_wrapped_forms(string $source): void
    {
        $calls = $this->analyze($source);

        $this->assertCount(1, $calls, '見本の中の投げる呼び出しは 1 つのはず');
        $this->assertNull($calls[0]['reason'], "包んであるのに不合格になった: {$calls[0]['reason']}");
    }

    /** @return array<string, array{0: string, 1: string}> 包んでいないとみなす見本 => 理由 */
    public static function unwrappedSamples(): array
    {
        $bodyNotOneStatement = '（catch の本体が throw $e->redirectTo(…) の 1 文でない）';

        return [
            'try が無い' => [self::sample('$request->validate([]);'), '（try の外）'],
            '\Exception の catch が先' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (\Exception $e) {
                    return null;
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP), '（\Exception の catch が先に受け止める）'],
            '\Throwable の catch が先' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (\Throwable $e) {
                    return null;
                }
                PHP), '（\Throwable の catch が先に受け止める）'],
            'use Exception; のある Exception の catch が先' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (Exception $e) {
                    return null;
                }
                PHP, "use Exception;\nuse Illuminate\\Validation\\ValidationException;\n"), '（Exception の catch が先に受け止める）'],
            'A | \Exception の catch' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException | \Exception $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP), '（\Exception の catch が先に受け止める）'],
            '本体が throw $e;' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e;
                }
                PHP), $bodyNotOneStatement],
            '本体の前にもう 1 文' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    report($e);
                    throw $e->redirectTo('/sample');
                }
                PHP), $bodyNotOneStatement],
            '本体の後にもう 1 文' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                    report($e);
                }
                PHP), $bodyNotOneStatement],
            '条件しだいで戻り先なしに投げ直す' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    if ($request->has('debug')) {
                        throw $e;
                    }
                    throw $e->redirectTo('/sample');
                }
                PHP), $bodyNotOneStatement],
            'throw の変数が catch の変数と違う' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $other->redirectTo('/sample');
                }
                PHP), $bodyNotOneStatement],
            '?-> で投げ直す' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e?->redirectTo('/sample');
                }
                PHP), $bodyNotOneStatement],
            '本体が throw でなく return' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    return $e->redirectTo('/sample');
                }
                PHP), $bodyNotOneStatement],
            '本体が redirectTo でない別のメソッドで投げ直す' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e->errorBag('import');
                }
                PHP), $bodyNotOneStatement],
            '本体の redirectTo が呼び出しでない' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e->redirectTo;
                }
                PHP), $bodyNotOneStatement],
            '内側の try の catch で決める（外側の catch は見ない）' => [self::sample(<<<'PHP'
                try {
                    try {
                        $request->validate([]);
                    } catch (ValidationException $e) {
                        throw $e;
                    }
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP), $bodyNotOneStatement],
            '変数の無い catch' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException) {
                    throw new \RuntimeException('x');
                }
                PHP), '（catch に変数が無く、投げ直せない）'],
            '呼び出しが catch の中' => [self::sample(<<<'PHP'
                try {
                    $this->load();
                } catch (ValidationException $e) {
                    $request->validate([]);
                }
                PHP), '（try の外）'],
            '呼び出しが finally の中' => [self::sample(<<<'PHP'
                try {
                    $this->load();
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                } finally {
                    $request->validate([]);
                }
                PHP), '（try の外）'],
            'use の無い ValidationException の catch' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (ValidationException $e) {
                    throw $e->redirectTo('/sample');
                }
                PHP, ''), '（use の無い ValidationException を受けている）'],
            'redirectTo の無い withMessages()（次の文の ->redirectTo( は続いたことにしない）' => [self::sample(<<<'PHP'
                throw ValidationException::withMessages(['a' => 'x']);
                $next->redirectTo('/sample');
                PHP), '（try の外）'],
            '別の例外しか受けない catch' => [self::sample(<<<'PHP'
                try {
                    $request->validate([]);
                } catch (CsvImportException $e) {
                    return null;
                }
                PHP, "use App\\Support\\CsvImportException;\n"), '（ValidationException を受け止める catch が無い）'],
            '引数の中の ->redirectTo( は続いたことにしない' => [self::sample(<<<'PHP'
                throw ValidationException::withMessages(['a' => $x->redirectTo('/sample')]);
                PHP), '（try の外）'],
            'B: throw の無い withMessages()->redirectTo()' => [self::sample(<<<'PHP'
                $e = ValidationException::withMessages(['a' => 'x'])->redirectTo('/sample');
                PHP), '（try の外）'],
            'B: match の腕の throw（, の後ろの ->redirectTo( は続いたことにしない）' => [self::sample(<<<'PHP'
                $v = match (true) { $bad => throw ValidationException::withMessages(['a' => 'x']), default => $other->redirectTo('/sample'), };
                PHP), '（try の外）'],
            'B: ?? の後ろの throw（隣の引数の ->redirectTo( は続いたことにしない）' => [self::sample(<<<'PHP'
                foo($x ?? throw ValidationException::withMessages(['a' => 'x']), $y->redirectTo('/sample'));
                PHP), '（try の外）'],
            'B: throw を括った括弧の外の ->redirectTo(' => [self::sample(<<<'PHP'
                $x = (throw ValidationException::withMessages(['a' => 'x']))->redirectTo('/sample');
                PHP), '（try の外）'],
            'B: ?->redirectTo(' => [self::sample(<<<'PHP'
                throw ValidationException::withMessages(['a' => 'x'])?->redirectTo('/sample');
                PHP), '（try の外）'],
        ];
    }

    /** @return array<string, array{0: string, 1: list<array{0: string, 1: ?string}>}> 投げる呼び出しが 2 つある見本 => [形, 理由（包んであれば null）] */
    public static function nestedCallSamples(): array
    {
        return [
            'B: withMessages() の引数の中の入力チェックは包まれない' => [self::sample(<<<'PHP'
                throw ValidationException::withMessages($request->validate(['a' => 'required']))->redirectTo(route('admin.sample-import'));
                PHP), [['::withMessages(', null], ['->validate(', '（try の外）']]],
            'B: new ValidationException() の引数の中の入力チェックは包まれない' => [self::sample(<<<'PHP'
                throw (new ValidationException(Validator::make($data, [])->validate()))->redirectTo('/sample');
                PHP), [['new ValidationException', null], ['->validate(', '（try の外）']]],
        ];
    }

    /** B は例外を作る呼び出し（`::withMessages(`・`new ValidationException`）だけを見る。その引数の中の入力チェックは別に判定する */
    #[DataProvider('nestedCallSamples')]
    public function test_the_judge_judges_each_call_on_its_own(string $source, array $expected): void
    {
        $this->assertSame($expected, array_map(fn (array $call) => [$call['form'], $call['reason']], $this->analyze($source)));
    }

    #[DataProvider('unwrappedSamples')]
    public function test_the_judge_rejects_the_unwrapped_forms(string $source, string $reason): void
    {
        $calls = $this->analyze($source);

        $this->assertCount(1, $calls, '見本の中の投げる呼び出しは 1 つのはず');
        $this->assertSame($reason, $calls[0]['reason']);
    }

    /** @return array<string, array{0: string, 1: string}> 解析できない見本 => 理由の一部 */
    public static function unparseableSamples(): array
    {
        return [
            'まとめた use（{ }）' => [self::sample('$request->validate([]);', "use Illuminate\\Validation\\{ValidationException, Validator};\n"), 'まとめた use'],
            'まとめた use（,）'   => [self::sample('$request->validate([]);', "use Illuminate\\Validation\\ValidationException, App\\Support\\CsvImportException;\n"), 'まとめた use'],
            'namespace が 2 つ'   => ["<?php\n\nnamespace A;\n\nclass X {}\n\nnamespace B;\n\nclass Y {}\n", 'namespace が 2 つ以上'],
            'namespace X { … }'   => ["<?php\n\nnamespace A {\n    class X {}\n}\n", 'namespace X { … } の形'],
            '閉じの足りない波括弧' => ["<?php\n\nnamespace A;\n\nclass X\n{\n    public function f()\n    {\n}\n", '波括弧の対応が取れない'],
            '閉じの多い波括弧'     => ["<?php\n\nnamespace A;\n\nclass X\n{\n}\n}\n", '波括弧の対応が取れない'],
            '冒頭のクロージャの use' => ["<?php\n\nnamespace A;\n\n\$f = function () use (\$x) {};\n", '読めない use'],
        ];
    }

    #[DataProvider('unparseableSamples')]
    public function test_unparseable_sources_are_rejected_instead_of_guessed(string $source, string $message): void
    {
        try {
            $this->analyze($source);
        } catch (\UnexpectedValueException $e) {
            $this->assertStringContainsString($message, $e->getMessage());

            return;
        }

        $this->fail("解析できないはずの書き方を読んでしまった（{$message}）");
    }

    public function test_comments_are_skipped_and_the_real_call_is_reported_on_its_line(): void
    {
        $source = "<?php\n\nnamespace App\\Http\\Controllers\\Admin;\n\nclass SampleImportController\n{\n"
            . "    public function execute(\$request)\n    {\n"
            . "        // \$request->validate([]);\n"
            . "        /** \$request->validate([]) */\n"
            . "        \$request->validate([]);\n"
            . "    }\n}\n";

        $this->assertSame([['line' => 11, 'form' => '->validate(', 'reason' => '（try の外）']], $this->analyze($source));
    }

    public function test_the_form_request_detector_sees_every_shape_of_type(): void
    {
        $sample = new \ReflectionClass(new class
        {
            public function plain(EmailVerificationRequest $request): void {}

            public function nullable(?EmailVerificationRequest $request): void {}

            public function union(EmailVerificationRequest|Request $request): void {}

            public function notAFormRequest(Request $request, int $id): void {}

            private function hidden(EmailVerificationRequest $request): void {}
        });

        $this->assertSame([
            'plain($request: ' . EmailVerificationRequest::class . ')',
            'nullable($request: ' . EmailVerificationRequest::class . ')',
            'union($request: ' . EmailVerificationRequest::class . ')',
        ], $this->formRequestParameters($sample));
    }

    // ================================================================
    // 走査
    // ================================================================

    /** 見本のメソッド本体を、名前空間・use・クラスで包んだソースにする */
    private static function sample(string $body, string $uses = "use Illuminate\\Validation\\ValidationException;\n"): string
    {
        return "<?php\n\nnamespace App\\Http\\Controllers\\Admin;\n\n{$uses}\nclass SampleImportController\n{\n"
            . "    public function execute(\$request)\n    {\n{$body}\n    }\n}\n";
    }

    /**
     * @return array<string, list<array{line: int, form: string, reason: ?string}>|string>
     *   相対パス => 投げる呼び出し（解析できなければその理由の文字列）
     */
    private function scan(): array
    {
        $results = [];

        foreach ($this->importControllerFiles() as $path => $absolute) {
            try {
                $results[$path] = $this->analyze(File::get($absolute));
            } catch (\UnexpectedValueException $e) {
                $results[$path] = $e->getMessage();
            }
        }

        return $results;
    }

    /**
     * ソースを読み、入力チェックの例外を投げる呼び出しごとに、包んでいない理由（包んであれば null）を返す。
     *
     * @return list<array{line: int, form: string, reason: ?string}>
     *
     * @throws \UnexpectedValueException 解析できない書き方（推測しない）
     */
    private function analyze(string $source): array
    {
        $tokens = $this->tokens($source);
        $braces = $this->braceMap($tokens);
        [$namespace, $imports] = $this->nameContext($tokens);
        $tries = $this->tryStatements($tokens, $braces);

        return array_map(fn (array $call) => [
            'line'   => $call['line'],
            'form'   => $call['form'],
            'reason' => $this->unwrappedReason($tokens, $call, $tries, $namespace, $imports),
        ], $this->throwingCalls($tokens, $namespace, $imports));
    }

    /**
     * コメントと空白を除いたトークン。1 つは [id（1 文字の記号は null）, 字面, 行]。
     *
     * @return list<array{0: ?int, 1: string, 2: int}>
     */
    private function tokens(string $source): array
    {
        $tokens = [];
        $line = 1;

        foreach (token_get_all($source) as $token) {
            [$id, $text] = is_array($token) ? [$token[0], $token[1]] : [null, $token];
            $line = is_array($token) ? $token[2] : $line;

            if (! in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                $tokens[] = [$id, $text, $line];
            }

            $line += substr_count($text, "\n");
        }

        return $tokens;
    }

    /** 1 文字の記号か */
    private function is(array $token, string $char): bool
    {
        return $token[0] === null && $token[1] === $char;
    }

    /** クラス名のトークンか（`A`・`A\B`・`\A\B`） */
    private function isName(array $token): bool
    {
        return in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);
    }

    /** 波括弧を開くか（文字列の中の `{$`・`${` も `}` で閉じるので数える） */
    private function opensBrace(array $token): bool
    {
        return $this->is($token, '{') || in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
    }

    /** 括弧を開くか（`(`・`[`・波括弧） */
    private function opens(array $token): bool
    {
        return $this->is($token, '(') || $this->is($token, '[') || $this->opensBrace($token);
    }

    /** 括弧を閉じるか */
    private function closes(array $token): bool
    {
        return $this->is($token, ')') || $this->is($token, ']') || $this->is($token, '}');
    }

    /**
     * 波括弧の対応（開きの位置 => 閉じの位置）。対応が取れなければ解析できない。
     *
     * @return array<int, int>
     */
    private function braceMap(array $tokens): array
    {
        $map = [];
        $open = [];

        foreach ($tokens as $i => $token) {
            if ($this->opensBrace($token)) {
                $open[] = $i;
            } elseif ($this->is($token, '}')) {
                if ($open === []) {
                    throw new \UnexpectedValueException("{$token[2]} 行目: 波括弧の対応が取れない（閉じが多い）");
                }

                $map[array_pop($open)] = $i;
            }
        }

        if ($open !== []) {
            throw new \UnexpectedValueException($tokens[end($open)][2] . ' 行目: 波括弧の対応が取れない（閉じが足りない）');
        }

        return $map;
    }

    /**
     * ファイルの名前空間と、冒頭（波括弧の外）の `use`（別名 => 完全な名前。どちらも小文字）。
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function nameContext(array $tokens): array
    {
        $namespace = null;
        $imports = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($this->opensBrace($token)) {
                $depth++;
            } elseif ($this->is($token, '}')) {
                $depth--;
            } elseif ($depth === 0 && $token[0] === T_NAMESPACE) {
                if ($namespace !== null) {
                    throw new \UnexpectedValueException("{$token[2]} 行目: namespace が 2 つ以上ある");
                }

                $name = $tokens[$i + 1] ?? [null, '', 0];
                if (! in_array($name[0], [T_STRING, T_NAME_QUALIFIED], true) || ! $this->is($tokens[$i + 2] ?? [null, '', 0], ';')) {
                    throw new \UnexpectedValueException("{$token[2]} 行目: namespace X { … } の形（または名前の無い namespace）");
                }

                $namespace = strtolower($name[1]);
                $i += 2;
            } elseif ($depth === 0 && $token[0] === T_USE) {
                $i = $this->readUse($tokens, $i, $imports);
            }
        }

        return [$namespace ?? '', $imports];
    }

    /**
     * 冒頭の `use` 文を 1 つ読んで $imports に足し、その `;` の位置を返す。
     *
     * @param  array<string, string>  $imports
     */
    private function readUse(array $tokens, int $i, array &$imports): int
    {
        $line = $tokens[$i][2];
        $j = $i + 1;

        if (in_array($tokens[$j][0] ?? null, [T_FUNCTION, T_CONST], true)) {
            // use function・use const はクラス名ではない
            while (isset($tokens[$j]) && ! $this->is($tokens[$j], ';')) {
                $j++;
            }

            return $j;
        }

        $name = $tokens[$j] ?? [null, '', 0];
        if (! $this->isName($name)) {
            throw new \UnexpectedValueException("{$line} 行目: 読めない use");
        }

        $full = strtolower(ltrim($name[1], '\\'));
        $alias = substr((string) strrchr('\\' . $full, '\\'), 1);
        $j++;

        if (($tokens[$j][0] ?? null) === T_AS) {
            $alias = strtolower($tokens[$j + 1][1]);
            $j += 2;
        }

        if (! $this->is($tokens[$j] ?? [null, '', 0], ';')) {
            throw new \UnexpectedValueException("{$line} 行目: まとめた use（1 文で 2 つ以上を読み込んでいる）");
        }

        $imports[$alias] = $full;

        return $j;
    }

    /**
     * 書かれたクラス名を、PHP と同じ規則で完全な名前（小文字・先頭の \ なし）にする。
     *
     * @param  array<string, string>  $imports
     */
    private function resolve(string $written, string $namespace, array $imports): string
    {
        if (str_starts_with($written, '\\')) {
            return strtolower(substr($written, 1));
        }

        $lower = strtolower($written);
        $first = explode('\\', $lower)[0];

        if (isset($imports[$first])) {
            return $imports[$first] . substr($lower, strlen($first));
        }

        return ltrim($namespace . '\\' . $lower, '\\');
    }

    /**
     * try 文ごとに、本体の範囲（try の直後の `{` と対応する `}`）と catch の並び（型・変数・本体の範囲）。
     *
     * @param  array<int, int>  $braces
     * @return list<array{open: int, close: int, catches: list<array{types: list<string>, var: ?string, open: int, close: int}>}>
     */
    private function tryStatements(array $tokens, array $braces): array
    {
        $tries = [];

        foreach ($tokens as $i => $token) {
            if ($token[0] !== T_TRY) {
                continue;
            }

            $catches = [];
            $j = $braces[$i + 1] + 1;

            while (($tokens[$j][0] ?? null) === T_CATCH) {
                $types = [];
                $var = null;

                for ($k = $j + 2; isset($tokens[$k]) && ! $this->is($tokens[$k], ')'); $k++) {
                    if ($this->isName($tokens[$k])) {
                        $types[] = $tokens[$k][1];
                    } elseif ($tokens[$k][0] === T_VARIABLE) {
                        $var = $tokens[$k][1];
                    }
                }

                $catches[] =['types' => $types, 'var' => $var, 'open' => $k + 1, 'close' => $braces[$k + 1]];
                $j = $braces[$k + 1] + 1;
            }

            $tries[] = ['open' => $i + 1, 'close' => $braces[$i + 1], 'catches' => $catches];
        }

        return $tries;
    }

    /**
     * 入力チェックの例外を投げる呼び出し（設計書 §4.3 の形）。index は判定に使うトークンの位置。
     * 例外を作る呼び出し（`::withMessages(`・`new ValidationException`）だけは、B の判定に使う
     * 式の頭（head）と呼び出しの後ろの位置（after）も返す（ほかは null）。
     *
     * @param  array<string, string>  $imports
     * @return list<array{index: int, line: int, form: string, head: ?int, after: ?int}>
     */
    private function throwingCalls(array $tokens, string $namespace, array $imports): array
    {
        $calls = [];
        $none = [null, '', 0];

        foreach ($tokens as $i => $token) {
            $next = $tokens[$i + 1] ?? $none;
            $after = $tokens[$i + 2] ?? $none;
            $calledName = $next[0] === T_STRING && $this->is($after, '(') ? strtolower($next[1]) : null;

            if (in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && in_array($calledName, ['validate', 'validatewithbag', 'validated', 'safe', 'validatewith'], true)) {
                // ->validate(・?->validate(・->validateWithBag(・->validated(・->safe(（中で validated() を呼ぶ）・->validateWith(
                $calls[] = ['index' => $i + 1, 'line' => $next[2], 'form' => $token[1] . $next[1] . '(', 'head' => null, 'after' => null];
            } elseif ($token[0] === T_DOUBLE_COLON && in_array($calledName, ['validate', 'withmessages', 'validatewithbag'], true)) {
                // ::validate(・::withMessages(・::validateWithBag(（withMessages() は例外を作る。頭は :: の前のクラス名）
                $constructs = $calledName === 'withmessages';
                $calls[] = [
                    'index' => $i + 1, 'line' => $next[2], 'form' => '::' . $next[1] . '(',
                    'head'  => $constructs ? $i - 1 : null,
                    'after' => $constructs ? $this->afterParens($tokens, $i + 2) : null,
                ];
            } elseif ($token[0] === T_NEW && $this->isName($next)
                && $this->resolve($next[1], $namespace, $imports) === self::VALIDATION_EXCEPTION) {
                // new ValidationException（引数の括弧は省けるので、無ければクラス名の次から）
                $calls[] = [
                    'index' => $i, 'line' => $token[2], 'form' => 'new ' . $next[1],
                    'head'  => $i,
                    'after' => $this->is($after, '(') ? $this->afterParens($tokens, $i + 2) : $i + 2,
                ];
            } elseif ($this->isName($token) && $next[0] === T_DOUBLE_COLON && $after[0] === T_CLASS
                && $this->resolve($token[1], $namespace, $imports) === self::VALIDATION_EXCEPTION) {
                // ValidationException::class（throw_if() などに渡す）
                $calls[] = ['index' => $i, 'line' => $token[2], 'form' => $token[1] . '::class', 'head' => null, 'after' => null];
            }
        }

        return $calls;
    }

    /**
     * 呼び出しが包んであれば null、包んでいなければ理由（設計書 §4.4 の A・B）。
     *
     * @param  array{index: int, line: int, form: string, head: ?int, after: ?int}  $call
     * @param  list<array{open: int, close: int, catches: list<array{types: list<string>, var: ?string, open: int, close: int}>}>  $tries
     * @param  array<string, string>  $imports
     */
    private function unwrappedReason(array $tokens, array $call, array $tries, string $namespace, array $imports): ?string
    {
        // B. 例外を作る呼び出しが throw の直後にあり、その後ろのメソッドの連鎖に ->redirectTo( がある
        if ($call['head'] !== null && $this->thrownWithRedirectTo($tokens, $call['head'], $call['after'])) {
            return null;
        }

        // A. 囲む try を内側から外へたどり、ValidationException を受け止める最初の catch で決める
        $index = $call['index'];
        $enclosing = array_values(array_filter($tries, fn (array $try) => $try['open'] < $index && $index < $try['close']));
        usort($enclosing, fn (array $a, array $b) => $b['open'] <=> $a['open']);

        if ($enclosing === []) {
            return '（try の外）';
        }

        $hint = null;

        foreach ($enclosing as $try) {
            foreach ($try['catches'] as $catch) {
                $catchesIt = false;

                foreach ($catch['types'] as $type) {
                    $resolved = $this->resolve($type, $namespace, $imports);

                    if (in_array($resolved, self::GENERIC_CATCHES, true)) {
                        return "（{$type} の catch が先に受け止める）";
                    }

                    if ($resolved === self::VALIDATION_EXCEPTION) {
                        $catchesIt = true;
                    } elseif (strcasecmp(substr((string) strrchr('\\' . $type, '\\'), 1), 'ValidationException') === 0) {
                        $hint = '（use の無い ValidationException を受けている）';
                    }
                }

                if (! $catchesIt) {
                    continue;
                }

                if ($catch['var'] === null) {
                    return '（catch に変数が無く、投げ直せない）';
                }

                $body = array_slice($tokens, $catch['open'] + 1, $catch['close'] - $catch['open'] - 1);

                return $this->rethrowsWithRedirectTo($body, $catch['var'])
                    ? null
                    : "（catch の本体が throw {$catch['var']}->redirectTo(…) の 1 文でない）";
            }
        }

        return $hint ?? '（ValidationException を受け止める catch が無い）';
    }

    /** catch の本体（コメントを除く）が `throw $<変数>->redirectTo(…)…;` の 1 文だけか */
    private function rethrowsWithRedirectTo(array $body, string $var): bool
    {
        $none = [null, '', 0];
        [$throw, $variable, $arrow, $method, $paren] = array_pad(array_slice($body, 0, 5), 5, $none);

        if ($throw[0] !== T_THROW
            || $variable[0] !== T_VARIABLE || $variable[1] !== $var
            || $arrow[0] !== T_OBJECT_OPERATOR
            || $method[0] !== T_STRING || strcasecmp($method[1], 'redirectTo') !== 0
            || ! $this->is($paren, '(')) {
            return false;
        }

        $depth = 0;

        foreach ($body as $k => $token) {
            if ($this->opens($token)) {
                $depth++;
            } elseif ($this->closes($token)) {
                $depth--;
            } elseif ($depth === 0 && $this->is($token, ';')) {
                return $k === count($body) - 1;   // 括弧の外の最初の ; で終わり、その後に何も無い
            }
        }

        return false;
    }

    /**
     * 例外を作る式（頭が $head）が throw の直後（括弧で括ってもよい）にあり、その後ろ（$after から）が
     * 括りの閉じとメソッドの連鎖だけで、連鎖の中に `->redirectTo(` があるか（設計書 §4.4 の B）。
     * 連鎖の外（`,`・`;`・`??`・`:` など）に出たら、そこから後ろの `->redirectTo(` は投げる式のものではない。
     */
    private function thrownWithRedirectTo(array $tokens, int $head, int $after): bool
    {
        $none = [null, '', 0];
        $groups = 0;
        $j = $head - 1;

        while ($this->is($tokens[$j] ?? $none, '(')) {   // throw (new ValidationException(…)) の括り
            $groups++;
            $j--;
        }

        if (($tokens[$j][0] ?? null) !== T_THROW) {
            return false;
        }

        for ($k = $after; isset($tokens[$k]);) {
            $token = $tokens[$k];

            if ($this->is($token, ')') && $groups > 0) {   // 括りを閉じる
                $groups--;
                $k++;

                continue;
            }

            $method = $tokens[$k + 1] ?? $none;

            if (! in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                || $method[0] !== T_STRING || ! $this->is($tokens[$k + 2] ?? $none, '(')) {
                return false;   // 連鎖の外に出た
            }

            if ($token[0] === T_OBJECT_OPERATOR && strcasecmp($method[1], 'redirectTo') === 0) {
                return true;
            }

            $k = $this->afterParens($tokens, $k + 2);   // 連鎖の途中の呼び出し（->errorBag(…) など）を飛ばす
        }

        return false;
    }

    /** $open の `(` と対応する `)` の次の位置（対応が無ければ末尾） */
    private function afterParens(array $tokens, int $open): int
    {
        $depth = 0;

        for ($k = $open; isset($tokens[$k]); $k++) {
            if ($this->is($tokens[$k], '(')) {
                $depth++;
            } elseif ($this->is($tokens[$k], ')') && --$depth === 0) {
                return $k + 1;
            }
        }

        return $k;
    }

    /**
     * public メソッドの引数に FormRequest（の子クラス）を受けるものがあれば「メソッド名($引数: 型)」を返す。
     *
     * @return list<string>
     */
    private function formRequestParameters(\ReflectionClass $class): array
    {
        $found = [];

        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                foreach ($this->classNamesIn($parameter->getType()) as $name) {
                    if (is_a($name, FormRequest::class, true)) {
                        $found[] = "{$method->getName()}(\${$parameter->getName()}: {$name})";
                    }
                }
            }
        }

        return $found;
    }

    /**
     * 型の中のクラス名（`A`・`?A`・`A|B`・`A&B`・`(A&B)|C`。組み込みの型は除く）。
     *
     * @return list<string>
     */
    private function classNamesIn(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }

        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            return array_merge(...array_map(fn (\ReflectionType $inner) => $this->classNamesIn($inner), $type->getTypes()));
        }

        return [];
    }
}
