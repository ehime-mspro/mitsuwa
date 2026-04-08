/**
 * 経営管理システム - 共通JavaScript
 */

import Alpine from 'alpinejs';

window.Alpine = Alpine;
Alpine.start();

/**
 * 横スクロールヒント制御
 *
 * 使い方:
 *   <div class="scroll-hint at-start">
 *       <div class="scroll-hint-inner">
 *           <table>...</table>
 *       </div>
 *       <div class="scroll-hint-text">← スクロールできます →</div>
 *   </div>
 *
 * 動作:
 *   - スクロール不要（PC幅で収まる）→ グラデーション・テキスト非表示
 *   - 左端にいる → 右グラデーション表示（まだ右にある）
 *   - スクロール中 → 左右グラデーション表示
 *   - 右端に到達 → 右グラデーション消える
 */
function updateScrollHint(inner) {
    const wrap = inner.closest('.scroll-hint');
    if (!wrap) return;

    const canScroll = inner.scrollWidth > inner.clientWidth + 1;

    if (!canScroll) {
        wrap.classList.add('no-scroll');
        wrap.classList.remove('scrolled', 'at-end');
        return;
    }

    wrap.classList.remove('no-scroll');

    const atStart = inner.scrollLeft <= 1;
    const atEnd = inner.scrollLeft + inner.clientWidth >= inner.scrollWidth - 1;

    wrap.classList.toggle('at-start', atStart);
    wrap.classList.toggle('scrolled', !atStart);
    wrap.classList.toggle('at-end', atEnd);
}

function checkAllScrollHints() {
    document.querySelectorAll('.scroll-hint-inner').forEach(updateScrollHint);
}

// イベント登録
document.addEventListener('DOMContentLoaded', function () {
    // 各scroll-hint-innerにスクロールイベントを登録
    document.querySelectorAll('.scroll-hint-inner').forEach(function (el) {
        el.addEventListener('scroll', function () {
            updateScrollHint(this);
        });
    });

    // 初期チェック
    checkAllScrollHints();
});

// リサイズ時に再チェック
window.addEventListener('resize', checkAllScrollHints);
