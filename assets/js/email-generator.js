/**
 * Email Generator: builds the same text as includes/email_generator.php (keep the
 * two in step), the shared recipients box, copy-to-clipboard and a small toast.
 * Nothing is sent — users paste the copied text into Gmail.
 */
(function () {
  "use strict";

  var SERVICE_DEFAULTS = {
    short_greeting: "Hi system, for assistance",
    short_closing: "Thanks and regards",
    formal_greeting: "Hi Maam/Sir",
    formal_intro: "For assistance",
    formal_closing: "Thank you"
  };

  // PHP trim() / \s without the u flag: ASCII whitespace only.
  function trim(value) { return String(value == null ? "" : value).replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, ""); }
  function squash(value) { return trim(String(value == null ? "" : value).replace(/[ \t\n\r\f\x0B]+/g, " ")); }
  function nonEmpty(x) { return x !== ""; }

  /* ---------- Shared ---------- */

  function greeting(hour) {
    if (hour < 12) { return "Goodmorning"; }
    return hour < 18 ? "Good afternoon" : "Good evening";
  }

  function recipientFormat(name, email) {
    name = squash(name);
    email = trim(email);
    if (name === "") { return email; }
    if (/[",;<>@()\[\]:]/.test(name)) { name = '"' + name.replace(/\\/g, "\\\\").replace(/"/g, '\\"') + '"'; }
    return name + " <" + email + ">";
  }

  /** list: [{name, email, checked}] -> "Name <email>, ..." (checked only, each address once). */
  function recipientsString(list) {
    var seen = {};
    var out = [];
    list.forEach(function (r) {
      var email = trim(r.email);
      var key = email.toLowerCase();
      if (email === "" || ("checked" in r && !r.checked) || seen[key]) { return; }
      seen[key] = true;
      out.push(recipientFormat(r.name || "", email));
    });
    return out.join(", ");
  }

  /* ---------- Service Request ---------- */

  /**
   * type: {label, phrase, text_mode}; subjectWords: SERVICE_SUBJECT_ACTIONS from the page
   * (lower-case label -> subject word). Returns {label, phrase, subject}.
   */
  function serviceAction(type, text, subjectWords) {
    text = squash(text);
    var mode = type.text_mode || "none";
    var label = String(type.label);
    var base = squash(label);
    if (text !== "" && mode === "append") { label += " " + text; }
    else if (text !== "" && mode === "replace") { label = text; }
    var phrase = squash(String(type.phrase).split("{text}").join(mode === "none" ? "" : text));
    var words = subjectWords || {};
    var subject = mode === "replace" && text !== "" ? text.toUpperCase()
      : (Object.prototype.hasOwnProperty.call(words, base.toLowerCase()) ? words[base.toLowerCase()] : base.toUpperCase());
    return { label: label, phrase: phrase !== "" ? phrase : label, subject: subject };
  }

  /** Same as service_subject(): "<device> <ACTIONS> TN: <tn> TID: <tid>". */
  function serviceSubject(device, actions, tn, tid) {
    var words = [];
    actions.forEach(function (a) {
      var word = trim(a.subject != null ? a.subject : String(a.label || "").toUpperCase());
      if (word !== "" && words.indexOf(word) === -1) { words.push(word); }
    });
    tn = trim(tn); tid = trim(tid);
    var parts = [device, words.join(" / "), tn === "" ? "" : "TN: " + tn, tid === "" ? "" : "TID: " + tid];
    return trim(parts.join(" ").replace(/[ \t\n\r\f\x0B]+/g, " "));
  }
  function serviceSentence(actions, model) {
    if (!actions.length) { return ""; }
    model = trim(model);
    var usedModel = false;
    var parts = actions.map(function (a) {
      var phrase = a.phrase;
      if (phrase.indexOf("{model}") !== -1) {
        phrase = phrase.split("{model}").join(usedModel ? "it" : (model !== "" ? model : "the terminal"));
        usedModel = true;
      }
      return phrase;
    });
    var last = parts.pop();
    var text = parts.length ? parts.join(", ") + " and " + last : last;
    if (!usedModel && model !== "") { text += " for " + model; }
    return "please " + text;
  }

  /** Same inputs and result as service_email_build(). */
  function serviceBuild(input, settings, hour) {
    var s = {};
    Object.keys(SERVICE_DEFAULTS).forEach(function (k) { s[k] = settings && settings[k] != null ? settings[k] : SERVICE_DEFAULTS[k]; });
    function v(key) { return trim(String(input[key] == null ? "" : input[key]).replace(/[ \t]+/g, " ")); }
    function field(label, value) { return value === "" ? "" : label + ": " + value; }

    var actions = input.actions || [];
    var labels = actions.map(function (a) { return a.label; });
    var main = labels[0] || "";
    var model = v("model");
    var notes = trim(String(input.notes || "").replace(/\r\n|\r/g, "\n"));
    var lines;

    if (input.template === "formal") {
      lines = [
        s.formal_greeting, greeting(hour), s.formal_intro, serviceSentence(actions, model),
        field("Merchant", v("merchant")), field("TID", v("tid")), field("TN", v("tn")),
        notes, s.formal_closing,
        v("area") === "" ? "" : "F.E " + v("area").toUpperCase(),
        v("name"), v("mobile")
      ];
    } else {
      lines = [
        s.short_greeting,
        model !== "" && main !== "" ? model + " for " + main : model + main,
        field("SN", v("sn")), field("TN", v("tn")), field("TID", v("tid")), field("Merchant Name", v("merchant")),
        labels.slice(1).join(", "),
        notes, s.short_closing, v("name")
      ];
    }

    return {
      subject: serviceSubject(v("device") !== "" ? v("device") : model, actions, v("tn"), v("tid")),
      actions: labels.join(", "),
      body: lines.map(trim).filter(nonEmpty).join("\n")
    };
  }

  /* ---------- ATW Request ---------- */

  /** "2026-10-07" -> "10-7-2026". */
  function atwDate(ymd) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ymd || "");
    return m ? parseInt(m[2], 10) + "-" + parseInt(m[3], 10) + "-" + m[1] : (ymd || "");
  }

  /** Same as atw_subject(): "<Merchant> ATW TN: <Ticket No>". */
  function atwSubject(names, tickets) {
    function unique(values) {
      var out = [], seen = [];
      values.forEach(function (v) {
        v = squash(v);
        if (v !== "" && seen.indexOf(v.toLowerCase()) === -1) { seen.push(v.toLowerCase()); out.push(v); }
      });
      return out;
    }
    tickets = unique(tickets);
    var parts = [unique(names).join(" / "), "ATW", tickets.length ? "TN: " + tickets.join(" / ") : ""];
    return trim(parts.join(" ").replace(/[ \t\n\r\f\x0B]+/g, " "));
  }

  /** Same inputs and result as atw_email_build(). */
  function atwBuild(input) {
    var blocks = [], names = [], tickets = [];
    (input.merchants || []).forEach(function (m) {
      var lines = [squash(m.ticket), squash(m.name), squash(m.address)].filter(nonEmpty);
      if (lines.length) {
        blocks.push(lines.join("\n"));
        names.push(squash(m.name));
        tickets.push(squash(m.ticket));
      }
    });
    var from = atwDate(squash(input.date_from));
    var to = atwDate(squash(input.date_to));
    var fes = (input.fe_names || []).map(squash).filter(nonEmpty);
    var area = squash(input.area).toUpperCase();
    var name = squash(input.name);
    var address = squash(input.address);
    var signOff = (name + " - " + (area === "" ? "FE" : area + " FE")).replace(/^[ -]+|[ -]+$/g, "");

    var lines = [
      "Hi ma'am, sir",
      blocks.join("\n\n"),
      "",
      "Good day requesting ATW:",
      from === "" ? "" : "Scheduled: " + (to === "" || to === from ? from : from + " to " + to),
      fes.length ? "Fe name : " + fes.join(" , ") : "",
      address === "" ? "" : "Address: " + address,
      signOff
    ];
    var body = lines.filter(function (line, i) { return line !== "" || (i === 2 && blocks.length > 0); });
    return { subject: atwSubject(names, tickets), body: body.join("\n") };
  }

  /* ---------- Copy + toast ---------- */

  // Fallback for browsers without the Clipboard API (e.g. the site opened over plain http on a LAN IP).
  function legacyCopy(text) {
    var area = document.createElement("textarea");
    area.value = text;
    area.setAttribute("readonly", "");
    area.style.position = "fixed";
    area.style.opacity = "0";
    document.body.appendChild(area);
    area.select();
    area.setSelectionRange(0, area.value.length);   // needed on iPhone (iOS Safari)
    var ok = false;
    try { ok = document.execCommand("copy"); } catch (e) { ok = false; }
    document.body.removeChild(area);
    return ok;
  }

  /** Copy text; resolves true when copied. Call straight from a click handler. */
  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text).then(function () { return true; }, function () { return legacyCopy(text); });
    }
    return Promise.resolve(legacyCopy(text));
  }

  function toast(message, variant) {
    var holder = document.getElementById("egToasts");
    if (!holder) {
      holder = document.createElement("div");
      holder.id = "egToasts";
      holder.className = "toast-container position-fixed bottom-0 end-0 p-3";
      holder.style.zIndex = "1090";
      document.body.appendChild(holder);
    }
    var el = document.createElement("div");
    el.className = "toast align-items-center border-0 text-bg-" + (variant || "success");
    el.setAttribute("role", "status");
    el.setAttribute("aria-live", "polite");
    var row = document.createElement("div");
    row.className = "d-flex";
    var body = document.createElement("div");
    body.className = "toast-body";
    body.textContent = message;
    var close = document.createElement("button");
    close.type = "button";
    close.className = "btn-close btn-close-white me-2 m-auto";
    close.setAttribute("data-bs-dismiss", "toast");
    close.setAttribute("aria-label", "Close");
    row.appendChild(body);
    row.appendChild(close);
    el.appendChild(row);
    holder.appendChild(el);
    el.addEventListener("hidden.bs.toast", function () { el.remove(); });
    bootstrap.Toast.getOrCreateInstance(el, { delay: 2500 }).show();
  }

  /** Copy and show "Copied!" (or what went wrong). */
  function copyWithToast(text, what) {
    if (!text) {
      toast("Nothing to copy" + (what ? " — the " + what + " is empty." : "."), "warning");
      return Promise.resolve(false);
    }
    return copyText(text).then(function (ok) {
      toast(ok ? "Copied!" : "Could not copy — select the text and copy it by hand.", ok ? "success" : "danger");
      return ok;
    });
  }

  /* ---------- Recipients box ---------- */

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text != null) { node.textContent = text; }
    return node;
  }

  function validEmail(email) { return /^[^\s@<>(),;:"]+@[^\s@<>(),;:"]+\.[^\s@<>(),;:"]+$/.test(email); }

  /**
   * options: { recipients: rows from email_recipients_for(), group: "ATW" | "SERVICE",
   *            isAdmin, csrf, endpoint, onChange }
   * Returns { list(), string() }.
   */
  function RecipientsBox(container, options) {
    var rows = (options.recipients || []).map(function (r) {
      var active = String(r.is_active) === "1";
      return { id: r.id, name: r.display_name || "", email: r.email, active: active, checked: active, extra: false, editing: false, notes: r.notes || "" };
    });
    var changed = options.onChange || function () {};

    function post(data) {
      var body = new FormData();
      body.append("csrf_token", options.csrf);
      Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
      return fetch(options.endpoint, { method: "POST", body: body, headers: { "X-Requested-With": "fetch" } })
        .then(function (res) { return res.json().catch(function () { return { errors: ["Unexpected server response (" + res.status + ")."] }; }); })
        .then(function (json) { if (json.errors) { throw json.errors; } return json; });
    }
    function fail(err) { toast(Array.isArray(err) ? err.join(" ") : "Could not save. Please try again.", "danger"); }

    function editor(row) {
      var wrap = el("div", "d-flex flex-wrap gap-1 align-items-center w-100 mt-1");
      var name = el("input", "form-control form-control-sm");
      name.placeholder = "Name (optional)"; name.value = row.name; name.maxLength = 100; name.style.maxWidth = "12rem";
      var email = el("input", "form-control form-control-sm");
      email.type = "email"; email.placeholder = "email@example.com"; email.value = row.email; email.maxLength = 255; email.style.maxWidth = "16rem";
      var ok = el("button", "btn btn-sm btn-primary", "Use for this email");
      ok.type = "button";
      var cancel = el("button", "btn btn-sm btn-link", "Cancel");
      cancel.type = "button";
      wrap.append(name, email, ok);
      if (options.isAdmin && row.id) {
        var update = el("button", "btn btn-sm btn-outline-primary", "Update in list");
        update.type = "button";
        update.addEventListener("click", function () {
          if (!validEmail(email.value.trim())) { toast("Enter a valid email address.", "warning"); return; }
          update.disabled = true;
          post({ action: "quick_update", id: row.id, display_name: name.value.trim(), email: email.value.trim() })
            .then(function () { row.name = name.value.trim(); row.email = email.value.trim(); row.editing = false; render(); toast("Updated in the recipient list."); })
            .catch(function (e) { update.disabled = false; fail(e); });
        });
        wrap.append(update);
      }
      wrap.append(cancel);
      ok.addEventListener("click", function () {
        if (!validEmail(email.value.trim())) { toast("Enter a valid email address.", "warning"); return; }
        row.name = name.value.trim(); row.email = email.value.trim(); row.edited = true; row.editing = false; row.checked = true;
        render();
      });
      cancel.addEventListener("click", function () { row.editing = false; render(); });
      setTimeout(function () { email.focus(); }, 0);
      return wrap;
    }

    function rowNode(row, index) {
      var item = el("div", "d-flex flex-wrap align-items-center gap-2 py-1 border-bottom" + (!row.active && !row.extra ? " opacity-50" : ""));
      var check = el("input", "form-check-input mt-0");
      check.type = "checkbox";
      check.checked = row.checked;
      check.id = "rcpt" + options.group + index;
      check.addEventListener("change", function () { row.checked = check.checked; render(); });
      var label = el("label", "form-check-label small text-break", recipientFormat(row.name, row.email));
      label.htmlFor = check.id;
      label.style.flex = "1 1 0";   // shrink and wrap beside the checkbox instead of dropping to its own line
      label.style.minWidth = "0";
      if (!row.checked) { label.classList.add("text-decoration-line-through", "text-muted"); }
      item.append(check, label);
      if (!row.active && !row.extra) { item.append(el("span", "badge bg-secondary", "inactive")); }
      if (row.extra) { item.append(el("span", "badge bg-info text-dark", "this email only")); }
      if (row.edited) { item.append(el("span", "badge bg-warning text-dark", "edited")); }
      if (row.notes && !row.active) { item.title = row.notes; }

      var edit = el("button", "btn btn-sm btn-link p-0");
      edit.type = "button";
      edit.title = "Edit this address for this email";
      edit.setAttribute("aria-label", "Edit " + row.email);
      edit.append(el("i", "bi bi-pencil"));
      edit.addEventListener("click", function () { row.editing = !row.editing; render(); });
      item.append(edit);

      if (row.extra) {
        if (options.isAdmin) {
          var save = el("button", "btn btn-sm btn-outline-success py-0", "Save to list");
          save.type = "button";
          save.addEventListener("click", function () {
            save.disabled = true;
            post({ action: "save", display_name: row.name, email: row.email, group_tag: options.group, is_active: 1 })
              .then(function (json) { row.extra = false; row.id = json.id; row.active = true; render(); toast("Saved to the recipient list."); })
              .catch(function (e) { save.disabled = false; fail(e); });
          });
          item.append(save);
        }
        var remove = el("button", "btn btn-sm btn-link text-danger p-0");
        remove.type = "button";
        remove.title = "Remove";
        remove.setAttribute("aria-label", "Remove " + row.email);
        remove.append(el("i", "bi bi-x-lg"));
        remove.addEventListener("click", function () { rows.splice(rows.indexOf(row), 1); render(); });
        item.append(remove);
      }
      if (row.editing) { item.append(editor(row)); }
      return item;
    }

    var listNode = el("div", "mb-2");
    var summary = el("div", "small text-muted mb-2");
    var addWrap = el("div", "d-flex flex-wrap gap-1");
    var addName = el("input", "form-control form-control-sm");
    addName.placeholder = "Name (optional)"; addName.maxLength = 100; addName.style.maxWidth = "12rem"; addName.setAttribute("aria-label", "Name of the extra recipient");
    var addEmail = el("input", "form-control form-control-sm");
    addEmail.type = "email"; addEmail.placeholder = "Add email"; addEmail.maxLength = 255; addEmail.style.maxWidth = "16rem"; addEmail.setAttribute("aria-label", "Extra recipient email");
    var addBtn = el("button", "btn btn-sm btn-outline-primary");
    addBtn.type = "button";
    addBtn.append(el("i", "bi bi-plus-lg"), " Add email");
    addWrap.append(addName, addEmail, addBtn);

    function add() {
      var email = addEmail.value.trim();
      if (!validEmail(email)) { toast("Enter a valid email address.", "warning"); addEmail.focus(); return; }
      rows.push({ id: null, name: addName.value.trim(), email: email, active: true, checked: true, extra: true, editing: false });
      addName.value = ""; addEmail.value = "";
      render();
    }
    addBtn.addEventListener("click", add);
    [addName, addEmail].forEach(function (input) {
      input.addEventListener("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); add(); } });
    });

    function render() {
      listNode.innerHTML = "";
      // Active and extra recipients first, inactive ones greyed out at the bottom.
      var ordered = rows.filter(function (r) { return r.active || r.extra; }).concat(rows.filter(function (r) { return !r.active && !r.extra; }));
      ordered.forEach(function (row, i) { listNode.append(rowNode(row, i)); });
      if (!rows.length) { listNode.append(el("div", "small text-muted", "No saved recipients for this email. Add one below.")); }
      var checked = rows.filter(function (r) { return r.checked; }).length;
      summary.textContent = checked + " of " + rows.length + " recipient" + (rows.length === 1 ? "" : "s") + " selected.";
      changed();
    }

    container.innerHTML = "";
    container.append(listNode, summary, addWrap);
    render();

    return {
      list: function () { return rows.map(function (r) { return { name: r.name, email: r.email, checked: r.checked }; }); },
      string: function () { return recipientsString(rows.map(function (r) { return { name: r.name, email: r.email, checked: r.checked }; })); }
    };
  }

  window.EmailGen = {
    greeting: greeting, recipientFormat: recipientFormat, recipientsString: recipientsString,
    serviceAction: serviceAction, serviceSentence: serviceSentence, serviceSubject: serviceSubject, serviceBuild: serviceBuild,
    atwDate: atwDate, atwSubject: atwSubject, atwBuild: atwBuild,
    copyText: copyText, copyWithToast: copyWithToast, toast: toast, RecipientsBox: RecipientsBox
  };
})();
