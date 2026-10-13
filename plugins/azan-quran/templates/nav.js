/* Azan & Quran plugin: "Azan & Quran" button on Acacia Radio station pages.
 * Acacia Radio's admin UI is a single-page app, so this watches the URL. On /station/<id>/... it asks the API
 * whether the current user may manage Azan & Quran (cached per station) and, if so, shows a button that opens
 * /azan-quran/<id>. It stacks above other plugins' buttons (Tracker, Music Downloader). */
(() => {
  'use strict';
  const allowed = {};               // station id -> true/false
  let btn = null;

  function button() {
    if (btn) return btn;
    btn = document.createElement('a');
    btn.id = 'azan-quran-button';
    btn.title = 'Prayer-time pauses and the Quran before opening (Azan & Quran plugin)';
    btn.setAttribute('aria-label', 'Open Azan & Quran');
    btn.style.cssText = [
      'position:fixed', 'right:24px', 'bottom:24px', 'z-index:2147483000',
      'background:#2f6f4e', 'color:#fff', 'font:700 15px/1 system-ui,sans-serif',
      'padding:13px 20px', 'border-radius:28px', 'text-decoration:none',
      'border:2px solid #fff', 'box-shadow:0 6px 20px rgba(0,0,0,.4)', 'display:none',
    ].join(';');
    btn.innerHTML = '<span aria-hidden="true" style="margin-right:8px">&#9790;</span>Azan &amp; Quran';
    document.body.appendChild(btn);
    return btn;
  }

  async function check() {
    const m = location.pathname.match(/^\/station\/([^/]+)/);
    if (!m) { if (btn) btn.style.display = 'none'; return; }
    const id = decodeURIComponent(m[1]);
    if (!(id in allowed)) {
      allowed[id] = false;
      try {
        const res = await fetch(`/api/station/${encodeURIComponent(id)}/azan-quran/access`, { credentials: 'same-origin' });
        allowed[id] = res.ok;
      } catch { allowed[id] = false; }
    }
    const b = button();
    b.href = `/azan-quran/${encodeURIComponent(id)}`;
    let bottom = 24;
    for (const other of ['branch-tracker-button', 'music-downloader-button']) {
      const o = document.getElementById(other);
      if (o && o.style.display !== 'none') bottom += 64;
    }
    b.style.bottom = `${bottom}px`;
    b.style.display = allowed[id] ? 'inline-block' : 'none';
  }

  let last = '';
  setInterval(() => { if (location.pathname !== last) { last = location.pathname; check(); } }, 700);
})();
