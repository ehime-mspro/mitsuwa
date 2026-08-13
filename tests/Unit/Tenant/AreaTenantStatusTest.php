<?php

namespace Tests\Unit\Tenant;

use App\Enums\AreaTenantStatus;
use PHPUnit\Framework\TestCase;

class AreaTenantStatusTest extends TestCase
{
    public function test_labels(): void
    {
        $this->assertSame('営業', AreaTenantStatus::Operating->label());
        $this->assertSame('空き', AreaTenantStatus::Vacant->label());
        $this->assertSame('不明', AreaTenantStatus::Unknown->label());
    }

    /** バッジは inline style を返す(Tailwind クラスは返さない — プロジェクト規約) */
    public function test_badge_style_is_inline_css_not_tailwind_classes(): void
    {
        foreach (AreaTenantStatus::cases() as $case) {
            $style = $case->badgeStyle();
            $this->assertStringContainsString('background:', $style);
            $this->assertStringContainsString('color:', $style);
            $this->assertStringNotContainsString('bg-', $style);
        }
    }

    /**
     * Excel 取込の状態エイリアス(設計 §7.2)。
     * 空欄と「?」は不明。判定できない語も不明に倒す(勝手に営業扱いしない)。
     */
    public function test_from_raw_label_normalizes_aliases(): void
    {
        foreach (['営業中', '営業', '入居', '入居中', '稼働'] as $raw) {
            $this->assertSame(AreaTenantStatus::Operating, AreaTenantStatus::fromRawLabel($raw), $raw);
        }
        foreach (['空室', '空き', '空き店舗', '空店舗'] as $raw) {
            $this->assertSame(AreaTenantStatus::Vacant, AreaTenantStatus::fromRawLabel($raw), $raw);
        }
        foreach ([null, '', '  ', '?', '？', '不明', 'よくわからない'] as $raw) {
            $this->assertSame(AreaTenantStatus::Unknown, AreaTenantStatus::fromRawLabel($raw), var_export($raw, true));
        }
    }

    /** 全角スペース(U+3000)混じりでも正規化できる */
    public function test_from_raw_label_ignores_full_width_space(): void
    {
        $this->assertSame(AreaTenantStatus::Operating, AreaTenantStatus::fromRawLabel("営　業"));
        $this->assertSame(AreaTenantStatus::Vacant, AreaTenantStatus::fromRawLabel("空　室"));
    }
}
