<?php

namespace App\Models;

use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

/** 添付を開いた記録（要件 14.2・設計書 §5.7）。**追記のみ。** 段階4 で PDF・Excel を足す */
class ApprovalDownloadLog extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $fillable = ['request_id', 'attachment_id', 'user_id', 'kind', 'ip_address', 'user_agent'];
}
