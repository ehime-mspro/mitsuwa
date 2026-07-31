-- re_contracts: 契約金額を土地/建物に分割し、消費税率と消費税額の手入力欄を持たせる
--
-- 方針: contract_amount を contract_amount_land に CHANGE（リネーム）して廃止し、
--       contract_amount_building を追加する。既存値はそのまま土地側に残る。
--
-- tax_amount は**手入力の上書き値**。NULL なら建物 × 税率の自動計算を使う。
-- 契約書に書かれた消費税額が端数処理の違いで自動計算と一致しない場合に備える。
--
-- リネームは全契約種別に及ぶが、いずれも _land が意味的に正しい:
--   仕入れ土地販売 / 分譲地販売 → 通常は土地のみ
--   中古マンション販売 / 中古戸建販売 → 本改修の主対象
--   仲介 → contract_amount を使わない（brokerage_fee 方式）ので実害なし
--
-- 適用（ローカル）: php artisan tinker で DB::unprepared(file_get_contents('database/sql/…'))
-- 適用（本番）    : 実装プランの Task 9 の手順。要ユーザー明示承認
-- ロールバック    : 逆向きの CHANGE + DROP COLUMN（データは失われない）

ALTER TABLE `re_contracts`
  CHANGE `contract_amount` `contract_amount_land` INT NULL,
  ADD COLUMN `contract_amount_building` INT NULL AFTER `contract_amount_land`,
  ADD COLUMN `tax_rate`   DECIMAL(5,2) NOT NULL DEFAULT 10.00 AFTER `contract_amount_building`,
  ADD COLUMN `tax_amount` INT NULL AFTER `tax_rate`;
