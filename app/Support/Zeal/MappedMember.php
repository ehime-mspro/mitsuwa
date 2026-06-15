<?php

namespace App\Support\Zeal;

class MappedMember
{
    /**
     * @param array<string,mixed> $memberAttributes
     * @param array<string,mixed>|null $contractAttributes
     * @param string[] $warnings
     * @param string[] $errors
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly string $displayName,
        public readonly string $status,
        public readonly string $kind,
        public readonly ?string $planName,
        public readonly string $rawPlan,
        public readonly ?int $sourceAmountIncl,
        public readonly ?int $courseListIncl,
        public readonly ?int $appliedPriceExcl,
        public readonly ?string $withdrewOn,
        public readonly ?string $scheduledOn,
        public readonly array $memberAttributes,
        public readonly ?array $contractAttributes,
        public readonly array $warnings,
        public readonly array $errors,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
