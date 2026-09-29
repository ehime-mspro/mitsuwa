<?php

namespace Tests\Unit\Approval;

use App\Support\Approval\LineDiff;
use App\Support\Approval\RequestSnapshot;
use PHPUnit\Framework\TestCase;

/** 控えどうしの比べ方（設計書 §5.13・§5.14 の D3） */
class RequestSnapshotTest extends TestCase
{
    /** make() と同じ形の控え */
    private static function snapshot(array $overrides = []): array
    {
        return array_replace([
            'type'            => ['id' => 1, 'name' => '購入・発注'],
            'department'      => ['id' => 10, 'name' => '住宅事業部'],
            'subject'         => '社用車の購入',
            'amount'          => 2850000,
            'schedule'        => '2026年10月',
            'body'            => "■ なぜ（目的・理由）\n・老朽化のため",
            'related_numbers' => ['R8-J-001', 'R7-J-015'],
            'attachments'     => [['id' => 5, 'name' => '見積書.pdf', 'size' => 1234], ['id' => 6, 'name' => '写真.jpg', 'size' => 99]],
        ], $overrides);
    }

    public function test_the_same_snapshots_have_no_changes(): void
    {
        $changes = RequestSnapshot::changes(self::snapshot(), self::snapshot());

        $this->assertSame([], $changes['fields']);
        $this->assertNull($changes['body']);
        $this->assertSame([], $changes['attachments_added']);
        $this->assertSame([], $changes['attachments_removed']);
        $this->assertFalse(RequestSnapshot::hasChanges($changes));
    }

    public function test_each_field_is_shown_from_before_to_after(): void
    {
        $after = self::snapshot([
            'type'            => ['id' => 2, 'name' => '契約'],
            'department'      => ['id' => 11, 'name' => '住宅（少額・追加工事）'],
            'subject'         => '社用車の購入（2 台）',
            'amount'          => null,
            'schedule'        => '',
            'related_numbers' => ['R8-J-001'],
        ]);

        $this->assertSame([
            ['label' => '申請の種類', 'before' => '購入・発注', 'after' => '契約'],
            ['label' => '申請部門', 'before' => '住宅事業部', 'after' => '住宅（少額・追加工事）'],
            ['label' => '件名', 'before' => '社用車の購入', 'after' => '社用車の購入（2 台）'],
            ['label' => '金額（税抜）', 'before' => '2,850,000円', 'after' => '（なし）'],
            ['label' => '実施時期', 'before' => '2026年10月', 'after' => '（なし）'],
            ['label' => '関連する決裁No', 'before' => 'R8-J-001・R7-J-015', 'after' => 'R8-J-001'],
        ], RequestSnapshot::changes(self::snapshot(), $after)['fields']);
    }

    public function test_a_renamed_type_or_department_is_not_a_change(): void
    {
        // 管理者があとで名前を変えただけ（id は同じ）なら、申請者は変えていない
        $after = self::snapshot(['type' => ['id' => 1, 'name' => '購入・発注（新）'], 'department' => ['id' => 10, 'name' => '住宅部']]);

        $this->assertSame([], RequestSnapshot::changes(self::snapshot(), $after)['fields']);
    }

    public function test_reordered_related_numbers_are_not_a_change(): void
    {
        $after = self::snapshot(['related_numbers' => ['R7-J-015', 'R8-J-001']]);

        $this->assertSame([], RequestSnapshot::changes(self::snapshot(), $after)['fields']);
    }

    public function test_the_body_is_compared_line_by_line(): void
    {
        $after = self::snapshot(['body' => "■ なぜ（目的・理由）\n・老朽化のため\n・燃費が悪い"]);

        $body = RequestSnapshot::changes(self::snapshot(), $after)['body'];

        $this->assertSame([
            ['type' => LineDiff::SAME, 'line' => '■ なぜ（目的・理由）'],
            ['type' => LineDiff::SAME, 'line' => '・老朽化のため'],
            ['type' => LineDiff::ADDED, 'line' => '・燃費が悪い'],
        ], $body);
    }

    public function test_attachments_are_compared_by_id(): void
    {
        $after = self::snapshot(['attachments' => [['id' => 6, 'name' => '写真.jpg', 'size' => 99], ['id' => 9, 'name' => '見積書（改）.pdf', 'size' => 2000]]]);

        $changes = RequestSnapshot::changes(self::snapshot(), $after);

        $this->assertSame([['id' => 9, 'name' => '見積書（改）.pdf', 'size' => 2000]], $changes['attachments_added']);
        $this->assertSame([['id' => 5, 'name' => '見積書.pdf', 'size' => 1234]], $changes['attachments_removed']);
        $this->assertTrue(RequestSnapshot::hasChanges($changes));
    }

    public function test_the_fingerprint_ignores_key_order_and_names(): void
    {
        // MySQL は JSON のキーを並べ替えて返す（キーの長さの順）。数も文字列で返ることがある
        $fromMysql = [
            'body'            => "■ なぜ（目的・理由）\n・老朽化のため",
            'type'            => ['name' => '購入・発注（新）', 'id' => '1'],
            'amount'          => '2850000',
            'subject'         => '社用車の購入',
            'schedule'        => '2026年10月',
            'department'      => ['name' => '住宅部', 'id' => 10],
            'attachments'     => [['size' => 99, 'name' => '別名.jpg', 'id' => 6], ['size' => 1234, 'name' => '見積書.pdf', 'id' => 5]],
            'related_numbers' => ['R8-J-001', 'R7-J-015'],
        ];

        $this->assertSame(RequestSnapshot::editableFingerprint(self::snapshot()), RequestSnapshot::editableFingerprint($fromMysql));
    }

    public function test_the_fingerprint_changes_with_any_editable_value(): void
    {
        $base = RequestSnapshot::editableFingerprint(self::snapshot());
        $edits = [
            '種類'             => ['type' => ['id' => 2, 'name' => '購入・発注']],
            '申請部門'         => ['department' => ['id' => 11, 'name' => '住宅事業部']],
            '件名'             => ['subject' => '社用車の購入 '],
            '金額'             => ['amount' => 2850001],
            '金額を空に'       => ['amount' => null],
            '実施時期'         => ['schedule' => '2026年11月'],
            '本文'             => ['body' => "■ なぜ（目的・理由）\n・老朽化のため\n"],
            '関連する決裁No の並び' => ['related_numbers' => ['R7-J-015', 'R8-J-001']],
            '添付を足す'       => ['attachments' => [['id' => 5, 'name' => 'a', 'size' => 1], ['id' => 6, 'name' => 'b', 'size' => 1], ['id' => 7, 'name' => 'c', 'size' => 1]]],
            '添付を外す'       => ['attachments' => [['id' => 5, 'name' => 'a', 'size' => 1]]],
        ];

        foreach ($edits as $label => $overrides) {
            $this->assertNotSame($base, RequestSnapshot::editableFingerprint(self::snapshot($overrides)), "{$label}を変えても同じ指紋になった");
        }
    }

    public function test_zero_and_null_amounts_are_different(): void
    {
        // `==` だと null == 0 が同じになる（§5.16）。金額 0 円と空は別
        $this->assertNotSame(
            RequestSnapshot::editableFingerprint(self::snapshot(['amount' => 0])),
            RequestSnapshot::editableFingerprint(self::snapshot(['amount' => null])),
        );
        // 変更点でも、空から 0 円にしたら変わったものとして出す（2b 計画 Task 8 の変異 S03）
        $this->assertSame(
            [['label' => '金額（税抜）', 'before' => '（なし）', 'after' => '0円']],
            RequestSnapshot::changes(self::snapshot(['amount' => null]), self::snapshot(['amount' => 0]))['fields'],
        );
    }
}
