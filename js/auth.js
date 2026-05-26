/**
 * MELLON FORGE — Auth Module
 * Usa localStorage per persistere la sessione lato client.
 * Il token JWT viene inviato ad ogni chiamata API come header Authorization.
 */
const Auth = (() => {

  const TOKEN_KEY = 'mf_token';
  const USER_KEY  = 'mf_user';

  // ── Helper API ─────────────────────────────────────────────────────────
  const api = (endpoint, data) =>
    fetch(`${CONFIG.API_BASE}/${endpoint}`, {
      method:  'POST',
      headers: {
        'Content-Type':  'application/json',
        'Authorization': 'Bearer ' + (localStorage.getItem(TOKEN_KEY) || ''),
      },
      body: JSON.stringify(data)
    }).then(r => r.json());

  // ── LOGIN ──────────────────────────────────────────────────────────────
  async function login(email, password) {
    try {
      const res = await api('auth.php', { action: 'login', email, password });
      if (res.success) {
        localStorage.setItem(USER_KEY,  JSON.stringify(res.user));
        localStorage.setItem(TOKEN_KEY, res.token);
      }
      return res;
    } catch (e) {
      return { success: false, message: 'Errore di connessione.' };
    }
  }

  // ── REGISTER ──────────────────────────────────────────────────────────
  async function register(email, password, username) {
    try {
      const res = await api('auth.php', { action: 'register', email, password, username });
      if (res.success) {
        localStorage.setItem(USER_KEY,  JSON.stringify(res.user));
        localStorage.setItem(TOKEN_KEY, res.token);
      }
      return res;
    } catch (e) {
      return { success: false, message: 'Errore di connessione.' };
    }
  }

  // ── LOGOUT ────────────────────────────────────────────────────────────
  async function logout() {
    await api('auth.php', { action: 'logout' }).catch(() => {});
    localStorage.removeItem(USER_KEY);
    localStorage.removeItem(TOKEN_KEY);
    window.location.href = 'login.html';
  }

  // ── GET USER ──────────────────────────────────────────────────────────
  async function getUser() {
    const cached = localStorage.getItem(USER_KEY);
    const token  = localStorage.getItem(TOKEN_KEY);
    if (cached && token) {
      try { return JSON.parse(cached); }
      catch { localStorage.removeItem(USER_KEY); }
    }
    return null;
  }

  // ── REQUIRE AUTH ──────────────────────────────────────────────────────
  async function requireAuth() {
    const user = await getUser();
    if (!user) {
      window.location.href = 'login.html';
      return null;
    }
    return user;
  }

  // ── IS GM ─────────────────────────────────────────────────────────────
  function isGM(campaign) {
    const user = JSON.parse(localStorage.getItem(USER_KEY) || 'null');
    return user && campaign && campaign.gm_id === user.id;
  }

  // ── GET TOKEN (usato da campaigns.js e game.js per le chiamate API) ───
  function getToken() {
    return localStorage.getItem(TOKEN_KEY) || '';
  }

  return { login, register, logout, getUser, requireAuth, isGM, getToken };
})();
