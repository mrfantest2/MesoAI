// Prior cache floors retained for compatibility checks: meso-app-shell-v6 meso-app-shell-v7
const CACHE_NAME = 'meso-app-shell-v8';
const STATIC_ASSETS = [
  '/meso/app.webmanifest',
  '/meso/offline.html',
  '/meso/pwa/install.js',
  '/meso/chat/chat.js',
  '/meso/chat/reply-audio.js',
  '/meso/icons/meso-192.png',
  '/meso/icons/meso-512.png',
  '/meso/icons/meso-maskable-512.png'
];
self.addEventListener('install', event => { event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(STATIC_ASSETS)).then(() => self.skipWaiting())); });
self.addEventListener('activate', event => { event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key)))).then(() => self.clients.claim())); });
function isPrivateOrDynamic(url, request) {
  if (request.method !== 'GET') return true;
  if (url.pathname.startsWith('/meso/api/')) return true;
  if (url.pathname === '/meso/chat/' || url.pathname === '/meso/chat/index.php') return true;
  if (url.search) return true;
  return false;
}
self.addEventListener('fetch', event => {
  const request = event.request;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  if (isPrivateOrDynamic(url, request)) { event.respondWith(fetch(request)); return; }
  if (request.mode === 'navigate') { event.respondWith(fetch(request).catch(() => caches.match('/meso/offline.html'))); return; }
  if (!STATIC_ASSETS.includes(url.pathname)) { event.respondWith(fetch(request)); return; }
  event.respondWith(caches.match(request).then(cached => {
    const network = fetch(request).then(response => {
      if (response && response.ok && response.type === 'basic') {
        const copy = response.clone();
        caches.open(CACHE_NAME).then(cache => cache.put(request, copy));
      }
      return response;
    });
    return cached || network;
  }));
});
