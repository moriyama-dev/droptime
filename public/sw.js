/*
 * DropTime の Service Worker。
 *
 * 役割は 2 つだけ:
 *   1. 携帯への通知（Web Push）を受けて表示し、押されたらアプリを開く。
 *   2. アプリとしてインストールできる条件を満たす。
 *
 * 画面や記録はキャッシュしない（いつも最新を見せるため。オフラインでは開けない）。
 * 通知の本文はサーバーが端末の鍵で暗号化して送り、ここに届く時点でブラウザが復号済み。
 */
'use strict';

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    var data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch (error) {
        data = { body: event.data ? event.data.text() : '' };
    }

    var scope = self.registration.scope;

    event.waitUntil(self.registration.showNotification(data.title || 'DropTime', {
        body: data.body || '',
        tag: data.tag || undefined,
        renotify: Boolean(data.tag),
        icon: scope + 'assets/icons/icon-192.png',
        badge: scope + 'assets/icons/icon-192.png',
        data: { url: data.url || scope }
    }));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var url = new URL((event.notification.data && event.notification.data.url) || self.registration.scope, self.registration.scope).href;

    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (windows) {
        for (var i = 0; i < windows.length; i++) {
            if (windows[i].url.indexOf(self.registration.scope) === 0 && 'focus' in windows[i]) {
                windows[i].navigate(url);

                return windows[i].focus();
            }
        }

        return self.clients.openWindow(url);
    }));
});
