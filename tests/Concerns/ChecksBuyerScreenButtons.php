<?php

namespace Tests\Concerns;

use App\Enums\UserRole;
use App\Models\Buyer;
use App\Models\BuyerSurvey;

/**
 * 買主の画面（buyers/index・buyers/show。住宅事業・不動産で共用）のボタンとリンクが、押せる人にだけ出ていること
 * （設計メモ 2026-11-27-buyer-screen-buttons-design.md・Bug #96 と同じ形）。
 * 送り先のルートの役割と同じ判定で見る: 新規登録・編集・アンケートの追加と編集は role:executive,manager、削除は role:executive。
 * 直す前はどのボタンも全員に出ていて、押せない人が押すと 403 になっていた。
 *
 * ⚠ 使う側は `$this->user`（画面を開く人）を持ち、SubmitsScreenForms と一緒に使う。
 * ⚠ 送り先の URL は閉じの `"` まで含めて当てる（`…/customers/1` が `…/customers/1/edit` に当たらないように）。
 * ⚠ 使えない行は「その URL が画面のどこにも無い」ことまで見る（文字を変えただけのボタンが残っていても落ちる）。
 */
trait ChecksBuyerScreenButtons
{
    /**
     * 買主の画面のボタンの表。who は everyone（全員）・manager（経営層・管理者）・executive（経営層だけ）。
     *
     * @return list<array{page: string, type: string, label: string, url: string, who: string}>
     */
    protected function buyerScreenButtons(string $department, Buyer $buyer, BuyerSurvey $survey): array
    {
        $row = fn (string $page, string $type, string $label, string $url, string $who) => compact('page', 'type', 'label', 'url', 'who');

        return [
            $row('index', 'link', '新規登録', route("{$department}.customers.create"), 'manager'),
            $row('index', 'link', '詳細', route("{$department}.customers.show", $buyer), 'everyone'),
            $row('show', 'link', '顧客一覧に戻る', route("{$department}.customers.index"), 'everyone'),
            $row('show', 'link', '編集', route("{$department}.customers.edit", $buyer), 'manager'),
            $row('show', 'delete', '削除', route("{$department}.customers.destroy", $buyer), 'executive'),
            $row('show', 'link', 'アンケートを追加', route("{$department}.customers.surveys.create", $buyer), 'manager'),
            $row('show', 'link', '詳細', route("{$department}.customers.surveys.edit", [$buyer, $survey]), 'manager'),
            $row('show', 'link', '編集', route("{$department}.customers.surveys.edit", [$buyer, $survey]), 'manager'),
            $row('show', 'delete', '削除', route("{$department}.customers.surveys.destroy", [$buyer, $survey]), 'executive'),
        ];
    }

    /** 表の行ごとに、使える人には 1 個、使えない人には 0 個（使えない人の画面にはその URL も無い） */
    protected function assertBuyerScreenButtons(string $indexHtml, string $showHtml, array $buttons, UserRole $role): void
    {
        foreach ($buttons as $b) {
            $html = $b['page'] === 'index' ? $indexHtml : $showHtml;
            $canUse = $this->canUseBuyerButton($b['who'], $role);
            $expected = $canUse ? 1 : 0;
            $url = preg_quote(e($b['url']), '/');

            if ($b['type'] === 'link') {
                preg_match_all('/<a\b[^>]*\bhref="' . $url . '"[^>]*>(.*?)<\/a>/su', $html, $m);
                $found = count(array_filter($m[1], fn (string $inner) => $this->buttonText($inner) === $b['label']));
                $this->assertSame($expected, $found, "[{$role->value}] {$b['page']} の「{$b['label']}」のリンク（{$b['url']}）が {$expected} 個ではない");
                $attribute = 'href';
            } else {
                preg_match_all('/<form\b[^>]*\baction="' . $url . '"[^>]*>(.*?)<\/form>/su', $html, $m);
                $found = count(array_filter($m[1], fn (string $inner) => str_contains($inner, 'name="_method" value="DELETE"')
                    && preg_match_all('/<button\b[^>]*>(.*?)<\/button>/su', $inner, $bm) > 0
                    && in_array($b['label'], array_map(fn (string $text) => $this->buttonText($text), $bm[1]), true)));
                $this->assertSame($expected, $found, "[{$role->value}] {$b['page']} の「{$b['label']}」の削除のフォーム（{$b['url']}）が {$expected} 個ではない");
                $attribute = 'action';
            }

            if (! $canUse) {
                $this->assertSame(0, preg_match('/\b' . $attribute . '="' . $url . '"/u', $html), "[{$role->value}] {$b['page']} に使えない人への {$b['url']} が残っている");
            }
        }
    }

    /** 使える人に出たリンクは、たどると開ける（Bug #47: 描いた href をそのまま開く） */
    protected function assertUsableLinksOpen(array $buttons, UserRole $role): void
    {
        foreach ($buttons as $b) {
            if ($b['type'] !== 'link' || ! $this->canUseBuyerButton($b['who'], $role)) {
                continue;
            }
            $status = $this->actingAs($this->user)->get($b['url'])->status();
            $this->assertSame(200, $status, "[{$role->value}] 「{$b['label']}」のリンク（{$b['url']}）を開けない");
        }
    }

    private function canUseBuyerButton(string $who, UserRole $role): bool
    {
        return match ($who) {
            'everyone' => true,
            'manager' => $role->isManagerOrAbove(),
            'executive' => $role === UserRole::Executive,
        };
    }

    private function buttonText(string $inner): string
    {
        return trim(html_entity_decode(strip_tags($inner)));
    }
}
