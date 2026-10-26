/*
 * グラフと帯の動きを「画面に入ったとき」に再生する。
 *
 * 動きそのものは app.css の CSS アニメーションで、ここは再生の合図だけを出す。
 * <html> に .js-motion を付けると、CSS が対象の要素のアニメーションを止めて
 * 待たせる。要素が画面に十分入ったら .is-inview を付けて再生し、監視をやめる
 * （1 回だけ動く）。
 *
 * このファイルが読めない・IntersectionObserver が無い環境では .js-motion が
 * 付かないので、今までどおり表示された瞬間に再生される（何も壊れない）。
 * CSP が script-src 'self' なので、インラインではなくこのファイルで読み込む。
 */
(function () {
    'use strict';

    if (!('IntersectionObserver' in window)) {
        return;
    }

    // app.css の「画面に入るまで止めておく」と同じ並びにする。
    var SELECTOR = '.rise, .chart-frame, .strip-frame, .drop-list__row, .rank-table__row';

    var root = document.documentElement;
    root.classList.add('js-motion');

    // 細かく刻んで、背の高い要素でも「画面の 3 割ほど見えた」ところで拾えるようにする。
    var thresholds = [];

    for (var i = 0; i <= 20; i++) {
        thresholds.push(i / 20);
    }

    function isEnough(entry) {
        if (!entry.isIntersecting) {
            return false;
        }

        var viewHeight = entry.rootBounds ? entry.rootBounds.height : window.innerHeight;
        var need = Math.min(entry.boundingClientRect.height, viewHeight) * 0.3;

        return entry.intersectionRect.height >= need;
    }

    function start() {
        try {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (isEnough(entry)) {
                        entry.target.classList.add('is-inview');
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: thresholds });

            document.querySelectorAll(SELECTOR).forEach(function (el) {
                observer.observe(el);
            });
        } catch (error) {
            // 止めたまま見えなくなるのを避けるため、失敗したら元の動きに戻す。
            root.classList.remove('js-motion');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
