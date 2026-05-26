<?php
/**
 * MELLON FORGE — api/characters.php
 *
 * GET  /characters.php?campaign_id=UUID          → tutti i personaggi della campagna
 * GET  /characters.php?id=UUID                   → singolo personaggio
 * POST /characters.php {action:'update_hp', id, hp_current}
 * POST /characters.php {action:'update_stats', id, stats:{...}}
 * POST /characters.php {action:'update_inventory', id, inventory:[...]}
 * POST /characters.php {action:'delete', id}
 *
 * NOTA: la creazione avviene tramite l'app desktop (→ desktop.php)
 *       ma il GM può anche creare personaggi NPC direttamente:
 * POST /characters.php {action:'create', campaign_id, name, class?, race?, level?, hp_max?, stats?, note?}
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ───────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $user = require_auth();
    $uid  = $user['id'];

    // Singolo personaggio
    if (!empty($_GET['id'])) {
        $id  = $_GET['id'];
        $res = sb_request("/rest/v1/characters?id=eq.{$id}&select=" . urlencode(
            'id,campaign_id,user_id,name,class,race,level,hp_current,hp_max,avatar_url,stats,inventory,notes,created_at,users(username)'
        ));
        if (!$res['ok'] || empty($res['data'])) err('Personaggio non trovato.', 404);
        $char = $res['data'][0];
        require_participant($char['campaign_id']);
        $char['owner_username'] = $char['users']['username'] ?? null;
        unset($char['users']);
        ok($char);
    }

    // Lista campagna
    $campId = $_GET['campaign_id'] ?? '';
    if (!$campId) err('campaign_id o id mancante.');
    require_participant($campId);
    $isGM = _is_gm($uid, $campId);

    $q = "/rest/v1/characters?campaign_id=eq.{$campId}&order=name.asc&select=" . urlencode(
        'id,user_id,name,class,race,level,hp_current,hp_max,avatar_url,conditions,stats,created_at,users(username)'
    );
    // Giocatori vedono solo il proprio personaggio + quelli degli altri (senza inventory/notes privati)
    // GM vede tutto
    if (!$isGM) {
        // RLS Supabase gestisce la visibilità, qui non filtriamo ulteriormente
    }

    $res = sb_request($q);
    if (!$res['ok']) err($res['error'] ?? 'Errore recupero personaggi.');

    $chars = $res['data'] ?? [];
    foreach ($chars as &$c) {
        $c['owner_username'] = $c['users']['username'] ?? null;
        unset($c['users']);
    }
    ok($chars);
}

// ── POST ──────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $user   = require_auth();
    $uid    = $user['id'];
    $body   = get_body();
    $action = $body['action'] ?? 'create';

    // ── CREATE (GM crea NPC) ──────────────────────────────────────────
    if ($action === 'create') {
        $campId = $body['campaign_id'] ?? '';
        if (!$campId) err('campaign_id mancante.');
        $gmUser = require_gm($campId); // solo GM

        $name = trim($body['name'] ?? '');
        if (!$name) err('Il nome del personaggio è obbligatorio.');

        $hpMax = (int)($body['hp_max'] ?? 10);
        $res   = sb_request('/rest/v1/characters', 'POST', [
            'campaign_id' => $campId,
            'user_id'     => $gmUser['id'],   // NPC appartiene al GM
            'name'        => $name,
            'class'       => trim($body['class'] ?? '') ?: null,
            'race'        => trim($body['race']  ?? '') ?: null,
            'level'       => (int)($body['level'] ?? 1),
            'hp_current'  => $hpMax,
            'hp_max'      => $hpMax,
            'stats'       => $body['stats'] ?? [
                'str'=>10,'dex'=>10,'con'=>10,'int'=>10,'wis'=>10,'cha'=>10,
                'ac'=>10,'speed'=>30,'initiative'=>0,'skills'=>[],'saving_throws'=>[]
            ],
            'inventory'   => $body['inventory'] ?? [],
            'notes'       => trim($body['notes'] ?? '') ?: null,
        ]);
        if (!$res['ok']) err($res['error'] ?? 'Errore creazione personaggio.');
        ok($res['data'][0] ?? $res['data'], 'Personaggio creato!');
    }

    // ── UPDATE HP ─────────────────────────────────────────────────────
    if ($action === 'update_hp') {
        $id = $body['id'] ?? '';
        if (!$id) err('id mancante.');

        $char   = _get_char_or_fail($id);
        $campId = $char['campaign_id'];

        // Può aggiornare: il proprietario del personaggio o il GM
        if ($char['user_id'] !== $uid && !_is_gm($uid, $campId)) {
            err('Permesso negato.', 403);
        }

        $hp = (int)$body['hp_current'];
        $hp = max(0, min($hp, $char['hp_max']));

        $res = sb_request("/rest/v1/characters?id=eq.{$id}", 'PATCH', ['hp_current' => $hp]);
        if (!$res['ok']) err($res['error'] ?? 'Errore aggiornamento HP.');
        ok(['hp_current' => $hp]);
    }

    // ── UPDATE STATS ──────────────────────────────────────────────────
    if ($action === 'update_stats') {
        $id = $body['id'] ?? '';
        if (!$id || !isset($body['stats'])) err('Dati mancanti.');

        $char = _get_char_or_fail($id);
        if ($char['user_id'] !== $uid && !_is_gm($uid, $char['campaign_id'])) {
            err('Permesso negato.', 403);
        }

        // Merge con le stat esistenti
        $merged = array_merge($char['stats'] ?? [], $body['stats']);
        $res    = sb_request("/rest/v1/characters?id=eq.{$id}", 'PATCH', ['stats' => $merged]);
        if (!$res['ok']) err($res['error'] ?? 'Errore aggiornamento stat.');
        ok($merged);
    }

    // ── UPDATE INVENTORY ──────────────────────────────────────────────
    if ($action === 'update_inventory') {
        $id = $body['id'] ?? '';
        if (!$id || !isset($body['inventory'])) err('Dati mancanti.');

        $char = _get_char_or_fail($id);
        if ($char['user_id'] !== $uid && !_is_gm($uid, $char['campaign_id'])) {
            err('Permesso negato.', 403);
        }

        $res = sb_request("/rest/v1/characters?id=eq.{$id}", 'PATCH', ['inventory' => $body['inventory']]);
        if (!$res['ok']) err($res['error'] ?? 'Errore aggiornamento inventario.');
        ok($body['inventory']);
    }

    // ── DELETE (solo GM o proprietario) ───────────────────────────────
    if ($action === 'delete') {
        $id   = $body['id'] ?? '';
        if (!$id) err('id mancante.');

        $char = _get_char_or_fail($id);
        if ($char['user_id'] !== $uid && !_is_gm($uid, $char['campaign_id'])) {
            err('Permesso negato.', 403);
        }

        $res = sb_request("/rest/v1/characters?id=eq.{$id}", 'DELETE');
        if (!$res['ok']) err($res['error'] ?? 'Errore eliminazione.');
        ok(null, 'Personaggio eliminato.');
    }

    err('Azione non valida.', 400);
}

err('Metodo non supportato.', 405);

// ── Helpers locali ─────────────────────────────────────────────────────────
function _get_char_or_fail(string $id): array {
    $res = sb_request("/rest/v1/characters?id=eq.{$id}&select=id,campaign_id,user_id,hp_current,hp_max,stats");
    if (!$res['ok'] || empty($res['data'])) err('Personaggio non trovato.', 404);
    return $res['data'][0];
}

function _is_gm(string $userId, string $campaignId): bool {
    $res = sb_request("/rest/v1/campaigns?id=eq.{$campaignId}&gm_id=eq.{$userId}&select=id");
    return $res['ok'] && !empty($res['data']);
}
