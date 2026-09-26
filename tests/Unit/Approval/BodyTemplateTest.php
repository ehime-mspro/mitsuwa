<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\BodyTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** 「見出しのまま」の判定（設計書 D12） */
class BodyTemplateTest extends TestCase
{
    public static function blanks(): array
    {
        return [
            '標準の見出しのまま' => [BodyTemplate::DEFAULT],
            '空'                  => [''],
            'null'                => [null],
            '・の後ろに空白'      => ["■ なぜ（目的・理由）\n・ \n■ 何を（内容）\n・\u{3000}"],
            '改行が CRLF'         => ["■ なぜ\r\n・\r\n"],
            '見出しを書き換えただけ' => ["■ 目的\n・\n■ 費用\n・"],
        ];
    }

    #[DataProvider('blanks')]
    public function test_headings_only_is_blank(?string $body): void
    {
        $this->assertTrue(BodyTemplate::isBlank($body));
    }

    public static function written(): array
    {
        return [
            '箇条書きに中身'  => ["■ なぜ（目的・理由）\n・老朽化のため"],
            '「なし」と書いた' => ["■ 補足\n・なし"],
            '見出しの外に文'  => ["社用車を 1 台買い替えたい"],
        ];
    }

    #[DataProvider('written')]
    public function test_any_content_is_not_blank(string $body): void
    {
        $this->assertFalse(BodyTemplate::isBlank($body));
    }

    /** 標準の見出しは 6 つ（いつ・いくらは入れない。要件 5.2） */
    public function test_the_default_has_six_headings_without_when_and_how_much(): void
    {
        $this->assertSame(6, substr_count(BodyTemplate::DEFAULT, '■'));
        $this->assertStringNotContainsString('いつ', BodyTemplate::DEFAULT);
        $this->assertStringNotContainsString('いくら', BodyTemplate::DEFAULT);
    }
}
