<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 空白の無い長い値（メールアドレス・ファイル名・自由入力に貼られた URL）が、狭い画面で枠からはみ出さないこと。
 *
 * 【背景】2026-10-07 の本番の Chrome 確認で、ZEAL の会員の詳細の長いメールアドレス（約 25 字）が
 *   375px で 24px、641px で 2 列目の値が 18px、カードの overflow: hidden に黙って切られていた（Bug #114 の追記）。
 *   2026-10-08 に使い捨ての環境で全ビューを測り直すと、同じ形が詳細画面に広くあった
 *   （39 字のメールで 375px で最大 109px はみ出す・95 字の URL を備考に貼ると 375px で main が 253〜413px はみ出す。
 *   Chrome はメールの `@` `.` でも URL の `/` でも折り返さない）。
 *
 * 【守るもの】長い値を文字として出す箇所（{{ }} ・{!! !!}・x-text）を全ビューから拾い、
 *   値を直に包む要素が「折り返し」か「省略表示」を持つこと。
 *   - 折り返し: overflow-wrap: anywhere（Tailwind の wrap-anywhere・[overflow-wrap:anywhere]）か word-break: break-all
 *   - 省略表示: text-overflow: ellipsis（Tailwind の truncate）
 *   - そのビューの <style> のクラスに書いたものも認める（.zeal-info-value など）
 *   ⚠ overflow-wrap: break-word（Tailwind の break-words）は認めない。幅の決まった段落の中では折り返すが、
 *     値の最小の幅を縮めないので、`140px 1fr` の行や `1fr 1fr` の 2 列は値の長さまで広がって切れる（anywhere は縮める）。
 *
 * 【対象外】表（<table>）の中の値。MobileLayoutTest がどの表も横スクロールの枠の中にあることを全件で守っており、
 *   長い値は表を広げてスクロールさせるだけで切れない。逆にセルへ anywhere を付けると、幅の足りない表で
 *   その列が 1 文字幅まで潰れる（表の幅は列の最小の幅の合計まで縮むため）。
 *
 * ⚠ 長い値の項目は名前で拾う（FIELDS）。ここに無い名前の項目（新しく足した自由入力など）は見えない。
 *   自由入力を足したら名前をここへ足す。
 * ⚠ 本当に切れないかは実ブラウザで測る（ここは描く前のソースの形しか見られない）。
 *
 * Bug #45 の教訓により「直したファイルを並べる」形にはしない。全ビューを走査し、
 * 例外はファイルごとの件数と理由で持つ（件数が合わなければ落ちる＝新しい箇所も、古くなった例外も拾う）。
 */
class LongUnbrokenValueWrapTest extends TestCase
{
    /** 空白の無い長い値になりうる項目の名前（メール・ファイル名・TEXT 型の自由入力の列） */
    private const FIELDS = 'email|file_name|original_name|notes|note|memo|description|remarks|reason|comment|withdraw_note|termination_reason|qualifications|deposit_deduction_reason|special_notes|purpose_detail|body|content|answer_value|result_reason';

    /** 走査が空振りしたら緑になる。拾えた箇所の数の下限（2026-10-08 の実測は 47 ビュー・表の外 61・表の中 25。下げる前に「消した」のか「拾えなくなった」のかを確かめる） */
    private const MIN_SITES_OUTSIDE_TABLES = 55;

    private const MIN_SITES_IN_TABLES = 20;

    /**
     * 折り返しも省略表示も持たなくてよい箇所（ファイル => [件数, 理由]）。
     * 足すときは「なぜ狭い画面で切れないか、なぜ直さないか」を書くこと。
     */
    private const EXEMPT = [
        'approvals/requests/show.blade.php' => [3, '決裁の画面は「社内決裁申請」の会話の担当（2026-10-08 時点で段階5a を作業中）。段落は break-words で、幅の決まった段落の中では折り返す（1fr の列の中かは未実測）'],
        'approvals/requests/_history.blade.php' => [2, '同上'],
        'approvals/requests/_steps.blade.php' => [1, '同上'],
        'approvals/requests/_actions.blade.php' => [1, '同上'],
        'approvals/requests/form.blade.php' => [1, '同上'],
        'approvals/login-guide.blade.php' => [1, '決裁の会話の担当。A4 の紙に印刷するための紙面（375px では以前から横にはみ出す・範囲外として記録済み）'],
    ];

    private const VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

    /** 文字として出さない要素（値が入っても画面の幅に関わらない） */
    private const NOT_RENDERED_AS_TEXT = ['textarea', 'option', 'title'];

    private const TAG = '/<(\/?)([a-zA-Z][\w:.-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/';

    private const WRAP_DECLARATION = '/overflow-wrap:\s*anywhere|word-break:\s*break-all|text-overflow:\s*ellipsis/';

    private const WRAP_CLASS = '/(?<![\w:-])(wrap-anywhere|break-all|truncate|\[overflow-wrap:anywhere\])(?![\w-])/';

    /** @return array<string, string> 相対パス => ソース */
    private function views(): array
    {
        $views = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $views[str_replace(resource_path('views') . '/', '', $file->getPathname())] = file_get_contents($file->getPathname());
            }
        }
        ksort($views);

        return $views;
    }

    /**
     * 長い値を文字として出す箇所。
     *
     * @return list<array{line: int, field: string, tag: string, wraps: bool, inTable: bool}>
     */
    private function sites(string $src): array
    {
        // Blade のコメント・script・style・@php は、位置を保ったまま空白にする（行番号を合わせるため）
        $blank = fn (array $m) => preg_replace('/[^\n]/', ' ', $m[0]);
        $s = preg_replace_callback('/\{\{--.*?--\}\}/s', $blank, $src);
        $s = preg_replace_callback('/<(script|style)\b[^>]*>.*?<\/\1\s*>/si', $blank, $s);
        $s = preg_replace_callback('/@php\b.*?@endphp/s', $blank, $s);

        $wrapClasses = $this->styleWrapClasses($src);

        preg_match_all(self::TAG, $s, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        preg_match_all('/\{\{(?!--)(.*?)\}\}|\{!!(.*?)!!\}|(?<![\w:-])x-text="([^"]*)"/s', $s, $echoes, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $sites = [];
        foreach ($echoes as $echo) {
            $isXText = isset($echo[3]) && $echo[3][1] >= 0;
            $expr = $isXText ? $echo[3][0] : (($echo[1][0] ?? '') . ($echo[2][0] ?? ''));
            if (! preg_match('/(?:->|\.|\[\')(' . self::FIELDS . ')\b/', $expr, $field)) {
                continue;
            }
            $pos = $echo[0][1];

            $ownTag = null;
            foreach ($tags as $tag) {
                if ($tag[0][1] < $pos && $pos < $tag[0][1] + strlen($tag[0][0])) {
                    $ownTag = $tag;
                    break;
                }
            }
            if ($ownTag !== null && ! $isXText) {
                continue; // 属性の値（value="" や href="mailto:"）は文字として出ない
            }

            $stack = [];
            foreach ($tags as $tag) {
                if ($tag[0][1] >= $pos) {
                    break;
                }
                $name = strtolower($tag[2][0]);
                if ($tag[1][0] === '/') {
                    for ($i = count($stack) - 1; $i >= 0; $i--) {
                        if ($stack[$i]['name'] === $name) {
                            array_splice($stack, $i);
                            break;
                        }
                    }
                } elseif (! in_array($name, self::VOID_ELEMENTS, true) && ! str_ends_with(rtrim($tag[3][0]), '/')) {
                    $stack[] = ['name' => $name, 'attrs' => $tag[3][0]];
                }
            }

            $element = $isXText
                ? ['name' => strtolower($ownTag[2][0]), 'attrs' => $ownTag[3][0]]
                : (end($stack) ?: ['name' => '', 'attrs' => '']);
            if (in_array($element['name'], self::NOT_RENDERED_AS_TEXT, true)) {
                continue;
            }

            $sites[] = [
                'line' => substr_count(substr($src, 0, $pos), "\n") + 1,
                'field' => $field[1],
                'tag' => $element['name'],
                'wraps' => $this->wraps($element['attrs'], $wrapClasses),
                'inTable' => in_array('table', array_column($stack, 'name'), true) || in_array($element['name'], ['td', 'th'], true),
            ];
        }

        return $sites;
    }

    /** @return list<string> そのビューの <style> で折り返しか省略表示を持つクラス（単独のクラスのセレクタだけ） */
    private function styleWrapClasses(string $src): array
    {
        $classes = [];
        preg_match_all('/<style\b[^>]*>(.*?)<\/style\s*>/si', $src, $styles);
        foreach ($styles[1] as $css) {
            $css = preg_replace('/\/\*.*?\*\//s', '', $css);
            preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
            foreach ($rules as $rule) {
                if (! preg_match(self::WRAP_DECLARATION, $rule[2])) {
                    continue;
                }
                foreach (explode(',', $rule[1]) as $selector) {
                    if (preg_match('/^\.([\w-]+)$/', trim($selector), $m)) {
                        $classes[] = $m[1];
                    }
                }
            }
        }

        return $classes;
    }

    /** @param list<string> $wrapClasses */
    private function wraps(string $attrs, array $wrapClasses): bool
    {
        if (preg_match('/\sstyle="([^"]*)"/', $attrs, $style) && preg_match(self::WRAP_DECLARATION, $style[1])) {
            return true;
        }
        if (! preg_match('/\sclass="([^"]*)"/', $attrs, $class)) {
            return false;
        }

        return preg_match(self::WRAP_CLASS, $class[1]) === 1
            || array_intersect(preg_split('/\s+/', trim($class[1])), $wrapClasses) !== [];
    }

    /** @return array<string, list<array{line: int, field: string, tag: string, wraps: bool, inTable: bool}>> */
    private function allSites(): array
    {
        $all = [];
        foreach ($this->views() as $path => $src) {
            if ($sites = $this->sites($src)) {
                $all[$path] = $sites;
            }
        }

        return $all;
    }

    public function test_the_scan_finds_long_values_inside_and_outside_tables(): void
    {
        $outside = $inside = 0;
        foreach ($this->allSites() as $sites) {
            foreach ($sites as $site) {
                $site['inTable'] ? $inside++ : $outside++;
            }
        }

        $this->assertGreaterThanOrEqual(self::MIN_SITES_OUTSIDE_TABLES, $outside, "表の外の長い値を {$outside} か所しか拾えていない（走査が空振りしている）");
        $this->assertGreaterThanOrEqual(self::MIN_SITES_IN_TABLES, $inside, "表の中の長い値を {$inside} か所しか拾えていない（表の判定が壊れている）");
    }

    public function test_every_long_value_outside_a_table_can_wrap(): void
    {
        $offenders = [];
        foreach ($this->allSites() as $path => $sites) {
            if (isset(self::EXEMPT[$path])) {
                continue;
            }
            foreach ($sites as $site) {
                if (! $site['inTable'] && ! $site['wraps']) {
                    $offenders[] = "{$path}:{$site['line']} {$site['field']}（<{$site['tag']}>）";
                }
            }
        }

        $this->assertSame([], $offenders, "空白の無い長い値を出す要素に折り返し（overflow-wrap: anywhere / word-break: break-all）も省略表示も無い（狭い画面で枠から切れる・はみ出す）:\n" . implode("\n", $offenders));
    }

    public function test_exemptions_match_the_views_exactly(): void
    {
        $all = $this->allSites();
        $mismatch = [];
        foreach (self::EXEMPT as $path => [$count, $reason]) {
            $this->assertNotSame('', $reason, "{$path} の例外に理由が無い");
            $unwrapped = count(array_filter($all[$path] ?? [], fn ($site) => ! $site['inTable'] && ! $site['wraps']));
            if ($unwrapped !== $count) {
                $mismatch[] = "{$path}: 例外は {$count} か所・実際は {$unwrapped} か所";
            }
        }

        $this->assertSame([], $mismatch, "例外の件数が実際と合わない（新しい箇所が増えたか、直して例外が古くなった）:\n" . implode("\n", $mismatch));
    }

    // ---- 見本（検出の分かれ目を 1 つずつ固定する。実物に無い書き方は、消しても走査の本番が緑のまま） ----

    /** @return list<string> 「field:tag:wraps:inTable」 */
    private function describe(string $src): array
    {
        return array_map(
            fn ($site) => "{$site['field']}:{$site['tag']}:" . ($site['wraps'] ? 'wraps' : 'no') . ':' . ($site['inTable'] ? 'table' : 'out'),
            $this->sites($src)
        );
    }

    public function test_sample_echo_forms_are_found(): void
    {
        $this->assertSame(['email:dd:no:out'], $this->describe('<dl><dd>{{ $buyer->email }}</dd></dl>'));
        $this->assertSame(['email:dd:no:out'], $this->describe('<dd>{{ $buyer?->email ?: \'—\' }}</dd>'));
        $this->assertSame(['notes:div:no:out'], $this->describe('<div>{!! nl2br(e($p->notes)) !!}</div>'));
        $this->assertSame(['email:td:no:table'], $this->describe('<table><tr><td>{{ $row[\'email\'] }}</td></tr></table>'));
        $this->assertSame(['file_name:a:no:out'], $this->describe('<a :href="file.file_path" x-text="file.file_name"></a>'));
        $this->assertSame([], $this->describe('<div>{{ $buyer->email_verified_at }} {{ $p->notes_count }}</div>'), '名前の続きの語まで拾っている');
        $this->assertSame([], $this->describe('<div>{{ $buyer->name }}</div>'), '長い値でない項目まで拾っている');
    }

    public function test_sample_values_not_rendered_as_text_are_skipped(): void
    {
        $this->assertSame([], $this->describe('<input type="email" value="{{ old(\'email\', $b->email) }}">'), '属性の値を拾っている');
        $this->assertSame([], $this->describe('<a href="mailto:{{ $c->email }}" title="{{ $c->notes }}">連絡</a>'), '属性の値を拾っている');
        $this->assertSame([], $this->describe('<textarea name="notes">{{ old(\'notes\', $c->notes) }}</textarea>'), 'textarea の中を拾っている');
        $this->assertSame([], $this->describe('<select><option>{{ $u->email }}</option></select>'), 'option の中を拾っている');
        $this->assertSame([], $this->describe('{{-- {{ $c->email }} --}}<div></div>'), 'Blade のコメントの中を拾っている');
        $this->assertSame([], $this->describe("<script>var x = '{{ \$c->email }}';</script>"), 'script の中を拾っている');
        $this->assertSame([], $this->describe("@php \$x = '<div>{{ \$c->notes }}</div>'; @endphp"), '@php の中を拾っている');
    }

    public function test_sample_the_innermost_element_decides(): void
    {
        $this->assertSame(['email:a:no:out'], $this->describe('<div class="wrap-anywhere"><a href="mailto:x">{{ $c->email }}</a></div>'), '外側の要素の折り返しを数えている');
        $this->assertSame(['notes:div:wraps:out'], $this->describe('<div class="wrap-anywhere"><span>備考:</span> {{ $o->notes }}</div>'), '閉じた span を内側と数えている');
        $this->assertSame(['memo:div:wraps:out'], $this->describe('<div class="wrap-anywhere"><br><img src="x"><x-icon name="a" /> {{ $o->memo }}</div>'), '空要素・自分で閉じる部品を開いたままと数えている');
        $this->assertSame(['memo:div:wraps:out'], $this->describe('<div x-on:click="open = count > 0" class="wrap-anywhere">{{ $o->memo }}</div>'), '属性の中の > でタグを切っている');
    }

    public function test_sample_wrapping_forms_are_recognized(): void
    {
        $this->assertSame(['email:dd:wraps:out'], $this->describe('<dd class="px-3 wrap-anywhere">{{ $c->email }}</dd>'));
        $this->assertSame(['email:dd:wraps:out'], $this->describe('<dd class="break-all">{{ $c->email }}</dd>'));
        $this->assertSame(['email:dd:wraps:out'], $this->describe('<dd class="[overflow-wrap:anywhere]">{{ $c->email }}</dd>'));
        $this->assertSame(['email:span:wraps:out'], $this->describe('<span class="truncate">{{ $c->email }}</span>'));
        $this->assertSame(['email:dd:wraps:out'], $this->describe('<dd style="padding: 10px; overflow-wrap: anywhere;">{{ $c->email }}</dd>'));
        $this->assertSame(['email:dd:wraps:out'], $this->describe('<dd style="word-break: break-all">{{ $c->email }}</dd>'));
        $this->assertSame(['email:span:wraps:out'], $this->describe('<span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $c->email }}</span>'));
        $this->assertSame(
            ['email:div:wraps:out', 'memo:div:no:out'],
            $this->describe("<style>\n/* .x-value { color: red } */\n.x-label, .x-value { overflow-wrap: anywhere; }\n.x-row .x-other { overflow-wrap: anywhere; }\n</style><div class=\"x-value\">{{ \$c->email }}</div><div class=\"x-other\">{{ \$c->memo }}</div>"),
            '<style> のクラスの読み方がずれた（単独のクラスのセレクタだけを認める）'
        );
    }

    public function test_sample_lookalikes_are_not_wrapping(): void
    {
        $this->assertSame(['email:dd:no:out'], $this->describe('<dd class="break-words whitespace-pre-wrap">{{ $c->email }}</dd>'), 'break-words を折り返しと数えている（値の最小の幅を縮めない）');
        $this->assertSame(['email:dd:no:out'], $this->describe('<dd style="overflow-wrap: break-word">{{ $c->email }}</dd>'), 'break-word を折り返しと数えている');
        $this->assertSame(['email:dd:no:out'], $this->describe('<dd class="sm:wrap-anywhere-x not-truncate">{{ $c->email }}</dd>'), 'クラス名の一部を折り返しと数えている');
        $this->assertSame(['email:dd:no:out'], $this->describe('<dd data-note="overflow-wrap: anywhere">{{ $c->email }}</dd>'), 'style 以外の属性を見ている');
    }

    public function test_sample_table_cells_are_told_apart(): void
    {
        $this->assertSame(['notes:span:no:table'], $this->describe('<table><tbody><template x-for="c in costs"><tr><td><span x-text="c.notes"></span></td></tr></template></tbody></table>'));
        $this->assertSame(['notes:div:no:out'], $this->describe('<table><tr><td>1</td></tr></table><div>{{ $p->notes }}</div>'), '閉じた表の後ろを表の中と数えている');
    }
}
