/**
 * Equitable System service worker (installed app / "Add to Home Screen").
 *
 * ON EVERY UPDATE: change CACHE_VERSION below (see DEPLOY.md). The new worker then
 * installs, deletes the old caches and takes over, and open pages reload once.
 *
 *   Pages (.php, navigations)       network first; the saved copy is used only when offline
 *   Static files (css/js/images/…)  cache first; their URLs carry ?v=<file time>, so a
 *                                   changed file has a new URL and is downloaded again
 *   Never cached                    POST, other sites, live-data endpoints (*_api.php,
 *                                   exports, scanning), login / logout / password pages
 */
const CACHE_VERSION = "equitable-v1";
const PAGE_CACHE    = CACHE_VERSION + "-pages";
const STATIC_CACHE  = CACHE_VERSION + "-static";

// Pages and endpoints that must always come from the server.
const NEVER_CACHE = /(^|\/)(login|logout|registration|forgot_password|change_password|notification_open|[a-z_]*_api)\.php$/;
const STATIC_FILE = /\.(css|js|mjs|png|jpe?g|gif|svg|webp|ico|woff2?|ttf|otf|eot)$/i;

self.addEventListener("install", function () {
  self.skipWaiting();
});

self.addEventListener("activate", function (event) {
  event.waitUntil(
    caches.keys()
      .then(function (names) {
        return Promise.all(names.map(function (name) {
          return name === PAGE_CACHE || name === STATIC_CACHE ? null : caches.delete(name);
        }));
      })
      .then(function () { return self.clients.claim(); })
  );
});

self.addEventListener("fetch", function (event) {
  const request = event.request;
  if (request.method !== "GET") { return; }                     // POST etc.: straight to the server
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) { return; }          // fonts / CDNs: browser default

  // Signing out: forget saved pages so the next person on this phone can't see them offline.
  if (/(^|\/)logout\.php$/.test(url.pathname)) {
    event.waitUntil(caches.delete(PAGE_CACHE));
    return;
  }
  if (NEVER_CACHE.test(url.pathname)) { return; }
  if (url.searchParams.has("export")) { return; }               // CSV downloads
  if ((request.headers.get("Accept") || "").indexOf("application/json") !== -1) { return; }   // AJAX data

  if (request.mode === "navigate" || /\.php$/.test(url.pathname) || url.pathname.slice(-1) === "/") {
    event.respondWith(networkFirst(request));
  } else if (STATIC_FILE.test(url.pathname)) {
    event.respondWith(cacheFirst(request));
  }
});

// Pages: the server's answer when online; the last saved copy when offline.
function networkFirst(request) {
  return fetch(request).then(function (response) {
    // Keep only normal, complete pages (not redirects to the login page, not errors).
    if (response.ok && response.type === "basic" && !response.redirected) {
      const copy = response.clone();
      caches.open(PAGE_CACHE).then(function (cache) { cache.put(request, copy); });
    }
    return response;
  }).catch(function () {
    return caches.match(request).then(function (saved) {
      return saved || new Response(
        "<!doctype html><meta name=viewport content='width=device-width,initial-scale=1'>" +
        "<title>Offline</title><p style='font-family:sans-serif;padding:2rem'>You are offline and this page has not been saved yet. " +
        "Connect to the internet and try again.</p>",
        { status: 503, headers: { "Content-Type": "text/html; charset=utf-8" } }
      );
    });
  });
}

// Static files: the saved copy if there is one, otherwise download and save it.
function cacheFirst(request) {
  return caches.match(request).then(function (saved) {
    if (saved) { return saved; }
    return fetch(request).then(function (response) {
      if (response.ok && response.type === "basic") {
        const copy = response.clone();
        caches.open(STATIC_CACHE).then(function (cache) { cache.put(request, copy); });
      }
      return response;
    });
  });
}
