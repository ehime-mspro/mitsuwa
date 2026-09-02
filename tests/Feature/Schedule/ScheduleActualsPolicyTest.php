<?php

namespace Tests\Feature\Schedule;

use App\Models\Concerns\HasScheduleSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Concerns\CreatesRealEstateSchema;

/**
 * 「実績（actual_start / actual_end）を持つか」は親モデルが宣言する（設計書 §3）。
 *
 * ⚠ **既定実装を置かないことまで固定する。** 既定値があると、新しい親を足した人が
 *   override を忘れた瞬間に無音で片方の挙動へ倒れる。abstract なら PHP が Fatal で止める。
 */
class ScheduleActualsPolicyTest extends ScheduleTestCase
{
    use RefreshDatabase;
    use CreatesRealEstateSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRealEstateSchema();
    }

    public function test_the_trait_declares_it_abstract_without_a_default(): void
    {
        $method = new ReflectionMethod(HasScheduleSteps::class, 'scheduleTracksActuals');

        $this->assertTrue($method->isAbstract(), 'scheduleTracksActuals() は abstract であること（既定実装を置かない）');
        $this->assertTrue($method->isPublic());
    }

    public function test_every_parent_declares_whether_it_tracks_actuals(): void
    {
        $expected = [
            'procurement' => true,
            'project'     => true,
            'property'    => false,
            'customOrder' => false,
        ];

        // ⚠ 4 親を全件見る。代表 1 種だけだと残りの経路が一度も実行されない(Bug #44)
        $this->assertSame(array_keys(self::PARENTS), array_keys($expected), '親の一覧と期待値の一覧がずれている');

        foreach ($expected as $key => $tracks) {
            $owner = $this->makeParent($key);

            $this->assertSame(
                $tracks,
                $owner->scheduleTracksActuals(),
                "{$key} の scheduleTracksActuals() が期待と違う"
            );

            // 親自身が宣言していること(trait から継がれた既定ではない)
            $declaring = (new ReflectionClass($owner))->getMethod('scheduleTracksActuals')->getDeclaringClass()->getName();
            $this->assertSame($owner::class, $declaring, "{$key} が自分で宣言していない");
        }
    }
}
