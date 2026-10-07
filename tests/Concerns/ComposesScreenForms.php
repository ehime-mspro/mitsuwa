<?php

namespace Tests\Concerns;

/**
 * 画面のテストで、入れ子のコンポーネントを持つフォームを組む・画面の JS が叩く JSON の API の応答を渡す（住宅事業と賃貸マンションで共用）。
 *
 * ⚠ 使う側は `$this->user`（叩く人）を持ち、ParsesForms・DrivesAlpineFetch と一緒に使う。
 */
trait ComposesScreenForms
{
    /**
     * フォームの中に入れ子のコンポーネント（`<div … x-data="名前(…)">`）を持つ画面のフォームを、ブラウザが送る項目で組む。
     * 入れ子の欄はそのコンポーネントの状態で、残りは親（$function か、インラインの x-data の式 $factory。どちらも無ければ素のフォーム）
     * の状態で評価して足す（ブラウザは 1 つのフォームとして全部を送る）。
     * 同じ名前のコンポーネントが複数あるとき（DAD の工事案件の日付の部品は 7 つ）は、全部を同じ JS で順に評価する
     * （$nestedSteps に配列を渡すと、描かれた順に 1 つずつ別の JS を走らせる）。
     *
     * @param  array<string, string|array<int, string>>  $nested  入れ子のコンポーネントの関数名 => そのコンポーネントで走らせる JS（例 買主を選ぶ）
     * @return array{method: string, action: string, fields: array<string, mixed>, run: ?array}
     */
    protected function composedForm(string $html, string $action, ?string $function, ?string $factory, array $nested, string $steps = '', array $responses = []): array
    {
        $needle = 'action="' . $action . '"';
        preg_match_all('/<script\b[^>]*>.*?<\/script>/s', $html, $scripts);
        $nestedFields = [];
        foreach ($nested as $name => $nestedSteps) {
            $at = $this->nestedInForm($html, $needle, $name);
            $this->assertNotNull($at, "画面のフォームの中に {$name} が無い");
            for ($i = 0; $at !== null; $i++) {
                $section = $this->balancedElement($html, (int) strrpos(substr($html, 0, $at), '<div'), 'div');
                $html = substr_replace($html, '', (int) strpos($html, $section, (int) strrpos(substr($html, 0, $at), '<div')), strlen($section));
                $part = '<form method="POST" ' . $needle . '>' . $section . '</form>' . implode("\n", $scripts[0]);
                $stepsForThis = is_array($nestedSteps) ? ($nestedSteps[$i] ?? '') : $nestedSteps;
                $nestedFields += $this->browserForm($part, $needle, $name, $stepsForThis)['fields'];
                $at = $this->nestedInForm($html, $needle, $name);
            }
        }

        $main = $function === null && $factory === null
            ? $this->parseForm($html, $needle) + ['run' => null]
            : $this->browserForm($html, $needle, $function, $steps, $responses, $factory);

        return ['method' => $main['method'], 'action' => $main['action'], 'fields' => $main['fields'] + $nestedFields, 'run' => $main['run']];
    }

    /** $needle のフォームの中で、次の `x-data="$name(` の位置（フォームの外のものは数えない＝ブラウザはそのフォームの欄しか送らない） */
    private function nestedInForm(string $html, string $needle, string $name): ?int
    {
        $pos = strpos($html, $needle);
        $this->assertNotFalse($pos, "フォームが見つからない: {$needle}");
        $open = (int) strrpos(substr($html, 0, $pos), '<form');
        $close = strpos($html, '</form>', $pos);
        $at = strpos($html, 'x-data="' . $name . '(', $open);

        return $at === false || ($close !== false && $at > $close) ? null : $at;
    }

    /** JS の fetch に、PHP の JSON の API の応答をそのまま返す（X-Requested-With つきで叩く。叩いたあとはヘッダーを戻す） */
    protected function apiResponse(string $url): array
    {
        $response = $this->actingAs($this->user)
            ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->get($url);
        $this->flushHeaders();
        $response->assertOk();

        return $this->asFetchResponse($response);
    }
}
