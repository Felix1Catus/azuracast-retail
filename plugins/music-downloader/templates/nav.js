/* Music Downloader plugin: "Music Downloader" button on AzuraCast station pages.
 * AzuraCast's admin UI is a single-page app, so this watches the URL. On /station/<id>/... it asks the API
 * whether the current user may download music (cached per station) and, if so, shows a button that opens
 * /music-downloader/<id>. If the Tracker plugin's button is on the page too, this one sits above it. */
(() => {
  'use strict';
  const allowed = {};               // station id -> true/false
  let btn = null;

  function button() {
    if (btn) return btn;
    btn = document.createElement('a');
    btn.id = 'music-downloader-button';
    btn.title = 'Download Spotify playlists into this station\'s media (Music Downloader plugin)';
    btn.setAttribute('aria-label', 'Open Music Downloader');
    btn.style.cssText = [
      'position:fixed', 'right:24px', 'bottom:24px', 'z-index:2147483000',
      'background:#1db954', 'color:#08130c', 'font:700 15px/1 system-ui,sans-serif',
      'padding:13px 20px', 'border-radius:28px', 'text-decoration:none',
      'border:2px solid #fff', 'box-shadow:0 6px 20px rgba(0,0,0,.4)', 'display:none',
    ].join(';');
    btn.innerHTML = '<span aria-hidden="true" style="margin-right:8px">&#9835;</span>Music Downloader';
    const style = document.createElement('style');
    style.textContent = '#music-downloader-button:hover{background:#1ed760!important}';
    document.head.appendChild(style);
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
        const res = await fetch(`/api/station/${encodeURIComponent(id)}/music-downloader/access`, { credentials: 'same-origin' });
        allowed[id] = res.ok;
      } catch { allowed[id] = false; }
    }
    const b = button();
    b.href = `/music-downloader/${encodeURIComponent(id)}`;
    const tracker = document.getElementById('branch-tracker-button');
    b.style.bottom = tracker && tracker.style.display !== 'none' ? '88px' : '24px';
    b.style.display = allowed[id] ? 'inline-block' : 'none';
  }

  let last = '';
  setInterval(() => { if (location.pathname !== last) { last = location.pathname; check(); } }, 700);
})();
