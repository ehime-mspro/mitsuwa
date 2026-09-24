<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\File;
use Tests\Concerns\ScansModelRelations;
use Tests\TestCase;

/**
 * 保存された日時（TIMESTAMP 列。UTC で保存）を、整形するときは必ず JapanTime::format() を通す（docs/RULES.md Bug #61）。
 *
 * ⚠ 属性の名前はモデルから機械的に集める（usesTimestamps() の作成・更新の列・SoftDeletes の削除の列・datetime キャスト）。
 *   date キャストの `_at`（repairs.started_at など）は自動で外れる＝名前で決め打ちしない（Top trap #13）。
 * ⚠ 見るのは直接の連鎖（`->created_at->format(` / `?->format(` / `optional($x->created_at)->format(` / `->year` など）。
 *
 * ⚠ 見えない形（2026-09-23 に断片を当てて実測。どれも「拾わない」ことを自己テストで記録してある）:
 *   - 変数に入れてから整形する（`$t = $c->created_at; … $t->format(`）
 *   - **配列の添字**（`$row['created_at']->format(`）
 *   - `{{ $x->created_at }}` の素の文字列化（Carbon の __toString）
 *   - 途中にメソッドを挟む（`->setTimezone(…)->format(` / `->copy()->format(` /
 *     `->addHours(9)->format(` / `->startOfDay()->format(`）
 *   - `sprintf('%s', $c->created_at)` のように関数へ渡す
 *   - `optional($c->created_at)->year`（2 本目の式は CALENDAR だけで FIELDS を見ない）
 * ⚠ 実在する唯一の対象外（＝正しいのに検出器には見えない）:
 *   app/Http/Controllers/Tenant/UnitController.php:529 の
 *   `$row['revision_date']->format('Y-m-d') . ' ' . $row['created_at']->format('H:i:s')`。
 *   **表示ではなく並び替えキー**なので UTC のままでよく、配列の添字なので走査にも当たらない
 *   （2026-09-23 実測。TIMESTAMP 属性を配列の添字で整形しているのはアプリ全体でこの 1 箇所だけ）。
 * ⚠ 上の死角は MIN_FORMAT_CALLS（件数の下限）だけが間接的に捕まえる。下限を下げる前に必ず読むこと。
 */
class StoredTimestampDisplayScanTest extends TestCase
{
    use ScansModelRelations;

    /**
     * 理由つきで許す直接の整形: 相対パス => [件数, 理由]。
     *
     * ⚠ 載せてよいのは **画面に出す文字列でない用途**だけ（並び替えキー・CSV の列・ファイル名・ログ）。
     *   画面に出るものは絶対に載せない——UTC のままだと日本時間の 0:00〜8:59 に前日が出る。
     */
    private const ALLOWED = [
        'app/Http/Controllers/Admin/UserController.php' => [1, '設定の変更の記録（approval_setting_logs.new_values）に削除の瞬間を UTC のまま残す（記録。画面に出さない。設計書 D14）'],
    ];

    private const CALENDAR = 'format|isoFormat|translatedFormat|to\w*String|diffForHumans|toJSON|toISOString';

    private const FIELDS = 'year|month|day|hour|minute|second|dayOfWeek|dayOfYear|weekOfYear|quarter';

    /**
     * JapanTime::format() の呼び出し件数の下限。2026-09-24 の実測 = 26（＝下限ちょうど。決裁 段階1 の取り込みで
     * 決裁の利用者の管理の最終ログインと再発行の通知メールの 2 件を足した。2026-09-23 は 24）。
     *
     * ⚠ 空振り防止だけでなく、directFormats() から見えない書き換え（変数に入れる・配列の添字・
     *   {{ }} の素出し・->setTimezone() を挟む等。クラスの docblock 参照）を件数の低下で捕まえる
     *   **唯一の守り手**。下げるときは、その 1 件が「画面ごと消した」のか
     *   「見えない形に化けた」のかを必ず確かめる。
     * ⚠ 合計しか見ないので、別の画面で 1 件増えると 1 件が死角へ化けても緑になる。
     */
    private const MIN_FORMAT_CALLS = 26;

    /** @return list<string> */
    private function timestampAttributes(): array
    {
        $names = [];
        foreach ($this->modelClasses() as $class) {
            $model = new $class();
            if ($model->usesTimestamps()) {
                foreach ([$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()] as $column) {
                    if ($column !== null) {
                        $names[$column] = true;
                    }
                }
            }
            // ⚠ Laravel の SoftDeletes は initializeSoftDeletes() で deleted_at を datetime キャストへ
            //    足すので、この分岐は今のところ下の casts のループと重なっている（2026-09-23 に
            //    Buyer / Unit / Attachment / Property で実測）。この 3 行を消す変異は**等価変異**で緑になる
            //    ＝「検出しない＝穴」と誤読しないこと。列名を変えたモデルや、将来 Laravel が自動キャストを
            //    やめた場合の備えとして残す。
            if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                $names[$model->getDeletedAtColumn()] = true;
            }
            foreach ($model->getCasts() as $attribute => $cast) {
                $base = strtolower(explode(':', (string) $cast)[0]);
                if (in_array($base, ['datetime', 'immutable_datetime', 'timestamp', 'custom_datetime', 'immutable_custom_datetime'], true)) {
                    $names[$attribute] = true;
                }
            }
        }
        ksort($names);

        return array_keys($names);
    }

    /** @param list<string> $names  @return list<array{int, string}> [行, 一致した文字列] */
    private function directFormats(string $code, array $names): array
    {
        $alt = implode('|', array_map(fn (string $n) => preg_quote($n, '/'), $names));
        $patterns = [
            '/->(?:' . $alt . ')\b\s*\??->\s*(?:(?:' . self::CALENDAR . ')\s*\(|(?:' . self::FIELDS . ')\b)/',
            '/\b(?:optional|Carbon::parse|CarbonImmutable::parse|Carbon::make)\(\s*\$[\w>\-]*->(?:' . $alt . ')\s*\)\s*\??->\s*(?:' . self::CALENDAR . ')\s*\(/',
        ];
        $found = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $code, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [$text, $offset]) {
                    $found[] = [substr_count(substr($code, 0, $offset), "\n") + 1, $text];
                }
            }
        }

        return $found;
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

    /**
     * @return list<string> 走査するディレクトリ。
     *
     * ⚠ 2 か所で別々に持つと、本体の走査と「件数の下限」のテストが別のものを見るようになり、
     *   片方だけ書き換えたときに下限の守りが静かに外れる。
     */
    private function scanDirs(): array
    {
        return [app_path(), resource_path('views')];
    }

    /** @return array<string, list<array{int, string}>> 相対パス => 一致 */
    private function scan(): array
    {
        $names = $this->timestampAttributes();
        $hits = [];
        foreach ($this->scanDirs() as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $found = $this->directFormats($this->withoutComments($file->getPathname()), $names);
                if ($found !== []) {
                    $hits[str_replace(base_path() . '/', '', $file->getPathname())] = $found;
                }
            }
        }

        return $hits;
    }

    public function test_timestamp_attributes_are_collected_from_the_models(): void
    {
        $names = $this->timestampAttributes();

        foreach (['created_at', 'updated_at', 'deleted_at', 'last_login_at', 'changed_at', 'logged_in_at', 'email_verified_at'] as $expected) {
            $this->assertContains($expected, $names, "{$expected} を TIMESTAMP の属性として拾えていない");
        }
        // date キャストの _at は拾わない（名前で決め打ちしていない証拠）
        foreach (['started_at', 'completed_at', 'executed_at'] as $dateOnly) {
            $this->assertNotContains($dateOnly, $names, "{$dateOnly} は date キャストなのに TIMESTAMP として拾っている");
        }
    }

    public function test_stored_timestamps_are_never_formatted_directly(): void
    {
        $hits = $this->scan();
        $problems = [];

        foreach ($hits as $path => $found) {
            $allowed = self::ALLOWED[$path][0] ?? 0;
            if (count($found) !== $allowed) {
                foreach ($found as [$line, $text]) {
                    $problems[] = "{$path}:{$line}  {$text}";
                }
            }
        }
        foreach (self::ALLOWED as $path => [$count]) {
            if (! isset($hits[$path])) {
                $problems[] = "{$path}: 一覧にあるのに直接の整形が 0 件（古い項目）";
            }
        }

        $this->assertSame([], $problems, "保存された日時を直接整形している（9 時間ずれる。JapanTime::format() を通す）:\n" . implode("\n", $problems));
    }

    public function test_japan_time_format_is_used_where_timestamps_are_shown(): void
    {
        $count = 0;
        foreach ($this->scanDirs() as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() === 'php') {
                    $count += preg_match_all('/JapanTime::format\(/', $this->withoutComments($file->getPathname()));
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MIN_FORMAT_CALLS,
            $count,
            'JapanTime::format() の呼び出しが ' . self::MIN_FORMAT_CALLS . ' 件を下回った。走査の空振りか、'
            . '検出器に見えない形（変数に入れる・配列の添字・{{ }} の素出し・->setTimezone() を挟む等）へ'
            . "化けたか、画面ごと消えたかのどれか。\n"
            . '下げる前に、その 1 件がどれなのかを確かめること（この下限が死角を捕まえる唯一の守り手）'
        );
    }

    public function test_the_detector_catches_what_it_should_and_ignores_the_rest(): void
    {
        $names = ['created_at', 'last_login_at'];
        // ⚠ CALENDAR / FIELDS の枝は 1 つずつサンプルを通す。サンプルの無い枝は
        //    消しても全テストが緑になる（Bug #45）
        $caught = [
            '$c->created_at->format(\'Y\')',
            '$c->created_at?->format(\'Y\')',
            '$c->created_at ->format(\'Y\')',
            'optional($c->created_at)->format(\'Y\')',
            'Carbon::parse($c->created_at)->format(\'Y\')',
            'CarbonImmutable::parse($c->created_at)->format(\'Y\')',
            'Carbon::make($c->created_at)->format(\'Y\')',
            '$c->created_at->isoFormat(\'LL\')',
            '$c->created_at->translatedFormat(\'Y年n月j日\')',
            '$c->created_at->toDateString()',
            '$c->created_at->toJSON()',
            '$c->created_at->toISOString()',
            '$c->created_at->diffForHumans()',
            '$u->last_login_at->year',
            '$c->created_at->month',
            '$c->created_at->day',
            '$c->created_at->hour',
            '$c->created_at->minute',
            '$c->created_at->second',
            '$c->created_at->dayOfWeek',
            '$c->created_at->dayOfYear',
            '$c->created_at->weekOfYear',
            '$c->created_at->quarter',
        ];
        // ⚠ 後半の 9 つは「拾わない」＝死角の記録（クラスの docblock の ⚠ と対）。
        //    同語反復でない値なので、検出器を広げたらここが赤くなって「広げた」と分かる
        $ignored = [
            'JapanTime::format($c->created_at)',
            '$c->contract_date->format(\'Y\')',
            '$c->created_at_label',
            '$c->created_atx->format(\'Y\')',
            '$c->created_at === null',
            '$t = $c->created_at; $t->format(\'Y\')',
            '$row[\'created_at\']->format(\'Y\')',
            '{{ $x->created_at }}',
            '$c->created_at->setTimezone(\'Asia/Tokyo\')->format(\'Y\')',
            '$c->created_at->copy()->format(\'Y\')',
            '$c->created_at->startOfDay()->format(\'Y\')',
            '$c->created_at->addHours(9)->format(\'Y\')',
            'sprintf(\'%s\', $c->created_at)',
            'optional($c->created_at)->year',
        ];

        foreach ($caught as $sample) {
            $this->assertNotSame([], $this->directFormats($sample, $names), "拾えていない: {$sample}");
        }
        foreach ($ignored as $sample) {
            $this->assertSame([], $this->directFormats($sample, $names), "拾うべきでない: {$sample}");
        }
    }

    public function test_comments_are_dropped_before_scanning(): void
    {
        $path = sys_get_temp_dir() . '/scan-' . uniqid('', true) . '.php';
        File::put($path, "<?php\n// \$c->created_at->format('Y')\n/** \$c->created_at->format('Y') */\n\$ok = 1;\n");
        $blade = sys_get_temp_dir() . '/scan-' . uniqid('', true) . '.blade.php';
        File::put($blade, "{{-- \$c->created_at->format('Y') --}}\n  // \$c->created_at->format('Y')\n<p>ok</p>\n");

        try {
            $this->assertSame([], $this->directFormats($this->withoutComments($path), ['created_at']));
            $this->assertSame([], $this->directFormats($this->withoutComments($blade), ['created_at']));
        } finally {
            File::delete([$path, $blade]);
        }

        // ⚠ 行中の // は落とさない（仕様）。落とすと `<p>x</p> // {{ $c->created_at->format('Y') }}`
        //    のような形で本物の直接の整形を隠してしまう。ビューの注意書きは {{-- --}} に書くこと
        $midline = sys_get_temp_dir() . '/scan-mid-' . uniqid('', true) . '.blade.php';
        File::put($midline, "{{ \$x }} // \$c->created_at->format('Y') は使わない\n");
        try {
            $this->assertNotSame([], $this->directFormats($this->withoutComments($midline), ['created_at']));
        } finally {
            File::delete($midline);
        }

        // ⚠ 文字列の中の `/*`（`request()->is('tenant/*')`）からブロックコメントを始めない。
        //    始めると次の `*/`（<style> の普通のコメントで十分）まで実コードを飲み込み、走査が無音で止まる
        $inString = sys_get_temp_dir() . '/scan-str-' . uniqid('', true) . '.blade.php';
        File::put($inString, "<a class=\"{{ request()->is('tenant/*') ? 'a' : 'b' }}\">x</a>\n"
            . "{{ \$c->created_at->format('Y') }}\n"
            . "<style>\n/* 折りたたみ時の幅 */\n.x { width: 64px; }\n</style>\n");
        try {
            $this->assertNotSame([], $this->directFormats($this->withoutComments($inString), ['created_at']));
        } finally {
            File::delete($inString);
        }
    }
}
