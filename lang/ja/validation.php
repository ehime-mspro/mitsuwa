<?php

/*
|--------------------------------------------------------------------------
| バリデーションメッセージ（日本語）
|--------------------------------------------------------------------------
|
| このファイルが無いと $request->validate() のエラー文が
| `validation.required` のような生の翻訳キーで画面に出る
| （APP_LOCALE=ja / APP_FALLBACK_LOCALE=ja のため en の組み込み文にも落ちない）。
|
| キーの構造は vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php
| と一対一で対応させている。Laravel をアップグレードしてキーが増減したときに
| diff で差分を追えるようにするため、順序も原文どおりに保つこと。
|
| ⚠ 翻訳ファイルは config:cache / view:cache の対象外で実行時に読まれる。
|    追加・変更したらキャッシュクリア無しで即反映される（deploy.sh の rsync 対象）。
|
| ⚠ :attribute の直後にスペースを入れない。入れると「プロジェクト名 は必須です」と
|    不自然な分かち書きになる。
|
*/

return [

    'accepted' => ':attributeを承認してください。',
    'accepted_if' => ':otherが:valueの場合、:attributeを承認してください。',
    'active_url' => ':attributeは有効なURLではありません。',
    'after' => ':attributeには:date以降の日付を指定してください。',
    'after_or_equal' => ':attributeには:date以降（同日を含む）の日付を指定してください。',
    'alpha' => ':attributeは英字のみで入力してください。',
    'alpha_dash' => ':attributeは英数字とハイフン、アンダースコアのみで入力してください。',
    'alpha_num' => ':attributeは英数字のみで入力してください。',
    'any_of' => ':attributeの値が正しくありません。',
    'array' => ':attributeは配列で指定してください。',
    'ascii' => ':attributeは半角の英数字と記号のみで入力してください。',
    'before' => ':attributeには:date以前の日付を指定してください。',
    'before_or_equal' => ':attributeには:date以前（同日を含む）の日付を指定してください。',
    'between' => [
        'array' => ':attributeは:min個から:max個までにしてください。',
        'file' => ':attributeのファイルサイズは:min KBから:max KBまでにしてください。',
        'numeric' => ':attributeは:minから:maxまでの値にしてください。',
        'string' => ':attributeは:min文字から:max文字までで入力してください。',
    ],
    'boolean' => ':attributeはtrueかfalseで指定してください。',
    'can' => ':attributeに許可されていない値が含まれています。',
    'confirmed' => ':attributeが確認用の入力と一致しません。',
    'contains' => ':attributeに必要な値が含まれていません。',
    'current_password' => 'パスワードが正しくありません。',
    'date' => ':attributeは正しい日付形式で入力してください。',
    'date_equals' => ':attributeには:dateと同じ日付を指定してください。',
    'date_format' => ':attributeは「:format」の形式で入力してください。',
    'decimal' => ':attributeは小数第:decimal位まで入力してください。',
    'declined' => ':attributeを拒否してください。',
    'declined_if' => ':otherが:valueの場合、:attributeを拒否してください。',
    'different' => ':attributeと:otherには異なる値を指定してください。',
    'digits' => ':attributeは:digits桁の数字で入力してください。',
    'digits_between' => ':attributeは:min桁から:max桁までの数字で入力してください。',
    'dimensions' => ':attributeの画像サイズが正しくありません。',
    'distinct' => ':attributeに重複した値があります。',
    'doesnt_contain' => ':attributeには次の値を含めないでください: :values',
    'doesnt_end_with' => ':attributeの末尾に次の値を使わないでください: :values',
    'doesnt_start_with' => ':attributeの先頭に次の値を使わないでください: :values',
    'email' => ':attributeは正しいメールアドレスの形式で入力してください。',
    'encoding' => ':attributeは:encodingでエンコードしてください。',
    'ends_with' => ':attributeの末尾は次のいずれかにしてください: :values',
    'enum' => '選択された:attributeは正しくありません。',
    'exists' => '選択された:attributeは存在しません。',
    'extensions' => ':attributeの拡張子は次のいずれかにしてください: :values',
    'file' => ':attributeはファイルを指定してください。',
    'filled' => ':attributeを入力してください。',
    'gt' => [
        'array' => ':attributeは:value個より多く指定してください。',
        'file' => ':attributeのファイルサイズは:value KBより大きくしてください。',
        'numeric' => ':attributeは:valueより大きい値にしてください。',
        'string' => ':attributeは:value文字より多く入力してください。',
    ],
    'gte' => [
        'array' => ':attributeは:value個以上指定してください。',
        'file' => ':attributeのファイルサイズは:value KB以上にしてください。',
        'numeric' => ':attributeは:value以上の値にしてください。',
        'string' => ':attributeは:value文字以上で入力してください。',
    ],
    'hex_color' => ':attributeは正しい16進数のカラーコードで入力してください。',
    'image' => ':attributeには画像ファイルを指定してください。',
    'in' => '選択された:attributeは正しくありません。',
    'in_array' => ':attributeは:otherに存在する値にしてください。',
    'in_array_keys' => ':attributeには次のいずれかのキーを含めてください: :values',
    'integer' => ':attributeは整数で入力してください。',
    'ip' => ':attributeは正しいIPアドレスで入力してください。',
    'ipv4' => ':attributeは正しいIPv4アドレスで入力してください。',
    'ipv6' => ':attributeは正しいIPv6アドレスで入力してください。',
    'json' => ':attributeは正しいJSON文字列で入力してください。',
    'list' => ':attributeはリスト形式で指定してください。',
    'lowercase' => ':attributeは小文字で入力してください。',
    'lt' => [
        'array' => ':attributeは:value個より少なく指定してください。',
        'file' => ':attributeのファイルサイズは:value KBより小さくしてください。',
        'numeric' => ':attributeは:valueより小さい値にしてください。',
        'string' => ':attributeは:value文字より少なく入力してください。',
    ],
    'lte' => [
        'array' => ':attributeは:value個以下にしてください。',
        'file' => ':attributeのファイルサイズは:value KB以下にしてください。',
        'numeric' => ':attributeは:value以下の値にしてください。',
        'string' => ':attributeは:value文字以下で入力してください。',
    ],
    'mac_address' => ':attributeは正しいMACアドレスで入力してください。',
    'max' => [
        'array' => ':attributeは:max個以下にしてください。',
        'file' => ':attributeのファイルサイズは:max KB以下にしてください。',
        'numeric' => ':attributeは:max以下の値にしてください。',
        'string' => ':attributeは:max文字以下で入力してください。',
    ],
    'max_digits' => ':attributeは:max桁以下の数字で入力してください。',
    'mimes' => ':attributeには次の種類のファイルを指定してください: :values',
    'mimetypes' => ':attributeには次の種類のファイルを指定してください: :values',
    'min' => [
        'array' => ':attributeは:min個以上指定してください。',
        'file' => ':attributeのファイルサイズは:min KB以上にしてください。',
        'numeric' => ':attributeは:min以上の値にしてください。',
        'string' => ':attributeは:min文字以上で入力してください。',
    ],
    'min_digits' => ':attributeは:min桁以上の数字で入力してください。',
    'missing' => ':attributeは指定できません。',
    'missing_if' => ':otherが:valueの場合、:attributeは指定できません。',
    'missing_unless' => ':otherが:valueでない場合、:attributeは指定できません。',
    'missing_with' => ':valuesがあるとき、:attributeは指定できません。',
    'missing_with_all' => ':valuesがすべてあるとき、:attributeは指定できません。',
    'multiple_of' => ':attributeは:valueの倍数にしてください。',
    'not_in' => '選択された:attributeは正しくありません。',
    'not_regex' => ':attributeの形式が正しくありません。',
    'numeric' => ':attributeは数値で入力してください。',
    'password' => [
        'letters' => ':attributeには英字を1文字以上含めてください。',
        'mixed' => ':attributeには大文字と小文字の英字をそれぞれ1文字以上含めてください。',
        'numbers' => ':attributeには数字を1文字以上含めてください。',
        'symbols' => ':attributeには記号を1文字以上含めてください。',
        'uncompromised' => 'この:attributeは漏洩が確認されています。別の:attributeを指定してください。',
    ],
    'present' => ':attributeが送信されていません。',
    'present_if' => ':otherが:valueの場合、:attributeを送信してください。',
    'present_unless' => ':otherが:valueでない場合、:attributeを送信してください。',
    'present_with' => ':valuesがあるとき、:attributeを送信してください。',
    'present_with_all' => ':valuesがすべてあるとき、:attributeを送信してください。',
    'prohibited' => ':attributeは指定できません。',
    'prohibited_if' => ':otherが:valueの場合、:attributeは指定できません。',
    'prohibited_if_accepted' => ':otherが承認されている場合、:attributeは指定できません。',
    'prohibited_if_declined' => ':otherが拒否されている場合、:attributeは指定できません。',
    'prohibited_unless' => ':otherが:valuesのいずれかでない場合、:attributeは指定できません。',
    'prohibits' => ':attributeを指定すると:otherは指定できません。',
    'regex' => ':attributeの形式が正しくありません。',
    'required' => ':attributeは必須です。',
    'required_array_keys' => ':attributeには次のキーを含めてください: :values',
    'required_if' => ':otherが:valueの場合、:attributeは必須です。',
    'required_if_accepted' => ':otherが承認されている場合、:attributeは必須です。',
    'required_if_declined' => ':otherが拒否されている場合、:attributeは必須です。',
    'required_unless' => ':otherが:valuesのいずれかでない場合、:attributeは必須です。',
    'required_with' => ':valuesがあるとき、:attributeは必須です。',
    'required_with_all' => ':valuesがすべてあるとき、:attributeは必須です。',
    'required_without' => ':valuesが無いとき、:attributeは必須です。',
    'required_without_all' => ':valuesがすべて無いとき、:attributeは必須です。',
    'same' => ':attributeと:otherには同じ値を指定してください。',
    'size' => [
        'array' => ':attributeは:size個で指定してください。',
        'file' => ':attributeのファイルサイズは:size KBにしてください。',
        'numeric' => ':attributeは:sizeにしてください。',
        'string' => ':attributeは:size文字で入力してください。',
    ],
    'starts_with' => ':attributeの先頭は次のいずれかにしてください: :values',
    'string' => ':attributeは文字列で入力してください。',
    'timezone' => ':attributeは正しいタイムゾーンで指定してください。',
    'unique' => ':attributeは既に使用されています。',
    'uploaded' => ':attributeのアップロードに失敗しました。',
    'uppercase' => ':attributeは大文字で入力してください。',
    'url' => ':attributeは正しいURLの形式で入力してください。',
    'ulid' => ':attributeは正しいULIDで指定してください。',
    'uuid' => ':attributeは正しいUUIDで指定してください。',

    /*
    |--------------------------------------------------------------------------
    | 属性ごとの個別メッセージ
    |--------------------------------------------------------------------------
    |
    | 「attribute.rule」の形式で、特定の項目・特定のルールだけ文言を差し替えられる。
    | 画面ごとに意味が変わる項目（下記 attributes の「⚠ 画面で意味が変わる項目」参照）は
    | ここではなく、各コントローラの validate() 第2引数でメッセージを個別指定するほうが
    | 意図が読み取りやすい（既に Admin\UserController 等がその方式を採っている）。
    |
    */

    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | 項目名の和名
    |--------------------------------------------------------------------------
    |
    | :attribute プレースホルダを画面上のラベルに差し替える。
    | ここに無いキーは Laravel が snake_case を単語に開いてそのまま出す
    | （例: land_area_sqm → 「land area sqm」）ので、フォームを新設したら追記する。
    |
    | 和名は実際の Blade の <label> テキストから採っている（2026-07-30 に全 32 コントローラ ×
    | 自身が返すビューを突合して洗い直した）。
    |
    | ⚠ attributes はアプリ全体で 1 つのマップしか持てない。画面ごとに意味が変わるキー
    |    （name は 物件名 / 顧客名 / 氏名 / 発注者名 など 7 通り、address は 住所 / 所在地）は
    |    ここに多数派の語を置き、少数派の画面は **そのコントローラの validate() 第3引数**で
    |    上書きする。第3引数はこのファイルより優先される
    |    （Illuminate\Validation\Concerns\FormatsMessages::getDisplayableAttribute()）。
    |
    |        $request->validate($rules, [], ['address' => '所在地']);
    |                                   ↑ 第2引数は messages。空配列を渡すこと
    |
    | ⚠ 括弧の注記は項目名に含めない方針（2026-07-30 決定）。
    |    画面ラベル「共益費（月額）」「建物販売価格（税抜）」「顧客（任意）」に対して
    |    ここでは 共益費 / 建物販売価格 / 顧客 とする。単位・税区分・任意/自動は項目名ではなく、
    |    「顧客（任意）は必須です」のような矛盾した文言も避けられるため。
    |    例外: area_sqm「面積（㎡）」と area_tsubo「面積（坪）」は単位が項目の区別そのものなので残す。
    |    例外: 姓/名のフリガナは画面ラベルが「セイ」「メイ」だが「メイは必須です」が読みにくいため
    |          「姓（フリガナ）」「名（フリガナ）」を採る。
    |
    | ⚠ 保証人・緊急連絡先は画面ラベルが単に「氏名」「住所」で、複数エラー時にどちらの
    |    ものか分からなくなるため、ここでは接頭辞を付けている（例「保証人1 氏名」）。
    |
    */

    'attributes' => [

        // --- 共通 ---
        'name' => '名称',                              // 上書き: 物件名 / 顧客名 / 名前 / 氏名 / 専門分野名 / 発注者名
        'name_kana' => 'フリガナ',
        'last_name' => '姓',
        'first_name' => '名',
        'last_name_kana' => '姓（フリガナ）',           // 画面ラベルは「セイ」（冒頭の例外を参照）
        'first_name_kana' => '名（フリガナ）',          // 画面ラベルは「メイ」（同上）
        'status' => 'ステータス',                      // 上書き: Dad/Employee は「在籍状況」
        'type' => '区分',
        'category' => 'カテゴリ',
        'notes' => '備考',
        'memo' => '備考',
        'note' => '備考',
        'withdraw_note' => '退会メモ',                 // 画面ラベルは「備考」だが退会画面固有なので区別する
        'description' => '内容',                       // 上書き: 工事概要 / 修繕内容 / 問合せ内容
        'content' => '対応内容',
        'reason' => '改定理由',
        'result_reason' => '結果理由',
        'department' => '部署',                        // 上書き: Admin/CustomerImport は「インポート先部署」
        'display_order' => '表示順',
        'active' => '有効',
        'ids' => '並び順',
        'ids.*' => '並び順',
        'order' => '表示順',
        'order.*' => '表示順',
        'mode' => '表示モード',
        'pattern' => '投資パターン',
        'color_bg' => '背景色',
        'color_text' => '文字色',
        'rank' => 'ランク',
        'label' => '設問文',
        'question_type' => '設問タイプ',

        // --- 連絡先・住所 ---
        'email' => 'メールアドレス',
        'phone' => '電話番号',                         // 上書き: Dad/Employee は「連絡先」
        'fax' => 'FAX番号',
        'postal_code' => '郵便番号',
        'prefecture' => '都道府県',
        'city' => '市区町村',
        'address' => '住所',                           // 上書き: 所在地 / 市区町村・番地以降
        'address_detail' => '住所詳細',
        'building_name' => '建物名',
        'representative' => '代表者名',
        'contact_person' => '担当者名',
        'company_name' => '会社名',                    // 上書き: Tenant/Inquiry は「会社名・屋号」
        'owner_name' => '所有者名',                    // 上書き: Mansion/Property は「オーナー名」
        'gender' => '性別',
        'birthday' => '生年月日',
        'workplace' => '勤務先',
        'qualifications' => '保有資格',

        // --- 認証・ユーザー・従業員 ---
        'password' => 'パスワード',                    // 上書き: Admin/User は「初期パスワード」
        'password_confirmation' => 'パスワード（確認）',
        'current_password' => '現在のパスワード',
        'role' => 'ロール',
        'departments' => '所属部門',
        'staff_user_id' => '担当者',
        'assigned_to' => '担当者',
        'created_by' => '担当者',
        'user_id' => 'ユーザー',
        'departments.*' => '所属部門',
        'employee_code' => '社員番号',
        'position' => '役職',
        'hire_date' => '入社日',

        // --- 物件・区画 ---
        'property_id' => '物件',
        'property_name' => '物件名',
        'unit_id' => '区画',
        'room_number' => '号室',
        'room_type' => '間取り',
        'floor' => '階数',
        'floors' => '階数',
        'total_floors' => '総階数',                    // 上書き: Mansion/Property は「階数」
        'total_units' => '総戸数',
        'structure' => '構造',
        'area_sqm' => '面積（㎡）',                    // 上書き: Mansion/Room は「専有面積」
        'area_tsubo' => '面積（坪）',
        'land_area_sqm' => '土地面積（㎡）',
        'building_area_sqm' => '建物面積（㎡）',
        'usage_type_id' => '用途',
        'desired_usage_id' => '希望用途',
        'zoning' => '用途地域',
        'building_coverage' => '建ぺい率',
        'floor_area_ratio' => '容積率',
        'latitude' => '緯度',
        'longitude' => '経度',
        'built_year_month' => '築年月',
        'built_date' => '築年月',
        'operation_status' => '稼働状態',
        'owner_type' => '所有者区分',
        'ownership_type' => '所有形態',
        'site_address' => '工事現場住所',
        'parking_number' => '駐車場番号',
        'has_roof' => '屋根',
        'monthly_fee' => '月額料金',
        'new_monthly_fee' => '新・月額料金',
        'key_money' => '礼金',
        'lot_number' => '号地番号',
        'unit_ids' => '区画',
        'unit_ids.*' => '区画',
        'tenant_type' => '入居者区分',

        // --- 契約・賃料 ---
        'contract_date' => '契約日',
        'start_date' => '開始日',                      // 上書き: 工事開始日 / 利用開始日
        'end_date' => '終了日',                        // 上書き: Tenant/Investment は「工事完了日」
        'rent_start_date' => '家賃発生日',
        'initial_month_type' => '初月家賃の請求方法',
        'initial_month_amount' => '初月家賃',
        'final_month_type' => '最終月家賃の請求方法',
        'final_month_amount' => '最終月家賃',
        'contract_end_date' => '契約終了日',
        'move_out_date' => '退去日',
        'termination_reason' => '退去理由',
        'terminate_parkings' => '一括解約する駐車場契約',
        'terminate_parkings.*' => '一括解約する駐車場契約',
        'settlement_file' => '解約精算書',
        'settlement_date' => '決済日',
        'revision_date' => '改定適用日',
        'rent' => '賃料',                              // 上書き: Mansion/Room は「募集賃料」
        'new_rent' => '新・月額家賃',
        'common_fee' => '共益費',
        'new_common_fee' => '新・共益費',
        'deposit' => '敷金',
        'new_deposit' => '新・敷金',
        'garbage_fee' => 'ゴミ代（月額）',
        'new_garbage_fee' => '新・ゴミ代',
        'pest_control_fee' => '駆除代（月額）',
        'new_pest_control_fee' => '新・駆除代',
        'tax_rate' => '消費税率',
        'contract_amount' => '契約額',                 // 上書き: Dad/Project は「受注金額」
        'estimate_amount' => '見積金額',
        'brokerage_fee' => '仲介手数料',
        'customer_id' => '顧客',                       // 上書き: Tenant/Contract は「テナント」
        'customer_name' => '顧客名',
        'customer_type' => '顧客種別',
        'tenant_id' => '入居者',
        'buyer_id' => '買主',
        'buyer_name' => '買主名',
        'store_name' => '店舗名',
        'inquiry_id' => '関連問合せ',

        // --- 不動産・住宅 ---
        'project_name' => 'プロジェクト名',            // 上書き: Dad/Project は「工事名」
        'project_id' => '分譲地',
        'project_type' => '工事種別',
        'client_type' => '種別',
        'estimate_date' => '見積日',
        'order_date' => '受注日',
        'payment_date' => '入金日',
        'completion_date' => '完工日',
        'period_start' => '工期開始',
        'period_end' => '工期終了',
        'order_name' => '案件名',
        're_project_lot_id' => '区画',
        're_procurement_id' => '仕入れ案件',
        'land_source_type' => '土地紐づけ種別',
        'supplier_id' => '仕入れ先',
        'client_id' => '発注者',
        'specialty_id' => '専門分野',
        'purchase_price' => '購入価格',
        'assessment_price' => '査定価格',
        'target_selling_price' => '想定販売価格',      // 上書き: RealEstate/Project は「想定総販売価格」
        'target_selling_price_building' => '建物予定販売価格',

        // 不動産 仕入れ案件・契約: 金額の土地/建物分割（2026-07-30）
        // ⚠ target_selling_price_building はここに足さない。
        //    建売（hs_properties）の「建物予定販売価格」で既に埋まっており、
        //    attributes はアプリ全体で 1 つのマップしか持てないため。
        //    仕入れ案件側は ProcurementController::validateProcurement() の第 3 引数で上書きする
        'assessment_price_land' => '査定価格（土地）',
        'assessment_price_building' => '査定価格（建物）',
        'purchase_price_land' => '購入価格（土地）',
        'purchase_price_building' => '購入価格（建物）',
        'target_selling_price_land' => '想定販売価格（土地）',
        'contract_amount_land' => '契約額（土地）',
        'contract_amount_building' => '契約額（建物）',
        'tax_amount' => '消費税額',

        'selling_price' => '販売価格',
        'selling_price_land' => '土地販売価格',
        'selling_price_building' => '建物販売価格',
        'land_selling_price' => '土地販売価格',
        'building_contract_price' => '建物請負金額',
        'land_cost' => '土地原価',
        'building_cost' => '建築原価',                 // 上書き: Housing/Property は「建築費」
        'is_land_cost_manual' => '土地原価の手動入力',
        'info_obtained_date' => '情報入手日',
        'acquired_date' => '取得日',
        'survey_date' => '来場日',
        'scheduled_completion_date' => '完成予定日',
        'actual_completion_date' => '実際の完成日',
        'delivery_date' => '引渡日',
        'property_type' => '物件種別',
        'transaction_type' => '取引種別',

        // --- 工事・修繕 ---
        'contractor_name' => '施工業者名',             // 上書き: Tenant/Repair は「業者名」
        'cost_item_id' => '原価項目',
        'estimated_amount' => '見込み額',              // ⚠ DAD では「見積額」
        'actual_amount' => '確定額',                   // ⚠ DAD では「実績額」
        'cost' => '費用',
        'started_at' => '実施日',
        'completed_at' => '完了日',

        // --- 原価明細（Alpine で行を増やす配列。* はワイルドカードとして解決される）---
        'costs' => '原価明細',
        'costs.*.cost_item_id' => '費用項目',
        'costs.*.estimated_amount' => '見込み額',
        'costs.*.actual_amount' => '確定額',
        'costs.*.notes' => '備考',
        'rows' => '原価明細',
        'rows.*.cost_item_id' => '費用項目',
        'rows.*.estimated_amount' => '見込み額',
        'rows.*.actual_amount' => '確定額',
        'rows.*.notes' => '備考',
        'details' => '内訳',
        'details.*.cost_item' => '費用項目',
        'details.*.contractor_name' => '施工業者名',
        'details.*.amount' => '金額',
        'details.*.executed_at' => '実施日',
        'details.*.notes' => '備考',

        // --- 問合せ ---
        'source' => '問合せ経路',
        'inquiry_date' => '問合せ日',
        'contact_name' => '問合せ者',
        'action_type' => '対応種別',
        'action_date' => '対応日',
        'desired_area_min' => '希望面積の下限',        // 画面は min/max とも「希望面積（坪）」なので区別する
        'desired_area_max' => '希望面積の上限',
        'budget_max' => '予算上限',
        'desired_move_date' => '希望入居月',

        // --- 周辺ビル調査（テナント管理）---
        // ⚠ name / address / room_number / floor / notes / rows は既存のグローバル値を変えず、
        //   各コントローラの validate() 第3引数で上書きする（第2引数は messages）。
        //     AreaBuildingController       … name→ビル名 / address→所在地
        //     AreaBuildingTenantController … name→テナント名 / room_number→部屋番号 / floor→階
        //     AreaBuildingSurveyController … notes→所見
        //     AreaBuildingImportController … rows→取込データ
        'industry' => '業種',
        'surveyed_month' => '調査年月',
        'surveyed_by' => '調査者',
        'operating_count' => '営業',
        'vacant_count' => '空き',
        'unknown_count' => '不明',
        'confirmed_on' => '最終確認日',
        'moved_out_on' => '退去日',
        'survey_notes' => '所見',                       // ビル登録画面で同時入力する初回調査の所見
        'kind' => '取込種別',
        'coordinates' => '取得した座標',

        // --- 保証人・緊急連絡先（画面ラベルは「氏名」等だけなので接頭辞を付ける）---
        'guarantor1_name' => '保証人1 氏名',
        'guarantor1_address' => '保証人1 住所',
        'guarantor1_contact' => '保証人1 連絡先',
        'guarantor1_workplace' => '保証人1 勤務先',
        'guarantor2_name' => '保証人2 氏名',
        'guarantor2_address' => '保証人2 住所',
        'guarantor2_contact' => '保証人2 連絡先',
        'guarantor2_workplace' => '保証人2 勤務先',
        'emergency_contact_name' => '緊急連絡先 氏名',
        'emergency_contact_phone' => '緊急連絡先 電話番号',
        'emergency_contact_relation' => '緊急連絡先 続柄',

        // --- ZEAL 会員・プラン ---
        'store_id' => '所属店舗',
        'trainer_id' => '担当トレーナー',
        'acquisition_source' => '集客チャネル',
        'purpose' => '入会目的',
        'plan_id' => 'プラン',
        'change_date' => '変更日',
        'is_campaign_applied' => '適用価格タイプ',
        'applied_price_excl' => '適用価格',
        'withdrew_on' => '退会日',
        'withdraw_reason' => '退会理由',
        'regular_price_excl' => '通常価格',
        'campaign_price_excl' => 'キャンペーン価格',
        'campaign_starts_on' => 'キャンペーン開始日',
        'campaign_ends_on' => 'キャンペーン終了日',
        'max_concurrent_reservations' => '同時予約可能数',
        'monthly_session_limit' => '月間利用上限回数',
        'includes_personal' => 'パーソナルセッションを含む',
        'includes_semi_personal' => 'セミパーソナルセッションを含む',
        'is_pair_plan' => 'ペアプラン',
        'open_date' => '開店日',

        // --- ZEAL 経営試算表 ---
        'fiscal_year' => '会計年度',
        'code' => 'コード',
        'group_type' => 'グループ',
        'calc_type' => '計算タイプ',
        'default_amount' => 'デフォルト額',
        'rate_percent' => '率',
        'values' => '入力値',
        'values.*' => '入力値',
        'values.*.*' => '入力値',

        // --- 外部連携 ---
        'sales_sheet_url' => '売上シートのCSVエクスポートURL',
        'expense_sheet_url' => '経費シートのCSVエクスポートURL',

        // --- ファイル ---
        'file' => 'ファイル',
        'csv_file' => 'CSVファイル',
        'files' => '添付ファイル',
        'files.*' => '添付ファイル',
        'attachments' => '添付ファイル',
        'attachments.*' => '添付ファイル',

    ],

];
