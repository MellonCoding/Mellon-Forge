/**
 * MELLON FORGE — Auth Module
 */
const Auth = (() => {

  const TOKEN_KEY = 'mf_token';
  const USER_KEY  = 'mf_user';

  // ── Helper API ─────────────────────────────────────────────────────────
  const api = async (endpoint, data) => {
    // Assicura che la config sia caricata prima di fare chiamate
    if (!CONFIG.SUPABASE_URL) await initConfig();
    return fetch(`${CONFIG.API_BASE}/${endpoint}`, {
      method:  'POST',
      headers: {
        'Content-Type':  'application/json',
        'Authorization': 'Bearer ' + (localStorage.getItem(TOKEN_KEY) || ''),
      },
      body: JSON.stringify(data)
    }).then(r => r.json());
  }

  // ── LOGIN ──────────────────────────────────────────────────────────────
  async function login(email, password) {
    try {
      const res = await api('auth.php', { action: 'login', email, password });
      if (res.success && res.data) {
        // res.data.user e res.data.token — non res.user/res.token
        localStorage.setItem(USER_KEY,  JSON.stringify(res.data.user));
        localStorage.setItem(TOKEN_KEY, res.data.token);
        return { success: true };
      }
      return { success: false, message: res.message || 'Credenziali non valide.' };
    } catch (e) {
      return { success: false, message: 'Errore di connessione.' };
    }
  }

  // ── REGISTER ──────────────────────────────────────────────────────────
  async function register(email, password, username) {
    try {
      const res = await api('auth.php', { action: 'register', email, password, username });
      if (res.success && res.data) {
        localStorage.setItem(USER_KEY,  JSON.stringify(res.data.user));
        localStorage.setItem(TOKEN_KEY, res.data.token);
        return { success: true };
      }
      return { success: false, message: res.message || 'Registrazione fallita.' };
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
    if (!cached || !token || cached === 'undefined' || token === 'undefined') {
      localStorage.removeItem(USER_KEY);
      localStorage.removeItem(TOKEN_KEY);
      return null;
    }
    try { return JSON.parse(cached); }
    catch { localStorage.removeItem(USER_KEY); return null; }
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

  // ── GET TOKEN ─────────────────────────────────────────────────────────
  function getToken() {
    return localStorage.getItem(TOKEN_KEY) || '';
  }

  return { login, register, logout, getUser, requireAuth, isGM, getToken };
})();
