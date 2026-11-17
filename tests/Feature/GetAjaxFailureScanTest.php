<?php

namespace Tests\Feature;

use Tests\Concerns\ScansSameOriginGetAjax;
use Tests\TestCase;

/**
 * 自社へ GET する Ajax が断られたとき、文を共通の部品（_partials/_ajax_get_failure）で作ることを全件で固定する
 * （設計メモ 2026-11-17-get-ajax-failure-design.md）。
 *
 * GET は送信の鍵を確かめないので、ログインが切れたときは 419 ではなく英語の 401 になる。部品を通さない分岐は
 * 「空き区画の取得に失敗しました（401）」と番号だけを出す（直す前の 23 か所）。
 *
 * ⚠ 呼び出し側（`ajaxGetFailure(` を呼ぶ分岐）と定義側（部品の script）を対で見る（Bug #28）。部品を読み込まないと、
 *   ブラウザでは関数が無くて止まるのに、HTML の上では何も壊れて見えない。
 * ⚠ 死角:
 *   - fetch の後ろで最初に現れる `.ok` の分岐を、その fetch の分岐とみなす。分岐を持たない fetch の後ろに別の fetch の
 *     分岐があると、そちらを見る（分岐を持たない fetch は AjaxErrorFeedbackTest が止める）
 *   - 呼ぶ画面のファイル自身に include があるかを見る（親の画面が読み込んでいても数えない）
 */
class GetAjaxFailureScanTest extends TestCase
{
    use ScansSameOriginGetAjax;

    private const PARTIAL = '_partials/_ajax_get_failure.blade.php';

    private const INCLUDE = "@include('_partials._ajax_get_failure')";

    /** 部品を通さなくてよい GET（ファイル => 理由）。実在しない・GET を持たない分類は落とす */
    private const EXEMPT = [
        'approvals/requests/form.blade.php' => '決裁の画面。サーバーが日本語の 401 を返す（bootstrap/app.php）・担当は「社内決裁申請」の会話',
        '_partials/_schedule_section.blade.php' => 'send() は保存（POST・PUT・DELETE）に使い、GET では呼ばない（method を変数で渡すので、走査は GET と数える）',
    ];

    /** `if (!res.ok)` `if (!roomsRes.ok || …)`（AjaxErrorFeedbackTest::OK_GUARD と同じ形） */
    private const OK_GUARD = '/if\s*\(\s*![a-zA-Z_$][\w$]*\.ok\b/';

    /** 拾えるはずの数の下限（2026-11-17 の実数: 分岐 21・XMLHttpRequest 2・呼ぶファイル 14）。走査が空振りして緑になるのを防ぐ */
    private const MIN_GUARDS = 21;

    private const MIN_XHRS = 2;

    private const MIN_CALLERS = 14;

    /**
     * fetch ごとに、後ろで最初に現れる `.ok` の分岐を切り出し、`ajaxGetFailure(` を通らないものの行を返す。
     *
     * @param  array<int, array{pos: int, line: int, call: string}>  $fetches
     * @return array{guards: array<int, int>, offenders: array<int, int>}  guards は分岐の位置（重複なし）
     */
    private static function fetchFailureOffenders(string $source, array $fetches): array
    {
        $guards = [];
        $offenders = [];

        foreach ($fetches as $fetch) {
            if (! preg_match(self::OK_GUARD, $source, $m, PREG_OFFSET_CAPTURE, $fetch['pos'] + strlen($fetch['call']))) {
                $offenders[] = $fetch['line'];
                continue;
            }
            $guard = $m[0][1];
            $guards[$guard] = $guard;
            $open = strpos($source, '{', $guard);
            $body = '';
            for ($depth = 0, $i = $open, $len = strlen($source); $open !== false && $i < $len; $i++) {
                if ($source[$i] === '{') {
                    $depth++;
                } elseif ($source[$i] === '}' && --$depth === 0) {
                    $body = substr($source, $open, $i - $open + 1);
                    break;
                }
            }
            if (! str_contains($body, 'ajaxGetFailure(')) {
                $offenders[] = $fetch['line'];
            }
        }

        return ['guards' => array_values($guards), 'offenders' => $offenders];
    }

    public function test_every_get_fetch_failure_goes_through_the_helper(): void
    {
        $byFile = [];
        foreach (self::sameOriginGetFetches() as $fetch) {
            $byFile[$fetch['file']]['source'] = $fetch['source'];
            $byFile[$fetch['file']]['fetches'][] = $fetch;
        }

        foreach (array_keys(self::EXEMPT) as $file) {
            $this->assertArrayHasKey($file, $byFile, "分類に書いた {$file} に自社への GET の fetch が無い（古い分類）");
        }

        $guards = 0;
        $offenders = [];
        foreach ($byFile as $file => $found) {
            if (array_key_exists($file, self::EXEMPT)) {
                continue;
            }
            $result = self::fetchFailureOffenders($found['source'], $found['fetches']);
            $guards += count($result['guards']);
            foreach ($result['offenders'] as $line) {
                $offenders[] = "{$file}:{$line}";
            }
        }

        $this->assertGreaterThanOrEqual(self::MIN_GUARDS, $guards, 'GET の fetch の失敗の分岐の走査が機能していない（拾えた分岐が少なすぎる）');
        $this->assertSame([], $offenders,
            "断られた GET の fetch の文を部品（ajaxGetFailure）で作っていない分岐があります。ログインが切れても番号だけが出ます:\n" . implode("\n", $offenders));
    }

    public function test_every_get_xhr_failure_goes_through_the_helper(): void
    {
        $xhrs = self::sameOriginGetXhrOpens();
        $offenders = [];
        foreach ($xhrs as $xhr) {
            if (! str_contains($xhr['block'], 'ajaxGetFailureMessage(')) {
                $offenders[] = $xhr['file'] . ':' . $xhr['line'];
            }
        }

        $this->assertGreaterThanOrEqual(self::MIN_XHRS, count($xhrs), 'GET の XMLHttpRequest の走査が機能していない（拾えた呼び出し箇所が少なすぎる）');
        $this->assertSame([], $offenders,
            "断られた GET の XMLHttpRequest の文を部品（ajaxGetFailureMessage）で作っていない所があります:\n" . implode("\n", $offenders));
    }

    public function test_every_caller_includes_the_helper(): void
    {
        $callers = 0;
        $missing = [];
        $definitions = [];
        foreach (self::bladeSources() as $file => $source) {
            if (str_contains($source, 'function ajaxGetFailure(') || str_contains($source, 'function ajaxGetFailureMessage(')) {
                $definitions[] = $file;
            }
            if ($file === self::PARTIAL || ! (str_contains($source, 'ajaxGetFailure(') || str_contains($source, 'ajaxGetFailureMessage('))) {
                continue;
            }
            $callers++;
            if (! str_contains($source, self::INCLUDE)) {
                $missing[] = $file;
            }
        }

        $this->assertSame([self::PARTIAL], $definitions, '部品の関数の定義が部品のほかにもある（または部品に無い）');
        $this->assertGreaterThanOrEqual(self::MIN_CALLERS, $callers, '部品の関数を呼ぶ画面の走査が機能していない');
        $this->assertSame([], $missing, "部品の関数を呼ぶのに部品を読み込んでいない画面があります（ブラウザでは関数が無くて止まります）:\n" . implode("\n", $missing));
    }

    /** 走査の分かれ目（実物に出てこない書き方も見本で固定する） */
    public function test_the_scanner_reads_each_way_of_writing(): void
    {
        // 部品を通さない分岐は、その fetch の行を名指しする
        $plain = "fetch('/api/a', { headers: {} })\n    .then(function (res) { if (!res.ok) { alert('a（' + res.status + '）'); return null; } return res.json(); });";
        $this->assertSame([1], self::fetchFailureOffenders($plain, self::sameOriginGetFetchesIn($plain))['offenders']);

        // 並んだ 2 本が 1 つの分岐（camelCase の変数・|| でつないだ条件）を共有する
        $paired = "const [roomsRes, parkingsRes] = await Promise.all([\n    fetch('/api/a', { headers: {} }),\n    fetch('/api/b', { headers: {} })\n]);\n"
            . "if (!roomsRes.ok || !parkingsRes.ok) { alert(await ajaxGetFailure(roomsRes, 'x', 'y')); return; }";
        $result = self::fetchFailureOffenders($paired, self::sameOriginGetFetchesIn($paired));
        $this->assertSame([], $result['offenders']);
        $this->assertCount(1, $result['guards'], '同じ分岐を 2 回数えている');

        // 部品を通す分岐と通さない分岐が並ぶと、通さないほうだけを名指しする（分岐の中身は波括弧の対応で切る）
        $mixed = "fetch('/api/a', {})\n.then(function (r) { if (!r.ok) { return ajaxGetFailure(r, 'a', 'b').then(function (m) { alert(m); return null; }); } return r.json(); });\n"
            . "fetch('/api/b', {})\n.then(function (r) { if (!r.ok) { alert('b'); return null; } return r.json(); });";
        $this->assertSame([3], self::fetchFailureOffenders($mixed, self::sameOriginGetFetchesIn($mixed))['offenders']);

        // 外部 API（://）と GET 以外の fetch は数えない
        $other = "fetch('https://zipcloud.ibsnet.co.jp/api/search?zipcode=7900001')\nfetch('/api/c', { method: 'POST' })";
        $this->assertSame([], self::sameOriginGetFetchesIn($other));
    }
}
