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

/*
 * 選手をさがす欄の「入力中の候補」（templates/parent/favorites.php の .suggest）。
 * Google の検索枠のように、打つたびに /favorites/suggest から候補を取って下に並べる。
 * 語順・打ちかけ・綴りの間違いの推測はサーバー側（Domain\NameSearch）。
 * ↑↓ で選び、Enter で決定、Esc で閉じる。候補を選ぶとその名前で検索する。
 */
(function () {
    'use strict';

    function setup(box) {
        var input = box.querySelector('input');
        var list = box.querySelector('.suggest__list');
        var form = box.closest('form');
        var url = box.getAttribute('data-suggest-url');

        if (!input || !list || !form || !url || !window.fetch) {
            return;
        }

        var timer = null;
        var seq = 0;
        var items = [];
        var active = -1;

        function close() {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            active = -1;
        }

        function highlight(name, query) {
            // 打った語の頭と同じ部分を太字にする（綴りの推測で当たった語はそのまま）。
            var words = query.toLowerCase().split(/[^a-z0-9]+/).filter(Boolean);
            var frag = document.createDocumentFragment();

            name.split(/(\s+)/).forEach(function (part) {
                var lower = part.toLowerCase();
                var hit = 0;

                words.forEach(function (w) {
                    if (lower.indexOf(w) === 0 && w.length > hit) {
                        hit = w.length;
                    }
                });

                if (hit > 0) {
                    var mark = document.createElement('mark');
                    mark.textContent = part.slice(0, hit);
                    frag.appendChild(mark);
                    frag.appendChild(document.createTextNode(part.slice(hit)));
                } else {
                    frag.appendChild(document.createTextNode(part));
                }
            });

            return frag;
        }

        function setActive(index) {
            var nodes = list.querySelectorAll('.suggest__item');

            nodes.forEach(function (node, i) {
                node.setAttribute('aria-selected', i === index ? 'true' : 'false');
            });

            active = index;

            if (index >= 0 && nodes[index]) {
                input.setAttribute('aria-activedescendant', nodes[index].id);
                nodes[index].scrollIntoView({ block: 'nearest' });
            } else {
                input.removeAttribute('aria-activedescendant');
            }
        }

        function choose(index) {
            if (!items[index]) {
                return;
            }

            input.value = items[index].name;
            close();
            form.submit();
        }

        function render(data, query) {
            items = data.items || [];
            list.textContent = '';

            if (items.length === 0) {
                close();
                return;
            }

            if (!data.exact) {
                var note = document.createElement('li');
                note.className = 'suggest__note';
                note.setAttribute('role', 'presentation');
                note.textContent = box.getAttribute('data-did-you-mean') || '';
                list.appendChild(note);
            }

            items.forEach(function (item, i) {
                var li = document.createElement('li');
                li.className = 'suggest__item';
                li.id = 'suggest-item-' + i;
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', 'false');

                var name = document.createElement('span');
                name.className = 'suggest__name';
                name.appendChild(highlight(item.name, query));
                li.appendChild(name);

                var metaText = item.meta || '';

                if (item.favorite) {
                    metaText += (metaText ? ' ・ ' : '') + (box.getAttribute('data-registered') || '');
                }

                if (metaText) {
                    var meta = document.createElement('span');
                    meta.className = 'suggest__meta';
                    meta.textContent = metaText;
                    li.appendChild(meta);
                }

                // blur より先に拾うため mousedown で決める。
                li.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    choose(i);
                });

                list.appendChild(li);
            });

            active = -1;
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }

        function load() {
            var query = input.value.trim();
            var mine = ++seq;

            if (query.length < 2) {
                close();
                return;
            }

            fetch(url + (url.indexOf('?') < 0 ? '?' : '&') + 'q=' + encodeURIComponent(query), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (data) {
                // 遅れて届いた古い答えは捨てる。
                if (data && mine === seq && document.activeElement === input) {
                    render(data, query);
                }
            }).catch(function () {
                close();
            });
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(load, 150);
        });

        input.addEventListener('focus', function () {
            if (input.value.trim().length >= 2) {
                load();
            }
        });

        input.addEventListener('blur', function () {
            setTimeout(close, 100);
        });

        input.addEventListener('keydown', function (event) {
            if (list.hidden) {
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setActive(active + 1 >= items.length ? 0 : active + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                setActive(active <= 0 ? items.length - 1 : active - 1);
            } else if (event.key === 'Enter' && active >= 0) {
                event.preventDefault();
                choose(active);
            } else if (event.key === 'Escape') {
                close();
            }
        });
    }

    function start() {
        document.querySelectorAll('.suggest').forEach(setup);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
