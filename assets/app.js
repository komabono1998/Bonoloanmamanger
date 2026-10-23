/* Bono Loan Manager - small, dependency-free UI helpers (works fully offline). */
(function () {
  'use strict';

  // ---- sortable + filterable tables
  document.querySelectorAll('table.datatable').forEach(function (t) {
    var tbody = t.tBodies[0];
    if (!tbody) return;
    var rows = tbody.querySelectorAll('tr');
    if (rows.length > 8 && !t.classList.contains('nofilter')) {
      var tools = document.createElement('div');
      tools.className = 'tbl-tools';
      tools.innerHTML = '<input type="search" placeholder="Filter this table…" aria-label="Filter table">';
      t.closest('.tbl-wrap').before(tools);
      tools.firstChild.addEventListener('input', function (e) {
        var q = e.target.value.toLowerCase();
        tbody.querySelectorAll('tr').forEach(function (r) { r.style.display = r.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none'; });
      });
    }
    t.tHead.querySelectorAll('th').forEach(function (th, i) {
      th.addEventListener('click', function () {
        var dir = th.classList.contains('asc') ? -1 : 1;
        t.tHead.querySelectorAll('th').forEach(function (x) { x.classList.remove('asc', 'desc'); });
        th.classList.add(dir === 1 ? 'asc' : 'desc');
        var arr = Array.prototype.slice.call(tbody.querySelectorAll('tr')).filter(function (r) { return r.cells.length > 1; });
        arr.sort(function (a, b) {
          var ca = a.cells[i], cb = b.cells[i];
          var va = ca.dataset.v !== undefined ? parseFloat(ca.dataset.v) : ca.textContent.trim().toLowerCase();
          var vb = cb.dataset.v !== undefined ? parseFloat(cb.dataset.v) : cb.textContent.trim().toLowerCase();
          return (va > vb ? 1 : va < vb ? -1 : 0) * dir;
        });
        arr.forEach(function (r) { tbody.appendChild(r); });
      });
    });
  });

  // ---- thousands separators on money inputs
  function fmt(el) {
    var d = el.value.replace(/[^\d]/g, '');
    el.value = d ? Number(d).toLocaleString('en-US') : '';
  }
  document.querySelectorAll('input.money').forEach(function (el) {
    el.addEventListener('input', function () { fmt(el); el.dispatchEvent(new Event('moneychange', { bubbles: true })); });
    el.addEventListener('focus', function () { el.select(); });
  });

  // ---- confirmation dialogs
  document.addEventListener('submit', function (e) {
    var m = e.target.getAttribute('data-confirm') || (e.submitter && e.submitter.getAttribute('data-confirm'));
    if (m && !window.confirm(m)) e.preventDefault();
  });
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-confirm]');
    if (a && !window.confirm(a.getAttribute('data-confirm'))) e.preventDefault();
    var p = e.target.closest('[data-print]');
    if (p) { e.preventDefault(); window.print(); }
  });

  // ---- prevent double submission
  document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    var f = e.target;
    if (f.method && f.method.toLowerCase() === 'post') {
      var b = f.querySelectorAll('button[type=submit]');
      setTimeout(function () { b.forEach(function (x) { x.disabled = true; }); }, 0);
      setTimeout(function () { b.forEach(function (x) { x.disabled = false; }); }, 8000);
    }
  });

  // ---- live loan calculator (mirrors app/services/calc.php: half-up rounding on basis points)
  var calc = document.getElementById('live-calc');
  if (calc) {
    var num = function (id) { var el = document.getElementById(id); return el ? (parseFloat(String(el.value).replace(/,/g, '')) || 0) : 0; };
    var pct = function (amt, p) { return Math.floor(amt * Math.round(p * 100) / 10000 + 0.5); };
    var run = function () {
      var P = num('f_approved_principal'), months = Math.max(1, num('f_months'));
      var interest = pct(P, num('f_rate_pct')) * months;
      var proc = pct(P, num('f_processing_pct'));
      var app = num('f_application_fee'), insp = num('f_inspection_fee'), other = num('f_other_fees');
      var fees = proc + app + insp + other;
      var ded = document.getElementById('f_fees_deducted').checked;
      var net = ded ? P - fees : P;
      var f = function (n) { return Number(n).toLocaleString('en-US'); };
      calc.innerHTML = '<table>' +
        '<tr><td>Approved principal</td><td>' + f(P) + '</td></tr>' +
        '<tr><td>Interest (' + num('f_rate_pct') + '% × ' + months + ' month' + (months > 1 ? 's' : '') + ', flat)</td><td>' + f(interest) + '</td></tr>' +
        '<tr class="tot"><td>Principal + interest</td><td>' + f(P + interest) + '</td></tr>' +
        '<tr><td>Processing fee (' + num('f_processing_pct') + '%)</td><td>' + f(proc) + '</td></tr>' +
        '<tr><td>Application fee</td><td>' + f(app) + '</td></tr>' +
        '<tr><td>Inspection fee</td><td>' + f(insp) + '</td></tr>' +
        (other ? '<tr><td>Other deductions</td><td>' + f(other) + '</td></tr>' : '') +
        '<tr class="tot"><td>Total fees</td><td>' + f(fees) + '</td></tr>' +
        '<tr class="tot"><td>' + (ded ? 'Net disbursement (fees deducted)' : 'Net disbursement (fees paid separately)') + '</td><td>' + f(net) + '</td></tr>' +
        '<tr><td>Total contractual amount due</td><td>' + f(P + interest + (ded ? 0 : fees)) + '</td></tr></table>';
    };
    document.querySelectorAll('#approve-form input, #approve-form select').forEach(function (el) { el.addEventListener('input', run); el.addEventListener('change', run); el.addEventListener('moneychange', run); });
    run();
  }

  // ---- payment preview (allocation shown before posting)
  var pay = document.getElementById('pay-amount');
  if (pay) {
    pay.addEventListener('input', function () {
      var due = parseFloat(pay.dataset.due || '0');
      var v = parseFloat(pay.value.replace(/,/g, '')) || 0;
      var note = document.getElementById('pay-note');
      if (!note) return;
      if (v > due) { note.textContent = 'Exceeds outstanding by ' + (v - due).toLocaleString('en-US') + ' — tick “record excess as customer credit” (requires authorization) or reduce the amount.'; note.style.color = 'var(--bad)'; }
      else if (v === due && v > 0) { note.textContent = 'Full settlement — the loan will close automatically.'; note.style.color = 'var(--ok)'; }
      else { note.textContent = v > 0 ? 'Balance after payment: ' + (due - v).toLocaleString('en-US') : ''; note.style.color = ''; }
    });
  }

  // ---- idle warning (server enforces the real timeout)
  if (window.IDLE_MS) {
    var idle;
    var reset = function () {
      clearTimeout(idle);
      idle = setTimeout(function () { window.location.href = 'index.php?p=dashboard'; }, window.IDLE_MS + 2000);
    };
    ['click', 'keydown', 'mousemove', 'scroll'].forEach(function (ev) { document.addEventListener(ev, reset, { passive: true }); });
    reset();
  }

  // ---- auto-dismiss success notices
  document.querySelectorAll('.flash.ok').forEach(function (f) { setTimeout(function () { f.style.display = 'none'; }, 9000); });
})();
