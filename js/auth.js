/**
 * MELLON FORGE — Auth Module
 * Tutte le chiamate passano per il backend PHP, che gestisce il JWT Supabase.
 */
const Auth = (() => {

  const api = (endpoint, data) =>
    fetch(`${CONFIG.API_BASE}/${endpoint}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',          // invia i cookie di sessione PHP
      body: JSON.stringify(data)
    }).then(r => r.json());

  // ── LOGIN ──────────────────────────────────────────────
  async function login(email, password) {
    try {
      const res = await api('auth.php', { action: 'login', email, password });
      if (res.success) {
        localStorage.setItem('mf_user', JSON.stringify(res.user));
      }
      return res;
    } catch (e) {
      return { success: false, message: 'Errore di connessione.' };
    }
  }

  // ── REGISTER ──────────────────────────────────────────
  async function register(email, password, username) {
    try {
      const res = await api('auth.php', { action: 'register', email, password, username });
      if (res.success) {
        localStorage.setItem('mf_user', JSON.stringify(res.user));
      }
      return res;
    } catch (e) {
      return { success: false, message: 'Errore di connessione.' };
    }
  }

  // ── LOGOUT ────────────────────────────────────────────
  async function logout() {
    await api('auth.php', { action: 'logout' });
    localStorage.removeItem('mf_user');
    window.location.href = 'login.html';
  }

  // ── GET USER ──────────────────────────────────────────
  // Ritorna l'utente dalla cache locale oppure verifica con PHP
  async function getUser() {
    const cached = localStorage.getItem('mf_user');
    if (cached) {
      try { return JSON.parse(cached); }
      catch { localStorage.removeItem('mf_user'); }
    }
    // Verifica sessione PHP lato server
    try {
      const res = await fetch(`${CONFIG.API_BASE}/auth.php?action=me`, {
        credentials: 'include'
      }).then(r => r.json());
      if (res.success && res.user) {
        localStorage.setItem('mf_user', JSON.stringify(res.user));
        return res.user;
      }
    } catch { /* not authenticated */ }
    return null;
  }

  // ── REQUIRE AUTH ──────────────────────────────────────
  // Chiama all'inizio di ogni pagina protetta
  async function requireAuth() {
    const user = await getUser();
    if (!user) {
      window.location.href = 'login.html';
      return null;
    }
    return user;
  }

  // ── IS GM ─────────────────────────────────────────────
  function isGM(campaign) {
    const user = JSON.parse(localStorage.getItem('mf_user') || 'null');
    return user && campaign && campaign.gm_id === user.id;
  }

  return { login, register, logout, getUser, requireAuth, isGM };
})();
