<?php

namespace Database\Seeders;

use App\Enums\DepartmentCode;
use App\Enums\UnitStatus;
use App\Models\Inquiry;
use App\Models\InquiryHistory;
use App\Models\InquiryUsageType;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 問合せサンプルデータ投入シーダー
 *
 * 実行: php artisan db:seed --class=InquirySampleSeeder
 *
 * - 既存のテナント物件・区画・ユーザーを動的に取得して使用
 * - 全5ステータス（フォロー/保留/成約/不成約/追客不可）を網羅
 * - 対応履歴も含めてリアルなデータを生成
 * - 既存の問合せがある場合は番号の重複を回避
 */
class InquirySampleSeeder extends Seeder
{
    public function run(): void
    {
        // --- 既存データの取得 ---
        $properties = Property::where('department', DepartmentCode::Tenant)
            ->with(['units'])
            ->get();

        if ($properties->isEmpty()) {
            $this->command->error('テナント物件が見つかりません。先に物件を登録してください。');
            return;
        }

        $user = User::where('status', 'active')->first();
        if (! $user) {
            $user = User::first();
        }
        if (! $user) {
            $this->command->error('ユーザーが見つかりません。先にユーザーを登録してください。');
            return;
        }

        $usageTypes = InquiryUsageType::orderBy('sort_order')->get();
        if ($usageTypes->isEmpty()) {
            $this->command->error('希望用途マスターが見つかりません。InquiryUsageTypeSeeder を先に実行してください。');
            return;
        }

        // --- 物件の準備（最大3物件使用） ---
        $prop1 = $properties->first();
        $prop2 = $properties->count() > 1 ? $properties->skip(1)->first() : $prop1;
        $prop3 = $properties->count() > 2 ? $properties->skip(2)->first() : $prop1;

        // 各物件の区画（最大2つ取得）
        $units1 = $prop1->units->take(2);
        $units2 = $prop2->units->take(2);
        $units3 = $prop3->units->take(2);

        $year = date('Y');

        // --- サンプルデータ定義（7件） ---
        $samples = [
            // 1. フォロー（アクティブ・対応履歴複数）
            [
                'property'    => $prop1,
                'units'       => $units1->pluck('id')->toArray(),
                'status'      => 'follow',
                'contact'     => '田中 太郎',
                'company'     => '田中商事株式会社',
                'phone'       => '03-1234-5678',
                'email'       => 'tanaka@example.com',
                'date'        => date('Y-m-d', strtotime('-10 days')),
                'source'      => 'phone',
                'usage_index' => 0, // 飲食店
                'area_min'    => 10.00,
                'area_max'    => 20.00,
                'budget'      => 15,
                'move_date'   => date('Y-m', strtotime('+3 months')),
                'description' => 'ラーメン店の出店を検討中。駅から近い物件を希望。',
                'notes'       => null,
                'histories'   => [
                    ['type' => 'first_contact', 'days_ago' => 10, 'content' => '問合せ受付'],
                    ['type' => 'consultation',  'days_ago' => 7,  'content' => '電話にて営業時間・客層・設備について質問あり。内見希望。'],
                    ['type' => 'viewing',       'days_ago' => 3,  'content' => '現地内見実施。広さ・立地に好感触。厨房設備の追加工事について検討中。'],
                ],
            ],
            // 2. フォロー（シンプル・区画未定）
            [
                'property'    => $prop2,
                'units'       => [],
                'status'      => 'follow',
                'contact'     => '鈴木 花子',
                'company'     => null,
                'phone'       => '090-9876-5432',
                'email'       => null,
                'date'        => date('Y-m-d', strtotime('-3 days')),
                'source'      => 'website',
                'usage_index' => 4, // 事務所
                'area_min'    => null,
                'area_max'    => null,
                'budget'      => 10,
                'move_date'   => null,
                'description' => 'ホームページを見て問合せ。小規模事務所を探している。',
                'notes'       => null,
                'histories'   => [
                    ['type' => 'first_contact', 'days_ago' => 3, 'content' => '問合せ受付'],
                ],
            ],
            // 3. 保留
            [
                'property'    => $prop1,
                'units'       => $units1->take(1)->pluck('id')->toArray(),
                'status'      => 'on_hold',
                'contact'     => '山田 健一',
                'company'     => '株式会社山田工務店',
                'phone'       => '03-5555-1234',
                'email'       => 'yamada-k@example.com',
                'date'        => date('Y-m-d', strtotime('-30 days')),
                'source'      => 'referral',
                'usage_index' => 7, // フィットネス・ジム
                'area_min'    => 30.00,
                'area_max'    => 50.00,
                'budget'      => 25,
                'move_date'   => date('Y-m', strtotime('+6 months')),
                'description' => 'フィットネスジムの出店を検討。広めの区画を希望。紹介経由。',
                'notes'       => '資金調達の見通しが立つまで保留。',
                'histories'   => [
                    ['type' => 'first_contact', 'days_ago' => 30, 'content' => '問合せ受付'],
                    ['type' => 'viewing',       'days_ago' => 25, 'content' => '現地内見。設備・広さに問題なし。'],
                    ['type' => 'negotiation',   'days_ago' => 20, 'content' => '賃料条件を提示。予算と折り合わず調整中。'],
                    ['type' => 'other',         'days_ago' => 14, 'content' => 'ステータスを「保留」に変更'],
                ],
            ],
            // 4. フォロー（別物件・看板経由）
            [
                'property'    => $prop3,
                'units'       => $units3->take(1)->pluck('id')->toArray(),
                'status'      => 'follow',
                'contact'     => '佐藤 美咲',
                'company'     => 'ビューティーサロン Misaki',
                'phone'       => '080-1111-2222',
                'email'       => 'misaki@example.com',
                'date'        => date('Y-m-d', strtotime('-5 days')),
                'source'      => 'signage',
                'usage_index' => 2, // 美容室・理容室
                'area_min'    => 15.00,
                'area_max'    => 25.00,
                'budget'      => 12,
                'move_date'   => date('Y-m', strtotime('+2 months')),
                'description' => '看板を見て来訪。美容室の移転先を探している。駐車場の有無を確認したい。',
                'notes'       => null,
                'histories'   => [
                    ['type' => 'first_contact', 'days_ago' => 5, 'content' => '問合せ受付'],
                    ['type' => 'consultation',  'days_ago' => 3, 'content' => '電話にて条件確認。来週内見予定。'],
                ],
            ],
            // 5. 成約（契約未連携）
            [
                'property'    => $prop1,
                'units'       => $units1->take(1)->pluck('id')->toArray(),
                'status'      => 'converted',
                'contact'     => '高橋 直樹',
                'company'     => '高橋クリニック',
                'phone'       => '03-3333-4444',
                'email'       => 'takahashi-clinic@example.com',
                'date'        => date('Y-m-d', strtotime('-60 days')),
                'source'      => 'referral',
                'usage_index' => 3, // クリニック
                'area_min'    => 20.00,
                'area_max'    => 30.00,
                'budget'      => 30,
                'move_date'   => date('Y-m', strtotime('-1 month')),
                'description' => 'クリニック開業予定。紹介経由で問合せ。',
                'notes'       => null,
                'result_reason' => '条件面で合意。契約手続きに進む。',
                'histories'   => [
                    ['type' => 'first_contact', 'days_ago' => 60, 'content' => '問合せ受付'],
                    ['type' => 'viewing',       'days_ago' => 55, 'content' => '現地内見実施。設備・立地に満足。'],
                    ['type' => 'negotiation',   'days_ago' => 45, 'content' => '賃料・敷金の条件を交渉。'],
                    ['type' => 'follow_up',     'days_ago' => 35, 'content' => '条件回答待ち。先方の開業スケジュールを確認。'],
                    ['type' => 'other',         'days_ago' => 30, 'content' => 'ステータスを「成約」に変更'],
                ],
            ],
            // 6. 不成約
            [
                'property'    => $prop2,
                'units'       => $units2->take(1)->pluck('id')->toArray(),
                'status'      => 'lost',
                'contact'     => '伊藤 雄二',
                'company'     => '伊藤書店',
                'phone'       => '03-6666-7777',
                'email'       => null,
                'date'        => date('Y-m-d', strtotime('-45 days')),
                'source'      => 'phone',
                'usage_index' => 1, // 物販店
                'area_min'    => 10.00,
                'area_max'    => 15.00,
                'budget'      => 8,
                'move_date'   => null,
                'description' => '書店の移転先を検討中。',
                'notes'       => null,
                'result_reason' => '他物件に決定。当物件は駅からの距離がネックとのこと。',
                'histories'   => [
                    ['type' => 'first_contact', 'days_ago' => 45, 'content' => '問合せ受付'],
                    ['type' => 'viewing',       'days_ago' => 40, 'content' => '現地内見。広さは良いが立地に不安。'],
                    ['type' => 'other',         'days_ago' => 25, 'content' => 'ステータスを「不成約」に変更'],
                ],
            ],
            // 7. 追客不可
            [
                'property'    => $prop1,
                'units'       => [],
                'status'      => 'unreachable',
                'contact'     => '中村 一郎',
                'company'     => null,
                'phone'       => '090-0000-1111',
                'email'       => null,
                'date'        => date('Y-m-d', strtotime('-90 days')),
                'source'      => 'unknown',
                'usage_index' => null,
                'area_min'    => null,
                'area_max'    => null,
                'budget'      => null,
                'move_date'   => null,
                'description' => '電話で問合せ。用途は未定。',
                'notes'       => null,
                'result_reason' => '複数回連絡するも応答なし。電話番号が変わっている可能性あり。',
                'histories'   => [
                    ['type' => 'first_contact', 'days_ago' => 90, 'content' => '問合せ受付'],
                    ['type' => 'follow_up',     'days_ago' => 80, 'content' => '折り返し電話するも不在。留守番電話にメッセージ残す。'],
                    ['type' => 'follow_up',     'days_ago' => 70, 'content' => '再度電話。応答なし。'],
                    ['type' => 'other',         'days_ago' => 60, 'content' => 'ステータスを「追客不可」に変更'],
                ],
            ],
        ];

        // --- 既存の問合せ番号の最大値を取得（重複回避） ---
        $prefix = "INQ-{$year}-";
        $lastNumber = Inquiry::withTrashed()
            ->where('inquiry_number', 'like', "{$prefix}%")
            ->orderByDesc('inquiry_number')
            ->value('inquiry_number');

        $seq = $lastNumber ? (int) substr($lastNumber, -3) : 0;

        // --- 投入 ---
        DB::transaction(function () use ($samples, $usageTypes, $user, $prefix, &$seq) {
            foreach ($samples as $s) {
                $seq++;
                $inquiryNumber = $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);

                $usageId = null;
                if ($s['usage_index'] !== null && isset($usageTypes[$s['usage_index']])) {
                    $usageId = $usageTypes[$s['usage_index']]->id;
                }

                $inquiry = Inquiry::create([
                    'inquiry_number'   => $inquiryNumber,
                    'property_id'      => $s['property']->id,
                    'status'           => $s['status'],
                    'contact_name'     => $s['contact'],
                    'company_name'     => $s['company'],
                    'phone'            => $s['phone'],
                    'email'            => $s['email'],
                    'inquiry_date'     => $s['date'],
                    'source'           => $s['source'],
                    'desired_usage_id' => $usageId,
                    'desired_area_min' => $s['area_min'],
                    'desired_area_max' => $s['area_max'],
                    'budget_max'       => $s['budget'],
                    'desired_move_date' => $s['move_date'],
                    'description'      => $s['description'],
                    'result_reason'    => $s['result_reason'] ?? null,
                    'assigned_to'      => $user->id,
                    'notes'            => $s['notes'],
                ]);

                // 希望区画
                if (! empty($s['units'])) {
                    $inquiry->units()->sync($s['units']);
                }

                // 対応履歴
                foreach ($s['histories'] as $h) {
                    InquiryHistory::create([
                        'inquiry_id'  => $inquiry->id,
                        'action_type' => $h['type'],
                        'action_date' => date('Y-m-d', strtotime("-{$h['days_ago']} days")),
                        'content'     => $h['content'],
                        'created_by'  => $user->id,
                    ]);
                }
            }
        });

        $this->command->info("問合せサンプルデータ {$seq} 件を投入しました。");
    }
}
