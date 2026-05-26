/**
 * MELLON FORGE — Configurazione
 * Sostituisci i valori con quelli del tuo progetto Supabase.
 * Dashboard → Settings → API
 */
const CONFIG = {
  SUPABASE_URL:      'https://YOUR_PROJECT_ID.supabase.co',
  SUPABASE_ANON_KEY: 'YOUR_ANON_KEY',
  API_BASE:          '/api',    // PHP backend base path

  // Chiave API per l'app desktop (stessa configurata in api/config.php)
  // NON esporre la service role key qui — usarla solo nel backend PHP.
};

// Supabase JS client (usato solo per Realtime)
// Importato via CDN in game.html
let _supabaseClient = null;

function getSupabaseClient() {
  if (!_supabaseClient) {
    _supabaseClient = supabase.createClient(
      CONFIG.SUPABASE_URL,
      CONFIG.SUPABASE_ANON_KEY
    );
  }
  return _supabaseClient;
}
