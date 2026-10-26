<?php

namespace Tests\Feature\Tenant\Screens;

/**
 * URL の値（`?property_id=` など）で前もって選ぶ画面が、URL を書き換えただけで壊れない（Bug #97 の形）。
 *
 * ⚠ 直す前は、末尾に `\` を付けると 200 なのに画面の JS が止まり（値を JS の文字列に直に書いていた。Bug #76 の形）、
 *   `?property_id[]=1` で 500 だった（2026-10-26 に実測）。数でない値は URL から外す（Controller::ignoreMalformedQuery()）。
 * ⚠ 普通の値は今までどおり効くことも対で見る（外しすぎを防ぐ）。
 */
class QueryPresetJsTest extends TenantScreenTestCase
{
    /** 画面を開いて 200 であること、画面の JS を node で組み立てて $expressions を評価する */
    private function evaluateAt(string $url, string $function, array $expressions): array
    {
        $response = $this->actingAs($this->user)->get($url);
        $this->assertSame(200, $response->getStatusCode(), "{$url} が 200 でない");
        $html = $response->getContent();

        return $this->driveAlpine($html, $function, $this->xData($html, $function), '', [], true, $expressions)['evaluated'];
    }

    public function test_the_contract_registration_screen_survives_a_rewritten_url(): void
    {
        $base = route('tenant.contracts.create');
        $id = (string) $this->building->id;

        foreach ([
            [['property_id' => $id], [$id, '']],
            [['property_id' => '1\\'], ['', '']],
            [['property_id' => ['1']], ['', '']],
            [['inquiry_id' => '999'], ['', '999']],
            [['inquiry_id' => '1\\'], ['', '']],
            [['inquiry_id' => ['1']], ['', '']],
        ] as [$query, $expected]) {
            $url = $base . '?' . http_build_query($query);
            $this->assertSame($expected, $this->evaluateAt($url, 'contractCreateForm', ['propertyId', 'inquiryId']), "{$url} で前もって選ぶ値が違う");
        }
    }

    public function test_the_inquiry_registration_screen_survives_a_rewritten_url(): void
    {
        $base = route('tenant.inquiries.create');
        $id = (string) $this->building->id;

        foreach ([
            [['property_id' => $id], $id],
            [['property_id' => '1\\'], ''],
            [['property_id' => ['1']], ''],
        ] as [$query, $expected]) {
            $url = $base . '?' . http_build_query($query);
            $this->assertSame([$expected], $this->evaluateAt($url, 'inquiryCreateForm', ['propertyId']), "{$url} で前もって選ぶ物件が違う");
        }
    }

    public function test_the_investment_registration_screen_survives_a_rewritten_url(): void
    {
        $unit = $this->unit(1, 'A');
        $base = route('tenant.investments.create');

        foreach ([
            [['unit_id' => (string) $unit->id], [(string) $this->building->id, (string) $unit->id]],
            [['unit_id' => ['1']], ['', '']],
        ] as [$query, $expected]) {
            $url = $base . '?' . http_build_query($query);
            $this->assertSame($expected, $this->evaluateAt($url, 'investmentForm', ['propertyId', 'unitId']), "{$url} で前もって選ぶ物件・区画が違う");
        }
    }
}
