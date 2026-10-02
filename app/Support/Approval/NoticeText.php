<?php

namespace App\Support\Approval;

use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStepKind;

/**
 * 場面ごとの知らせの文（メールの件名の見出し・必要な対応・本文の前置き）を 1 か所に（段階3 設計書 §5.5 の表）。
 * お知らせの文もメールの件名も同じ見出しから作る。
 *
 * ⚠ 金額・本文・添付・コメント（差戻しの理由・条件）は書かない（要件 8.2）。
 */
final class NoticeText
{
    /** 対応が要らない知らせの「必要な対応」 */
    public const NO_ACTION = '対応は要りません（お知らせです）';

    /** 場面 2 のわけ（本文の 1 行目。D1） */
    public const REASSIGNED        = '決裁の管理者が担当を付け替えたため、あなたの担当になりました。';
    public const HEAD_CHANGED      = '部門長が交代したため、あなたの担当になりました。';
    public const PRESIDENT_CHANGED = '社長の指定が変わったため、あなたの担当になりました。';
    public const REVIEWER_ADDED    = '審査担当者に加わったため、あなたの担当になりました。';

    /** 担当の番（段階ごと）: [見出し, 必要な対応, 番の言い方] */
    private const TURNS = [
        'head'      => ['部門長の確認のお願い', '部門長の確認（承認か差戻し）', '確認'],
        'review'    => ['審査の意見のお願い', '審査の意見（可・保留・否）', '審査の意見'],
        'president' => ['社長の決裁のお願い', '社長の決裁（可・条可・差戻し・否）', '決裁'],
    ];

    private const CONDITION_ACTION = '条件を確かめて「条件を確認しました」を押してください';

    /** 場面 1: 自分の番が来た */
    public static function turn(ApprovalStepKind $kind): Notice
    {
        [$headline, $action, $turn] = self::TURNS[$kind->value];

        return new Notice('turn', $headline, $action, ["次の申請が、あなたの{$turn}の番になりました。"], handlerTurn: true);
    }

    /** 場面 2: 担当が入れ替わった（件名と対応は場面 1 と同じ。本文の 1 行目にわけ） */
    public static function handlerChanged(ApprovalStepKind $kind, string $why): Notice
    {
        [$headline, $action, $turn] = self::TURNS[$kind->value];

        return new Notice('changed', $headline, $action, [$why, "次の申請が、あなたの{$turn}の番になりました。"], handlerTurn: true);
    }

    /** 場面 3: 差戻しされた（申請者へ） */
    public static function returned(ApprovalStepKind $by): Notice
    {
        return new Notice('returned', '差戻しされました', '直して出し直すか、取り下げてください', ["次の申請が{$by->label()}から差し戻されました。"]);
    }

    /** 場面 4（可・否）と、場面 5 の部門長・審査担当者（条可） */
    public static function decided(ApprovalDecision $decision): Notice
    {
        return match ($decision) {
            ApprovalDecision::Approve     => new Notice('decided', '決裁されました（可）', self::NO_ACTION, ['次の申請が決裁されました（可）。']),
            ApprovalDecision::Conditional => new Notice('decided', '決裁されました（条可）', self::NO_ACTION, ['次の申請が条件付きで決裁されました（条可）。']),
            ApprovalDecision::Reject      => new Notice('decided', '否決されました', self::NO_ACTION, ['次の申請が否決されました。']),
        };
    }

    /** 場面 5 の申請者: 条件の確認のお願い */
    public static function conditionRequested(): Notice
    {
        return new Notice('condition', '条件の確認のお願い（条可）', self::CONDITION_ACTION, ['次の申請が条件付きで決裁されました（条可）。社長の条件を確かめてください。']);
    }

    /** 場面 6: 条件が確認された（お知らせだけ） */
    public static function conditionConfirmed(): Notice
    {
        return new Notice('confirmed', '条件が確認されました', self::NO_ACTION, ['申請者が条件を確認し、決裁が完了しました。'], mailed: false);
    }

    /** 場面 7: 取り下げられた（管理者の代理なら、そう書く。D8） */
    public static function withdrawn(bool $byAdmin): Notice
    {
        return new Notice('withdrawn', '取り下げられました', self::NO_ACTION, [
            $byAdmin ? '決裁の管理者が、申請者に代わって次の申請を取り下げました。' : '申請者が次の申請を取り下げました。',
        ]);
    }

    /** 場面 8: 操作が取り消された（取り消された操作をした人・申請者。対応は要らない） */
    public static function undone(string $undoneLabel): Notice
    {
        return new Notice('undone', '操作が取り消されました', self::NO_ACTION, ["決裁の管理者が「{$undoneLabel}」を取り消しました。"]);
    }

    /**
     * 場面 8: 取り消しで番が戻った人（戻った段階の今の担当。$kind が null なら条件の確認を取り消された申請者）
     */
    public static function undoneTurn(string $undoneLabel, ?ApprovalStepKind $kind): Notice
    {
        $first = "決裁の管理者が「{$undoneLabel}」を取り消しました。";

        if ($kind === null) {
            return new Notice('undone', '操作が取り消されました', self::CONDITION_ACTION, [$first, 'もう一度、条件を確認してください。']);
        }

        [, $action, $turn] = self::TURNS[$kind->value];

        return new Notice('undone', '操作が取り消されました', $action, [$first, "次の申請が、もう一度あなたの{$turn}の番になりました。"], handlerTurn: true);
    }
}
