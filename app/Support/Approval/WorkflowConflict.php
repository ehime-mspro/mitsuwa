<?php

namespace App\Support\Approval;

use RuntimeException;

/** ほかの人（または別のタブの自分）が先に操作した（要件 4.2・計画 §0.3） */
final class WorkflowConflict extends RuntimeException
{
    public const MESSAGE = 'すでに処理されています。画面を開き直して、今の状態を確かめてください。';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
