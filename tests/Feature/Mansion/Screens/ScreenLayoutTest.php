<?php

namespace Tests\Feature\Mansion\Screens;

/**
 * 賃貸マンションの画面の並び（実ブラウザで見つけた 2 つ。PHP からは見た目を測れないので、描いた HTML の形で固定する）。
 *
 * ⚠ 本当に直ったかは実ブラウザで測る（2026-10-07: 1440px と 375px で、削除のボタンの中心が押せること・`main` の横スクロールが 0 であること）。
 */
class ScreenLayoutTest extends MansionScreenTestCase
{
    /**
     * 編集画面の削除の欄は、フォームの下（ページの一番下）に置かれる。画面の下に固定した保存のバー（x-form-actions。高さ約 74px）が
     * ページの一番下に重なるので、そのままだと削除の欄がバーの後ろに隠れ、ボタンの上の端しか押せなかった（1440px で実測）。
     * 削除の欄の下に、バーと同じ高さの余白を置く。
     */
    public function test_the_delete_zone_on_each_edit_screen_has_room_below_it_for_the_fixed_save_bar(): void
    {
        $tenant = $this->tenant();
        $room = $this->room('101');
        $parking = $this->parking('A-1');
        $screens = [
            [route('mansion.properties.edit', $this->building), route('mansion.properties.destroy', $this->building)],
            [route('mansion.rooms.edit', $room), route('mansion.rooms.destroy', $room)],
            [route('mansion.parkings.edit', $parking), route('mansion.parkings.destroy', $parking)],
            [route('mansion.tenants.edit', $tenant), route('mansion.tenants.destroy', $tenant)],
        ];
        $missing = [];
        foreach ($screens as [$url, $destroy]) {
            $html = $this->htmlOf($url);
            $pattern = '/action="' . preg_quote($destroy, '/') . '"\s+onsubmit="[^"]*">.*?<\/form>\s*<\/div>\s*<div style="height: 80px;" aria-hidden="true"><\/div>/s';
            if (! preg_match($pattern, $html)) {
                $missing[] = $url;
            }
        }
        $this->assertSame([], $missing, '削除の欄の下に保存のバーの高さの余白が無い（削除のボタンがバーの後ろに隠れる）');
    }

    /**
     * ダッシュボードの「空室・申込み中」「空き駐車場」の 2 列は、表（min-width 640px）が入るとグリッドの列ごと広がり、
     * 1440px でも `main` が 196px 横にはみ出した（Bug #29 と同じ形。`1fr` は `minmax(auto, 1fr)` の略で、中身の幅が下限になる）。
     * 列の下限を 0 にして、表は自分の横スクロールの枠の中で動かす。
     */
    public function test_the_dashboard_two_column_grid_does_not_grow_with_its_tables(): void
    {
        $html = $this->htmlOf(route('mansion.dashboard'));

        $this->assertMatchesRegularExpression('/\.ms-two-col\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\) minmax\(0, 1fr\);/', $html);
        $this->assertMatchesRegularExpression('/@media \(max-width: 900px\)\s*\{[^@]*\.ms-two-col\s*\{\s*grid-template-columns:\s*minmax\(0, 1fr\);/', $html);
    }
}
