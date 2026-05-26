/**
 * MELLON FORGE — Campaigns Page
 */
(async () => {

  // ── AUTH GUARD ──────────────────────────────────────────────────
  const user = await Auth.requireAuth();
  if (!user) return;

  document.getElementById('nav-username').textContent = user.username;

  // ── STATE ───────────────────────────────────────────────────────
  let allCampaigns = [];
  let currentFilter = 'all';

  // ── API HELPER ──────────────────────────────────────────────────
  const api = async (endpoint, method = 'GET', body = null) => {
    const opts = {
      method,
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include'
    };
    if (body) opts.body = JSON.stringify(body);
    const r = await fetch(`${CONFIG.API_BASE}/${endpoint}`, opts);
    return r.json();
  };

  // ── LOAD CAMPAIGNS ──────────────────────────────────────────────
  async function loadCampaigns() {
    const res = await api('campaigns.php');
    if (!res.success) { showToast(res.message || 'Errore caricamento', true); return; }

    allCampaigns = res.data || [];
    renderCampaigns();
  }

  // ── RENDER ──────────────────────────────────────────────────────
  function renderCampaigns() {
    const grid  = document.getElementById('campaigns-grid');
    const empty = document.getElementById('empty-state');

    let campaigns = allCampaigns;
    if (currentFilter === 'gm')     campaigns = allCampaigns.filter(c => c.gm_id === user.id);
    if (currentFilter === 'player') campaigns = allCampaigns.filter(c => c.gm_id !== user.id);

    grid.innerHTML = '';

    if (!campaigns.length) {
      empty.classList.remove('hidden');
      return;
    }
    empty.classList.add('hidden');

    campaigns.forEach(c => {
      const isGM   = c.gm_id === user.id;
      const active = c.active;                   // bool column in campaigns table

      const card = document.createElement('div');
      card.className = 'campaign-card';
      card.innerHTML = `
        <div style="padding:1.25rem 1.25rem .75rem;">
          <div class="flex items-start justify-between gap-2 mb-3">
            <h3 style="font-family:'Cinzel',serif;font-size:.85rem;letter-spacing:.08em;color:#fff8f0;flex:1;">${escHtml(c.title)}</h3>
            ${active
              ? `<span class="badge-active" style="flex-shrink:0;">⬡ Live</span>`
              : `<span class="badge-waiting" style="flex-shrink:0;">In attesa</span>`}
          </div>
          <p style="font-size:.85rem;color:#6f6f85;line-height:1.5;margin-bottom:.75rem;min-height:2.5rem;">
            ${escHtml(c.description || '—')}
          </p>
          <div class="flex items-center gap-2 flex-wrap">
            <span style="font-family:'Cinzel',serif;font-size:.65rem;letter-spacing:.1em;text-transform:uppercase;color:#474754;">
              ${escHtml(c.system || 'D&D 5e')}
            </span>
            <span style="color:#1e1e24;">◆</span>
            ${isGM
              ? `<span class="badge-gm">Game Master</span>`
              : `<span class="badge-player">Giocatore</span>`}
          </div>
          ${isGM ? `
          <div style="font-family:'Cinzel',serif;font-size:.65rem;letter-spacing:.08em;text-transform:uppercase;color:#474754;margin-top:.5rem;">
            ${c.player_count ?? 0} giocatori iscritti
          </div>` : ''}
        </div>
        <div style="padding:.75rem 1.25rem 1rem;border-top:1px solid #18181d;display:flex;gap:.5rem;align-items:center;justify-content:flex-end;">
          ${isGM ? `
            <button class="gm-btn" style="font-family:'Cinzel',serif;letter-spacing:.1em;text-transform:uppercase;font-size:.65rem;padding:.35rem .7rem;background:#1d0402;color:#f4857d;border:1px solid #3a0805;cursor:pointer;"
              onclick="toggleSession('${c.id}', ${active})">
              ${active ? 'Chiudi Sessione' : 'Avvia Sessione'}
            </button>` : ''}
          <button class="btn-enter" ${active ? '' : 'disabled'}
            onclick="${active ? `enterSession('${c.id}','${c.active_session_id ?? ''}')` : ''}">
            ${active ? '▶ Entra' : '⬡ In attesa'}
          </button>
        </div>`;
      grid.appendChild(card);
    });
  }

  // ── FILTER ──────────────────────────────────────────────────────
  window.setFilter = function(f) {
    currentFilter = f;
    ['all','gm','player'].forEach(id => {
      const btn = document.getElementById(`filter-${id}`);
      if (id === f) { btn.style.borderBottomColor='#92140c'; btn.style.color='#fff8f0'; }
      else          { btn.style.borderBottomColor='transparent'; btn.style.color='#6f6f85'; }
    });
    renderCampaigns();
  };

  // ── TOGGLE SESSION (GM only) ─────────────────────────────────────
  window.toggleSession = async function(campaignId, currently_active) {
    const res = await api('sessions.php', 'POST', {
      action:      currently_active ? 'close' : 'open',
      campaign_id: campaignId
    });
    if (res.success) { showToast(currently_active ? 'Sessione chiusa.' : 'Sessione avviata!'); loadCampaigns(); }
    else showToast(res.message || 'Errore', true);
  };

  // ── ENTER SESSION ───────────────────────────────────────────────
  window.enterSession = function(campaignId, sessionId) {
    const params = new URLSearchParams({ campaign: campaignId, session: sessionId });
    window.location.href = `game.html?${params}`;
  };

  // ── CREATE CAMPAIGN ─────────────────────────────────────────────
  window.openModal  = () => document.getElementById('modal-create').classList.add('open');
  window.closeModal = () => document.getElementById('modal-create').classList.remove('open');

  window.handleCreate = async function(e) {
    e.preventDefault();
    const btn  = document.getElementById('create-btn');
    const err  = document.getElementById('create-err');
    const body = {
      title:       document.getElementById('c-title').value.trim(),
      system:      document.getElementById('c-system').value,
      description: document.getElementById('c-desc').value.trim()
    };

    btn.disabled = true;
    btn.textContent = 'Creazione…';
    err.style.display = 'none';

    const res = await api('campaigns.php', 'POST', body);

    btn.disabled = false;
    btn.textContent = 'Crea Campagna';

    if (res.success) {
      closeModal();
      document.getElementById('form-create').reset();
      showToast('Campagna creata!');
      loadCampaigns();
    } else {
      err.textContent = res.message || 'Errore creazione campagna.';
      err.style.display = 'block';
    }
  };

  // Close modal clicking outside
  document.getElementById('modal-create').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
  });

  // ── TOAST ───────────────────────────────────────────────────────
  function showToast(msg, isErr = false) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = isErr ? 'err show' : 'show';
    setTimeout(() => t.className = isErr ? 'err' : '', 3000);
  }

  // ── UTILS ───────────────────────────────────────────────────────
  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // ── INIT ────────────────────────────────────────────────────────
  await loadCampaigns();

})();
