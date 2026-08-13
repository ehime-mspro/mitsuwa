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

    /** 「営業」と判定する語(現況が営業中であることを示す) */
    private const OPERATING = ['営業', '入居', '稼働'];

    /** 「空き」と判定する語(現況が空きであることを示す) */
    private const VACANT = ['空室', '空き', '空店舗', '空'];

    /**
     * これらを含むときは「営業」と判定しない(現況が営業でないことを示す語)。
     *
     * ⚠ この一覧は Vacant への昇格には使わない。判定を Unknown 側へ倒すだけに使う。
     *   空室率は「不明」も空きに数える(設計 §4)ので、Unknown へ倒すのは安全側。
     *   逆に Operating へ倒すと空室率が下振れして経営指標が狂う。
     */
    private const NOT_OPERATING = ['不', '非', '退去', '撤退', '閉店', '休業'];

    /**
     * Excel 取込の状態列を正規化する(設計 §7.2)。
     * 判定できない値は Unknown に倒す(勝手に営業扱いすると空室率が下振れするため)。
     *
     * ⚠ 営業系・空き系の両方の語を含む場合、あるいはどちらも含まない場合は判定不能として
     *   Unknown に倒す(順序に依存させない — 「空き営業中」のような文言は現況が確定できない)。
     *
     * DAD の工事案件 Excel 取込はエイリアス解決をクライアント側 JS で行っているが、
     * こちらはビル名の DB 突合が要り最初から PHP 側処理が必要なため、Enum に置いている。
     *
     * ⚠ 既知の限界: VACANT の単一文字 needle 「空」は広く一致する(例:「空調工事中」も Vacant 判定)。
     *   空室率を過大に出す方向 = 安全側であり、「空」単体も実データにありうる値のため意図的に許容している。
     */
    public static function fromRawLabel(?string $raw): self
    {
        // ⚠ /u は PCRE2_UCP も立てるので \s だけでも U+3000 に当たる(PHP 8.3 / PCRE 10.47 で実測)。
        //   \x{3000} の明示は冗長だが、UCP 無効なビルドでも同じ挙動になるよう残している。
        $s = preg_replace('/[\s\x{3000}]+/u', '', (string) $raw);

        if ($s === '' || $s === '?' || $s === '？') {
            return self::Unknown;
        }

        $negated       = self::containsAny($s, self::NOT_OPERATING);
        $hitsOperating = ! $negated && self::containsAny($s, self::OPERATING);
        $hitsVacant    = self::containsAny($s, self::VACANT);

        // 両方の信号が立つときは判定不能。順序で勝敗を決めず Unknown へ倒す。
        if ($hitsOperating && $hitsVacant) {
            return self::Unknown;
        }

        if ($hitsOperating) {
            return self::Operating;
        }

        if ($hitsVacant) {
            return self::Vacant;
        }

        return self::Unknown;
    }

    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
