/**
 * TRANSPORTATION EXPENSE: shared browser helpers.
 *   Transpo.locationSelect(el, options)  searchable location dropdown (Tom Select)
 *       - searches names and aliases on the server, most-used first
 *       - "create new" goes through the duplicate check:
 *         "Did you mean: MAKAR DROPOINT (used 12 times)?" [Use existing] [Create new anyway]
 *   Transpo.post(action, data)            POST to transpo_api.php with the CSRF token
 *   Transpo.money(n)                      "₱1,234.00"
 * Needs window.TRANSPO = { api, csrf, types } and Tom Select + Bootstrap loaded.
 */
(function () {
  "use strict";

  var cfg = window.TRANSPO || {};

  function post(action, data) {
    var body = new FormData();
    body.append("action", action);
    body.append("csrf_token", cfg.csrf);
    Object.keys(data || {}).forEach(function (key) {
      if (data[key] !== undefined && data[key] !== null) { body.append(key, data[key]); }
    });
    return fetch(cfg.api, { method: "POST", body: body })
      .then(function (res) {
        return res.json().catch(function () { return { error: "Unexpected server response (" + res.status + ")." }; });
      })
      .then(function (json) {
        if (json.error) { throw new Error(json.error); }
        if (json.errors) { var err = new Error(json.errors.join("\n")); err.list = json.errors; throw err; }
        return json;
      });
  }

  function get(params) {
    var qs = Object.keys(params).map(function (k) { return encodeURIComponent(k) + "=" + encodeURIComponent(params[k]); }).join("&");
    return fetch(cfg.api + "?" + qs, { headers: { Accept: "application/json" } }).then(function (res) { return res.json(); });
  }

  function money(n) {
    return "₱" + Number(n || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function esc(text) {
    var div = document.createElement("div");
    div.textContent = text == null ? "" : String(text);
    return div.innerHTML;
  }

  /* ---------- "Did you mean ...?" prompt (one shared modal) ---------- */

  var promptModal = null;
  function duplicatePrompt(typedName, candidates) {
    return new Promise(function (resolve) {
      if (!promptModal) {
        var wrap = document.createElement("div");
        wrap.innerHTML =
          '<div class="modal fade" id="transpoDupModal" tabindex="-1" aria-labelledby="transpoDupTitle" aria-hidden="true">' +
          '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
          '<div class="modal-header"><h5 class="modal-title" id="transpoDupTitle">Did you mean…?</h5>' +
          '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
          '<div class="modal-body"><p class="mb-2">You typed <strong class="dup-typed"></strong>. A similar place already exists:</p>' +
          '<div class="list-group dup-list"></div></div>' +
          '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary dup-new">Create new anyway</button></div>' +
          "</div></div></div>";
        document.body.appendChild(wrap.firstChild);
        promptModal = document.getElementById("transpoDupModal");
      }
      var settled = false;
      function finish(value) {
        if (settled) { return; }
        settled = true;
        bootstrap.Modal.getOrCreateInstance(promptModal).hide();
        resolve(value);
      }
      promptModal.querySelector(".dup-typed").textContent = typedName;
      var list = promptModal.querySelector(".dup-list");
      list.innerHTML = "";
      candidates.forEach(function (c) {
        var item = document.createElement("div");
        item.className = "list-group-item d-flex justify-content-between align-items-center gap-2";
        item.innerHTML = "<div><strong>" + esc(c.name) + "</strong>" +
          '<div class="small text-muted">' + esc((cfg.types || {})[c.type] || c.type) + " · used " + c.times_used + " time" + (c.times_used === 1 ? "" : "s") +
          (c.address ? " · " + esc(c.address) : "") + "</div></div>";
        var use = document.createElement("button");
        use.type = "button";
        use.className = "btn btn-sm btn-primary text-nowrap";
        use.textContent = "Use existing";
        use.addEventListener("click", function () { finish({ decision: c.id }); });
        item.appendChild(use);
        list.appendChild(item);
      });
      promptModal.querySelector(".dup-new").onclick = function () { finish({ decision: "new" }); };
      promptModal.addEventListener("hidden.bs.modal", function onHide() {
        promptModal.removeEventListener("hidden.bs.modal", onHide);
        finish(null);
      });
      bootstrap.Modal.getOrCreateInstance(promptModal).show();
    });
  }

  /**
   * Duplicate-safe create: exact name / alias -> reuse; similar -> ask; else create.
   * Resolves with the location ({id, name, type, ...}) or null if cancelled.
   */
  function resolveLocation(name, type, address) {
    return post("resolve_location", { name: name, type: type || "landmark", address: address || "" })
      .then(function (res) {
        if (res.status !== "similar") { return res.location; }
        return duplicatePrompt(res.name, res.candidates).then(function (choice) {
          if (!choice) { return null; }
          return post("resolve_location", { name: name, type: type || "landmark", address: address || "", decision: choice.decision })
            .then(function (r) { return r.location; });
        });
      });
  }

  /* ---------- Searchable location dropdown ---------- */

  /**
   * options: { type: search only this type (optional), createType: type for new places,
   *            placeholder, address: function returning an address for new merchants,
   *            onChange: function(location|null) }
   */
  function locationSelect(el, options) {
    options = options || {};
    var ts = new TomSelect(el, {
      valueField: "id",
      labelField: "name",
      searchField: [],                  // the server already matched names and aliases
      maxOptions: 30,
      loadThrottle: 250,
      preload: "focus",
      placeholder: options.placeholder || "Search or type a place…",
      create: function (input, callback) {
        resolveLocation(input, options.createType || "landmark", options.address ? options.address() : "")
          .then(function (loc) { callback(loc || undefined); })
          .catch(function (err) { alert(err.message); callback(); });
      },
      load: function (query, callback) {
        var self = this;
        get({ action: "search_locations", q: query, type: options.type || "" })
          .then(function (json) {
            self.clearOptions();
            callback(json.results || []);
          })
          .catch(function () { callback(); });
      },
      score: function () { return function () { return 1; }; },
      render: {
        option: function (data, escape) {
          return "<div><div>" + escape(data.name) + "</div><div class=\"small text-muted\">" +
            escape((cfg.types || {})[data.type] || data.type) + " · used " + (data.times_used || 0) + "×" +
            (data.address ? " · " + escape(data.address) : "") + "</div></div>";
        },
        item: function (data, escape) { return "<div>" + escape(data.name) + "</div>"; },
        option_create: function (data, escape) {
          return '<div class="create">+ Add <strong>' + escape(data.input) + "</strong> as a new place…</div>";
        },
        no_results: function () { return '<div class="no-results">No match — type the full name to add it.</div>'; }
      },
      onChange: function (value) {
        if (options.onChange) { options.onChange(value ? ts.options[value] : null); }
      }
    });
    return ts;
  }

  /** Put a known location into a Tom Select and select it. */
  function setLocation(ts, loc, silent) {
    if (!loc || !loc.id) { ts.clear(silent); return; }
    if (!ts.options[loc.id]) { ts.addOption(loc); }
    ts.setValue(String(loc.id), silent);
  }

  window.Transpo = {
    post: post,
    get: get,
    money: money,
    esc: esc,
    resolveLocation: resolveLocation,
    locationSelect: locationSelect,
    setLocation: setLocation
  };
})();
