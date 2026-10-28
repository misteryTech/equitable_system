/**
 * Installs the service worker (sw.js) and keeps the installed app up to date.
 * - sw.js is registered with a path relative to the page, so it works in a sub-folder
 *   (XAMPP: /equitable_system/) and at the site root (Hostinger).
 * - updateViaCache "none": the browser always asks the server for sw.js, so a new
 *   CACHE_VERSION is noticed on the next visit.
 * - When a new service worker takes over, the page reloads once to show the update.
 * Service workers only run on https:// (or http://localhost); elsewhere this does nothing.
 */
(function () {
  "use strict";
  if (!("serviceWorker" in navigator) || !window.isSecureContext) { return; }

  // Only reload for an update, not when the app is installed for the first time.
  var hadController = !!navigator.serviceWorker.controller;
  var reloading = false;
  navigator.serviceWorker.addEventListener("controllerchange", function () {
    if (!hadController || reloading) { return; }
    reloading = true;
    window.location.reload();
  });

  window.addEventListener("load", function () {
    navigator.serviceWorker.register("sw.js", { scope: "./", updateViaCache: "none" })
      .then(function (registration) {
        // Check for a new version when the app is brought back to the front, too.
        document.addEventListener("visibilitychange", function () {
          if (document.visibilityState === "visible") { registration.update().catch(function () {}); }
        });
      })
      .catch(function () { /* not supported or blocked: the site works without it */ });
  });
})();
