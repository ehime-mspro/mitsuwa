<?php

namespace Tests\Feature;

use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Depends;
use ReflectionProperty;
use Symfony\Component\Translation\Translator as SymfonyTranslator;
use Tests\TestCase;

/**
 * Carbon の共有の翻訳に、アプリを起動するたびに日本語の文が積もらないこと（tests/TestCase.php の tearDown）。
 *
 * ⚠ 2 本は宣言の順に流れる（#[Depends]）。1 本目で起動を重ねて文を積もらせ、2 本目で「前のテストの片付けで
 *   1 つに戻り、このテストの起動の分だけが足されている」ことを見る。片付けを消すと 2 本目が赤になる。
 */
class CarbonTranslatorTrimTest extends TestCase
{
    /** 1 回の起動で足される日本語の文の数（1 本目で測る） */
    private static int $perBoot = 0;

    private function japaneseResources(): int
    {
        $resources = (new ReflectionProperty(SymfonyTranslator::class, 'resources'))->getValue(Carbon::getTranslator());

        return count($resources['ja'] ?? []);
    }

    public function test_booting_the_app_again_piles_up_carbon_messages(): void
    {
        $before = $this->japaneseResources();
        $this->refreshApplication();
        self::$perBoot = $this->japaneseResources() - $before;

        $this->assertGreaterThan(0, self::$perBoot,
            '前提: Carbon の Laravel 用のプロバイダが起動のたびに文を足す。足さなくなったら、TestCase の片付けとこのテストは外してよい');
    }

    #[Depends('test_booting_the_app_again_piles_up_carbon_messages')]
    public function test_each_test_starts_from_the_messages_of_one_boot(): void
    {
        $this->assertSame(1 + self::$perBoot, $this->japaneseResources(),
            '前のテストの片付け（TestCase::tearDown）で Carbon の文が 1 つに戻っていない');
    }
}
