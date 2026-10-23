/* One-click End of Day: runs each step in turn, shows a progress bar and the outcome of every step. */
(function () {
  try {
    var done = sessionStorage.getItem('eodDone');
    if (done) { sessionStorage.removeItem('eodDone'); var m = document.querySelector('main') || document.body; var n = document.createElement('div'); n.className = 'notice ok'; n.innerHTML = done; m.insertBefore(n, m.firstChild); }
  } catch (e) {}
  document.querySelectorAll('[data-eod]').forEach(function (box) {
    var start = box.querySelector('[data-eod-start]');
    if (!start) return;
    var run = box.querySelector('.eod-run'), ready = box.querySelector('.eod-ready'), fill = box.querySelector('[data-eod-fill]'),
        pct = box.querySelector('[data-eod-pct]'), bar = box.querySelector('.eod-bar'), result = box.querySelector('[data-eod-result]'),
        items = Array.prototype.slice.call(box.querySelectorAll('.eod-list li')), idx = 0, backupOk = true, busy = false;

    function progress(running) {
      var p = Math.round(((idx + (running ? 0.5 : 0)) / items.length) * 100);
      fill.style.width = p + '%'; pct.textContent = p + '%'; bar.setAttribute('aria-valuenow', p);
    }
    function mark(li, state, msg) {
      li.className = state; if (msg !== undefined) li.querySelector('[data-msg]').textContent = msg;
    }
    function post(phase) {
      var fd = new FormData();
      fd.append('_csrf', box.getAttribute('data-csrf')); fd.append('branch', box.getAttribute('data-branch')); fd.append('date', box.getAttribute('data-date'));
      fd.append('phase', phase); fd.append('notes', (box.querySelector('[data-eod-notes]') || {}).value || ''); if (phase === 'done') fd.append('backup_ok', backupOk ? '1' : '0');
      return fetch(box.getAttribute('data-url'), { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function fail(li, msg) {
      mark(li, 'err', msg); busy = false;
      result.hidden = false; result.className = 'eod-result bad';
      result.innerHTML = '<b>End of Day FAILED.</b> ' + esc(msg) + ' <button type="button" class="btn sm" data-eod-retry>Try again from this step</button>';
      result.querySelector('[data-eod-retry]').addEventListener('click', function () { result.hidden = true; busy = true; next(); });
    }
    function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
    function finish(nextDate) {
      idx = items.length; progress(false); busy = false;
      result.hidden = false; result.className = 'eod-result ok';
      result.innerHTML = '<div class="eod-tick">&#10003;</div><div><b>End of Day completed successfully</b><br>' + esc(box.getAttribute('data-branchname')) + ': the business date is closed and locked' +
        (nextDate ? ', and the system date is now <b>' + esc(nextDate) + '</b>' : '') + '.' + (backupOk ? '' : ' <span class="warn">The closing backup failed: take a manual backup now.</span>') +
        '<br><a class="btn sm" href="' + location.pathname + location.search + '">Refresh</a></div>';
      try { document.title = '✓ End of Day completed'; } catch (e) {}
      try { sessionStorage.setItem('eodDone', '<b>End of Day completed successfully</b> for ' + esc(box.getAttribute('data-branchname')) + ' (' + esc(box.getAttribute('data-datelabel')) + ' closed and locked)' + (nextDate ? '. The system date is now <b>' + esc(nextDate) + '</b>.' : '.')); } catch (e) {}
      var note = document.createElement('div'); note.className = 'muted'; note.style.marginTop = '.4rem'; note.textContent = 'Updating the system date everywhere…'; result.appendChild(note);
      setTimeout(function () { location.href = location.pathname + location.search; }, 3000);
    }
    var nextDate = '';
    function next() {
      if (idx >= items.length) { finish(nextDate); return; }
      var li = items[idx], phase = li.getAttribute('data-phase');
      mark(li, 'run', 'Working…'); progress(true);
      post(phase).then(function (r) {
        if (phase === 'closing_backup' && !r.ok) { backupOk = false; mark(li, 'warn', r.message || 'The closing backup failed'); idx++; next(); return; }
        if (phase === 'done') { if (r.next_date) nextDate = r.next_date; mark(li, r.ok ? 'ok' : 'warn', r.message); idx++; next(); return; }
        if (!r.ok) { fail(li, r.message || 'The step failed.'); return; }
        if (phase === 'next' && r.message) nextDate = (r.message.match(/now (.+)\.$/) || [])[1] || '';
        mark(li, 'ok', r.message); idx++; next();
      }).catch(function () { fail(li, 'The connection was interrupted. Reload the page to see how far End of Day got.'); });
    }
    var modal = document.querySelector('[data-eod-modal]');
    function begin() { busy = true; ready.hidden = true; run.hidden = false; idx = 0; progress(false); next(); }
    start.addEventListener('click', function () {
      if (busy || start.disabled) return;
      modal.querySelector('[data-eod-question]').innerHTML = 'Are you ready to run End of Day for <b>' + esc(box.getAttribute('data-branchname')) + '</b> on <b>' + esc(box.getAttribute('data-datelabel')) + '</b>?';
      modal.hidden = false;
      var yes = modal.querySelector('[data-eod-yes]'), no = modal.querySelector('[data-eod-no]');
      function close() { modal.hidden = true; yes.onclick = null; no.onclick = null; document.removeEventListener('keydown', esc2); }
      function esc2(e) { if (e.key === 'Escape') close(); }
      document.addEventListener('keydown', esc2);
      yes.onclick = function () { close(); begin(); };
      no.onclick = close;
      yes.focus();
    });
  });
})();
