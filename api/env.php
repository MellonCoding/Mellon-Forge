<?php
/**
 * MELLON FORGE — api/env.php
 * Ritorna le variabili pubbliche al frontend.
 * Solo anon key e URL — MAI la service key.
 */
require_once __DIR__ . '/config.php';

ok([
    'supabase_url'      => SB_URL,
    'supabase_anon_key' => SB_ANON_KEY,
]);
