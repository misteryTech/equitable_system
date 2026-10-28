/**
 * Transpo Filing page: ticket form with dynamic legs.
 * Defaults: first leg of the day starts at the engineer's base; the first leg of
 * a later ticket starts at the previous ticket's merchant; each new leg starts
 * where the previous one ended; a new leg's To defaults to the ticket's merchant.
 * Fares auto-fill from the route fare list (get_rate); a changed fare is an
 * override for this leg only. A mode missing from the list can be registered from
 * the leg ("+ Add new mode…"); it is added to every leg's list.
 * Needs window.FILING (from transpo_filing.php) and window.Transpo (transpo.js).
 */
(function () {
  "use strict";

  var F = window.FILING;
  var T = window.Transpo;
  var $ = function (id) { return document.getElementById(id); };
  var TICKET = /^\d{6}-[A-Z]+-\d{4}$/;

  var legsBox = $("legs");
  var template = $("legTemplate");
  var editingId = null;

  /* ---------- messages ---------- */

  function flash(text, type) {
    var box = $("filingMessage");
    box.className = "alert alert-" + (type || "success");
    box.textContent = text;
    box.scrollIntoView({ block: "nearest" });
  }
  try {
    var saved = sessionStorage.getItem("transpoFlash");
    if (saved) { sessionStorage.removeItem("transpoFlash"); flash(saved, "success"); }
  } catch (e) {}

  function showErrors(list) {
    var box = $("formErrors");
    box.innerHTML = "";
    if (!list || !list.length) { box.classList.add("d-none"); return; }
    var ul = document.createElement("ul");
    ul.className = "mb-0";
    list.forEach(function (text) { var li = document.createElement("li"); li.textContent = text; ul.appendChild(li); });
    box.appendChild(ul);
    box.classList.remove("d-none");
    box.scrollIntoView({ block: "nearest" });
  }

  /* ---------- starting point (base) ---------- */

  var baseTs = T.locationSelect($("baseSelect"), {
    placeholder: "Search your base / starting point…",
    createType: "landmark",
    onChange: function (loc) {
      if (!loc || String(loc.id) === String(F.base.id)) { return; }
      var oldBase = F.base;
      T.post("set_base", { location_id: loc.id, engineer_id: F.engineerId })
        .then(function () {
          F.base = loc;
          flash("Starting point saved: " + loc.name + ".", "success");
          // Update the first leg if it still starts at the old base.
          var first = legs()[0];
          if (first && String(first._from.getValue()) === String(oldBase.id)) {
            T.setLocation(first._from, loc, true);
            lookupRate(first);
          }
        })
        .catch(function (err) { flash(err.message, "danger"); T.setLocation(baseTs, oldBase, true); });
    }
  });
  T.setLocation(baseTs, F.base, true);

  /* ---------- merchant ---------- */

  var merchantTs = T.locationSelect($("merchantSelect"), {
    type: "merchant",
    createType: "merchant",
    placeholder: "Search merchant or type a new one…",
    address: function () { return $("address").value; },
    onChange: function (loc) {
      if (!loc) { return; }
      if (!$("address").value && loc.address) { $("address").value = loc.address; }
      // The last leg's To defaults to the merchant.
      var list = legs();
      var last = list[list.length - 1];
      if (last && !last._to.getValue()) {
        T.setLocation(last._to, loc, true);
        lookupRate(last);
      }
    }
  });

  /* ---------- legs ---------- */

  function legs() { return Array.prototype.slice.call(legsBox.querySelectorAll(".transpo-leg")); }

  function merchantLocation() {
    var id = merchantTs.getValue();
    return id ? merchantTs.options[id] : null;
  }

  /** Where the first leg of this ticket starts: previous ticket's merchant that day, else the base. */
  function startPoint() {
    var time = $("visitedAt").value;
    var previous = null;
    F.visits.forEach(function (v) {
      if (v.id === editingId) { return; }
      if (!time || v.visited_at <= time) { previous = v; }
    });
    return previous ? previous.merchant : F.base;
  }

  function renumber() {
    legs().forEach(function (el, i) {
      el.querySelector(".leg-no").textContent = "Leg " + (i + 1);
      el.querySelector(".leg-up").disabled = i === 0;
      el.querySelector(".leg-down").disabled = i === legs().length - 1;
    });
  }

  function setRateArea(el, html) { el.querySelector(".leg-rate").innerHTML = html; }

  function updateBadge(el) {
    var fare = parseFloat(el.querySelector(".leg-fare").value);
    if (el._rate) {
      var overridden = !isNaN(fare) && Math.abs(fare - el._rate.fare) > 0.001;
      setRateArea(el, (el._rate.status === "pending"
          ? '<span class="badge bg-warning text-dark">pending fare</span>'
          : '<span class="badge bg-success">saved rate</span>') +
        (overridden ? ' <span class="badge bg-danger">override (list: ' + T.money(el._rate.fare) + ")</span>" : ""));
    }
  }

  /** Look up the route fare. keepFare: don't replace the typed fare (when editing a saved ticket). */
  function lookupRate(el, keepFare) {
    var from = el._from.getValue(), to = el._to.getValue(), mode = el.querySelector(".leg-mode").value;
    el._rate = null;
    el.querySelector(".leg-add-rate").classList.add("d-none");
    if (!from || !to || !mode) { setRateArea(el, ""); return; }
    if (from === to) { setRateArea(el, '<span class="text-danger">From and To are the same.</span>'); return; }
    var seq = (el._seq = (el._seq || 0) + 1);
    setRateArea(el, '<span class="text-muted">Looking up fare…</span>');
    T.get({ action: "get_rate", from: from, to: to, mode: mode }).then(function (json) {
      if (seq !== el._seq) { return; }          // a newer lookup is running
      if (json.rate) {
        el._rate = json.rate;
        if (!keepFare || !el.querySelector(".leg-fare").value) { el.querySelector(".leg-fare").value = json.rate.fare.toFixed(2); }
        updateBadge(el);
      } else {
        setRateArea(el, '<span class="text-muted">No fare set —</span> <a href="#" class="add-rate-link">Add this route fare</a>');
      }
      recalc();
    }).catch(function () { setRateArea(el, '<span class="text-danger">Could not look up the fare.</span>'); });
  }

  /* ---------- transport modes ---------- */

  function modeSelects() {
    return [template.content.querySelector(".leg-mode")].concat(Array.prototype.slice.call(legsBox.querySelectorAll(".leg-mode")));
  }

  // A mode that is not in this list yet (just registered, or turned off but used on a saved leg).
  function addModeOption(select, code, label) {
    if (select.querySelector('option[value="' + code.replace(/"/g, "") + '"]')) { return; }
    var o = document.createElement("option");
    o.value = code;
    o.textContent = label;
    select.insertBefore(o, select.querySelector('option[value="__new"]'));
  }

  function setupModeAdd(el) {
    var select = el.querySelector(".leg-mode");
    var box = el.querySelector(".leg-mode-new");
    var input = el.querySelector(".leg-mode-name");
    select._last = select.value;

    function close() { box.classList.add("d-none"); select.value = select._last || ""; }
    function add() {
      var label = input.value.trim();
      if (!label) { input.focus(); return; }
      var btn = el.querySelector(".leg-mode-add");
      btn.disabled = true;
      T.post("add_mode", { label: label }).then(function (res) {
        modeSelects().forEach(function (s) { addModeOption(s, res.mode.code, res.mode.label); });
        select.value = select._last = res.mode.code;
        box.classList.add("d-none");
        flash(res.created ? 'Mode "' + res.mode.label + '" added. It is now in every leg\'s list.' : '"' + res.mode.label + '" was already in the list; it is selected now.', "success");
        lookupRate(el);
      }).catch(function (err) { alert(err.message); })
        .finally(function () { btn.disabled = false; });
    }

    select.addEventListener("change", function () {
      if (select.value === "__new") {
        box.classList.remove("d-none");
        input.value = "";
        input.focus();
        return;
      }
      select._last = select.value;
      box.classList.add("d-none");
      lookupRate(el);
    });
    el.querySelector(".leg-mode-add").addEventListener("click", add);
    el.querySelector(".leg-mode-cancel").addEventListener("click", close);
    input.addEventListener("keydown", function (e) {
      if (e.key === "Enter") { e.preventDefault(); add(); }
      if (e.key === "Escape") { e.preventDefault(); close(); }
    });
  }

  function addLeg(data, after) {
    var el = template.content.firstElementChild.cloneNode(true);
    if (after && after.nextSibling) { legsBox.insertBefore(el, after.nextSibling); } else { legsBox.appendChild(el); }

    el._from = T.locationSelect(el.querySelector(".leg-from"), { placeholder: "From…", onChange: function () { lookupRate(el); } });
    el._to = T.locationSelect(el.querySelector(".leg-to"), { placeholder: "To…", onChange: function () { lookupRate(el); } });
    setupModeAdd(el);
    el.querySelector(".leg-fare").addEventListener("input", function () { updateBadge(el); recalc(); });

    el.querySelector(".leg-remove").addEventListener("click", function () {
      if (legs().length === 1) {
        el._from.clear(); el._to.clear();
        el.querySelector(".leg-mode").value = el.querySelector(".leg-mode")._last = ""; el.querySelector(".leg-fare").value = "";
        el.querySelector(".leg-mode-new").classList.add("d-none");
        lookupRate(el);
      } else {
        el._from.destroy(); el._to.destroy(); el.remove();
      }
      renumber(); recalc();
    });
    el.querySelector(".leg-up").addEventListener("click", function () {
      if (el.previousElementSibling) { legsBox.insertBefore(el, el.previousElementSibling); renumber(); }
    });
    el.querySelector(".leg-down").addEventListener("click", function () {
      if (el.nextElementSibling) { legsBox.insertBefore(el.nextElementSibling, el); renumber(); }
    });

    // "No fare set - Add this route fare"
    el.addEventListener("click", function (e) {
      if (!e.target.classList.contains("add-rate-link")) { return; }
      e.preventDefault();
      var panel = el.querySelector(".leg-add-rate");
      panel.querySelector(".rate-fare").value = el.querySelector(".leg-fare").value;
      panel.classList.remove("d-none");
      panel.querySelector(".rate-fare").focus();
    });
    el.querySelector(".rate-cancel").addEventListener("click", function () { el.querySelector(".leg-add-rate").classList.add("d-none"); });
    el.querySelector(".rate-save").addEventListener("click", function () {
      var panel = el.querySelector(".leg-add-rate");
      var btn = this;
      btn.disabled = true;
      T.post("quick_add_rate", {
        from: el._from.getValue(), to: el._to.getValue(), mode: el.querySelector(".leg-mode").value,
        fare: panel.querySelector(".rate-fare").value, two_way: panel.querySelector(".rate-two-way").checked ? 1 : ""
      }).then(function (res) {
        el._rate = res.rate;
        el.querySelector(".leg-fare").value = res.rate.fare.toFixed(2);
        panel.classList.add("d-none");
        updateBadge(el); recalc();
      }).catch(function (err) { alert(err.message); })
        .finally(function () { btn.disabled = false; });
    });

    // Values: given (editing) or defaults.
    if (data) {
      T.setLocation(el._from, data.from, true);
      T.setLocation(el._to, data.to, true);
      if (data.mode) { addModeOption(el.querySelector(".leg-mode"), data.mode, (data.mode_label || data.mode) + " (turned off)"); }
      el.querySelector(".leg-mode").value = el.querySelector(".leg-mode")._last = data.mode || "";
      if (data.fare) { el.querySelector(".leg-fare").value = Number(data.fare).toFixed(2); }
      lookupRate(el, true);
    } else {
      var prev = el.previousElementSibling;
      var from = prev && prev._to && prev._to.getValue() ? prev._to.options[prev._to.getValue()] : (prev ? null : startPoint());
      if (from) { T.setLocation(el._from, from, true); }
      var merchant = merchantLocation();
      if (merchant && (!from || String(from.id) !== String(merchant.id))) { T.setLocation(el._to, merchant, true); }
    }
    renumber(); recalc();
    return el;
  }

  $("addLeg").addEventListener("click", function () {
    var list = legs();
    var el = addLeg(null, list[list.length - 1]);
    el.querySelector(".leg-mode").focus();
  });

  /* ---------- totals ---------- */

  function recalc() {
    var subtotal = 0;
    legs().forEach(function (el) {
      var v = parseFloat(el.querySelector(".leg-fare").value);
      if (!isNaN(v) && v > 0) { subtotal += v; }
    });
    var editing = F.visits.filter(function (v) { return v.id === editingId; })[0];
    $("subtotal").textContent = T.money(subtotal);
    $("dayWithThis").textContent = T.money(F.dayTotal - (editing ? editing.subtotal : 0) + subtotal);
  }

  /* ---------- form ---------- */

  function clearLegs() {
    legs().forEach(function (el) { el._from.destroy(); el._to.destroy(); el.remove(); });
  }

  function resetForm() {
    editingId = null;
    $("formTitle").textContent = "Add Ticket";
    $("visitId").value = "";
    $("ticketNo").value = "";
    $("ticketNo").classList.remove("is-invalid");
    $("visitedAt").value = F.date + "T" + (F.isToday ? F.now : "08:00");
    merchantTs.clear(true);
    $("address").value = "";
    $("remarks").value = "";
    $("cancelEdit").classList.add("d-none");
    showErrors([]);
    clearLegs();
    addLeg();
  }

  function editVisit(id) {
    var v = F.visits.filter(function (x) { return x.id === id; })[0];
    if (!v) { return; }
    editingId = v.id;
    $("formTitle").textContent = "Edit Ticket " + v.ticket_no;
    $("visitId").value = v.id;
    $("ticketNo").value = v.ticket_no;
    $("visitedAt").value = v.visited_at;
    T.setLocation(merchantTs, v.merchant, true);
    $("address").value = v.address;
    $("remarks").value = v.remarks;
    $("cancelEdit").classList.remove("d-none");
    showErrors([]);
    clearLegs();
    v.legs.forEach(function (leg) { addLeg(leg); });
    $("visitForm").scrollIntoView({ behavior: "smooth", block: "start" });
  }

  $("cancelEdit").addEventListener("click", resetForm);
  $("ticketNo").addEventListener("input", function () {
    var pos = this.selectionStart;
    this.value = this.value.toUpperCase();
    this.setSelectionRange(pos, pos);
    this.classList.toggle("is-invalid", this.value !== "" && !TICKET.test(this.value.trim()));
  });

  document.querySelectorAll(".edit-visit").forEach(function (btn) {
    btn.addEventListener("click", function () { editVisit(Number(btn.dataset.id)); });
  });
  document.querySelectorAll(".delete-visit").forEach(function (btn) {
    btn.addEventListener("click", function () {
      if (!confirm("Delete ticket " + btn.dataset.ticket + " and its legs?")) { return; }
      btn.disabled = true;
      T.post("delete_visit", { visit_id: btn.dataset.id })
        .then(function () { sessionStorage.setItem("transpoFlash", "Ticket " + btn.dataset.ticket + " deleted."); location.href = F.returnUrl; })
        .catch(function (err) { alert(err.message); btn.disabled = false; });
    });
  });

  $("visitForm").addEventListener("submit", function (e) {
    e.preventDefault();
    var errors = [];
    var ticket = $("ticketNo").value.trim().toUpperCase();
    if (!TICKET.test(ticket)) { errors.push("Ticket no. must look like 260911-BSERV-0523."); }
    if (!merchantTs.getValue()) { errors.push("Please choose the merchant."); }
    if (!$("visitedAt").value) { errors.push("Please enter the date and time."); }
    var legData = legs().map(function (el, i) {
      var mode = el.querySelector(".leg-mode").value;
      var leg = { from_id: el._from.getValue(), to_id: el._to.getValue(), mode: mode === "__new" ? "" : mode, fare: el.querySelector(".leg-fare").value };
      if (!leg.from_id || !leg.to_id) { errors.push("Leg " + (i + 1) + ": choose From and To."); }
      else if (leg.from_id === leg.to_id) { errors.push("Leg " + (i + 1) + ": From and To must be different."); }
      if (!leg.mode) { errors.push("Leg " + (i + 1) + ": choose the mode."); }
      if (!(parseFloat(leg.fare) > 0)) { errors.push("Leg " + (i + 1) + ": enter a fare above 0."); }
      return leg;
    });
    if (errors.length) { showErrors(errors); return; }

    var btn = $("saveVisit");
    btn.disabled = true;
    T.post("save_visit", {
      payload: JSON.stringify({
        visit_id: editingId || 0,
        engineer_id: F.engineerId,
        ticket_no: ticket,
        merchant_id: merchantTs.getValue(),
        visited_at: $("visitedAt").value,
        address: $("address").value,
        remarks: $("remarks").value,
        legs: legData
      })
    }).then(function (res) {
      sessionStorage.setItem("transpoFlash", "Ticket " + ticket + " saved.");
      location.href = F.returnUrl.replace(/date=[0-9-]+/, "date=" + res.date);
    }).catch(function (err) {
      showErrors(err.list || [err.message]);
      btn.disabled = false;
    });
  });

  resetForm();
})();
