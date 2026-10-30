<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Tests\Concerns\BindsOldInputToPages;
use Tests\Concerns\CollectsValidationKeys;
use Tests\Concerns\ScansBladeJsEchoes;
use Tests\TestCase;

/**
 * 画面が `old('x')` で描く欄は、**その画面のフォームの送り先の**入力チェックに規則がある（Bug #121）。
 *
 * 配列の形は ShapeCheckingValidator が規則で見て、断ったときに形の合わない欄を控えから外す（bootstrap/app.php の render）。
 * 外すのは断った入力チェックの規則の欄だけなので、規則の無い欄は手で組んだ配列のまま控えに残り、戻った画面が `old()` を
 * 文字のつもりで描いて 500 になる。控えは画面全体で共有されるので、同じ画面の**別のフォーム**の送り先から見ても規則が要る
 * （2026-10-30 に問合せの詳細で実測: 状態の変更 updateStatus に対応履歴の content を配列で足すと、戻った画面が 500）。
 *
 * 以前は「どこかの入力チェックに同じ名前の規則がある」で見ていたので、別の画面の同じ名前の規則に当たると素通りした
 * （2026-10-30 のカナリア: CustomerController の memo の規則を消しても緑。memo の規則がほかに 7 本のコントローラにあった）。
 *
 * 結び付け方（BindsOldInputToPages）: 画面（`@include`・`<x-…>` でたどった木のいちばん上の親）の中のすべての old() が、
 * 木の中のフォームのすべての送り先（ルートの URL と送る方式で引いた Class@method と、そこから `$this->` で呼ぶメソッドの
 * 入力チェック）に規則を持つこと。控えを作らない送り先（入力チェックも withInput( も ValidationException も届かない。削除など）は外す。
 * 規則を持たない欄は分類表に理由つきで載せる（全件分類。Top trap #13）。
 *
 * ⚠ 決裁の画面（approvals/）は「社内決裁申請」の会話の担当なので見ない（決裁のビューの old() も、決裁のビューを親とする木も）
 * ⚠ 死角（黙って見落とす）:
 *   - `@if` で描く／描かないが変わる欄は読まない（描かない画面は NOT_RENDERED_ON に載せる）。逆に、条件つきで足す規則
 *     （登録のときだけ足す規則など）も、どの送り先にもあるとみなす
 *   - `exclude` だけの規則の欄で、入力チェックが通ったあとに ValidationException::withMessages() を投げる経路（規則を持たない
 *     入力チェックなので控えから外れない。今は同時の送信の競合のときだけ）
 *   - サービスなど app/Http の外が投げる ValidationException（その送り先は「控えを作らない」として外れる）
 *   - コントローラの中の old()（2026-10-30 に 9 か所。どれも自分の送り先に規則がある）
 *   - `@includeWhen` などの読み込み・`@include($変数)`（今 0 件。使うと欄の送り先が無くなり、ここで落ちる）
 */
class OldInputRuleCoverageTest extends TestCase
{
    use BindsOldInputToPages;
    use CollectsValidationKeys;
    use ScansBladeJsEchoes;

    /**
     * 規則を持たない欄（書いてあるビュー => キー => [そのビューの old() の件数, 理由]）。そのビューを含むすべての画面・送り先で規則が無くてよい。
     * 件数は「同じビューに同じ欄の危ない描き方を後から足した」を止める（件数が変われば分類を見直す）
     */
    private const WITHOUT_RULE = [
        'admin/master/zeal-simulation-categories/_form.blade.php' => [
            'is_active' => [1, '$valBool は GET の画面で old() を読まない（真偽にしか使わない。保存は $request->boolean()）'],
        ],
        'auth/login.blade.php' => [
            'remember' => [1, 'old() を checked の真偽にしか使わない（配列でも 500 にならない）'],
        ],
        'buyers/_survey_form.blade.php' => [
            'survey.{$qId}' => [1, 'アンケートの回答の 1 問。規則を持たず、after のフックで SurveyAnswers が形を見る（Bug #120）'],
        ],
        'tenant/area-buildings/tenants/create.blade.php' => [
            'keep_adding' => [1, 'old() を checked の真偽にしか使わない（配列でも 500 にならない）'],
        ],
    ];

    /** その画面では描かない欄（静的には読めない `@if` の中。画面 => キー => 理由） */
    private const NOT_RENDERED_ON = [
        'tenant/area-buildings/edit.blade.php' => [
            'surveyed_month'  => '初回調査の欄は登録画面にだけ描く（_form の @isset($withInitialSurvey)）',
            'operating_count' => '同上',
            'vacant_count'    => '同上',
            'unknown_count'   => '同上',
            'survey_notes'    => '同上',
        ],
    ];

    /** 拾えた数の下限（走査が空振りして緑になるのを止める。数は画面ごとに数える。注記は 2026-10-30 の実測） */
    private const MIN = [
        'uses'      => 800,  // 画面の木の中の old()（部品は読み込む画面ごとに数える。実測 909）
        'keys'      => 215,  // 欄の名前（実測 242）
        'pages'     => 72,   // old() を持つ画面（実測 80）
        'targets'   => 72,   // 控えを作る送り先（実測 81）
        'skipped'   => 11,   // 控えを作らないので外した送り先（削除など。実測 13）
        'dynamic'   => 4,    // 名前を変数で組む送り先のフォーム（買主・アンケートの登録と編集。実測 5）
        'component' => 1,    // 部品の呼び出し側の属性から読んだ送り先（問合せの詳細の削除の確認。実測 1）
        'wrapped'   => 9,    // old() を包む関数で読んだ欄（ZEAL 試算表の項目の登録と編集の 5 欄ずつ。実測 10）
    ];

    public function test_every_old_input_key_has_a_rule_on_each_target_of_its_page(): void
    {
        $sources = $this->appHttpSources();
        $collected = $this->collectValidationKeys($sources);
        $this->assertSame([], $collected['unreadable'], '入力チェックを読めない書き方がある');
        $siteIndex = self::siteIndex($collected['sites']);
        $routes = self::routeTable();
        $renderers = self::renderersOf($sources);
        $files = array_fill_keys(array_keys($sources), true);

        $views = array_map(self::blankBladeComments(...), $this->bladeViews());
        $parsed = [];
        $unreadable = [];
        foreach ($views as $view => $source) {
            $uses = self::oldInputUses($source);
            $forms = self::bladeForms($source);
            $parsed[$view] = ['uses' => $uses['uses'], 'forms' => $forms['forms'], 'includes' => array_values(array_filter(
                self::bladeIncludes($source), fn (array $include) => isset($views[$include['view']]),
            ))];
            foreach (array_merge($uses['unreadable'], $forms['unreadable']) as $problem) {
                if (! str_starts_with($view, 'approvals/')) {
                    $unreadable[] = "{$view}: {$problem}";
                }
            }
        }

        $includers = [];
        foreach ($parsed as $view => $p) {
            foreach ($p['includes'] as $include) {
                $includers[$include['view']][$view] = true;
            }
        }
        $pages = [];
        foreach ($parsed as $view => $p) {
            if ($p['uses'] !== [] && ! str_starts_with($view, 'approvals/')) {
                foreach (self::rootsOf($view, $includers) as $root) {
                    if (! str_starts_with($root, 'approvals/')) {
                        $pages[$root] = true;
                    }
                }
            }
        }
        ksort($pages);

        $count = array_fill_keys(array_keys(self::MIN), 0);
        $keys = [];
        $targets = [];
        $skipped = [];
        $rules = [];
        $missing = [];
        $usedWithoutRule = [];
        $usedNotRendered = [];
        foreach (array_keys($pages) as $page) {
            $tree = self::treeOf($page, $parsed);
            $pageTargets = [];
            foreach ($tree as [$view, $attributes]) {
                // GET のフォームも見る（GET の入力チェックで断られても、送った値は控えに入る）
                foreach ($parsed[$view]['forms'] as $form) {
                    $names = self::actionRouteNames($form['action'], $attributes);
                    $actions = $names === null ? null : self::routeActions($names, $form['methods'], $routes, $renderers[$page] ?? []);
                    if ($actions === null) {
                        $unreadable[] = "{$page}: {$view}:{$form['line']} のフォームの送り先を読めない（" . ($form['action'] ?? 'Alpine の :action か action が無い') . '）';

                        continue;
                    }
                    $count['dynamic'] += (int) in_array(true, array_column($names, 'dynamic'), true);
                    $count['component'] += (int) (bool) preg_match('/^\s*\{\{\s*\$\w+\s*\}\}\s*$/', (string) $form['action']);
                    foreach ($actions as $action) {
                        $rules[$action] ??= self::ruleKeysOfAction($action, $siteIndex, $files);
                        if ($rules[$action]['flashes']) {
                            $pageTargets[$action] = true;
                        } else {
                            $skipped[$action] = true;
                        }
                    }
                }
            }
            if ($pageTargets === []) {
                $unreadable[] = "{$page}: old() を描くのに、控えを作る送り先が 1 つも無い";
            }
            $targets += $pageTargets;

            $uses = [];
            foreach ($tree as [$view]) {
                foreach ($parsed[$view]['uses'] as $use) {
                    $uses[] = $use + ['view' => $view];
                    $count['uses']++;
                    $count['wrapped'] += (int) ($use['via'] !== null);
                    $keys[$use['key']] = true;
                }
            }
            $targetKeys = array_map(fn (string $action) => $rules[$action]['keys'], array_combine(array_keys($pageTargets), array_keys($pageTargets)));
            foreach (self::pairsWithoutRule($uses, $targetKeys) as [$use, $action]) {
                if (isset(self::WITHOUT_RULE[$use['view']][$use['key']])) {
                    $usedWithoutRule["{$use['view']} {$use['key']}"] = true;
                } elseif (isset(self::NOT_RENDERED_ON[$page][$use['key']])) {
                    $usedNotRendered["{$page} {$use['key']}"] = true;
                } else {
                    $missing[] = "{$page}: {$use['key']}（{$use['view']}:{$use['line']}）の規則が {$action} に無い";
                }
            }
        }
        foreach ($rules as $info) {
            array_push($unreadable, ...$info['unreadable']);
        }
        $count['keys'] = count($keys);
        $count['pages'] = count($pages);
        $count['targets'] = count($targets);
        $count['skipped'] = count($skipped);

        foreach (self::MIN as $what => $min) {
            $this->assertGreaterThanOrEqual($min, $count[$what], "拾えた数（{$what}）が下限を割った（走査が空振りしている）");
        }
        $this->assertSame([], array_values(array_unique($unreadable)), '画面の欄・フォーム・送り先を読めない書き方がある');
        $this->assertSame([], array_values(array_unique($missing)), '画面が old() で描くのに、その画面のフォームの送り先に規則が無い欄がある（規則を足すか、分類表に理由つきで載せる）');

        foreach (self::WITHOUT_RULE as $view => $entries) {
            foreach ($entries as $key => [$expected]) {
                $this->assertArrayHasKey($view, $parsed, "WITHOUT_RULE の「{$view}」が無い（古い分類）");
                $actual = count(array_filter($parsed[$view]['uses'], fn (array $use) => $use['key'] === $key));
                $this->assertSame($expected, $actual, "WITHOUT_RULE の「{$view} {$key}」の old() の件数が違う（描き方を見直して件数を直す）");
                $this->assertArrayHasKey("{$view} {$key}", $usedWithoutRule, "WITHOUT_RULE の「{$view} {$key}」はどの送り先にも規則がある（分類から外す）");
            }
        }
        foreach (self::NOT_RENDERED_ON as $page => $entries) {
            foreach (array_keys($entries) as $key) {
                $this->assertArrayHasKey("{$page} {$key}", $usedNotRendered, "NOT_RENDERED_ON の「{$page} {$key}」は使われていない（画面か欄が無いか、規則がある。分類から外す）");
            }
        }
    }

    // ---------- 見本（実データが通らない・数で守れない分かれ目） ----------

    public function test_samples_read_old_input(): void
    {
        $source = self::blankBladeComments(implode("\n", [
            "{{-- old('in_comment') --}}",
            "{{ old('plain') }} {{ old(\"survey.{\$qId}\") }}",
            "@php \$val = function (\$key, \$default = '') use (\$c) { return old(\$key, \$default); }; @endphp",
            "{{ \$val('wrapped') }} {{ old(\$other) }}",
            "@php \$notWrapper = function (\$key) { return \$key; }; @endphp {{ \$notWrapper('not_old') }}",
            '// 初期値は old() から決める',
        ]));

        $read = self::oldInputUses($source);

        $this->assertSame(['plain', 'survey.{$qId}', 'wrapped'], array_column($read['uses'], 'key'));
        $this->assertSame([null, null, '$val'], array_column($read['uses'], 'via'));
        $this->assertSame(['old() の引数が文字でない（4 行）'], $read['unreadable']);
    }

    public function test_samples_read_forms(): void
    {
        $read = self::bladeForms(implode("\n", [
            '<form method="post" action="{{ route("{$department}.customers.store") }}" class="x">',
            '@csrf @method(\'PUT\')',
            '</form>',
            '<form :action="url" method="POST"></form>',
            '<form {{ $attributes->merge([\'class\' => \'x\']) }} method="POST" action="{{ route(\'b\') }}"></form>',
            '<form action="{{ route(\'a\') }}">',
        ]));

        $this->assertSame([
            ['line' => 1, 'action' => '{{ route("{$department}.customers.store") }}', 'methods' => ['POST', 'PUT']],
            ['line' => 4, 'action' => null, 'methods' => ['POST']],
            ['line' => 5, 'action' => "{{ route('b') }}", 'methods' => ['POST']],
        ], $read['forms']);
        $this->assertSame(['閉じていない <form>（6 行）'], $read['unreadable']);

        $this->assertSame(['フォームの中にフォームがある（2 行）'], self::bladeForms("<form>\n<form></form>")['unreadable']);
        $this->assertSame(['対応する <form> の無い </form>（1 行）'], self::bladeForms('</form>')['unreadable']);
    }

    public function test_samples_read_includes(): void
    {
        $read = self::bladeIncludes(implode("\n", [
            "@include('a.b', ['x' => 1]) @includeIf('not.followed')",
            '<x-delete-confirm-modal title="t" :action="route(\'z.destroy\', $m)" />',
        ]));

        $this->assertSame(['a/b.blade.php', 'components/delete-confirm-modal.blade.php'], array_column($read, 'view'));
        $this->assertSame([[], ['title' => 't', ':action' => "route('z.destroy', \$m)"]], array_column($read, 'attributes'));
    }

    public function test_samples_read_actions(): void
    {
        $this->assertSame([['name' => 'a.store', 'dynamic' => false]], self::actionRouteNames("{{ route('a.store') }}"));
        $this->assertSame(
            [['name' => 'a.update', 'dynamic' => false], ['name' => 'a.store', 'dynamic' => false]],
            self::actionRouteNames("{{ \$isEdit ? route('a.update', \$x) : route('a.store') }}"),
        );
        $this->assertSame([['name' => '{$department}.customers.store', 'dynamic' => true]], self::actionRouteNames('{{ route("{$department}.customers.store") }}'));
        $this->assertSame([['name' => 'z.destroy', 'dynamic' => false]], self::actionRouteNames('{{ $action }}', [':action' => "route('z.destroy', \$m)"]));
        $this->assertNull(self::actionRouteNames('{{ $action }}'), '呼び出し側の属性が無いのに読めた');
        $this->assertNull(self::actionRouteNames("{{ route('a.store') . '#x' }}"), 'route() のほかの式を読んだ');
        $this->assertNull(self::actionRouteNames("{{ url('a') }}"));
        $this->assertNull(self::actionRouteNames('/plain/path'));
        $this->assertNull(self::actionRouteNames(null));
    }

    public function test_samples_match_routes(): void
    {
        $routes = [
            ['name' => 'login', 'uri' => 'login', 'methods' => ['GET', 'HEAD'], 'action' => 'Auth@showLogin'],
            ['name' => null, 'uri' => 'login', 'methods' => ['POST'], 'action' => 'Auth@login'],
            ['name' => 'housing.customers.store', 'uri' => 'housing/customers', 'methods' => ['POST'], 'action' => 'Buyers@store'],
            ['name' => 'tenant.customers.store', 'uri' => 'tenant/customers', 'methods' => ['POST'], 'action' => 'TenantCustomers@store'],
            ['name' => 'x.show', 'uri' => 'x/{x}', 'methods' => ['GET', 'HEAD'], 'action' => 'X@show'],
            ['name' => 'x.update', 'uri' => 'x/{x}', 'methods' => ['PUT', 'PATCH'], 'action' => 'X@update'],
            ['name' => 'x.destroy', 'uri' => 'x/{x}', 'methods' => ['DELETE'], 'action' => 'X@destroy'],
        ];
        $name = fn (string $n, bool $dynamic = false) => [['name' => $n, 'dynamic' => $dynamic]];

        $this->assertSame(['Auth@login'], self::routeActions($name('login'), ['POST'], $routes, []), 'URL と送る方式でなく名前で引いた');
        $this->assertSame(['X@update'], self::routeActions($name('x.update'), ['POST', 'PUT'], $routes, []));
        $this->assertSame(['X@destroy'], self::routeActions($name('x.show'), ['POST', 'DELETE'], $routes, []));
        $this->assertSame(['Buyers@store'], self::routeActions($name('{$department}.customers.store', true), ['POST'], $routes, ['Buyers']), '画面を描くコントローラで絞っていない');
        $this->assertNull(self::routeActions($name('{$department}.customers.store', true), ['POST'], $routes, ['Other']));
        $this->assertNull(self::routeActions($name('nothing'), ['POST'], $routes, []));
    }

    public function test_samples_reach_rules(): void
    {
        $file = str_replace(base_path() . '/', '', __FILE__);
        $collected = $this->collectValidationKeys([$file => file_get_contents(__FILE__)]);
        $this->assertSame([], $collected['unreadable']);
        $siteIndex = self::siteIndex($collected['sites']);
        $files = [$file => true];
        $of = fn (string $method, ?array $index = null) => self::ruleKeysOfAction(OldInputScopeFixtureController::class . '@' . $method, $index ?? $siteIndex, $files);

        $this->assertSame(['deep_key'], array_keys($of('deep')['keys']), '`$this->` の 2 段目をたどっていない');
        $this->assertTrue($of('deep')['flashes']);

        $flashesOnly = $of('flashesOnly');
        $this->assertSame([], $flashesOnly['keys']);
        $this->assertTrue($flashesOnly['flashes'], 'withInput( だけある送り先を、控えを作らないとみなした');
        $this->assertTrue($of('throwsOnly')['flashes'], 'ValidationException だけある送り先を、控えを作らないとみなした');
        $this->assertFalse($of('quiet')['flashes']);

        $this->assertStringContainsString('入力チェックが 2 つ以上届く', implode(' ', $of('twice')['unreadable']));
        $this->assertStringContainsString('FormRequest を受ける', implode(' ', $of('formRequest')['unreadable']));

        // 別のファイルの同じ行の入力チェックを当てない（ファイルと行の両方で当てる）
        $deepLine = (new \ReflectionMethod(OldInputScopeFixtureController::class, 'second'))->getStartLine() + 1;
        $this->assertArrayNotHasKey('other_file_key', $of('deep', $siteIndex + ['app/Other.php' => [[$deepLine, ['other_file_key']]]])['keys']);
    }

    public function test_samples_require_a_rule_on_every_target(): void
    {
        $uses = [['view' => 'v', 'key' => 'a', 'line' => 1], ['view' => 'v', 'key' => 'costs.{$i}.name', 'line' => 2]];
        $targets = ['A@store' => ['a' => true, 'costs.*.name' => true], 'B@status' => ['b' => true]];

        $pairs = self::pairsWithoutRule($uses, $targets);

        $this->assertSame([['a', 'B@status'], ['costs.{$i}.name', 'B@status']], array_map(fn (array $p) => [$p[0]['key'], $p[1]], $pairs), 'どれか 1 つの送り先に規則があれば通している');
        $this->assertSame([], self::pairsWithoutRule($uses, ['A@store' => $targets['A@store']]), '`*` の規則に当てていない');
        $this->assertNotSame([], self::pairsWithoutRule([['view' => 'v', 'key' => 'costs.0.name.x', 'line' => 1]], ['A@store' => $targets['A@store']]), '`*` が 2 段以上に当たった');
    }

    // ---------- 組み立て ----------

    /**
     * 画面の欄と送り先の組のうち、送り先に規則が無いもの（分類表はまだ見ない）。欄は画面のすべての送り先に規則が要る
     *
     * @param  list<array{view: string, key: string, line: int}>  $uses
     * @param  array<string, array<string, true>>  $targetKeys  送り先 => 規則のキー
     * @return list<array{0: array, 1: string}>
     */
    private static function pairsWithoutRule(array $uses, array $targetKeys): array
    {
        $pairs = [];
        foreach ($uses as $use) {
            foreach ($targetKeys as $action => $keys) {
                if (! self::hasRule($use['key'], $keys)) {
                    $pairs[] = [$use, $action];
                }
            }
        }

        return $pairs;
    }

    /** 規則のキー（`costs.*.name` の `*` は 1 段ぶん）に当たるか */
    private static function hasRule(string $key, array $ruleKeys): bool
    {
        if (isset($ruleKeys[$key])) {
            return true;
        }
        foreach (array_keys($ruleKeys) as $ruleKey) {
            if (preg_match('/^' . str_replace('\*', '[^.]+', preg_quote((string) $ruleKey, '/')) . '$/', $key)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, list<array{0: int, 1: list<string>}>> ファイル => [入力チェックの行, キー] */
    private static function siteIndex(array $sites): array
    {
        $index = [];
        foreach ($sites as $site) {
            $at = strrpos($site['where'], ':');
            $index[substr($site['where'], 0, $at)][] = [(int) substr($site['where'], $at + 1), $site['keys']];
        }

        return $index;
    }

    /** @return list<array{name: ?string, uri: string, methods: list<string>, action: string}> */
    private static function routeTable(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $routes[] = ['name' => $route->getName(), 'uri' => $route->uri(), 'methods' => $route->methods(), 'action' => $route->getActionName()];
        }

        return $routes;
    }

    /** @return array<string, list<string>> ビュー => それを `view('…')` で描くコントローラのクラス */
    private static function renderersOf(array $sources): array
    {
        $out = [];
        foreach ($sources as $source) {
            if (! preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) || ! preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $class)) {
                continue;
            }
            preg_match_all('/\bview\(\s*[\'"]([\w.\-]+)[\'"]/', $source, $m);
            foreach ($m[1] as $name) {
                $out[str_replace('.', '/', $name) . '.blade.php'][] = $namespace[1] . '\\' . $class[1];
            }
        }

        return $out;
    }

    /** @return list<string> そのビューを読み込む木のいちばん上の親 */
    private static function rootsOf(string $view, array $includers, array $seen = []): array
    {
        if (empty($includers[$view]) || isset($seen[$view])) {
            return [$view];
        }
        $roots = [];
        foreach (array_keys($includers[$view]) as $parent) {
            $roots = array_merge($roots, self::rootsOf($parent, $includers, $seen + [$view => true]));
        }

        return array_values(array_unique($roots));
    }

    /** @return list<array{0: string, 1: array<string, string>}> 画面の木のビューと、そこへ読み込んだときの属性（部品の `{{ $action }}` を読む） */
    private static function treeOf(string $page, array $parsed): array
    {
        $out = [];
        $queue = [[$page, [], [$page => true]]];
        while ($queue !== []) {
            [$view, $attributes, $path] = array_shift($queue);
            $out[] = [$view, $attributes];
            foreach ($parsed[$view]['includes'] as $include) {
                if (! isset($path[$include['view']])) {
                    $queue[] = [$include['view'], $include['attributes'], $path + [$include['view'] => true]];
                }
            }
        }

        return $out;
    }
}

/** 見本（test_samples_reach_rules）の送り先 */
final class OldInputScopeFixtureController
{
    public function deep(Request $request): void
    {
        $this->first($request);
    }

    private function first(Request $request): void
    {
        $this->second($request);
    }

    private function second(Request $request): void
    {
        $request->validate(['deep_key' => 'string']);
    }

    public function flashesOnly(): mixed
    {
        return back()->withInput();
    }

    public function throwsOnly(): void
    {
        throw \Illuminate\Validation\ValidationException::withMessages(['x' => 'y']);
    }

    public function quiet(): int
    {
        return 1;
    }

    public function twice(Request $request): void
    {
        $request->validate(['a' => 'string']);
        Validator::make($request->all(), ['b' => 'string'])->validate();
    }

    public function formRequest(OldInputScopeFixtureRequest $request): void {}
}

/** 見本（test_samples_reach_rules）の FormRequest */
final class OldInputScopeFixtureRequest extends FormRequest
{
    public function rules(): array
    {
        return ['x' => 'string'];
    }
}
