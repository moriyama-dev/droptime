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

/*
 * 合言葉の欄の目のアイコン（templates/auth/_passphrase-input.php）。
 * 目が開いている間は伏せ字、押すと目が閉じて文字がそのまま見える。
 * ボタンは hidden で出力しておき、ここで初めて見せる（JavaScript が無ければ出ない）。
 */
(function () {
    'use strict';

    function setup(button) {
        var input = button.parentNode.querySelector('input');

        if (!input) {
            return;
        }

        button.hidden = false;
        button.addEventListener('click', function () {
            var reveal = input.type === 'password';

            input.type = reveal ? 'text' : 'password';
            button.setAttribute('aria-pressed', reveal ? 'true' : 'false');
            button.setAttribute('aria-label', button.getAttribute(reveal ? 'data-label-hide' : 'data-label-show'));
            input.focus();
        });
    }

    function start() {
        document.querySelectorAll('[data-reveal]').forEach(setup);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();

/*
 * アプリのインストールと、携帯への通知（ホームの下の「携帯で使う」・templates/parent/_app-setup.php）。
 *
 * インストール:
 *   - すでにアプリとして開いている（display-mode: standalone）なら何も出さない。
 *   - Chrome・Edge（Android・PC）は beforeinstallprompt が来たときだけボタンを出す。
 *     インストール済みのブラウザにはこの合図が来ないので、ボタンは出ない。
 *   - iPhone・iPad の Safari は合図が無いので、「共有 → ホーム画面に追加」の説明を出す。
 *
 * 通知:
 *   - Service Worker（/sw.js）を登録し、押されたら許可を求めて pushManager で購読し、サーバーに保存する。
 *   - iPhone は、ホーム画面に追加したアプリから開いたときだけ通知を使える（Safari のタブでは使えない）。
 *   - 購読済みなら、開くたびにサーバーへ送り直す（お気に入りの入れ物や言語が変わっても追いつくように）。
 */
(function () {
    'use strict';

    var root = document.documentElement;
    var base = root.getAttribute('data-base') || '';
    var swReady = null;

    function isStandalone() {
        return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
    }

    function isIos() {
        return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }

    if ('serviceWorker' in navigator && window.isSecureContext) {
        swReady = navigator.serviceWorker.register(base + '/sw.js', { scope: base + '/' }).then(function () {
            return navigator.serviceWorker.ready;
        }).catch(function () {
            return null;
        });
    }

    // beforeinstallprompt は読み込みの早い段階で来ることがあるので、画面の準備より先に受けておく。
    var deferredPrompt = null;
    var onPromptReady = null;

    window.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredPrompt = event;

        if (onPromptReady) {
            onPromptReady();
        }
    });

    function b64uToBytes(text) {
        var padded = (text + '===='.slice((text.length + 3) % 4 + 1)).replace(/-/g, '+').replace(/_/g, '/');
        var raw = window.atob(padded);
        var bytes = new Uint8Array(raw.length);

        for (var i = 0; i < raw.length; i++) {
            bytes[i] = raw.charCodeAt(i);
        }

        return bytes;
    }

    function post(path, fields) {
        var body = new URLSearchParams();

        Object.keys(fields).forEach(function (key) {
            body.append(key, fields[key]);
        });

        return fetch(base + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false };
            });
        });
    }

    function setupInstall(box) {
        var button = box.querySelector('[data-install-button]');
        var iosHelp = box.querySelector('[data-install-ios]');

        function hideAll() {
            box.hidden = true;
        }

        if (isStandalone()) {
            hideAll();

            return;
        }

        function showButton() {
            box.hidden = false;
            button.hidden = false;
        }

        if (deferredPrompt) {
            showButton();
        }

        onPromptReady = showButton;

        button.addEventListener('click', function () {
            if (!deferredPrompt) {
                return;
            }

            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (choice) {
                if (choice && choice.outcome === 'accepted') {
                    hideAll();
                }

                deferredPrompt = null;
            });
        });

        window.addEventListener('appinstalled', hideAll);

        if (isIos() && !deferredPrompt) {
            box.hidden = false;
            iosHelp.hidden = false;
        }
    }

    function setupPush(box, csrf) {
        var key = box.getAttribute('data-vapid');
        var state = box.querySelector('[data-push-state]');
        var onButton = box.querySelector('[data-push-on]');
        var offButton = box.querySelector('[data-push-off]');
        var testButton = box.querySelector('[data-push-test]');
        var message = box.querySelector('[data-push-message]');
        var current = null;

        function text(name) {
            return box.getAttribute('data-text-' + name) || '';
        }

        function show(mode, note) {
            box.hidden = false;
            onButton.hidden = mode !== 'off';
            offButton.hidden = mode !== 'on';
            testButton.hidden = mode !== 'on';
            state.textContent = text(mode);
            state.className = 'app-setup__state app-setup__state--' + mode;
            message.textContent = note || '';
        }

        var supported = key && swReady && 'PushManager' in window && 'Notification' in window;

        if (!supported) {
            // iPhone の Safari のタブでは使えない。ホーム画面に追加してから開いてもらう。
            if (isIos() && !isStandalone()) {
                show('ios');
            } else {
                show('unsupported');
            }

            return;
        }

        function save(subscription) {
            var json = subscription.toJSON();

            return post('/push/subscribe', {
                _token: csrf,
                endpoint: json.endpoint,
                p256dh: json.keys.p256dh,
                auth: json.keys.auth
            });
        }

        swReady.then(function (registration) {
            if (!registration) {
                show('unsupported');

                return null;
            }

            return registration.pushManager.getSubscription().then(function (subscription) {
                current = subscription;

                if (subscription && Notification.permission === 'granted') {
                    show('on');
                    save(subscription);
                } else if (Notification.permission === 'denied') {
                    show('denied');
                } else {
                    show('off');
                }
            });
        });

        onButton.addEventListener('click', function () {
            onButton.disabled = true;
            message.textContent = text('working');

            Notification.requestPermission().then(function (permission) {
                if (permission !== 'granted') {
                    show(permission === 'denied' ? 'denied' : 'off');

                    return null;
                }

                return swReady.then(function (registration) {
                    return registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: b64uToBytes(key)
                    });
                }).then(function (subscription) {
                    current = subscription;

                    return save(subscription);
                }).then(function (result) {
                    show(result && result.ok ? 'on' : 'off', result && result.ok ? text('saved') : text('failed'));
                });
            }).catch(function () {
                show('off', text('failed'));
            }).then(function () {
                onButton.disabled = false;
            });
        });

        offButton.addEventListener('click', function () {
            if (!current) {
                show('off');

                return;
            }

            var endpoint = current.endpoint;

            current.unsubscribe().catch(function () {}).then(function () {
                current = null;
                show('off');

                return post('/push/unsubscribe', { _token: csrf, endpoint: endpoint });
            });
        });

        testButton.addEventListener('click', function () {
            if (!current) {
                return;
            }

            testButton.disabled = true;
            post('/push/test', { _token: csrf, endpoint: current.endpoint }).then(function (result) {
                message.textContent = result && result.ok ? text('test_sent') : text('failed');
                testButton.disabled = false;
            });
        });
    }

    function start() {
        var setup = document.querySelector('[data-app-setup]');

        if (!setup) {
            return;
        }

        var csrf = setup.getAttribute('data-csrf') || '';
        var install = setup.querySelector('[data-install]');
        var push = setup.querySelector('[data-push]');

        if (install) {
            setupInstall(install);
        }

        if (push) {
            setupPush(push, csrf);
        }

        // どちらも出すものが無ければ、見出しごと隠す。
        var refresh = function () {
            setup.hidden = (!install || install.hidden) && (!push || push.hidden);
        };

        refresh();
        window.setInterval(refresh, 1000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
