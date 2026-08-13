<?php

namespace App\Enums;

/**
 * 周辺ビルの入居テナントの状態。
 *
 * ⚠ モデルで casts() にかけるので、読み出した属性は既に enum インスタンス。
 *   キャスト済み属性に tryFrom() を呼ばないこと(Bug #22)。
 *   クエリで使うときだけ ->value を渡す。
 */
enum AreaTenantStatus: string
{
    case Operating = 'operating';
    case Vacant    = 'vacant';
    case Unknown   = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Operating => '営業',
            self::Vacant    => '空き',
            self::Unknown   => '不明',
        };
    }

    /** ステータスバッジは inline style を返す(Tailwind クラス指定は規約で NG) */
    public function badgeStyle(): string
    {
        return match ($this) {
            self::Operating => 'background: #d1fae5; color: #065f46;',
            self::Vacant    => 'background: #fee2e2; color: #991b1b;',
            self::Unknown   => 'background: #f3f4f6; color: #374151;',
        };
    }

    /**
     * Excel 取込の状態列を正規化する(設計 §7.2)。
     * 判定できない値は Unknown に倒す(勝手に営業扱いすると空室率が下振れするため)。
     */
    public static function fromRawLabel(?string $raw): self
    {
        // ⚠ PCRE の \s は /u を付けても U+3000 に当たらないので明示する
        $s = preg_replace('/[\s\x{3000}]+/u', '', (string) $raw);

        if ($s === '' || $s === '?' || $s === '？') {
            return self::Unknown;
        }

        foreach (['営業', '入居', '稼働'] as $needle) {
            if (str_contains($s, $needle)) {
                return self::Operating;
            }
        }

        foreach (['空室', '空き', '空店舗', '空'] as $needle) {
            if (str_contains($s, $needle)) {
                return self::Vacant;
            }
        }

        return self::Unknown;
    }
}
