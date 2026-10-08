<?php

namespace App\Support\Approval;

use App\Models\ApprovalAttachment;
use App\Models\ApprovalRequest;

/**
 * 提出ごとの中身の控え（設計書 §5.11）と、控えどうしの比べ方（2b の変更点と履歴・差戻しの取り消し。§5.13・§5.14）。
 *
 * ⚠ 名前（種類名・部門名・ファイル名）も一緒に控える。あとで種類や部門の名前が変わっても、
 *   その回に出した中身のまま見せるため。
 * ⚠ 段階5: 明細表（`amount_table`。明細表の種類だけ・5W2H の種類は null）・追加の入力欄（`extras`。提出したときに種類が使っていた
 *   欄だけ。キーは RequestExtras::FIELDS）・定型文（`fixed_text`）も控える。段階5 より前の控えにはこれらのキーが無い（無ければ空として読む）。
 * ⚠ 控えは JSON の列。MySQL は JSON のオブジェクトのキーを並べ替えて返す（キーの長さの順）ので、
 *   控えを丸ごと `==`・`===` で比べない。決まったキーを取り出して比べる（§5.16）。
 */
final class RequestSnapshot
{
    /** @return array<string, mixed> */
    public static function make(ApprovalRequest $request): array
    {
        $request->loadMissing(['type', 'department']);

        return [
            'type'            => ['id' => $request->type_id, 'name' => $request->type?->name],
            'department'      => ['id' => $request->department_id, 'name' => $request->department?->name],
            'subject'         => $request->subject,
            'amount'          => $request->amount,
            'schedule'        => $request->schedule,
            'body'            => $request->body,
            'related_numbers' => $request->related_numbers ?? [],
            'attachments'     => $request->attachments()->get()
                ->map(fn (ApprovalAttachment $a) => ['id' => $a->id, 'name' => $a->original_name, 'size' => $a->size])
                ->all(),
            'amount_table'    => $request->amount_table,
            'extras'          => RequestExtras::valuesOf($request, $request->type),
            'fixed_text'      => $request->fixed_text,
        ];
    }

    /**
     * 直前の回の控えと今の回の控えの違い（出し直した申請の変更点。§5.13）。変わったものだけを返す。
     *
     * - 種類・申請部門は id で比べ、名前を出す（管理者があとで名前を変えただけなら変わっていない）
     * - 関連する決裁No は並びを無視して比べる（並べ替えただけなら変わっていない）
     * - 本文は行ごとの差（LineDiff）。添付は id で足したもの・外したもの
     * - 追加の入力欄（坪数・坪単価・担当者・契約予定日）は件名などと同じ並び。明細表は行ごと（tableChanges。段階5 §5.6）
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array{
     *     fields: list<array{label: string, before: string, after: string}>,
     *     body: list<array{type: string, line: string}>|null,
     *     table: list<array{section: string, kind: string, before: ?array, after: ?array, changed: list<string>}>|null,
     *     attachments_added: list<array{id: int, name: string, size: int}>,
     *     attachments_removed: list<array{id: int, name: string, size: int}>
     * }
     */
    public static function changes(array $before, array $after): array
    {
        $fields = [];

        if (self::idOf($before, 'type') !== self::idOf($after, 'type')) {
            $fields[] = ['label' => '申請の種類', 'before' => self::text($before['type']['name'] ?? null), 'after' => self::text($after['type']['name'] ?? null)];
        }
        if (self::idOf($before, 'department') !== self::idOf($after, 'department')) {
            $fields[] = ['label' => '申請部門', 'before' => self::text($before['department']['name'] ?? null), 'after' => self::text($after['department']['name'] ?? null)];
        }
        if (($before['subject'] ?? null) !== ($after['subject'] ?? null)) {
            $fields[] = ['label' => '件名', 'before' => self::text($before['subject'] ?? null), 'after' => self::text($after['subject'] ?? null)];
        }
        if (self::intOrNull($before['amount'] ?? null) !== self::intOrNull($after['amount'] ?? null)) {
            $fields[] = ['label' => '金額（税抜）', 'before' => self::amount($before['amount'] ?? null), 'after' => self::amount($after['amount'] ?? null)];
        }
        if (($before['schedule'] ?? null) !== ($after['schedule'] ?? null)) {
            $fields[] = ['label' => '実施時期', 'before' => self::text($before['schedule'] ?? null), 'after' => self::text($after['schedule'] ?? null)];
        }
        $numbersBefore = self::numbers($before);
        $numbersAfter  = self::numbers($after);
        if (self::sorted($numbersBefore) !== self::sorted($numbersAfter)) {
            $fields[] = ['label' => '関連する決裁No', 'before' => self::text(implode('・', $numbersBefore)), 'after' => self::text(implode('・', $numbersAfter))];
        }
        $extrasBefore = RequestExtras::ordered($before['extras'] ?? null);
        $extrasAfter  = RequestExtras::ordered($after['extras'] ?? null);
        foreach (RequestExtras::FIELDS as $key => $label) {
            $was = RequestExtras::display($key, $extrasBefore[$key] ?? null);
            $now = RequestExtras::display($key, $extrasAfter[$key] ?? null);
            if ($was !== $now) {
                $fields[] = ['label' => $label, 'before' => self::text($was), 'after' => self::text($now)];
            }
        }

        $body = LineDiff::text($before['body'] ?? null, $after['body'] ?? null);

        $attachmentsBefore = self::attachments($before);
        $attachmentsAfter  = self::attachments($after);

        return [
            'fields'              => $fields,
            'body'                => LineDiff::hasChanges($body) ? $body : null,
            'table'               => self::tableChanges($before['amount_table'] ?? null, $after['amount_table'] ?? null),
            'attachments_added'   => array_values(array_diff_key($attachmentsAfter, $attachmentsBefore)),
            'attachments_removed' => array_values(array_diff_key($attachmentsBefore, $attachmentsAfter)),
        ];
    }

    /** changes() の結果に変わったものがあるか */
    public static function hasChanges(array $changes): bool
    {
        return $changes['fields'] !== [] || $changes['body'] !== null || ($changes['table'] ?? null) !== null
            || $changes['attachments_added'] !== [] || $changes['attachments_removed'] !== [];
    }

    /**
     * 明細表の行ごとの違い（要件 4.4。項目・販売金額・工事原価）。前半・後半ごとに、詳細に出す行（名前も金額も無い自由行を除く）を
     * **行の鍵（名前を設定した行か・項目名）で合わせる**（本文と同じ最長共通部分列＝LineDiff。真ん中の自由行を消したり空にしたりしても、
     * 変わっていないほかの行は「消えた」と出ない。点検の I-2）。同じ鍵の行は販売金額と工事原価を比べ、合わない行は増えた行・消えた行。
     * 項目名を直した自由行は、消えた行と増えた行で出る。変わった行・増えた行・消えた行だけを返す
     *
     * @param mixed $before 前の回の明細表（無ければ空）
     * @param mixed $after  今の回の明細表
     * @return list<array{section: string, kind: string, before: ?array, after: ?array, changed: list<string>}>|null
     */
    private static function tableChanges(mixed $before, mixed $after): ?array
    {
        $key  = fn (array $row): string => ($row['fixed'] ? 'F:' : 'N:') . ($row['name'] ?? '');
        $rows = [];
        foreach (AmountTable::SECTIONS as $section) {
            $was = AmountTable::shownRows(is_array($before) ? $before : [], $section);
            $now = AmountTable::shownRows(is_array($after) ? $after : [], $section);
            $i   = 0;
            $j   = 0;

            foreach (LineDiff::lines(array_map($key, $was), array_map($key, $now)) as $op) {
                if ($op['type'] === LineDiff::REMOVED) {
                    $rows[] = ['section' => $section, 'kind' => 'removed', 'before' => $was[$i++], 'after' => null, 'changed' => []];
                } elseif ($op['type'] === LineDiff::ADDED) {
                    $rows[] = ['section' => $section, 'kind' => 'added', 'before' => null, 'after' => $now[$j++], 'changed' => []];
                } else {
                    $old     = $was[$i++];
                    $new     = $now[$j++];
                    $changed = array_values(array_filter(['sale', 'cost'], fn (string $field) => $old[$field] !== $new[$field]));
                    if ($changed !== []) {
                        $rows[] = ['section' => $section, 'kind' => 'changed', 'before' => $old, 'after' => $new, 'changed' => $changed];
                    }
                }
            }
        }

        return $rows === [] ? null : $rows;
    }

    /**
     * 申請者が直せる中身の指紋（差戻しの取り消し D3 で「差戻しのあと中身か添付を直し始めたか」を比べる。§5.14・§5.16）。
     *
     * 名前（種類名・部門名・ファイル名）は入れない（管理者があとで名前を変えても「直した」にしない）。
     * 決まった並びの配列に詰め直すので、MySQL が JSON のキーを並べ替えて返しても `===` で比べられる。
     * 関連する決裁No の並べ替えは直したことに数える（申請者が画面で動かしたため）。
     *
     * @param array<string, mixed> $snapshot make() の形（控えの JSON を読んだものも同じ）
     * @return array<string, mixed>
     */
    public static function editableFingerprint(array $snapshot): array
    {
        $attachmentIds = array_keys(self::attachments($snapshot));
        sort($attachmentIds);

        return [
            'type_id'         => self::idOf($snapshot, 'type'),
            'department_id'   => self::idOf($snapshot, 'department'),
            'subject'         => self::stringOrNull($snapshot['subject'] ?? null),
            'amount'          => self::intOrNull($snapshot['amount'] ?? null),
            'schedule'        => self::stringOrNull($snapshot['schedule'] ?? null),
            'body'            => self::stringOrNull($snapshot['body'] ?? null),
            'related_numbers' => self::numbers($snapshot),
            'attachment_ids'  => $attachmentIds,
            // 段階5: 明細表（行の並びと中身。キーの並びは rowsOf でそろえる）と追加の入力欄（値は文字にそろえる）。
            // 定型文は申請者が直すものではない（保存のときに種類から写す）ので入れない
            'amount_table'    => array_map(fn (string $section) => AmountTable::rowsOf(is_array($snapshot['amount_table'] ?? null) ? $snapshot['amount_table'] : null, $section), AmountTable::SECTIONS),
            // 追加の欄は 4 つとも（無い欄は空）。種類の「使う」の設定で欄が増えたり減ったりしても、値が同じなら同じ指紋（点検の M-1）
            'extras'          => array_map(fn (mixed $value) => $value === null || $value === '' ? null : (string) $value, RequestExtras::allOf($snapshot['extras'] ?? null)),
        ];
    }

    /**
     * 今の中身（申請の行と今の添付）の指紋（差戻しの取り消しで、控えの指紋と比べる）。追加の欄は種類の今の設定で絞らずに 4 つの列を
     * そのまま入れる（差戻しのあと管理者が種類の「使う」を変えても、申請者が何も直していなければ控えと同じ指紋になる。使わない欄は
     * 保存のときに空になっているので、控えに無い欄は空どうしで合う。点検の M-1）
     *
     * @return array<string, mixed>
     */
    public static function currentFingerprint(ApprovalRequest $request): array
    {
        return self::editableFingerprint(['extras' => RequestExtras::columnsOf($request)] + self::make($request));
    }

    private static function idOf(array $snapshot, string $key): ?int
    {
        return self::intOrNull($snapshot[$key]['id'] ?? null);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /** @return list<string> */
    private static function numbers(array $snapshot): array
    {
        return array_values(array_map('strval', $snapshot['related_numbers'] ?? []));
    }

    /** @param list<string> $numbers @return list<string> */
    private static function sorted(array $numbers): array
    {
        sort($numbers);

        return $numbers;
    }

    /** 添付（id => {id, name, size}） @return array<int, array{id: int, name: string, size: int}> */
    private static function attachments(array $snapshot): array
    {
        $out = [];
        foreach ($snapshot['attachments'] ?? [] as $attachment) {
            $id       = (int) $attachment['id'];
            $out[$id] = ['id' => $id, 'name' => (string) ($attachment['name'] ?? ''), 'size' => (int) ($attachment['size'] ?? 0)];
        }

        return $out;
    }

    private static function text(?string $value): string
    {
        return ($value === null || $value === '') ? '（なし）' : $value;
    }

    private static function amount(mixed $value): string
    {
        return $value === null ? '（なし）' : number_format((int) $value) . '円';
    }
}
