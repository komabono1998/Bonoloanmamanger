/* End of Day for all offices: one confirmation, then every office runs its steps in turn with a progress bar for each office and one overall. */
(function () {
  function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
  try {
    var done = sessionStorage.getItem('eodDone');
    if (done) { sessionStorage.removeItem('eodDone'); var m = document.querySelector('main') || document.body; var n = document.createElement('div'); n.className = 'notice ok'; n.innerHTML = done; m.insertBefore(n, m.firstChild); }
  } catch (e) {}

  function notes() { var t = document.querySelector('[data-eod-notes-global]'); return t ? t.value : ''; }

  /** One office: runs its phases; box._run(onProgress, cb) -> cb({ok, nextDate, backupOk, message}). */
  document.querySelectorAll('[data-eod][data-participant]').forEach(function (box) {
    var run = box.querySelector('.eod-run'), ready = box.querySelector('.eod-ready'), fill = box.querySelector('[data-eod-fill]'),
        pct = box.querySelector('[data-eod-pct]'), bar = box.querySelector('.eod-bar'), result = box.querySelector('[data-eod-result]'),
        items = Array.prototype.slice.call(box.querySelectorAll('.eod-list li'));
    var idx = 0, backupOk = true, nextDate = '', cb = null, onProgress = null;

    function progress(running) {
      var f = (idx + (running ? 0.5 : 0)) / items.length, p = Math.round(f * 100);
      fill.style.width = p + '%'; pct.textContent = p + '%'; bar.setAttribute('aria-valuenow', p);
      if (onProgress) onProgress(f);
    }
    function mark(li, state, msg) { li.className = state; if (msg !== undefined) li.querySelector('[data-msg]').textContent = msg; }
    function post(phase) {
      var fd = new FormData();
      fd.append('_csrf', box.getAttribute('data-csrf')); fd.append('branch', box.getAttribute('data-branch')); fd.append('date', box.getAttribute('data-date'));
      fd.append('phase', phase); fd.append('notes', notes()); if (phase === 'done') fd.append('backup_ok', backupOk ? '1' : '0');
      return fetch(box.getAttribute('data-url'), { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function fail(li, msg) {
      mark(li, 'err', msg);
      result.hidden = false; result.className = 'eod-result bad';
      result.innerHTML = '<b>End of Day FAILED for ' + esc(box.getAttribute('data-branchname')) + '.</b> ' + esc(msg);
      var c = cb; cb = null; if (c) c({ ok: false, message: box.getAttribute('data-branchname') + ': ' + msg });
    }
    function finish() {
      idx = items.length; progress(false);
      result.hidden = false; result.className = 'eod-result ok';
      result.innerHTML = '<div class="eod-tick">&#10003;</div><div><b>' + esc(box.getAttribute('data-branchname')) + ' closed</b>: ' + esc(box.getAttribute('data-datelabel')) + ' is locked' +
        (nextDate ? ' and the system date is now <b>' + esc(nextDate) + '</b>' : '') + '.' + (backupOk ? '' : ' <span class="warn">The closing backup failed: take a manual backup now.</span>') + '</div>';
      var c = cb; cb = null; if (c) c({ ok: true, nextDate: nextDate, backupOk: backupOk });
    }
    function next() {
      if (idx >= items.length) { finish(); return; }
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
    box._run = function (progressFn, doneFn) { cb = doneFn; onProgress = progressFn; ready.hidden = true; run.hidden = false; idx = 0; backupOk = true; nextDate = ''; progress(false); next(); };
  });

  var all = document.querySelector('[data-eod-all]');
  if (!all) return;
  var master = document.querySelector('[data-eod-master]'), mfill = document.querySelector('[data-master-fill]'), mpct = document.querySelector('[data-master-pct]'),
      mres = document.querySelector('[data-master-result]'), modal = document.querySelector('[data-eod-modal]');
  function mprogress(f) { var p = Math.round(f * 100); mfill.style.width = p + '%'; mpct.textContent = p + '%'; master.querySelector('.eod-bar').setAttribute('aria-valuenow', p); }

  all.addEventListener('click', function () {
    if (all.disabled) return;
    var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-eod][data-participant]')).filter(function (b) { return b._run; });
    if (!boxes.length) return;
    var yes = modal.querySelector('[data-eod-yes]'), no = modal.querySelector('[data-eod-no]');
    modal.querySelector('[data-eod-question]').innerHTML = 'Are you ready to run End of Day for <b>' + (boxes.length === 1 ? '1 office' : 'all ' + boxes.length + ' offices') + '</b> on <b>' + esc(boxes[0].getAttribute('data-datelabel')) + '</b>?<br>' +
      boxes.map(function (b) { return esc(b.getAttribute('data-branchname')) + ' &mdash; ' + esc(b.getAttribute('data-datelabel')); }).join('<br>') +
      (all.getAttribute('data-catchup') ? '<br><br>The other offices are further ahead, so End of Day then continues day by day until every office is on <b>' + esc(all.getAttribute('data-catchup-label')) + '</b>.' : '');
    modal.hidden = false;
    function close() { modal.hidden = true; yes.onclick = null; no.onclick = null; document.removeEventListener('keydown', esc2); }
    function esc2(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', esc2);
    no.onclick = close;
    yes.onclick = function () { start(true); };
    function start(first) {
      if (modal.hidden === false) close(); all.disabled = true; master.hidden = false; mres.hidden = true; mprogress(0);
      var msgs = [], total = boxes.length;
      (function step(i) {
        if (i >= total) {
          mprogress(1); mres.hidden = false; mres.className = 'eod-result ok';
          mres.innerHTML = '<div class="eod-tick">&#10003;</div><div><b>End of Day completed successfully for ' + (total === 1 ? '1 office' : 'all ' + total + ' offices') + '</b><br>Updating the system date everywhere…</div>';
          try { document.title = '✓ End of Day completed'; sessionStorage.setItem('eodDone', '<b>End of Day completed successfully</b> for ' + msgs.join('; ') + '.'); } catch (e) {}
          try { if (all.getAttribute('data-catchup')) sessionStorage.setItem('eodCatchup', '1'); else sessionStorage.removeItem('eodCatchup'); } catch (e) {}
          setTimeout(function () { location.href = location.pathname + location.search; }, 2500); return;
        }
        boxes[i]._run(function (f) { mprogress((i + f) / total); }, function (r) {
          if (!r.ok) { mres.hidden = false; mres.className = 'eod-result bad'; mres.innerHTML = '<b>End of Day FAILED.</b> ' + esc(r.message) + (i > 0 ? '<br>Offices already closed stay closed; fix the problem and run End of Day again for the rest.' : '') + ' <button type="button" class="btn sm" onclick="location.reload()">Reload</button>'; all.disabled = false; try { sessionStorage.removeItem('eodCatchup'); } catch (e) {} return; }
          msgs.push(esc(boxes[i].getAttribute('data-branchname')) + ' (' + esc(boxes[i].getAttribute('data-datelabel')) + ' closed' + (r.nextDate ? '; system date now ' + esc(r.nextDate) : '') + ')');
          step(i + 1);
        });
      })(0);
    }
    yes.focus();
    window.__eodStart = start;
  });
  try { if (sessionStorage.getItem('eodCatchup') && !all.disabled && all.getAttribute('data-catchup')) { sessionStorage.removeItem('eodCatchup'); setTimeout(function () { all.click(); setTimeout(function () { var y = modal.querySelector('[data-eod-yes]'); if (y && !modal.hidden) y.click(); }, 50); }, 600); } else sessionStorage.removeItem('eodCatchup'); } catch (e) {}
})();
