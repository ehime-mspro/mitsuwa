<?php

namespace App\Support\Approval;

/**
 * 5W2H の見出し（要件 5.2）と「見出しのまま」の判定（設計書 D12）。
 */
final class BodyTemplate
{
    /** 標準の見出し（「いつ」「いくら」は実施時期・金額の欄で書くので入れない。要件 5.2） */
    public const DEFAULT = "■ なぜ（目的・理由）\n・\n■ 何を（内容）\n・\n■ 誰が（担当・実施者）\n・\n■ どこで（場所・対象）\n・\n■ どのように（方法・手順）\n・\n■ 補足（費用の内訳・その他）\n・";

    /**
     * 何も書き足していないか。
     *
     * 見出しの行（`■` で始まる行）・中身の無い `・` の行・空の行を除いて、何も残らなければ「書いていない」。
     * ⚠ 前後の空白は全角（U+3000）も落とす（`trim()` は全角の空白を落とさない）。
     */
    public static function isBlank(?string $body): bool
    {
        foreach (preg_split('/\R/u', (string) $body) as $line) {
            $line = preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $line);

            if ($line === '' || $line === '・' || str_starts_with($line, '■')) {
                continue;
            }

            return false;
        }

        return true;
    }
}
