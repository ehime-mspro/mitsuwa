<script>
// 周辺ビル調査の地図から POI(店舗・施設)と駅・バス停のラベルを消す。
// 設計書: docs/superpowers/specs/2026-08-30-area-building-map-poi-design.md
//
// このコメントは /create /edit の HTML にそのまま出る。画面から外した項目名を
// そのまま書くと AreaBuildingCrudTest の「画面に出さない」検査に引っかかる。
//
// 消えるもの: 店舗 / 飲食店 / 会社 / 学校 / 病院 / 公園 / 役所などのアイコンと名前、駅・バス停
// 残るもの:   道路・道路名・地名・行政区画・地形・建物の輪郭・自社のビルピン
//
// 定義はここ 1 箇所だけ。2 つのビューに同じ配列を書くと片方だけ直す事故になる(Bug #41)。
// const ではなく var にする。同じページに 2 度読み込まれても再宣言で落ちないため。
//
// 本番の地図は Map ID を持たない(実測 areaMapInstance.get('mapId') === null)ので
// styles がそのまま効く。将来クラウドスタイルへ移行すると styles は無視されるので、
// そのときは Cloud Console 側の設定に置き換わる。
var AREA_MAP_STYLES = [
    { featureType: 'poi',     elementType: 'labels', stylers: [{ visibility: 'off' }] },
    { featureType: 'transit', elementType: 'labels', stylers: [{ visibility: 'off' }] }
];
</script>
