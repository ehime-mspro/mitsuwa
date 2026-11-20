{{--
    住宅事業の土地原価の、手動入力 OFF の表示の決まり（判断待ち ⑤・案 A＋b。設計 docs/superpowers/specs/2026-11-20-housing-land-cost-auto-design.md）。
    ⚠ script の中に include する（JS の関数だけ。script のタグは書かない）。画面のテストの道具がコンポーネントを定義する
      script だけを読むため、その script の中に置く。建売・注文住宅の登録と編集・契約の編集の 4 画面が使う。

    housingLandCostView(s) は、サーバの App\Support\HousingLandCost::decide() と同じ決まり（片方だけ変えない。Bug #41。
    LandCostViewParityTest が突き合わせる）:
      s = { manual, creating, savedManual, linkChanged, refresh, computed, saved, hasLink }
      willCompute    = 手動 OFF で、新規登録／保存済みが手動 ON／紐付け先を変えた／「今の計算値にする」のどれか
      value          = willCompute なら computed（紐付け先から計算した値。計算できなければ null）、でなければ saved（保存済みの値）
      refreshOffered = 「今の計算値にする」を出すか（手動 OFF・新規でない・保存済みが手動 OFF・紐付け先が同じ・計算値がある・保存値と違う）。
                       付けたあとも外せるように、refresh には関係なく決める
      unavailable    = 保存すると空になる（計算できない）ことを知らせるか（紐付け先を選んでいるときだけ）
    housingLandLinkChanged(saved, now) は紐付け先の比べ方（LocksLandAfterContract::landLink() と同じ: 種別と、その種別で使う id だけ。
    数字だけの id は先頭の 0 を落とす）。housingYen(v) は金額の書き方（4,000,000円）。
    ⚠ 説明はこの Blade コメントに書く。script の中のコメントにアットマークで始まる語やコンポーネントのタグを書くと、Blade が展開して壊す（Bug #30）。
--}}
function housingLandCostView(s) {
    var willCompute = !s.manual && !!(s.creating || s.savedManual || s.linkChanged || s.refresh);
    var computed = s.computed === undefined ? null : s.computed;
    var saved = s.saved === undefined ? null : s.saved;
    return {
        willCompute: willCompute,
        value: s.manual ? null : (willCompute ? computed : saved),
        refreshOffered: !s.manual && !s.creating && !s.savedManual && !s.linkChanged && computed !== null && computed !== saved,
        unavailable: willCompute && computed === null && !!s.hasLink
    };
}
function housingLandLinkChanged(saved, now) {
    var id = function (v) {
        if (v === null || v === undefined || v === '') { return null; }
        var text = String(v);
        return /^[0-9]+$/.test(text) ? String(parseInt(text, 10)) : text;
    };
    var key = function (link) {
        var type = link.type ? String(link.type) : null;
        return JSON.stringify([type, type === 'project_lot' ? id(link.lot) : null, type === 'procurement' ? id(link.procurement) : null]);
    };
    return key(saved) !== key(now);
}
function housingYen(v) {
    return Number(v).toLocaleString('ja-JP') + '円';
}
