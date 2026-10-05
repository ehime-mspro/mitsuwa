<?php

namespace Tests\Feature\Zeal;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\ZealMember;
use App\Models\ZealMemberContract;
use App\Models\ZealPlan;
use App\Models\ZealStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesZealSchema;
use Tests\Concerns\ParsesForms;
use Tests\TestCase;

/**
 * ZEAL の画面（会員・体験予約・プラン・店舗・トレーナー・経営試算表）のテストの土台。
 * 名前は最初に作った会員の画面（編集・プラン変更・退会。Zeal\MemberController）のまま。
 *
 * 経営層の利用者・店舗 1 つ・プラン 2 つ・在籍の会員 1 人（現契約あり）を用意する。
 * ⚠ 送る値は、描いた画面のフォームから送り先と項目名を取り、その項目だけ差し替える（Bug #47 の往復）。
 *   プラン変更・退会のフォームは値を Alpine が入れるので、ブラウザで選んだときと同じ値を入れる。
 */
abstract class MemberScreenTestCase extends TestCase
{
    use RefreshDatabase;
    use CreatesZealSchema;
    use ParsesForms;

    protected User $user;

    protected ZealMember $member;

    /** 今のプラン */
    protected ZealPlan $plan;

    /** プラン変更の変更先 */
    protected ZealPlan $otherPlan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createZealSchema();
        $this->pointGymInquiriesAtMemory();

        $this->user = User::factory()->create([
            'role' => UserRole::Executive->value,   // department.access:zeal を素通りする
            'must_change_password' => false,
        ]);
        $store = ZealStore::create(['name' => '松山市駅前店', 'display_order' => 1, 'active' => true]);
        $this->plan = ZealPlan::create(['name' => 'セミパーソナル通い放題', 'regular_price_excl' => 9800]);
        $this->otherPlan = ZealPlan::create(['name' => 'パーソナル&セミパーソナル月4回', 'regular_price_excl' => 13000]);
        $this->member = ZealMember::create([
            'store_id' => $store->id, 'name' => '在籍 太郎', 'name_kana' => 'ザイセキ タロウ', 'gender' => 'male',
            'joined_on' => '2025-10-17', 'current_plan_id' => $this->plan->id, 'created_by' => $this->user->id,
        ]);
        ZealMemberContract::create([
            'member_id' => $this->member->id, 'plan_id' => $this->plan->id, 'period_start' => '2025-10-17',
            'applied_price_excl' => 9800, 'tax_rate_at_contract' => 10, 'change_reason' => 'new_join',
            'created_by' => $this->user->id,
        ]);
    }

    /** 編集画面が描いたフォームを分解し、$over の項目だけ差し替えて送る */
    protected function sendEdit(array $over): TestResponse
    {
        $edit = route('zeal.members.edit', $this->member);
        $html = $this->actingAs($this->user)->get($edit)->assertOk()->getContent();
        $form = $this->parseForm($html, 'action="' . route('zeal.members.update', $this->member) . '"');
        $this->assertSame('PUT', $form['method']);

        return $this->actingAs($this->user)->from($edit)->post($form['action'], array_merge($form['fields'], $over));
    }

    /** 詳細画面のプラン変更のフォームを分解し、ブラウザで選んだときの値（変更先は $otherPlan）を入れて送る */
    protected function sendPlanChange(array $over): TestResponse
    {
        $form = $this->parseForm($this->showHtml(), 'action="' . route('zeal.members.change-plan', $this->member) . '"');
        $this->assertArrayHasKey('note', $form['fields'], 'プラン変更のフォームに備考が無い');

        return $this->actingAs($this->user)->from(route('zeal.members.show', $this->member))->post($form['action'], array_merge(
            $form['fields'],
            ['plan_id' => (string) $this->otherPlan->id, 'change_date' => '2026-10-01', 'applied_price_excl' => '13000', 'is_campaign_applied' => '0', 'note' => ''],
            $over,
        ));
    }

    /** 詳細画面の退会のフォームを分解し、ブラウザで選んだときの値を入れて送る */
    protected function sendWithdraw(array $over): TestResponse
    {
        $form = $this->parseForm($this->showHtml(), 'action="' . route('zeal.members.withdraw', $this->member) . '"');
        $this->assertArrayHasKey('withdraw_reason', $form['fields'], '退会のフォームに退会理由が無い');

        return $this->actingAs($this->user)->from(route('zeal.members.show', $this->member))->post($form['action'], array_merge(
            $form['fields'],
            ['withdrew_on' => '2026-10-31', 'withdraw_reason' => 'busy', 'withdraw_note' => ''],
            $over,
        ));
    }

    protected function showHtml(): string
    {
        return $this->actingAs($this->user)->get(route('zeal.members.show', $this->member))->assertOk()->getContent();
    }

    /**
     * 体験予約（GymInquiry・'zeal' 接続）を SQLite のメモリ DB へ向け直す（会員の詳細画面・体験予約の画面が読む）。
     * ⚠ 向け直さないと、テストが手元の MySQL へ接続しに行く（MonthEndOverflowTest と同じ理由）。
     * ⚠ 表は外部の DB（Spreadsheet 同期側）が持ち、リポジトリに DDL が無い。列は体験予約の画面
     *   （zeal/inquiries/*.blade.php）が読むものだけを置く。trial_time は本番の画面が 'H:i:s' として読むので文字列で持つ。
     */
    private function pointGymInquiriesAtMemory(): void
    {
        config(['database.connections.zeal' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('zeal');
        Schema::connection('zeal')->create('gym_inquiries', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->nullable();
            $t->string('status', 20)->nullable();
            $t->date('inquiry_date')->nullable();
            $t->date('trial_date')->nullable();
            $t->string('trial_time', 8)->nullable();
            $t->string('contract_plan', 100)->nullable();
            $t->string('gender', 10)->nullable();
            $t->integer('age')->nullable();
            $t->string('phone', 20)->nullable();
            $t->string('email', 100)->nullable();
            $t->string('purpose', 100)->nullable();
            $t->text('purpose_detail')->nullable();
            $t->text('memo')->nullable();
            $t->text('special_notes')->nullable();
        });
    }
}
