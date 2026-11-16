<?php

namespace App\Support;

use App\Models\ReCostItem;
use App\Models\ReProcurement;
use App\Models\ReProject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 不動産（仕入れ案件・分譲地）の原価の表をまとめて保存する（見込み額の表・確定額の表）。
 *
 * 1 行でも断るところがあれば何も書かない（断りは ValidationException）。
 * 順番: 親と原価の行をロック → 衝突（表を開いたあとで誰かが変えた）→ 行の決まり → 書く。
 * 断りの文言はここの MESSAGES だけ（画面の JS は同じ定数を @json で受け取る）。
 */
final class RealEstateCostGrid
{
    public const MESSAGES = [
        'notNumber'          => '金額は数字で入力してください。',
        'tooLarge'           => '2,147,483,647 以下で入力してください。',
        'hasActual'          => '確定額が入っているので、見込み額は空にできません（0 は入れられます）。',
        'onlyExecutive'      => '保存済みの行を消せるのは経営層だけです（0 は入れられます）。',
        'notesOnlyEstimated' => '見込み額を入力してください（備考だけでは保存できません）。',
        'notesOnlyActual'    => '確定額を入力してください（備考だけでは保存できません）。',
        'propertyPurchase'   => '物件購入費は査定価格・購入価格から自動で入るため、ここでは直せません。',
        'conflict'           => '表を開いたあとで、ほかの人がこの原価を変えました。画面を開き直してから入れ直してください。',
    ];

    /**
     * @param  array<int, array<string, mixed>>  $rows  検査済みの行（rows.*.id / cost_item_id / amount / notes / original）
     * @return array{message: string, costs: list<array<string, mixed>>}
     */
    public function save(ReProcurement|ReProject $owner, string $mode, array $rows, bool $canDelete): array
    {
        return DB::transaction(function () use ($owner, $mode, $rows, $canDelete) {
            // 1. 親と原価の行をロックする（同じ案件への同時の保存・削除・取込を待たせる）
            $locked = $owner::query()->whereKey($owner->getKey())->lockForUpdate()->first();
            $costs = $locked->costs()->with('costItem')->lockForUpdate()->get()->keyBy('id');

            // 2. 衝突（表を開いたあとで誰かが変えた・消えた）。1 つでもあれば何も保存しない
            if ($this->hasConflict($costs, $rows)) {
                throw ValidationException::withMessages(['rows' => self::MESSAGES['conflict']]);
            }

            // 3. 行の決まり（物件購入費が先）。全行を確かめて、断りを集める
            $newItems = ReCostItem::whereIn('id', collect($rows)->whereNull('id')->pluck('cost_item_id'))->get()->keyBy('id');
            $errors = [];
            $plan = [];
            foreach ($rows as $i => $row) {
                $cost = $row['id'] !== null ? $costs->get((int) $row['id']) : null;
                $item = $cost ? $cost->costItem : $newItems->get((int) $row['cost_item_id']);
                $amount = $row['amount'] !== null ? (int) $row['amount'] : null;
                $notes = $this->notes($row['notes'] ?? null);

                if ($item !== null && $item->isPropertyPurchase()) {
                    $errors["rows.{$i}.amount"] = [self::MESSAGES['propertyPurchase']];

                    continue;
                }

                $decided = $this->decide($mode, $cost, $amount, $notes, $canDelete);
                if (is_string($decided)) {
                    $errors["rows.{$i}.amount"] = [self::MESSAGES[$decided]];

                    continue;
                }
                $plan[] = $decided + ['cost' => $cost, 'row' => $row, 'amount' => $amount, 'notes' => $notes];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            // 4. 書く
            $added = $changed = $deleted = 0;
            foreach ($plan as $p) {
                /** @var Model|null $cost */
                $cost = $p['cost'];
                switch ($p['action']) {
                    case 'skip':
                        break;
                    case 'delete':
                        $cost->delete();
                        $deleted++;
                        break;
                    case 'create':
                        $locked->costs()->create([
                            'cost_item_id'     => (int) $p['row']['cost_item_id'],
                            'estimated_amount' => $mode === 'estimated' ? $p['amount'] : 0,
                            'actual_amount'    => $mode === 'actual' ? $p['amount'] : null,
                            'notes'            => $p['notes'],
                        ]);
                        $added++;
                        break;
                    case 'update':
                        $cost->fill([
                            $mode === 'estimated' ? 'estimated_amount' : 'actual_amount' => $p['amount'],
                            'notes' => $p['notes'],
                        ]);
                        if ($cost->isDirty()) {
                            $cost->save();
                            $changed++;
                        }
                        break;
                }
            }

            // 5. 応答
            return [
                'message' => $this->message($mode, $added, $changed, $deleted),
                'costs'   => RealEstateCostImportService::formatCostsForJson(
                    $locked->costs()->with('costItem')->orderBy('id')->get(),
                ),
            ];
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Model>  $costs  今の DB の原価の行（id で引ける）
     */
    private function hasConflict($costs, array $rows): bool
    {
        $seenIds = [];
        $newItemIds = [];
        $savedItemIds = $costs->pluck('cost_item_id')->all();

        foreach ($rows as $row) {
            if ($row['id'] !== null) {
                $id = (int) $row['id'];
                $cost = $costs->get($id);
                // その案件の行でない（消えた・ほかの案件の id）・同じ id が 2 つ
                if ($cost === null || isset($seenIds[$id])) {
                    return true;
                }
                $seenIds[$id] = true;

                $original = $row['original'] ?? [];
                if ($cost->estimated_amount !== $this->intOrNull($original['estimated_amount'] ?? null)
                    || $cost->actual_amount !== $this->intOrNull($original['actual_amount'] ?? null)
                    || Str::trim((string) ($cost->notes ?? '')) !== Str::trim((string) ($original['notes'] ?? ''))) {
                    return true;
                }
            } else {
                $itemId = (int) $row['cost_item_id'];
                // 新しい行の費用項目に、もう行がある・同じ項目の新しい行が 2 つ
                if (in_array($itemId, $savedItemIds, true) || isset($newItemIds[$itemId])) {
                    return true;
                }
                $newItemIds[$itemId] = true;
            }
        }

        return false;
    }

    /**
     * 1 行の決まり（設計書 7.3 の 2 つの表）。
     *
     * @return array{action: string}|string  動作、または断りの MESSAGES のキー
     */
    private function decide(string $mode, ?Model $cost, ?int $amount, ?string $notes, bool $canDelete): array|string
    {
        if ($amount === null) {
            if ($mode === 'estimated') {
                if ($cost === null) {
                    return $notes !== null ? 'notesOnlyEstimated' : ['action' => 'skip'];
                }
                if ($cost->actual_amount !== null) {
                    return 'hasActual';
                }

                return $canDelete ? ['action' => 'delete'] : 'onlyExecutive';
            }
            // 確定額: 空は「未定」に戻す（行は残す）
            if ($cost === null) {
                return $notes !== null ? 'notesOnlyActual' : ['action' => 'skip'];
            }

            return ['action' => 'update'];
        }

        return ['action' => $cost === null ? 'create' : 'update'];
    }

    private function message(string $mode, int $added, int $changed, int $deleted): string
    {
        if ($added + $changed + $deleted === 0) {
            return '変更はありませんでした。';
        }
        $parts = "追加 {$added} 件・変更 {$changed} 件" . ($deleted > 0 ? "・削除 {$deleted} 件" : '');

        return ($mode === 'estimated' ? '見込み額' : '確定額') . "を保存しました（{$parts}）。";
    }

    private function notes(mixed $notes): ?string
    {
        $trimmed = Str::trim((string) ($notes ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
