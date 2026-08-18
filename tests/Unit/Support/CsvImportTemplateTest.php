<?php

namespace Tests\Unit\Support;

use App\Support\CsvImportTemplate;
use Tests\TestCase;

class CsvImportTemplateTest extends TestCase
{
    public function test_it_quotes_every_field(): void
    {
        $this->assertSame("\"a\",\"b\"\n", CsvImportTemplate::line(['a', 'b']));
    }

    public function test_it_doubles_embedded_quotes(): void
    {
        $this->assertSame("\"say \"\"hi\"\"\"\n", CsvImportTemplate::line(['say "hi"']));
    }

    public function test_the_response_starts_with_a_bom_so_excel_reads_utf8(): void
    {
        $response = CsvImportTemplate::response(['物件名'], [['ミツワビル']], 'x.csv');

        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->getContent());
        $this->assertSame("\xEF\xBB\xBF\"物件名\"\n\"ミツワビル\"\n", $response->getContent());
    }

    public function test_the_response_is_an_attachment(): void
    {
        $response = CsvImportTemplate::response(['A'], [], 'テンプレート.csv');

        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame(
            'attachment; filename="テンプレート.csv"',
            $response->headers->get('Content-Disposition')
        );
    }
}
