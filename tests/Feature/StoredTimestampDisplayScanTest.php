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
 *   変数に入れてから整形する形は見えない。
 */
class StoredTimestampDisplayScanTest extends TestCase
{
    use ScansModelRelations;

    /** 理由つきで許す直接の整形: 相対パス => [件数, 理由]（今は無い） */
    private const ALLOWED = [];

    private const CALENDAR = 'format|isoFormat|translatedFormat|to\w*String|diffForHumans|toJSON|toISOString';

    private const FIELDS = 'year|month|day|hour|minute|second|dayOfWeek|dayOfYear|weekOfYear|quarter';

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

        $this->assertGreaterThanOrEqual(24, $count, '走査が空振りしている（JapanTime::format() の呼び出しが少なすぎる）');
    }

    public function test_the_detector_catches_what_it_should_and_ignores_the_rest(): void
    {
        $names = ['created_at', 'last_login_at'];
        $caught = [
            '$c->created_at->format(\'Y\')',
            '$c->created_at?->format(\'Y\')',
            '$c->created_at ->format(\'Y\')',
            'optional($c->created_at)->format(\'Y\')',
            'Carbon::parse($c->created_at)->format(\'Y\')',
            '$c->created_at->toDateString()',
            '$c->created_at->diffForHumans()',
            '$u->last_login_at->year',
        ];
        $ignored = [
            'JapanTime::format($c->created_at)',
            '$c->contract_date->format(\'Y\')',
            '$c->created_at_label',
            '$c->created_atx->format(\'Y\')',
            '$c->created_at === null',
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
    }
}
