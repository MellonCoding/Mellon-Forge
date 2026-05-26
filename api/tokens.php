<?php
/**
 * MELLON FORGE — api/tokens.php
 *
 * GET  /tokens.php?session_id=UUID     → token della mappa attiva della sessione
 * POST /tokens.php {action:'add',    map_id, hex_col, hex_row, label, image_url?, character_id?, visible_to_players?}
 * POST /tokens.php {action:'move',   id, hex_col, hex_row}
 * POST /tokens.php {action:'delete', id}
 * POST /tokens.php {action:'toggle_visibility', id}
 * POST /tokens.php {action:'saveFog', map_id, fog:[{col,row}]}
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ───────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $user      = require_auth();
    $sessionId = $_GET['session_id'] ?? '';
    if (!$sessionId) err('session_id mancante.');

    // Recupera sessione per trovare mappa attiva e campagna
    $sessRes = sb_request("/rest/v1/sessions?id=eq.{$sessionId}&select=active_map_id,campaign_id");
    if (!$sessRes['ok'] || empty($sessRes['data'])) err('Sessione non trovata.', 404);

    $sess   = $sessRes['data'][0];
    $mapId  = $sess['active_map_id'];
    $campId = $sess['campaign_id'];

    require_participant($campId);

    if (!$mapId) ok([], 'Nessuna mappa attiva.');

    // Determina se è GM
    $isGM = _is_gm($user['id'], $campId);

    // Query token con personaggio allegato
    $filter  = "map_id=eq.{$mapId}";
    if (!$isGM) $filter .= '&visible_to_players=eq.true';

    $tokRes = sb_request(
        "/rest/v1/tokens?{$filter}&select=" . urlencode(
            'id,map_id,hex_col,hex_row,image_url,label,visible_to_players,size,conditions,updated_at,' .
            'characters(id,name,class,level,hp_current,hp_max,avatar_url)'
        )
    );
    if (!$tokRes['ok']) err($tokRes['error'] ?? 'Errore recupero token.');

    $tokens = $tokRes['data'] ?? [];
    // Rinomina characters → character (singolo)
    foreach ($tokens as &$t) {
        $t['character'] = !empty($t['characters']) ? $t['characters'][0] : null;
        unset($t['characters']);
    }
    ok($tokens);
}

// ── POST ──────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $user   = require_auth();
    $uid    = $user['id'];
    $body   = get_body();
    $action = $body['action'] ?? 'add';

    // ── ADD TOKEN (solo GM) ───────────────────────────────────────────
    if ($action === 'add') {
        $mapId = $body['map_id'] ?? '';
        if (!$mapId) err('map_id mancante.');

        $campId = _campaign_of_map($mapId);
        require_gm($campId);

        // Verifica hex libero
        $col = (int)$body['hex_col'];
        $row = (int)$body['hex_row'];
        $occupied = sb_request("/rest/v1/tokens?map_id=eq.{$mapId}&hex_col=eq.{$col}&hex_row=eq.{$row}&select=id");
        if ($occupied['ok'] && !empty($occupied['data'])) {
            err("Esagono ({$col},{$row}) già occupato.");
        }

        $res = sb_request('/rest/v1/tokens', 'POST', [
            'map_id'              => $mapId,
            'hex_col'             => $col,
            'hex_row'             => $row,
            'label'               => strtoupper(trim($body['label'] ?? '?')),
            'image_url'           => $body['image_url']    ?? null,
            'character_id'        => $body['character_id'] ?? null,
            'visible_to_players'  => (bool)($body['visible_to_players'] ?? true),
            'size'                => (int)($body['size']   ?? 1),
            'conditions'          => $body['conditions']   ?? [],
        ]);
        if (!$res['ok']) err($res['error'] ?? 'Errore aggiunta token.');
        ok($res['data'][0] ?? $res['data'], 'Token aggiunto.');
    }

    // ── MOVE TOKEN ────────────────────────────────────────────────────
    if ($action === 'move') {
        $tokenId = $body['id'] ?? '';
        if (!$tokenId) err('id token mancante.');

        // Recupera token per sapere mappa e campagna
        $tokRes = sb_request("/rest/v1/tokens?id=eq.{$tokenId}&select=map_id,character_id");
        if (!$tokRes['ok'] || empty($tokRes['data'])) err('Token non trovato.', 404);
        $tok    = $tokRes['data'][0];
        $mapId  = $tok['map_id'];
        $campId = _campaign_of_map($mapId);

        // GM può muovere tutto, giocatore solo il proprio token
        $isGM = _is_gm($uid, $campId);
        if (!$isGM) {
            // Verifica che il character appartenga all'utente
            if (!$tok['character_id']) err('Non puoi muovere questo token.', 403);
            $charRes = sb_request("/rest/v1/characters?id=eq.{$tok['character_id']}&user_id=eq.{$uid}&select=id");
            if (!$charRes['ok'] || empty($charRes['data'])) err('Non puoi muovere questo token.', 403);
        }

        $newCol = (int)$body['hex_col'];
        $newRow = (int)$body['hex_row'];

        // Verifica hex libero (escludi token stesso)
        $occRes = sb_request("/rest/v1/tokens?map_id=eq.{$mapId}&hex_col=eq.{$newCol}&hex_row=eq.{$newRow}&id=neq.{$tokenId}&select=id");
        if ($occRes['ok'] && !empty($occRes['data'])) err("Esagono ({$newCol},{$newRow}) già occupato.");

        $res = sb_request("/rest/v1/tokens?id=eq.{$tokenId}", 'PATCH', [
            'hex_col' => $newCol,
            'hex_row' => $newRow,
        ]);
        if (!$res['ok']) err($res['error'] ?? 'Errore spostamento token.');
        ok($res['data'][0] ?? null, 'Token spostato.');
    }

    // ── DELETE TOKEN (solo GM) ────────────────────────────────────────
    if ($action === 'delete') {
        $tokenId = $body['id'] ?? '';
        if (!$tokenId) err('id token mancante.');

        $tokRes = sb_request("/rest/v1/tokens?id=eq.{$tokenId}&select=map_id");
        if (!$tokRes['ok'] || empty($tokRes['data'])) err('Token non trovato.', 404);
        $campId = _campaign_of_map($tokRes['data'][0]['map_id']);
        require_gm($campId);

        $res = sb_request("/rest/v1/tokens?id=eq.{$tokenId}", 'DELETE');
        if (!$res['ok']) err($res['error'] ?? 'Errore eliminazione token.');
        ok(null, 'Token rimosso.');
    }

    // ── TOGGLE VISIBILITY (solo GM) ───────────────────────────────────
    if ($action === 'toggle_visibility') {
        $tokenId = $body['id'] ?? '';
        if (!$tokenId) err('id token mancante.');

        $tokRes = sb_request("/rest/v1/tokens?id=eq.{$tokenId}&select=map_id,visible_to_players");
        if (!$tokRes['ok'] || empty($tokRes['data'])) err('Token non trovato.', 404);
        $tok    = $tokRes['data'][0];
        $campId = _campaign_of_map($tok['map_id']);
        require_gm($campId);

        $newVis = !$tok['visible_to_players'];
        sb_request("/rest/v1/tokens?id=eq.{$tokenId}", 'PATCH', ['visible_to_players' => $newVis]);
        ok(['visible_to_players' => $newVis], $newVis ? 'Token visibile.' : 'Token nascosto.');
    }

    // ── UPDATE CONDITIONS ─────────────────────────────────────────────
    if ($action === 'set_conditions') {
        $tokenId    = $body['id']         ?? '';
        $conditions = $body['conditions'] ?? [];
        if (!$tokenId) err('id token mancante.');

        $tokRes = sb_request("/rest/v1/tokens?id=eq.{$tokenId}&select=map_id");
        if (!$tokRes['ok'] || empty($tokRes['data'])) err('Token non trovato.', 404);
        $campId = _campaign_of_map($tokRes['data'][0]['map_id']);
        require_gm($campId);

        sb_request("/rest/v1/tokens?id=eq.{$tokenId}", 'PATCH', ['conditions' => $conditions]);
        ok(null, 'Condizioni aggiornate.');
    }

    // ── SAVE FOG OF WAR (solo GM) ─────────────────────────────────────
    if ($action === 'saveFog') {
        $mapId = $body['map_id'] ?? '';
        $fog   = $body['fog']    ?? [];
        if (!$mapId) err('map_id mancante.');

        $campId = _campaign_of_map($mapId);
        require_gm($campId);

        // Valida struttura fog
        $cleanFog = array_values(array_filter($fog, fn($f) =>
            is_array($f) && isset($f['col'], $f['row']) &&
            is_int($f['col']) && is_int($f['row'])
        ));

        $res = sb_request("/rest/v1/maps?id=eq.{$mapId}", 'PATCH', ['fog_of_war' => $cleanFog]);
        if (!$res['ok']) err($res['error'] ?? 'Errore salvataggio fog of war.');
        ok(null, 'Fog of war salvato.');
    }

    err('Azione non valida.', 400);
}

err('Metodo non supportato.', 405);

// ── Helpers locali ─────────────────────────────────────────────────────────
function _campaign_of_map(string $mapId): string {
    $res = sb_request("/rest/v1/maps?id=eq.{$mapId}&select=campaign_id");
    if (!$res['ok'] || empty($res['data'])) err('Mappa non trovata.', 404);
    return $res['data'][0]['campaign_id'];
}

function _is_gm(string $userId, string $campaignId): bool {
    $res = sb_request("/rest/v1/campaigns?id=eq.{$campaignId}&gm_id=eq.{$userId}&select=id");
    return $res['ok'] && !empty($res['data']);
}
