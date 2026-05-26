/**
 * MELLON FORGE — Game Module
 * Hex grid canvas, Supabase Realtime, dice roller, chat
 */

// ── UTILS ─────────────────────────────────────────────────────────────────
const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
const $ = id => document.getElementById(id);
const api = async (endpoint, method = 'GET', body = null) => {
  const opts = { method, headers:{'Content-Type':'application/json'}, credentials:'include' };
  if (body) opts.body = JSON.stringify(body);
  return fetch(`${CONFIG.API_BASE}/${endpoint}`, opts).then(r => r.json());
};

// ── HEX MATH (pointy-top, offset odd-r) ───────────────────────────────────
const Hex = {
  // Pixel center of hex at offset (col, row) given hex size s
  toPixel(col, row, s) {
    const w = Math.sqrt(3) * s;
    const h = 2 * s;
    const x = col * w + (row % 2 !== 0 ? w / 2 : 0) + w / 2;
    const y = row * h * 0.75 + s;
    return { x, y };
  },
  // Hex offset coords from canvas pixel (px, py)
  fromPixel(px, py, s) {
    const w = Math.sqrt(3) * s;
    const h = 2 * s;
    // Rough estimate of row first
    const row = Math.floor(py / (h * 0.75));
    const offset = row % 2 !== 0 ? w / 2 : 0;
    const col = Math.floor((px - offset) / w);
    // Refine: check all neighbors
    const candidates = [[col, row],[col+1,row],[col-1,row],[col,row+1],[col,row-1],
                        [col+1,row+1],[col-1,row+1],[col+1,row-1],[col-1,row-1]];
    let best = candidates[0], bestD = Infinity;
    for (const [c,r] of candidates) {
      const ctr = this.toPixel(c, r, s);
      const d = (px-ctr.x)**2 + (py-ctr.y)**2;
      if (d < bestD) { bestD = d; best = [c,r]; }
    }
    return { col: best[0], row: best[1] };
  },
  // Pointy-top hex corners
  corners(cx, cy, s) {
    return Array.from({length:6}, (_,i) => {
      const a = Math.PI / 180 * (60*i - 30);
      return [cx + s * Math.cos(a), cy + s * Math.sin(a)];
    });
  }
};

// ── HEX CANVAS RENDERER ───────────────────────────────────────────────────
const HexMap = (() => {
  const canvas = $('hex-canvas');
  const ctx    = canvas.getContext('2d');

  let map = null, tokens = [], fogSet = new Set();
  let hexSize = 36;
  let panX = 0, panY = 0, zoom = 1;
  let isPanning = false, panStart = {x:0, y:0};
  let selectedHex = null;
  let selectedTokenId = null;
  let fogMode = false;

  // Colors
  const C = {
    grid:     '#1e1e24',
    gridLine: '#2a2a32',
    hover:    'rgba(146,20,12,.25)',
    selected: 'rgba(146,20,12,.45)',
    fog:      'rgba(6,6,7,.82)',
    fogEdit:  'rgba(146,20,12,.35)',
    token:    '#92140c',
    tokenBg:  '#1d0402',
  };

  function resize() {
    const area = document.getElementById('canvas-area');
    canvas.width  = area.clientWidth;
    canvas.height = area.clientHeight;
    draw();
  }

  function draw() {
    if (!map) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.save();
    ctx.translate(panX, panY);
    ctx.scale(zoom, zoom);

    const s = hexSize;

    // Background
    ctx.fillStyle = '#121216';
    ctx.fillRect(-panX/zoom - 100, -panY/zoom - 100, canvas.width/zoom + 200, canvas.height/zoom + 200);

    // Draw hexes
    for (let r = 0; r < map.rows; r++) {
      for (let c = 0; c < map.cols; c++) {
        const { x, y } = Hex.toPixel(c, r, s);
        const corners  = Hex.corners(x, y, s);
        const key      = `${c},${r}`;
        const isFog    = fogSet.has(key);
        const isSel    = selectedHex && selectedHex.col === c && selectedHex.row === r;

        // Hex fill
        ctx.beginPath();
        corners.forEach(([px,py], i) => i ? ctx.lineTo(px,py) : ctx.moveTo(px,py));
        ctx.closePath();
        ctx.fillStyle = isSel ? C.selected : '#18181d';
        ctx.fill();
        ctx.strokeStyle = C.gridLine;
        ctx.lineWidth = .8 / zoom;
        ctx.stroke();

        // Fog
        if (isFog) {
          ctx.beginPath();
          corners.forEach(([px,py],i) => i ? ctx.lineTo(px,py) : ctx.moveTo(px,py));
          ctx.closePath();
          ctx.fillStyle = fogMode ? C.fogEdit : C.fog;
          ctx.fill();
        }

        // Coord label (only when zoomed in)
        if (zoom > 1.5) {
          ctx.fillStyle = '#474754';
          ctx.font = `${8/zoom}px Cinzel`;
          ctx.textAlign = 'center';
          ctx.fillText(`${c},${r}`, x, y + 3);
        }
      }
    }

    // Draw tokens
    for (const tok of tokens) {
      if (!tok.visible_to_players && !window._isGM) continue;
      const { x, y } = Hex.toPixel(tok.hex_col, tok.hex_row, s);
      const r2 = s * 0.6;
      const isSelTok = tok.id === selectedTokenId;

      // Token background circle
      ctx.beginPath();
      ctx.arc(x, y, r2, 0, Math.PI*2);
      ctx.fillStyle = isSelTok ? '#580c07' : C.tokenBg;
      ctx.fill();
      ctx.strokeStyle = isSelTok ? '#d31e11' : '#92140c';
      ctx.lineWidth = (isSelTok ? 2.5 : 1.5) / zoom;
      ctx.stroke();

      if (tok.image_url) {
        // Draw cached image
        const img = imageCache.get(tok.image_url);
        if (img && img.complete) {
          ctx.save();
          ctx.beginPath();
          ctx.arc(x, y, r2 - 1, 0, Math.PI*2);
          ctx.clip();
          ctx.drawImage(img, x - r2 + 1, y - r2 + 1, (r2-1)*2, (r2-1)*2);
          ctx.restore();
        }
      }

      // Label
      ctx.fillStyle = '#ffca8d';
      ctx.font = `bold ${Math.max(7, s*0.22)}px Cinzel`;
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillText(tok.label ? tok.label.slice(0,3).toUpperCase() : '?', x, y);

      // HP bar (if character attached)
      if (tok.character) {
        const maxHp = tok.character.hp_max  || 10;
        const curHp = tok.character.hp_current || 0;
        const pct   = Math.max(0, Math.min(1, curHp / maxHp));
        const bw    = s * 1.1;
        const bx    = x - bw/2;
        const by    = y + r2 + 3/zoom;
        const bh    = 4/zoom;
        ctx.fillStyle = '#0c0c0e';
        ctx.fillRect(bx, by, bw, bh);
        ctx.fillStyle = pct > 0.5 ? '#3a6b2a' : pct > 0.25 ? '#b07d12' : '#92140c';
        ctx.fillRect(bx, by, bw * pct, bh);
      }
    }

    ctx.restore();
  }

  // Image cache
  const imageCache = new Map();
  function preloadTokenImages(toks) {
    toks.forEach(t => {
      if (t.image_url && !imageCache.has(t.image_url)) {
        const img = new Image();
        img.src = t.image_url;
        img.onload = draw;
        imageCache.set(t.image_url, img);
      }
    });
  }

  // Mouse handling
  let hoverHex = null;

  canvas.addEventListener('mousedown', e => {
    if (e.button === 1 || (e.button === 0 && e.altKey)) {
      isPanning = true;
      panStart = { x: e.clientX - panX, y: e.clientY - panY };
      canvas.style.cursor = 'grabbing';
      return;
    }
    if (e.button !== 0) return;
    const rect = canvas.getBoundingClientRect();
    const wx = (e.clientX - rect.left - panX) / zoom;
    const wy = (e.clientY - rect.top  - panY) / zoom;
    const h  = Hex.fromPixel(wx, wy, hexSize);

    if (window._currentTool === 'fog' && window._isGM) {
      const key = `${h.col},${h.row}`;
      fogSet.has(key) ? fogSet.delete(key) : fogSet.add(key);
      HexMap.saveFog();
      draw();
      return;
    }

    // Check token hit
    const hit = tokens.find(t => t.hex_col === h.col && t.hex_row === h.row);
    selectedHex   = h;
    selectedTokenId = hit ? hit.id : null;
    draw();
    $('hex-coords').textContent = `col:${h.col}  row:${h.row}`;
  });

  canvas.addEventListener('mousemove', e => {
    if (isPanning) {
      panX = e.clientX - panStart.x;
      panY = e.clientY - panStart.y;
      draw(); return;
    }
    const rect = canvas.getBoundingClientRect();
    const wx = (e.clientX - rect.left - panX) / zoom;
    const wy = (e.clientY - rect.top  - panY) / zoom;
    const h  = Hex.fromPixel(wx, wy, hexSize);
    if (!hoverHex || hoverHex.col !== h.col || hoverHex.row !== h.row) {
      hoverHex = h;
      $('hex-coords').textContent = `col:${h.col}  row:${h.row}`;
    }
  });

  canvas.addEventListener('mouseup',   () => { isPanning = false; canvas.style.cursor = 'crosshair'; });
  canvas.addEventListener('mouseleave',() => { isPanning = false; });

  canvas.addEventListener('wheel', e => {
    e.preventDefault();
    const delta = e.deltaY > 0 ? 0.9 : 1.1;
    const newZoom = Math.min(4, Math.max(0.3, zoom * delta));
    const rect = canvas.getBoundingClientRect();
    const mx = e.clientX - rect.left;
    const my = e.clientY - rect.top;
    panX = mx - (mx - panX) * (newZoom / zoom);
    panY = my - (my - panY) * (newZoom / zoom);
    zoom = newZoom;
    draw();
  }, { passive: false });

  // Touch: pinch to zoom
  let lastTouchDist = null;
  canvas.addEventListener('touchstart', e => {
    if (e.touches.length === 2) lastTouchDist = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
  });
  canvas.addEventListener('touchmove', e => {
    if (e.touches.length === 2) {
      const d = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
      const delta = d / (lastTouchDist || d);
      zoom = Math.min(4, Math.max(0.3, zoom * delta));
      lastTouchDist = d;
      draw();
    }
  });

  window.addEventListener('resize', resize);

  return {
    init(mapData, tokenData, isGM) {
      map     = mapData;
      tokens  = tokenData;
      hexSize = mapData.hex_size || 36;
      window._isGM = isGM;
      fogSet  = new Set((mapData.fog_of_war || []).map(f => `${f.col},${f.row}`));
      preloadTokenImages(tokens);
      resize();
    },
    updateToken(tok) {
      const idx = tokens.findIndex(t => t.id === tok.id);
      if (idx >= 0) tokens[idx] = { ...tokens[idx], ...tok };
      else          tokens.push(tok);
      preloadTokenImages([tok]);
      draw();
    },
    removeToken(id)   { tokens = tokens.filter(t => t.id !== id); draw(); },
    setFogMode(v)     { fogMode = v; draw(); },
    clearFog()        { fogSet.clear(); HexMap.saveFog(); draw(); },
    saveFog: async () => {
      const fog = [...fogSet].map(k => { const [col,row] = k.split(','); return {col:+col,row:+row}; });
      await api('tokens.php', 'POST', { action:'saveFog', map_id: map.id, fog });
    },
    getSelectedHex()    { return selectedHex; },
    getSelectedTokenId(){ return selectedTokenId; },
  };
})();

// ── DICE PARSER ───────────────────────────────────────────────────────────
const Dice = {
  // Parse and roll an expression like "2d6+3", "1d20-1", "4d8"
  roll(expr) {
    expr = expr.trim().toLowerCase();
    const results = [];
    let total = 0;

    // Match all dice/modifier groups
    const parts = expr.match(/[+-]?[^+-]+/g) || [];
    for (const part of parts) {
      const sign   = part.startsWith('-') ? -1 : 1;
      const clean  = part.replace(/^[+-]/, '').trim();
      const dMatch = clean.match(/^(\d+)d(\d+)$/);
      if (dMatch) {
        const n   = Math.min(parseInt(dMatch[1]), 100); // max 100 dice
        const die = parseInt(dMatch[2]);
        for (let i = 0; i < n; i++) {
          const roll = Math.ceil(Math.random() * die);
          results.push({ die, value: roll, sign });
          total += roll * sign;
        }
      } else {
        const mod = parseInt(clean);
        if (!isNaN(mod)) { total += mod * sign; results.push({ mod: mod * sign }); }
      }
    }
    return { expression: expr, results, total };
  }
};

// ── GAME CONTROLLER ───────────────────────────────────────────────────────
const Game = (() => {

  let sessionId, campaignId, currentUser, isGM = false;
  let realtimeChannel = null;

  // ── INIT ────────────────────────────────────────────────────────────────
  async function init() {
    currentUser = await Auth.requireAuth();
    if (!currentUser) return;

    const params  = new URLSearchParams(window.location.search);
    campaignId    = params.get('campaign');
    sessionId     = params.get('session');

    if (!campaignId || !sessionId) {
      alert('Sessione non valida.'); window.location.href = 'campaigns.html'; return;
    }

    // Load session + map + tokens + characters
    const [sessRes, tokRes, charRes] = await Promise.all([
      api(`sessions.php?session_id=${sessionId}`),
      api(`tokens.php?session_id=${sessionId}`),
      api(`characters.php?campaign_id=${campaignId}`)
    ]);

    if (!sessRes.success) { alert('Sessione non trovata.'); window.location.href = 'campaigns.html'; return; }

    const session = sessRes.data;
    isGM = session.campaign.gm_id === currentUser.id;
    window._isGM  = isGM;
    window._currentTool = 'select';

    // UI
    $('session-name').textContent = session.title;
    $('topbar-user').textContent  = currentUser.username;
    if (isGM) {
      $('gm-controls').classList.add('visible');
      $('btn-end-session').classList.remove('hidden');
    }

    // Init hex map
    if (session.active_map) {
      HexMap.init(session.active_map, tokRes.data || [], isGM);
    }

    // Render characters
    renderCharacters(charRes.data || []);

    // Load recent messages
    const msgRes = await api(`chat.php?session_id=${sessionId}&limit=50`);
    if (msgRes.success) {
      (msgRes.data || []).reverse().forEach(appendMessage);
      scrollChatBottom();
    }

    // Supabase Realtime
    setupRealtime();
  }

  // ── CHARACTERS SIDEBAR ──────────────────────────────────────────────────
  function renderCharacters(chars) {
    const list = $('char-list');
    list.innerHTML = '';
    if (!chars.length) {
      list.innerHTML = '<p style="padding:.75rem;font-size:.8rem;color:#474754;font-family:\'Cinzel\',serif;letter-spacing:.05em;">Nessun personaggio</p>';
      return;
    }
    chars.forEach(c => {
      const pct  = Math.max(0,Math.min(1,(c.hp_current||0)/(c.hp_max||10)));
      const hpColor = pct > 0.5 ? '#3a6b2a' : pct > 0.25 ? '#b07d12' : '#92140c';
      const div  = document.createElement('div');
      div.className = 'char-card';
      div.innerHTML = `
        <div class="char-name">${esc(c.name)}</div>
        <div class="char-meta">${esc(c.class||'—')} · Lv.${c.level||1}</div>
        <div class="char-meta" style="color:#9e9eae;">${c.hp_current}/${c.hp_max} HP</div>
        <div class="hp-bar"><div class="hp-fill" style="width:${pct*100}%;background:${hpColor};"></div></div>`;
      list.appendChild(div);
    });
  }

  // ── REALTIME ────────────────────────────────────────────────────────────
  function setupRealtime() {
    const sb = getSupabaseClient();

    realtimeChannel = sb
      .channel(`session:${sessionId}`)
      .on('postgres_changes', { event:'*', schema:'public', table:'tokens',
            filter: `map_id=eq.${window._mapId}` },
        payload => {
          if (payload.eventType === 'DELETE') HexMap.removeToken(payload.old.id);
          else HexMap.updateToken(payload.new);
        })
      .on('postgres_changes', { event:'INSERT', schema:'public', table:'chat_messages',
            filter: `session_id=eq.${sessionId}` },
        payload => { appendMessage(payload.new); scrollChatBottom(); })
      .on('postgres_changes', { event:'INSERT', schema:'public', table:'dice_rolls',
            filter: `session_id=eq.${sessionId}` },
        payload => { appendDiceRoll(payload.new); scrollChatBottom(); })
      .subscribe();
  }

  // ── CHAT ────────────────────────────────────────────────────────────────
  function appendMessage(msg) {
    const feed = $('chat-messages');
    const div  = document.createElement('div');
    const ts   = new Date(msg.created_at).toLocaleTimeString('it-IT',{hour:'2-digit',minute:'2-digit'});

    if (msg.type === 'system') {
      div.className = 'msg system';
      div.textContent = msg.content;
    } else {
      div.className = `msg ${msg.type}`;
      div.innerHTML = `
        <div class="sender">${esc(msg.sender_username || '?')} <span style="color:#474754;font-size:.6rem;">${ts}</span>
        ${msg.type === 'whisper' ? '<span style="color:#ef483c;"> · whisper</span>' : ''}
        </div>
        <div>${esc(msg.content)}</div>`;
    }
    feed.appendChild(div);
  }

  function appendDiceRoll(roll) {
    const feed = $('chat-messages');
    const div  = document.createElement('div');
    const ts   = new Date(roll.created_at).toLocaleTimeString('it-IT',{hour:'2-digit',minute:'2-digit'});
    const dice = (roll.results || []).filter(r => r.die).map(r => `<span style="color:${r.value===r.die?'#ffca8d':r.value===1?'#ef483c':'#9e9eae'};">${r.value}</span>`).join(' + ');
    div.className = 'msg dice';
    div.innerHTML = `
      <div class="sender">${esc(roll.sender_username||'?')} <span style="color:#474754;font-size:.6rem;">${ts}</span>
        ${roll.is_private ? '<span style="color:#ef483c;"> · privato</span>' : ''}
      </div>
      <div style="font-size:.8rem;color:#6f6f85;">${esc(roll.dice_expression)}${roll.reason ? ' — '+esc(roll.reason) : ''}</div>
      <div>${dice} = <span class="dice-total">${roll.total}</span></div>`;
    feed.appendChild(div);
  }

  function scrollChatBottom() {
    const feed = $('chat-messages');
    feed.scrollTop = feed.scrollHeight;
  }

  // ── PUBLIC METHODS ──────────────────────────────────────────────────────
  async function sendMessage() {
    const input   = $('chat-input');
    const content = input.value.trim();
    if (!content) return;
    const type = $('chat-type').value;

    const res = await api('chat.php', 'POST', {
      session_id: sessionId,
      content, type,
      whisper_to: type === 'whisper' ? null : undefined
    });
    if (res.success) input.value = '';
    else alert(res.message || 'Errore invio messaggio.');
  }

  async function rollDice() {
    const expr   = $('dice-expr-input').value.trim() || '1d20';
    const reason = $('dice-reason').value.trim();
    const priv   = $('dice-private').checked;

    let rolled;
    try { rolled = Dice.roll(expr); }
    catch { alert('Espressione dadi non valida. Es: 2d6+3'); return; }

    // Show locally
    $('roll-result').innerHTML = `${rolled.results.filter(r=>r.die).map(r=>`<span style="color:${r.value===r.die?'#ffca8d':r.value===1?'#ef483c':'inherit'}">${r.value}</span>`).join('+')} = <strong style="color:#ffca8d;">${rolled.total}</strong>`;

    await api('dice.php', 'POST', {
      session_id:      sessionId,
      dice_expression: rolled.expression,
      results:         rolled.results,
      total:           rolled.total,
      reason,
      is_private:      priv
    });
  }

  async function toggleFog() {
    const ind = $('fog-indicator');
    const active = ind.style.display !== 'none';
    HexMap.setFogMode(!active);
    ind.style.display = active ? 'none' : 'block';
    $('tool-fog').classList.toggle('active', !active);
  }

  function clearFog() {
    if (!confirm('Rivelare tutta la mappa? I giocatori vedranno tutto.')) return;
    HexMap.clearFog();
  }

  async function addNPCToken() {
    const label = prompt('Etichetta NPC (max 3 lettere):')?.slice(0,3).toUpperCase();
    if (!label) return;
    const hex = HexMap.getSelectedHex();
    if (!hex) { alert('Prima seleziona un esagono sulla mappa.'); return; }

    await api('tokens.php', 'POST', {
      action:            'add',
      map_id:            window._mapId,
      hex_col:           hex.col,
      hex_row:           hex.row,
      label,
      visible_to_players: false
    });
  }

  async function endSession() {
    if (!confirm('Chiudere la sessione? I giocatori verranno disconnessi.')) return;
    await api('sessions.php', 'POST', { action:'close', campaign_id: campaignId });
    window.location.href = 'campaigns.html';
  }

  return { init, sendMessage, rollDice, toggleFog, clearFog, addNPCToken, endSession };
})();

// ── HELPERS exposd to HTML ─────────────────────────────────────────────────
function setDiceExpr(expr) { $('dice-expr-input').value = expr; }

function setTool(tool) {
  window._currentTool = tool;
  ['select','move','fog'].forEach(t => $(`tool-${t}`)?.classList.remove('active'));
  $(`tool-${tool}`)?.classList.add('active');
}

function chatKeydown(e) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); Game.sendMessage(); }
}

// ── BOOT ──────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => Game.init());
