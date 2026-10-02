<?php

namespace App\Models;

use App\Enums\UserStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 決裁の部門（設計書 §5.8）。
 *
 * ⚠ 基幹の `Department` とは別物。基幹の部門はデータを見られる範囲（tenant / realestate …）で、
 *   こちらは申請の宛先と申請番号に使う（D1）。
 */
class ApprovalDepartment extends Model
{
    protected $fillable = ['company_id', 'name', 'short_name', 'code', 'sort_order', 'head_user_id'];

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'sort_order' => 'integer', 'head_user_id' => 'integer'];
    }

    /** 部門長（⚠ 利用者は SoftDeletes。Top trap #18） */
    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id')->withTrashed();
    }

    /**
     * 審査担当者（設計書 §5.4。所属は問わない。D6）。
     *
     * ⚠ `withTrashed()` を付けない（Top trap #18 の例外）。今判断できる人の並びで、提出の条件（SubmitChecker）と
     *   「いま誰の番か」（CurrentHandler）が使う。利用者を削除しても status は active のままなので、付けると
     *   削除した人を審査担当者に数え、誰も判断できない審査部門へ申請が通ってしまう。
     */
    public function reviewers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'approval_reviewers', 'department_id', 'user_id')
                    ->withTimestamps('created_at', false);
    }

    /**
     * 有効な審査担当者（今判断できる人。段階3 設計書 §5.7）。「有効な審査担当者」の条件はここ 1 か所にする。
     * 提出の条件（SubmitChecker）・申請種類の一覧の人数（TypeController）・⑩ の印（AdminRequestController）・
     * 知らせの宛先（StepHandlers）が使う。削除した人は reviewers() と同じく SoftDeletes で入らない。
     *
     * ⚠ 「いま誰の番か」の名前（CurrentHandler）と部門の管理の一覧は reviewers() のまま（無効の人の名前も出す。振る舞いを変えない）
     */
    public function activeReviewers(): BelongsToMany
    {
        return $this->reviewers()->where('users.status', UserStatus::Active->value);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class, 'department_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ApprovalCompany::class, 'company_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'approval_department_user', 'department_id', 'user_id')
                    ->withPivot('created_at');
    }
}
