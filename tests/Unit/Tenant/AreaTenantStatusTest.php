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
            // 単純な 'bg-' 部分一致だけでは 'text-emerald-800' のような bg- を含まない
            // Tailwind クラスの混入を検出できない。代表的なユーティリティ接頭辞をまとめて検査する。
            $this->assertDoesNotMatchRegularExpression(
                '/\b(?:bg|text|border|rounded|px|py|font|shadow)-/',
                $style,
                'Tailwind クラスが混入している(バッジは inline style で返す規約)'
            );
        }
    }

    /** status カラムは VARCHAR(20)。将来ケースを足したときに収まりきらないことに気づけるようにする */
    public function test_values_fit_the_database_column(): void
    {
        foreach (AreaTenantStatus::cases() as $case) {
            $this->assertLessThanOrEqual(20, strlen($case->value), "{$case->name} の値が status VARCHAR(20) に収まらない");
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

    /**
     * 判定は順序に依存させない。否定語で営業判定を打ち消す、および
     * 営業系・空き系の両方の語を含む(判定不能)は Unknown に倒す。
     *
     * 単語単体しか見ないテストだと、順序で片方が勝ってしまう誤実装を検出できない
     * (コード品質レビューで発見: 「不稼働」「入居者退去済み」等が誤って Operating と判定されていた)。
     */
    public function test_from_raw_label_falls_back_to_unknown_for_ambiguous_or_negated_text(): void
    {
        // 否定語が「営業」判定を打ち消す(Vacant への昇格はしない — 空き系の語を含まないため)
        foreach (['不稼働（休業中）', '入居者退去済み', '退去済', '募集中'] as $raw) {
            $this->assertSame(AreaTenantStatus::Unknown, AreaTenantStatus::fromRawLabel($raw), $raw);
        }

        // 営業系・空き系の両方の信号を含む場合は判定不能 = Unknown(順序で勝敗を決めない)
        foreach (['空床あり、他は稼働', '空室だが近日営業予定', '空き営業中'] as $raw) {
            $this->assertSame(AreaTenantStatus::Unknown, AreaTenantStatus::fromRawLabel($raw), $raw);
        }
    }
}
