<?php

namespace Tests\Feature\Admin\Master;

use App\Models\SurveyQuestion;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SendsValuesBack;

/**
 * アンケート設問の管理の一覧（Admin\SurveyQuestionController の index・move・destroy）。住宅事業・不動産事業の 2 つのタブ。
 * 並び替えは行の ↑↓（ふつうのフォーム。押すたびに保存して一覧を開き直す）、削除は行の削除のフォーム（確認のあと送る）。
 * 追加・編集は別の画面（SurveyQuestionCreateTest・SurveyQuestionEditTest）。
 *
 * ⚠ 送る値は描いた一覧のフォームから組む（Bug #47）。↑ と ↓ のフォームは送り先が同じなので、hidden の向きで選ぶ。
 * ⚠ 番号は有効な設問だけの通し番号（CSV の Q の番号・アンケートの画面の並びとそろう）。端（↑・↓ を押せない行）は
 *   無効の設問も含めた部署の並びで決める（無効の設問とも入れ替わる）。
 */
class SurveyQuestionMasterTest extends SurveyQuestionScreenTestCase
{
    use SendsValuesBack;

    private const BANNER = 'ℹ️ 選択肢の名前を変えても、過去の回答は元の名前のまま残ります（アンケートの編集画面では「（選択肢に無い値）」と出ます）。回答がある設問は、種類を変えられません。';

    /** 設問を 1 つ作る（並びは部署の末尾。$attributes で上書き） */
    private function question(string $label, array $attributes = []): SurveyQuestion
    {
        $department = $attributes['department'] ?? 'housing';

        return SurveyQuestion::create(array_merge([
            'department'    => $department,
            'label'         => $label,
            'question_type' => 'text',
            'options'       => null,
            'sort_order'    => (int) SurveyQuestion::ofDepartment($department)->max('sort_order') + 1,
            'is_active'     => true,
        ], $attributes));
    }

    /** 部署の設問の並び（sort_order・id の順の設問文） */
    private function order(string $department): array
    {
        return SurveyQuestion::ofDepartment($department)->orderBy('sort_order')->orderBy('id')->pluck('label')->all();
    }

    /** 一覧のタブの中身（`id="q-list-…"` の要素） */
    private function pane(string $html, string $department): string
    {
        $pos = strpos($html, 'id="q-list-' . $department . '"');
        $this->assertNotFalse($pos, "{$department} のタブが描かれていない");

        return $this->balancedElement($html, strrpos(substr($html, 0, $pos), '<div'), 'div');
    }

    /** タブの中の行の見出し（並びのまま。「番号. 設問文」か、無効の設問は設問文だけ） */
    private function rowLabels(string $html, string $department): array
    {
        preg_match_all('/<div class="sq-row-label">(.*?)<\/div>/su', $this->pane($html, $department), $m);

        return array_map(fn (string $label): string => html_entity_decode($label, ENT_QUOTES, 'UTF-8'), $m[1]);
    }

    /** 設問の行（`id="question-…"` の要素） */
    private function row(string $html, SurveyQuestion $question): string
    {
        $pos = strpos($html, 'id="question-' . $question->id . '"');
        $this->assertNotFalse($pos, "設問「{$question->label}」の行が無い");

        return $this->balancedElement($html, strrpos(substr($html, 0, $pos), '<div'), 'div');
    }

    /** 行の ↑（up）か ↓（down）のフォーム（ちょうど 1 つ） */
    private function moveForm(string $html, SurveyQuestion $question, string $direction): array
    {
        $action = 'action="' . route('admin.survey-questions.move', $question) . '"';
        preg_match_all('/<form\b[^>]*>.*?<\/form>/su', $this->row($html, $question), $forms);
        $found = [];
        foreach ($forms[0] as $form) {
            if (str_contains($form, $action)) {
                $parsed = $this->parseForm($form, $action);
                if (($parsed['fields']['direction'] ?? null) === $direction) {
                    $found[] = $parsed;
                }
            }
        }
        $this->assertCount(1, $found, "設問「{$question->label}」の行に {$direction} のフォームがちょうど 1 つ無い");
        $this->assertSame('POST', $found[0]['method']);
        $this->assertNotSame('', $found[0]['fields']['_token'] ?? '', '並び替えのフォームに @csrf が無い');

        return $found[0];
    }

    /** 描いた一覧から設問の ↑↓ を押し、着いた画面の HTML を返す（行き先は一覧の同じタブのその設問の行） */
    private function press(SurveyQuestion $question, string $direction, string $from): string
    {
        $response = $this->send($this->moveForm($this->htmlOf($from), $question, $direction), $from);
        $this->assertSame($this->indexUrl($question->department) . '#question-' . $question->id, $response->headers->get('Location'));

        return $this->landed($response);
    }

    /** 行の削除のフォーム */
    private function deleteForm(string $html, SurveyQuestion $question): array
    {
        $form = $this->parseForm($this->row($html, $question), 'action="' . route('admin.survey-questions.destroy', $question) . '"');
        $this->assertSame('DELETE', $form['method']);
        $this->assertNotSame('', $form['fields']['_token'] ?? '', '削除のフォームに @csrf が無い');

        return $form;
    }

    /** タブの要素が最初から隠れているか（サーバーが描く display: none。Alpine が起きる前・#question- への移動のため） */
    private function paneHidden(string $html, string $department): bool
    {
        $pane = $this->pane($html, $department);

        return str_contains(substr($pane, 0, strpos($pane, '>')), 'display: none');
    }

    public function test_the_list_shows_each_departments_questions_and_opens_the_requested_tab(): void
    {
        $this->question('来場のきっかけ', ['question_type' => 'single_select', 'options' => ['チラシ', 'Web']]);
        $this->question('ご予算', ['question_type' => 'number']);
        $this->question('希望エリア', ['department' => 'realestate']);

        $html = $this->htmlOf($this->indexUrl());
        $this->assertSame(['1. 来場のきっかけ', '2. ご予算'], $this->rowLabels($html, 'housing'));
        $this->assertStringContainsString('選択肢: 2個', $this->pane($html, 'housing'));
        $this->assertSame(['1. 希望エリア'], $this->rowLabels($html, 'realestate'));

        foreach (['housing' => $html, 'realestate' => $this->htmlOf($this->indexUrl('realestate'))] as $department => $page) {
            $run = $this->driveAlpine($page, 'surveyQuestionManager', $this->xData($page, 'surveyQuestionManager'), '');
            $this->assertSame($department, $run['state']['activeTab']);
            $other = $department === 'housing' ? 'realestate' : 'housing';
            $this->assertFalse($this->paneHidden($page, $department), "{$department} のタブが最初から隠れている");
            $this->assertTrue($this->paneHidden($page, $other), "{$other} のタブが最初から出ている");
        }

        $run = $this->driveAlpine($html, 'surveyQuestionManager', $this->xData($html, 'surveyQuestionManager'), "data.switchTab('realestate');");
        $this->assertSame('realestate', $run['state']['activeTab']);
    }

    /** 番号は有効な設問だけ・回答があれば件数・案内文は今の動き（☰ とドラッグの文は無い） */
    public function test_the_list_numbers_only_active_questions_and_shows_answer_counts(): void
    {
        $first = $this->question('ご来場のきっかけ');
        $inactive = $this->question('現在のお住まい', ['is_active' => false]);
        $third = $this->question('ご予算');
        $this->question('希望エリア', ['department' => 'realestate']);
        $this->answer($first, 'チラシ');
        $this->answer($first, 'Web');
        foreach (range(1, 3) as $i) {
            $this->answer($inactive, "賃貸{$i}");
        }

        $html = $this->htmlOf($this->indexUrl());

        $this->assertSame(['1. ご来場のきっかけ', '現在のお住まい', '2. ご予算'], $this->rowLabels($html, 'housing'));
        $this->assertSame(['1. 希望エリア'], $this->rowLabels($html, 'realestate'), '番号が部署ごとに 1 から始まらない');
        $this->assertStringContainsString('回答 2件', $this->row($html, $first));
        $this->assertStringContainsString('回答 3件', $this->row($html, $inactive));
        $this->assertDoesNotMatchRegularExpression('/回答 \d+件/u', $this->row($html, $third), '回答の無い設問に件数が出ている');

        $openTag = fn (string $row): string => substr($row, 0, strpos($row, '>') + 1);
        $this->assertStringContainsString('sq-row-off', $openTag($this->row($html, $inactive)), '無効の設問の行が薄くならない');
        $this->assertStringNotContainsString('sq-row-off', $openTag($this->row($html, $first)));
        $this->assertStringContainsString('>無効</span>', $this->row($html, $inactive));

        $this->assertStringContainsString(e(self::BANNER), $html, '案内文が今の動きの文でない');
        $this->assertStringNotContainsString('☰', $html);
        $this->assertStringNotContainsString('ドラッグ', $html);
        $this->assertStringNotContainsString('スナップショット', $html);
    }

    public function test_the_arrows_move_a_question_and_keep_the_tab(): void
    {
        $a = $this->question('A');
        $this->question('B');
        $this->question('C');
        $this->question('D', ['department' => 'realestate']);
        $e = $this->question('E', ['department' => 'realestate']);

        $html = $this->press($a, 'down', $this->indexUrl());
        $this->assertSame(['B', 'A', 'C'], $this->order('housing'));
        $this->assertSame(['1. B', '2. A', '3. C'], $this->rowLabels($html, 'housing'));
        $this->assertStringNotContainsString('<span class="text-sm text-emerald-800">', $html, '↑↓ で成功の帯が出ている');
        $this->assertStringNotContainsString('<span class="text-sm text-red-800">', $html);

        $html = $this->press($e, 'up', $this->indexUrl('realestate'));
        $this->assertSame(['E', 'D'], $this->order('realestate'));
        $this->assertSame('realestate', $this->driveAlpine($html, 'surveyQuestionManager', $this->xData($html, 'surveyQuestionManager'), '')['state']['activeTab']);
        $this->assertFalse($this->paneHidden($html, 'realestate'), '不動産事業のタブに戻らない');
        $this->assertSame(['1. E', '2. D'], $this->rowLabels($html, 'realestate'));
        $this->assertSame(['B', 'A', 'C'], $this->order('housing'));
    }

    /** 端は無効の設問も含めた並びで決める。押せない ↑↓ は disabled で、理由はボタンを包む span の title（Top trap #12） */
    public function test_the_edge_arrows_are_disabled_with_a_reason(): void
    {
        $first = $this->question('先頭の設問');
        $middle = $this->question('真ん中の設問');
        $last = $this->question('末尾の無効の設問', ['is_active' => false]);
        $alone = $this->question('ひとつだけの設問', ['department' => 'realestate']);

        $html = $this->htmlOf($this->indexUrl());
        $disabled = function (string $row, string $reason, string $arrow): void {
            $pattern = '/<span title="' . $reason . '"[^>]*>\s*<button\b([^>]*)>\s*' . $arrow . '\s*<\/button>\s*<\/span>/u';
            $this->assertSame(1, preg_match($pattern, $row, $button), "「{$reason}」の span に包まれた {$arrow} のボタンが無い");
            $this->assertMatchesRegularExpression('/\sdisabled\b/', $button[1], "{$arrow} のボタンが押せる");
            $this->assertStringNotContainsString('title=', $button[1], '押せないボタン自身の title はどのブラウザでも出ない（Top trap #12）');
        };

        $disabled($this->row($html, $first), '先頭の設問です', '↑');
        $this->moveForm($html, $first, 'down');
        $this->moveForm($html, $middle, 'up');
        $this->moveForm($html, $middle, 'down');
        $this->moveForm($html, $last, 'up');
        $disabled($this->row($html, $last), '末尾の設問です', '↓');
        $this->assertStringNotContainsString('先頭の設問です', $this->row($html, $middle));
        $this->assertStringNotContainsString('末尾の設問です', $this->row($html, $middle), '無効の設問が末尾なのに、その前の有効の設問の ↓ が押せない');

        $alonePage = $this->htmlOf($this->indexUrl('realestate'));
        $disabled($this->row($alonePage, $alone), '先頭の設問です', '↑');
        $disabled($this->row($alonePage, $alone), '末尾の設問です', '↓');
        $this->assertStringNotContainsString('action="' . route('admin.survey-questions.move', $alone) . '"', $this->row($alonePage, $alone),
            '1 つだけの設問の行に ↑↓ のフォームがある');
    }

    /** 無効の設問とも入れ替わり、番号は有効な設問だけで振り直される。並びは 1 から振り直す（Review Focus 4） */
    public function test_moving_passes_over_inactive_questions_and_numbers_only_active_ones(): void
    {
        $a = $this->question('A', ['sort_order' => 5]);
        $b = $this->question('B', ['sort_order' => 5, 'is_active' => false]);
        $c = $this->question('C', ['sort_order' => 9]);

        $this->assertSame(['1. A', 'B', '2. C'], $this->rowLabels($this->htmlOf($this->indexUrl()), 'housing'));

        $html = $this->press($c, 'up', $this->indexUrl());
        $this->assertSame(['A', 'C', 'B'], $this->order('housing'));
        $this->assertSame(['1. A', '2. C', 'B'], $this->rowLabels($html, 'housing'));
        $this->assertSame([1, 2, 3], SurveyQuestion::whereKey([$a->id, $c->id, $b->id])->orderBy('sort_order')->pluck('sort_order')->all());

        $html = $this->press($c, 'up', $this->indexUrl());
        $this->assertSame(['C', 'A', 'B'], $this->order('housing'));
        $this->assertSame(['1. C', '2. A', 'B'], $this->rowLabels($html, 'housing'));

        $html = $this->press($b, 'up', $this->indexUrl());
        $this->assertSame(['C', 'B', 'A'], $this->order('housing'));
        $this->assertSame(['1. C', 'B', '2. A'], $this->rowLabels($html, 'housing'));
    }

    /** 手で組んだ端の ↑・↓ は理由を帯に出して断る（並びは変わらない。Review Focus 4） */
    public function test_moving_past_the_edge_is_refused(): void
    {
        $first = $this->question('A', ['sort_order' => 3]);
        $last = $this->question('B', ['sort_order' => 8]);

        foreach ([[$first, 'up', '先頭の設問は上へ動かせません。'], [$last, 'down', '末尾の設問は下へ動かせません。']] as [$question, $direction, $message]) {
            $response = $this->actingAs($this->user)->from($this->indexUrl())
                ->post(route('admin.survey-questions.move', $question), ['direction' => $direction]);

            $this->assertSame($this->indexUrl('housing') . '#question-' . $question->id, $response->headers->get('Location'));
            $this->assertFlash($this->landed($response), 'error', $message);
            $this->assertSame(['A', 'B'], $this->order('housing'));
            $this->assertSame([3, 8], SurveyQuestion::ofDepartment('housing')->orderBy('id')->pluck('sort_order')->all(), '断ったのに並びを書き換えた');
        }
    }

    public function test_moving_never_touches_the_other_department(): void
    {
        $a = $this->question('A');
        $this->question('B');
        $x = $this->question('X', ['department' => 'realestate', 'sort_order' => 7]);
        $y = $this->question('Y', ['department' => 'realestate', 'sort_order' => 2]);

        $this->press($a, 'down', $this->indexUrl());
        $this->assertSame(['B', 'A'], $this->order('housing'));
        $this->assertSame([7, 2], [$x->fresh()->sort_order, $y->fresh()->sort_order], '別の部署の並びを書き換えた');

        $this->press($x, 'up', $this->indexUrl('realestate'));
        $this->assertSame(['X', 'Y'], $this->order('realestate'));
        $this->assertSame(['B', 'A'], $this->order('housing'));
    }

    public function test_a_question_without_answers_is_deleted_with_the_delete_form(): void
    {
        $this->question('希望エリア', ['department' => 'realestate']);
        $question = $this->question('ご予算', ['department' => 'realestate']);
        $from = $this->indexUrl('realestate');

        $response = $this->send($this->deleteForm($this->htmlOf($from), $question), $from);

        $response->assertRedirect($this->indexUrl('realestate'));
        $html = $this->landed($response);
        $this->assertFlash($html, 'success', '設問を削除しました。');
        $this->assertNull(SurveyQuestion::find($question->id));
        $this->assertSame(['1. 希望エリア'], $this->rowLabels($html, 'realestate'));
    }

    /** 回答がある設問は消さずに無効にする（過去の回答の控えを残すため。編集画面で有効に戻せる） */
    public function test_a_question_with_answers_is_deactivated_instead(): void
    {
        $question = $this->question('来場のきっかけ');
        $this->question('ご予算');
        $this->answer($question, 'Web');

        $response = $this->send($this->deleteForm($this->htmlOf($this->indexUrl()), $question), $this->indexUrl());

        $response->assertRedirect($this->indexUrl('housing'));
        $html = $this->landed($response);
        $this->assertFlash($html, 'success', '回答があるため、設問を無効にしました（編集画面で有効に戻せます）。');
        $this->assertNotNull($question->fresh(), '回答がある設問が消えた');
        $this->assertFalse($question->fresh()->is_active);
        $this->assertSame(['来場のきっかけ', '1. ご予算'], $this->rowLabels($html, 'housing'));
        $this->assertStringContainsString('回答 1件', $this->row($html, $question));
    }

    /** 削除の確認に設問文がそのまま出る（壊れやすい文字でも JS が組める・「いいえ」なら送らない。Bug #76） */
    #[DataProvider('labels')]
    public function test_the_delete_confirmation_names_the_question(string $label): void
    {
        $question = $this->question($label);
        $row = $this->row($this->htmlOf($this->indexUrl()), $question);
        $action = 'action="' . route('admin.survey-questions.destroy', $question) . '"';
        $pos = strpos($row, $action);
        $this->assertNotFalse($pos, '行に削除のフォームが無い');
        $open = strrpos(substr($row, 0, $pos), '<form');
        $tag = substr($row, $open, strpos($row, '>', $pos) - $open + 1);
        $this->assertSame(1, preg_match('/\sonsubmit="([^"]*)"/', $tag, $m), '削除のフォームに onsubmit が無い');
        $handler = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

        $declined = $this->runInlineHandler($handler, false);
        $this->assertTrue($declined['compiled'], "削除の確認の JS が組み立てられない: {$declined['error']}");
        $this->assertSame(false, $declined['returned'], '確認で「いいえ」を選んでも送信が止まらない');
        $this->assertSame(['「' . $label . '」を削除しますか？回答がある設問は無効にします。'], $declined['confirms']);
        $this->assertSame(true, $this->runInlineHandler($handler, true)['returned'], '確認で「はい」を選んでも送信されない');
    }

    public static function labels(): array
    {
        return [
            'ふつうの設問文'   => ['ご来場のきっかけを教えてください'],
            '壊れやすい文字'   => [self::tricky('O\'Brien様の設問')],
            '閉じて続ける'     => ["x');alert(1);('"],
        ];
    }

    /** 並び替えは ↑↓ のフォームだけ（部署も id も確かめなかった今までの reorder は外した） */
    public function test_the_reorder_route_is_gone(): void
    {
        $this->question('A');

        $this->assertFalse(Route::has('admin.survey-questions.reorder'));
        $this->assertContains($this->actingAs($this->user)->post('/admin/survey-questions/reorder', ['order' => [1]])->getStatusCode(), [404, 405]);
        $this->assertStringNotContainsString('reorder', $this->htmlOf($this->indexUrl()));
    }

    /** 手で組んだ向きは理由を一覧の箱に出して断る（500 にしない・並びは変わらない。Review Focus 5） */
    public function test_tampered_move_and_form_posts_do_not_crash(): void
    {
        $this->question('X', ['department' => 'realestate']);
        $question = $this->question('Y', ['department' => 'realestate']);
        $from = $this->indexUrl('realestate');
        $form = $this->moveForm($this->htmlOf($from), $question, 'up');

        foreach ([
            ['sideways', '選択された動かす向きは正しくありません。'],
            // 配列は ShapeCheckingValidator が先に断る（文は型の規則 in の文）
            [['up'], '選択された動かす向きは正しくありません。'],
            [null, '動かす向きは必須です。'],
        ] as [$direction, $message]) {
            $sent = $form;
            if ($direction === null) {
                unset($sent['fields']['direction']);
            } else {
                $sent['fields']['direction'] = $direction;
            }
            $response = $this->send($sent, $from);

            $response->assertRedirect($from);
            $html = $this->landed($response);
            $this->assertSame([$message], $this->errorItems($html), json_encode($direction));
            $this->assertFalse($this->paneHidden($html, 'realestate'));
            $this->assertSame(['X', 'Y'], $this->order('realestate'));
        }

        $this->actingAs($this->user)->from($from)->post(route('admin.survey-questions.move', 999999), ['direction' => 'up'])->assertNotFound();
    }

    /**
     * 開くタブ（`?department=`）を URL で書き換えても画面が壊れない（Bug #97 の形）。
     * ⚠ 直す前は値を確かめずに JS の文字列に直に書いていて、末尾に `\` を付けると 200 なのに画面の JS が止まり、
     *   `?department[]=1` で 500 だった（2026-10-26 に実測）。2 つの部署のほかは住宅事業のタブを開く。
     */
    public function test_a_rewritten_department_in_the_url_opens_the_housing_tab(): void
    {
        $this->question('来場のきっかけ');

        foreach ([
            [['department' => 'realestate'], 'realestate'],
            [['department' => 'housing'], 'housing'],
            [['department' => 'x\\'], 'housing'],
            [['department' => ['realestate']], 'housing'],
        ] as [$query, $expected]) {
            $url = route('admin.survey-questions.index') . '?' . http_build_query($query);
            $response = $this->actingAs($this->user)->get($url);
            $this->assertSame(200, $response->getStatusCode(), "{$url} が 200 でない");
            $html = $response->getContent();

            $run = $this->driveAlpine($html, 'surveyQuestionManager', $this->xData($html, 'surveyQuestionManager'), '', [], true, ['activeTab']);
            $this->assertSame([$expected], $run['evaluated'], "{$url} で開くタブが違う");
            $this->assertFalse($this->paneHidden($html, $expected), "{$url} で開くタブが最初から隠れている");
        }
    }
}
