<?php

namespace App\Enums;

/**
 * 決裁の申請の状態（要件定義書 4.8・設計書 §5.8）。
 *
 * ⚠ DB は VARCHAR（ENUM にしない。設計書 §5.3）。モデルで casts() にかけるので、読み出した属性は
 *   すでに enum。キャスト済みの属性に tryFrom() を呼ばない（Bug #22）。クエリには ->value を渡す。
 */
enum ApprovalStatus: string
{
    case Draft      = 'draft';
    case HeadReview = 'head_review';
    case Review     = 'review';
    case President  = 'president';
    case Returned   = 'returned';
    case Condition  = 'condition';
    case Approved   = 'approved';
    case Rejected   = 'rejected';
    case Withdrawn  = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft      => '下書き',
            self::HeadReview => '部門長確認中',
            self::Review     => '審査中',
            self::President  => '社長決裁待ち',
            self::Returned   => '差戻し中',
            self::Condition  => '条件確認待ち',
            self::Approved   => '決裁済み',
            self::Rejected   => '否決',
            self::Withdrawn  => '取り下げ',
        };
    }

    /** ステータスバッジは inline style を返す（Tailwind クラス指定は規約で NG） */
    public function badgeStyle(): string
    {
        return match ($this) {
            self::Draft                                     => 'background: #f3f4f6; color: #374151;',
            self::HeadReview, self::Review, self::President => 'background: #dbeafe; color: #1e40af;',
            self::Returned, self::Condition                 => 'background: #fef3c7; color: #92400e;',
            self::Approved                                  => 'background: #d1fae5; color: #065f46;',
            self::Rejected                                  => 'background: #fee2e2; color: #991b1b;',
            self::Withdrawn                                 => 'background: #f3f4f6; color: #6b7280;',
        };
    }

    /** 回覧中（部門長・審査・社長のどこかで待っている） */
    public function isInCirculation(): bool
    {
        return in_array($this, [self::HeadReview, self::Review, self::President], true);
    }

    /** 申請者が中身と添付を直せる（要件 5.3・設計書 §5.11） */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Returned], true);
    }

    /** 申請者が取り下げられる（要件 4.5） */
    public function isWithdrawable(): bool
    {
        return in_array($this, [self::HeadReview, self::Review, self::President, self::Returned], true);
    }

    /**
     * 自分の申請一覧（画面④）の絞り込み（設計書 §5.12）。キーは URL の `?filter=` に出る。
     *
     * @return array<string, array{label: string, statuses: list<self>}>
     */
    public static function listFilters(): array
    {
        return [
            'draft'     => ['label' => '下書き',   'statuses' => [self::Draft]],
            'progress'  => ['label' => '進行中',   'statuses' => [self::HeadReview, self::Review, self::President]],
            'returned'  => ['label' => '差戻し中', 'statuses' => [self::Returned]],
            'done'      => ['label' => '完了',     'statuses' => [self::Condition, self::Approved, self::Rejected]],
            'withdrawn' => ['label' => '取り下げ', 'statuses' => [self::Withdrawn]],
        ];
    }
}
