<?php

namespace App\Support\Approval;

/**
 * 再発行の結果（設計書 §5.12 の案内の画面に渡す）。
 */
final class ReissueResult
{
    /** @param  list<array{user: \App\Models\User, password: string}>  $entries */
    public function __construct(
        public readonly array $entries,
        public readonly int $notifiedCount,
        public readonly int $skippedCount,
    ) {}

    public function toGuide(): LoginGuide
    {
        return new LoginGuide($this->entries, $this->notifiedCount, $this->skippedCount);
    }
}
