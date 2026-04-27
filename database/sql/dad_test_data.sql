-- DAD モジュール 動作確認用テストデータ
-- 実行: sudo mysql manage < database/sql/dad_test_data.sql
-- 削除: sudo mysql manage -e "DELETE FROM dad_project_assignments; DELETE FROM dad_project_costs; DELETE FROM dad_projects; DELETE FROM dad_employees; DELETE FROM dad_subcontractors; DELETE FROM dad_clients;"

SET NAMES utf8mb4;

-- 担当者の user_id（佐伯 政則 を想定。なければ id=1 を使用）
SET @user_id := COALESCE((SELECT id FROM users WHERE name LIKE '%佐伯%' LIMIT 1), 1);

-- 1. 発注者（公共事業）
INSERT INTO dad_clients
  (client_type, name, representative, postal_code, address, phone, email, notes, created_by, created_at, updated_at)
VALUES
  ('municipality', '松山市役所 建設部', '山田 一郎', '7900003', '愛媛県松山市中央', '089-948-6111', 'kensetsu@city.matsuyama.ehime.jp', '動作確認用テスト発注者', @user_id, NOW(), NOW());
SET @client_id := LAST_INSERT_ID();

-- 2. 協力業者 2 件（土工 / 舗装）
INSERT INTO dad_subcontractors
  (specialty_id, company_name, representative, postal_code, address, phone, email, notes, created_by, created_at, updated_at)
VALUES
  ((SELECT id FROM dad_specialties WHERE name='土工' LIMIT 1), '日進建設株式会社', '田中 浩二', '7900012', '愛媛県松山市湊町', '089-933-1234', 'info@nisshin.jp', '土工事メイン', @user_id, NOW(), NOW()),
  ((SELECT id FROM dad_specialties WHERE name='舗装' LIMIT 1), '愛媛舗装工業', '鈴木 雅彦', '7900822', '愛媛県松山市古川北', '089-957-5678', 'eimei@example.jp', '舗装専門', @user_id, NOW(), NOW());

SET @sub1_id := (SELECT id FROM dad_subcontractors WHERE company_name='日進建設株式会社' LIMIT 1);
SET @sub2_id := (SELECT id FROM dad_subcontractors WHERE company_name='愛媛舗装工業' LIMIT 1);

-- 3. 従業員 1 件
INSERT INTO dad_employees
  (employee_code, name, name_kana, phone, position, qualifications, hire_date, status, notes, created_at, updated_at)
VALUES
  ('E001', '佐藤 健', 'サトウ ケン', '090-1234-5678', '現場代理人', '一級土木施工管理技士\n二級建築施工管理技士', '2020-04-01', 'active', NULL, NOW(), NOW());
SET @emp_id := LAST_INSERT_ID();

-- 4. 工事案件 1 件
INSERT INTO dad_projects
  (project_code, project_name, project_type, status, client_id, site_address, latitude, longitude,
   estimate_amount, contract_amount,
   estimate_date, order_date, start_date, period_start, period_end,
   staff_user_id, memo, created_by, updated_by, created_at, updated_at)
VALUES
  ('DAD-001', '松山駅前道路改良工事', 'public', 'in_progress',
   @client_id, '愛媛県松山市駅前町2丁目', 33.8417000, 132.7665000,
   28000000, 27500000,
   '2026-02-15', '2026-03-01', '2026-04-15', '2026-04-15', '2026-09-30',
   @user_id, '部分実績入力で 3 モードハイブリッド表示テスト', @user_id, @user_id, NOW(), NOW());
SET @project_id := LAST_INSERT_ID();

-- 5. 原価明細 4 行（2 行に実績入力 → ハイブリッドモード）
INSERT INTO dad_project_costs
  (project_id, cost_category, description, estimated_amount, actual_amount, subcontractor_id, notes, created_at, updated_at)
VALUES
  (@project_id, 'material',    '砕石・砂利',  3500000, 3650000, @sub1_id, '実績超過 +150,000円', NOW(), NOW()),
  (@project_id, 'subcontract', '土工事',      8000000, 8200000, @sub1_id, '実績超過 +200,000円', NOW(), NOW()),
  (@project_id, 'subcontract', '舗装工事',    5500000, NULL,    @sub2_id, '実績未入力（見積のみ）', NOW(), NOW()),
  (@project_id, 'overhead',    '諸経費',      1500000, NULL,    NULL,     '実績未入力（見積のみ）', NOW(), NOW());

-- 6. 人員配置 1 件
INSERT INTO dad_project_assignments
  (project_id, employee_id, role, start_date, end_date, notes, created_at, updated_at)
VALUES
  (@project_id, @emp_id, '現場代理人', '2026-04-15', NULL, '主任技術者兼任', NOW(), NOW());

-- 確認
SELECT '=== 投入結果 ===' AS info;
SELECT '発注者' AS table_name, COUNT(*) AS count FROM dad_clients
UNION ALL SELECT '協力業者', COUNT(*) FROM dad_subcontractors
UNION ALL SELECT '従業員', COUNT(*) FROM dad_employees
UNION ALL SELECT '工事案件', COUNT(*) FROM dad_projects
UNION ALL SELECT '原価明細', COUNT(*) FROM dad_project_costs
UNION ALL SELECT '人員配置', COUNT(*) FROM dad_project_assignments;
