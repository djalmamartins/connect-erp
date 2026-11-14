/* Service worker do Connect Morador (ADR 0302). Não guarda dados do morador: só a tela "sem conexão"
   e os arquivos estáticos do tema. Páginas e API vão sempre à rede (no-store). */
var OFFLINE = '__OFFLINE_URL__';
var CACHE = 'connect-morador-v1';
self.addEventListener('install', function (event) {
  event.waitUntil(caches.open(CACHE).then(function (cache) { return cache.add(OFFLINE); }).then(function () { return self.skipWaiting(); }));
});
self.addEventListener('activate', function (event) {
  event.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});
self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') return;
  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(function () { return caches.match(OFFLINE); }));
    return;
  }
  if (/\/container\/app\/.+\.(css|js|png|svg)(\?|$)/.test(request.url)) {
    event.respondWith(caches.open(CACHE).then(function (cache) {
      return cache.match(request).then(function (hit) {
        var network = fetch(request).then(function (response) { if (response.ok) cache.put(request, response.clone()); return response; });
        return hit || network;
      });
    }));
  }
});
