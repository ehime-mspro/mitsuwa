<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 時計の読み取り（「今」を使う呼び出し）を全件分類する（docs/RULES.md Bug #61・Top trap #13）。
 *
 * アプリの timezone は UTC なので、now() / today() / date('Y') などで作る「今日」「今月」「今年度」は
 * 日本時間の 0:00〜8:59 に前日になる。暦の日付が要る所は App\Support\JapanTime::today() を使う。
 *
 * ⚠ ビューは 0 件。これは **PHP の時計の読み取りが 0 件**という意味で、JS は見ていない——
 *   ビューの `new Date(` は 2026-09-23 実測で 79 箇所 / 11 ファイル（日付ピッカーなど）あるが、
 *   ブラウザのローカル時刻＝日本時間なので対象外。
 * ⚠ PHP は ALLOWED（ファイルごとの件数と理由）に載っているものだけ許す。件数が合わない・載っていない・
 *   古い項目は落とす。瞬間を保存する（TIMESTAMP 列）・期限・運用のように UTC の瞬間でよい所だけ、理由を書いて載せる。
 *
 * ⚠ ビューの注意書きは必ず {{-- --}} に書く。行中の // は落とさないので、
 *   `{{ $x }} // now() を使うな` のように書くとこのテストが赤くなる
 *   （行頭の // だけ落とす。https:// を残すため）。検出器を弱めて通さないこと——
 *   行中の // を落とすと `<p>x</p> // {{ now() }}` の形で本物の時計の読み取りを隠せてしまう
 *   （Blade では // はコメントではなくただの文字で、<script> や @php の外では後ろも描画される）。
 *   2026-09-23 実測でビューの行中 // は 21 行 / 9 ファイル。near-miss は
 *   admin/master/zeal-simulation-categories/_form.blade.php:112 の `… // デフォルト: 来月`。
 *
 * ⚠ 見えないもの（2026-09-23 実測。断りの無いものはリポジトリに 0 件）:
 *   - 変数に入れた Carbon をあとで暦として使う形（`$t = now(); … $t->year`）は、呼び出しとしては
 *     拾うがその使い道は見ない（載せるときに理由で説明する）
 *   - 先頭 \ 付きのグローバル呼び出し（`\now()` / `\date('Y')`）——lookbehind が \ を除くため
 *   - `Carbon::parse('now')` / `new DateTime('+1 day')`——4 番の枝は引数が空 / 'now' / 'today' のときだけ当たる
 *   - 一覧に無い判定（`isCurrentWeek()` など。5 番の枝は名前を並べているだけ）
 * ⚠ 過剰に拾うもの（2026-09-23 実測。いずれもリポジトリに 0 件）:
 *   - 絶対日付のリテラル `strtotime('2026-01-01')`・補間文字列 `strtotime("{$y}-04-01")`
 *     （3 番の枝は「引数 1 つの文字列リテラル」で当てるので中身を見ない）
 *   - PHP の文字列リテラルの中の `now()`（`DB::raw('now()')` など。走査はトークンを見ない）
 *   出てきたら ALLOWED に理由つきで載せる（検出器を緩めない）。
 * ⚠ 「今」を使わない呼び出しは数えない: 引数 2 つの date()（保存された日付の整形）・文字列の変数を渡す strtotime()・
 *   日まで書式にある createFromFormat()。
 */
class ClockReadScanTest extends TestCase
{
    /** @var array<string, array{0: int, 1: string}> 相対パス => [件数, 理由] */
    private const ALLOWED = [
        'app/Support/JapanTime.php'                           => [2, '日本の今日を作る部品そのもの（today() の宣言と Carbon::now()）'],
        'app/Http/Controllers/Auth/AuthController.php'        => [2, 'last_login_at・logged_in_at は TIMESTAMP 列（UTC の瞬間で保存する）'],
        'app/Http/Controllers/Tenant/PropertyController.php'  => [1, 'property_change_logs.changed_at は TIMESTAMP 列（UTC の瞬間で保存する）'],
        'app/Http/Controllers/Zeal/SimulationController.php'  => [1, '一括 insert の created_at・updated_at（TIMESTAMP 列。Eloquent を通らないので手で入れる）'],
        'app/Http/Controllers/Zeal/SheetImportController.php' => [1, 'zeal_sheet_imports.created_at は TIMESTAMP 列（UTC の瞬間で保存する）'],
        'app/Console/Commands/BackupCommand.php'              => [2, '運用: 日本時間を明示している（CarbonImmutable::now(\'Asia/Tokyo\')）'],
        'app/Console/Commands/MailTestCommand.php'            => [1, '運用: 日本時間を明示している'],
        'routes/console.php'                                  => [1, '運用: 日本時間を明示している'],
        'app/Support/Approval/PasswordReissuer.php'           => [1, '再発行の瞬間（通知メールへ渡し、PasswordReissuedMail が JapanTime で日本時間に直して出す）'],
        'app/Support/OneTimeAction.php'                       => [1, '期限: 1 回限りの鍵のキャッシュの有効期限（瞬間でよい）'],
    ];

    /** @return list<array{int, string}> [行, 呼び出し] */
    private function clockReads(string $code): array
    {
        $found = [];
        $add = function (int $offset, string $text) use (&$found, $code) {
            $found[] = [substr_count(substr($code, 0, $offset), "\n") + 1, $text];
        };
        $notMember = '(?<![\w$>:.\\\\])';

        // 1. now( / today(（ヘルパー関数）
        if (preg_match_all('/' . $notMember . '(?:now|today)\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$text, $offset]) {
                $add($offset, trim($text));
            }
        }
        // 2. Carbon::now() / CarbonImmutable::today() / Date::now() など
        if (preg_match_all('/\b(?:Carbon|CarbonImmutable|Date)::(?:now|today|yesterday|tomorrow)\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$text, $offset]) {
                $add($offset, trim($text));
            }
        }
        // 3. PHP の関数: date/gmdate/idate は引数 1 つ・time は引数なし・mktime は 6 未満・strtotime は文字列そのもの
        if (preg_match_all('/' . $notMember . '(date|gmdate|idate|time|mktime|strtotime)\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $k => [$name]) {
                $open = $m[0][$k][1] + strlen($m[0][$k][0]) - 1;
                $args = $this->argumentsAt($code, $open);
                if ($args === null) {
                    continue;
                }
                $count = $this->topLevelArgumentCount($args);
                $isClock = match ($name) {
                    'date', 'gmdate', 'idate' => $count === 1,
                    'time'                    => $count === 0,
                    'mktime'                  => $count < 6,
                    'strtotime'               => $count === 1 && preg_match('/^\s*([\'"])[^\'"]*\1\s*$/', $args) === 1,
                };
                if ($isClock) {
                    $add($m[0][$k][1], "{$name}({$args})");
                }
            }
        }
        // 4. new DateTime() / new Carbon('now')
        if (preg_match_all('/\bnew\s+\\\\?(?:\w+\\\\)*(?:DateTime|DateTimeImmutable|Carbon|CarbonImmutable)\s*\(\s*(?:([\'"])(?:now|today)\1)?\s*\)/', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$text, $offset]) {
                $add($offset, trim($text));
            }
        }
        // 5. 暗黙に「今」と比べる: 日付の属性の ->age・引数なしの diffIn…() / diffForHumans()・isToday() など
        $implicit = '/(?:birthday|_date|_on|_at)->age\b'
            . '|->(?:isToday|isPast|isFuture|isYesterday|isTomorrow|isCurrentDay|isCurrentMonth|isCurrentYear|isNextMonth|isLastMonth|isNextYear|isLastYear)\s*\('
            . '|->diff(?:In\w+|ForHumans)\s*\(\s*\)/';
        if (preg_match_all($implicit, $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$text, $offset]) {
                $add($offset, trim($text));
            }
        }
        // 6. createFromFormat の書式に年・月があって日が無い（無い「日」は今日から補われる）
        if (preg_match_all('/createFromFormat\(\s*([\'"])([^\'"]*)\1/', $code, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[2] as $k => [$format]) {
                if (preg_match('/[YymnMF]/', $format) && ! preg_match('/[djDlNSwz!|]/', $format)) {
                    $add($m[0][$k][1], "createFromFormat('{$format}')");
                }
            }
        }

        return $found;
    }

    /** "(" の位置から対応する ")" までの中身。閉じていなければ null */
    private function argumentsAt(string $code, int $open): ?string
    {
        $depth = 0;
        for ($i = $open, $len = strlen($code); $i < $len; $i++) {
            if ($code[$i] === '(') {
                $depth++;
            } elseif ($code[$i] === ')' && --$depth === 0) {
                return substr($code, $open + 1, $i - $open - 1);
            }
        }

        return null;
    }

    private function topLevelArgumentCount(string $args): int
    {
        if (trim($args) === '') {
            return 0;
        }
        $depth = 0;
        $count = 1;
        $quote = null;
        for ($i = 0, $len = strlen($args); $i < $len; $i++) {
            $c = $args[$i];
            if ($quote !== null) {
                if ($c === '\\') {
                    $i++;
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"') {
                $quote = $c;
            } elseif ($c === '(' || $c === '[') {
                $depth++;
            } elseif ($c === ')' || $c === ']') {
                $depth--;
            } elseif ($c === ',' && $depth === 0) {
                $count++;
            }
        }

        return $count;
    }

    private function withoutComments(string $path): string
    {
        $source = File::get($path);
        $keepNewlines = fn (array $m) => str_repeat("\n", substr_count($m[0], "\n"));

        if (str_ends_with($path, '.blade.php')) {
            $source = preg_replace_callback('/\{\{--.*?--\}\}/s', $keepNewlines, $source);
            // ⚠ 文字列の中の `/*`（`request()->is('tenant/*')` など実測 14 箇所）から始めない。
            //    始めると次の `*/`（<style> の普通のコメントで十分）まで実コードを飲み込み、走査が無音で止まる。
            $source = preg_replace_callback('#(?<![\w\x27"])/\*.*?\*/#s', $keepNewlines, $source);

            return preg_replace('#^([ \t]*)//[^\n]*#m', '$1', $source); // 行頭の // だけ（https:// を残す）
        }

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /** @return array{0: array<string, list<array{int, string}>>, 1: int, 2: int} [相対パス => 一致, PHP の本数, Blade の本数] */
    private function scan(): array
    {
        $hits = [];
        $php = 0;
        $blade = 0;
        foreach ([app_path(), base_path('routes'), config_path(), resource_path('views')] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                str_ends_with($file->getFilename(), '.blade.php') ? $blade++ : $php++;
                $found = $this->clockReads($this->withoutComments($file->getPathname()));
                if ($found !== []) {
                    $hits[str_replace(base_path() . '/', '', $file->getPathname())] = $found;
                }
            }
        }

        return [$hits, $php, $blade];
    }

    public function test_the_scan_sees_enough_files(): void
    {
        [, $php, $blade] = $this->scan();

        $this->assertGreaterThanOrEqual(250, $php, '走査が空振りしている（PHP のファイルが少なすぎる）');
        $this->assertGreaterThanOrEqual(230, $blade, '走査が空振りしている（Blade のファイルが少なすぎる）');
    }

    public function test_views_never_read_the_clock(): void
    {
        [$hits] = $this->scan();
        $problems = [];
        foreach ($hits as $path => $found) {
            if (str_starts_with($path, 'resources/views/')) {
                foreach ($found as [$line, $text]) {
                    $problems[] = "{$path}:{$line}  {$text}";
                }
            }
        }

        $this->assertSame([], $problems, "ビューで時計を読んでいる（日本時間の 0:00〜8:59 に前日になる。\\App\\Support\\JapanTime::today() を使う）。\n"
            . "注意書きの中の now() に一致した場合は、その注意書きを {{-- --}} に移すこと（行中の // は落とさない）:\n"
            . implode("\n", $problems));
    }

    public function test_php_clock_reads_are_classified(): void
    {
        [$hits] = $this->scan();
        $problems = [];

        foreach ($hits as $path => $found) {
            if (str_starts_with($path, 'resources/views/')) {
                continue;
            }
            if (! isset(self::ALLOWED[$path])) {
                foreach ($found as [$line, $text]) {
                    $problems[] = "{$path}:{$line}  {$text}  （分類されていない。日本の今日なら JapanTime::today()、瞬間なら理由を書いて ALLOWED へ）";
                }
                continue;
            }
            if (count($found) !== self::ALLOWED[$path][0]) {
                $problems[] = "{$path}: 件数が " . count($found) . '（一覧は ' . self::ALLOWED[$path][0] . '）: '
                    . implode(' / ', array_map(fn ($f) => ":{$f[0]} {$f[1]}", $found));
            }
        }
        foreach (array_keys(self::ALLOWED) as $path) {
            if (! isset($hits[$path])) {
                $problems[] = "{$path}: 一覧にあるのに時計を読んでいない（古い項目）";
            }
        }

        $this->assertSame([], $problems, "時計の読み取りが分類と合わない:\n" . implode("\n", $problems));
    }

    public function test_the_detector_catches_what_it_should_and_ignores_the_rest(): void
    {
        // ⚠ 正規表現の枝は 1 つずつサンプルを通す。サンプルの無い枝は消しても全テストが緑になる（Bug #45）
        $caught = [
            'now()', 'now(\'Asia/Tokyo\')', 'today()', 'Carbon::now()', '\Carbon\CarbonImmutable::today()',
            'Date::now()', 'Carbon::yesterday()', 'CarbonImmutable::tomorrow()',
            'date(\'Y-m-d\')', 'gmdate(\'Y\')', 'idate(\'Y\')', 'time()', 'mktime(0, 0, 0)', 'strtotime(\'today\')',
            'new DateTime()', 'new \DateTimeImmutable(\'now\')', 'new Carbon()', 'new CarbonImmutable()',
            '$m->birthday->age', '$m->contract_date->age',
            '$d->isToday()', '$d->isPast()', '$d->isFuture()', '$d->isYesterday()', '$d->isTomorrow()',
            '$d->isCurrentDay()', '$d->isCurrentMonth()', '$d->isCurrentYear()',
            '$d->isNextMonth()', '$d->isLastMonth()', '$d->isNextYear()', '$d->isLastYear()',
            '$d->diffInDays()', '$d->diffForHumans()',
            'createFromFormat(\'Y-m\', $m)',
        ];
        // ⚠ 後半は「拾わない」＝死角の記録（docblock の ⚠ と対）。同語反復でない値なので、
        //    検出器を広げたときにここが赤くなって「広げた」と分かる
        $ignored = [
            'JapanTime::today()', '$x->now()', '$this->today()', 'date(\'Y\', $ts)', 'date(\'Y\', strtotime($stored))',
            'strtotime($stored)', '$inquiry->age', '$d->diffInDays($other)', 'createFromFormat(\'Y-m-d\', $s)',
            'createFromFormat(\'H:i:s\', $t)', 'Date.now()', 'new Date()', '$q->update($a)', '$todayLabel',
            '\now()', '\date(\'Y\')', 'Carbon::parse(\'now\')', 'new DateTime(\'+1 day\')', '$d->isCurrentWeek()',
        ];

        foreach ($caught as $sample) {
            $this->assertNotSame([], $this->clockReads($sample), "拾えていない: {$sample}");
        }
        foreach ($ignored as $sample) {
            $this->assertSame([], $this->clockReads($sample), "拾うべきでない: {$sample}");
        }
    }

    public function test_comments_are_dropped_before_scanning(): void
    {
        $php = sys_get_temp_dir() . '/clock-' . uniqid('', true) . '.php';
        File::put($php, "<?php\n// now()\n/** today() */\n\$ok = 1;\n");
        $blade = sys_get_temp_dir() . '/clock-' . uniqid('', true) . '.blade.php';
        File::put($blade, "{{-- now() --}}\n  // date('Y')\n<a href=\"https://example.com\">ok</a>\n");

        try {
            $this->assertSame([], $this->clockReads($this->withoutComments($php)));
            $this->assertSame([], $this->clockReads($this->withoutComments($blade)));
        } finally {
            File::delete([$php, $blade]);
        }

        // ⚠ 行中の // は落とさない（仕様）。落とすと `<p>x</p> // {{ now() }}` のような形で
        //    本物の時計の読み取りを隠してしまう。ビューの注意書きは {{-- --}} に書くこと
        $midline = sys_get_temp_dir() . '/clock-mid-' . uniqid('', true) . '.blade.php';
        File::put($midline, "{{ \$x }} // now() は使わない\n");
        try {
            $this->assertNotSame([], $this->clockReads($this->withoutComments($midline)));
        } finally {
            File::delete($midline);
        }

        // ⚠ 文字列の中の `/*`（`request()->is('tenant/*')`）からブロックコメントを始めない。
        //    始めると次の `*/`（<style> の普通のコメントで十分）まで実コードを飲み込み、走査が無音で止まる
        $inString = sys_get_temp_dir() . '/clock-str-' . uniqid('', true) . '.blade.php';
        File::put($inString, "<a class=\"{{ request()->is('tenant/*') ? 'a' : 'b' }}\">x</a>\n"
            . "@php(\$z = now()->year)\n"
            . "<style>\n/* 折りたたみ時の幅 */\n.x { width: 64px; }\n</style>\n");
        try {
            $this->assertNotSame([], $this->clockReads($this->withoutComments($inString)));
        } finally {
            File::delete($inString);
        }
    }
}
