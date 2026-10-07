<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * 添付・PDF・台帳の Excel を出力した記録（要件 14.2・段階4 設計書 §5.7・D25）。**追記のみ。**
 *
 * ⚠ 書くのは `App\Support\Approval\DownloadLogger` だけ（IP と端末の書き方を 1 か所にそろえる）。
 * ⚠ Excel の行は申請の欄（request_id）が空で、絞り込みの条件（filters）と件数（request_count）を控える。
 */
class ApprovalDownloadLog extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    /** 記録の種類（DB の値。本番の行がこの値で残るので変えない） */
    public const KIND_ATTACHMENT = 'attachment';

    public const KIND_PDF = 'pdf';

    public const KIND_EXCEL = 'excel';

    protected $fillable = ['request_id', 'attachment_id', 'user_id', 'kind', 'ip_address', 'user_agent', 'filters', 'request_count'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'request_count' => 'integer'];
    }
}
