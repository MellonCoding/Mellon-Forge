/**
 * MELLON FORGE — js/config.js
 * Carica le variabili d'ambiente dal backend PHP a runtime.
 * Nessun valore hardcoded.
 */

const CONFIG = {
  API_BASE:          '/api',
  SUPABASE_URL:      null,
  SUPABASE_ANON_KEY: null,
};

// Carica le env vars dal backend e inizializza il client Supabase
async function initConfig() {
  const res  = await fetch('/api/env.php').then(r => r.json());
  if (!res.success) throw new Error('Impossibile caricare la configurazione dal server.');
  CONFIG.SUPABASE_URL      = res.data.supabase_url;
  CONFIG.SUPABASE_ANON_KEY = res.data.supabase_anon_key;
}

// Client Supabase per il Realtime (usato solo in game.js)
let _supabaseClient = null;
function getSupabaseClient() {
  if (!_supabaseClient) {
    _supabaseClient = supabase.createClient(
      CONFIG.SUPABASE_URL,
      CONFIG.SUPABASE_ANON_KEY,
      {
        auth: {
          autoRefreshToken:   false,
          persistSession:     false,
          detectSessionInUrl: false,
        }
      }
    );
  }
  return _supabaseClient;
}
