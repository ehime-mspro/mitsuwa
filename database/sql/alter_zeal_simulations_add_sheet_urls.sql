-- ZEAL 試算表に本部 Google Sheets 連携用 URL を追加
-- 実行: sudo mysql manage < database/sql/alter_zeal_simulations_add_sheet_urls.sql
--
-- 本部 (株式会社 ZEAL) から加盟店に毎月共有される売上 / 経費 Sheet の
-- 公開 CSV エクスポート URL を試算表 1 枚あたり 2 件保存する。

ALTER TABLE `zeal_simulations`
  ADD COLUMN `sales_sheet_url`   VARCHAR(500) NULL COMMENT '本部から共有された売上 Sheet の CSV エクスポート URL' AFTER `notes`,
  ADD COLUMN `expense_sheet_url` VARCHAR(500) NULL COMMENT '本部から共有された経費 Sheet の CSV エクスポート URL' AFTER `sales_sheet_url`;
