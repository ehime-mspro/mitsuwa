<?php

namespace App\Support\Approval;

use RuntimeException;

/** 今の状態・権限ではできない操作（理由を画面に出す） */
final class WorkflowRefused extends RuntimeException
{
    /** @param list<string> $reasons */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct($reasons[0] ?? 'この操作はできません。');
    }
}
