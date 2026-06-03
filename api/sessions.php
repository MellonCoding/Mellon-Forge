<?php
/**
 * MELLON FORGE — api/sessions.php
 *
 * GET  /sessions.php?session_id=UUID   → dettaglio sessione (mappa + campagna)
 * POST /sessions.php {action:'open',  campaign_id, title?, map_id?}
 * POST /sessions.php {action:'close', campaign_id}
 * POST /sessions.php {action:'create_map', campaign_id, title, cols, rows, hex_size, background_url?}
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ───────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $user      = require_auth();
    $sessionId = $_GET['session_id'] ?? '';
    if (!$sessionId) err('session_id mancante.');

    // Recupera sessione con mappa e campagna
    $res = sb_request(
        "/rest/v1/sessions?id=eq.{$sessionId}&select=" . urlencode(
            'id,title,status,started_at,campaign_id,' .
            'campaigns(id,title,gm_id,system),' .
            'maps!sessions_active_map_id_fkey(id,title,cols,rows,hex_size,background_url,fog_of_war)'
        )
    );

    if (!$res['ok'] || empty($res['data'])) err('Sessione non trovata.', 404);

    $session = $res['data'][0];

    // Verifica partecipazione
    require_participant($session['campaign_id']);

    // Rinomina FK annidate per comodità nel frontend
    $session['campaign']   = $session['campaigns']  ?? null;
    $session['active_map'] = $session['maps']        ?? null;
    unset($session['campaigns'], $session['maps']);

    ok($session);
}

// ── POST ──────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body   = get_body();
    $action = $body['action'] ?? '';

    // ── OPEN SESSION (solo GM) ────────────────────────────────────────
    if ($action === 'open') {
        $campId = $body['campaign_id'] ?? '';
        if (!$campId) err('campaign_id mancante.');
        $user = require_gm($campId);

        // Scegli mappa: quella indicata oppure la prima disponibile
        $mapId = $body['map_id'] ?? null;
        if (!$mapId) {
            $mapRes = sb_request("/rest/v1/maps?campaign_id=eq.{$campId}&select=id&order=created_at.asc&limit=1");
            if ($mapRes['ok'] && !empty($mapRes['data'])) {
                $mapId = $mapRes['data'][0]['id'];
            }
        }

        // Chiudi eventuali sessioni attive precedenti
        sb_request(
            "/rest/v1/sessions?campaign_id=eq.{$campId}&status=eq.active",
            'PATCH',
            ['status' => 'ended', 'ended_at' => date('c')]
        );

        // Crea nuova sessione
        $title   = trim($body['title'] ?? '') ?: 'Sessione ' . date('d/m/Y');
        $sessRes = sb_request('/rest/v1/sessions', 'POST', [
            'campaign_id'   => $campId,
            'title'         => $title,
            'status'        => 'active',
            'active_map_id' => $mapId,
            'started_at'    => date('c'),
        ]);
        if (!$sessRes['ok']) err($sessRes['error'] ?? 'Errore creazione sessione.');

        $session = $sessRes['data'][0] ?? $sessRes['data'];

        // FIX: Attiva il flag `active` sulla campagna.
        // active_session_id è opzionale: se la colonna non esiste nello schema
        // Supabase restituisce un errore che ignoriamo, ma `active` viene comunque aggiornato.
        $patchData = ['active' => true];
        if (!empty($session['id'])) {
            $patchData['active_session_id'] = $session['id'];
        }

        $patchRes = sb_request("/rest/v1/campaigns?id=eq.{$campId}", 'PATCH', $patchData);

        // FIX: Se il PATCH fallisce per via di active_session_id (colonna mancante),
        // ritenta con solo il campo `active` per garantire che la campagna si attivi.
        if (!$patchRes['ok']) {
            $retryRes = sb_request("/rest/v1/campaigns?id=eq.{$campId}", 'PATCH', ['active' => true]);
            if (!$retryRes['ok']) {
                err('Sessione creata ma errore nell\'attivazione della campagna: ' . ($retryRes['error'] ?? 'sconosciuto'));
            }
        }

        // Messaggio di sistema in chat
        sb_request('/rest/v1/chat_messages', 'POST', [
            'session_id' => $session['id'],
            'user_id'    => $user['id'],
            'content'    => '⬡ La sessione è iniziata. Buona avventura!',
            'type'       => 'system',
        ]);

        ok($session, 'Sessione avviata!');
    }

    // ── CLOSE SESSION (solo GM) ───────────────────────────────────────
    if ($action === 'close') {
        $campId = $body['campaign_id'] ?? '';
        if (!$campId) err('campaign_id mancante.');
        $user = require_gm($campId);

        // Trova sessione attiva
        $sessRes = sb_request("/rest/v1/sessions?campaign_id=eq.{$campId}&status=eq.active&select=id&limit=1");
        if ($sessRes['ok'] && !empty($sessRes['data'])) {
            $sessId = $sessRes['data'][0]['id'];

            // Messaggio di sistema
            sb_request('/rest/v1/chat_messages', 'POST', [
                'session_id' => $sessId,
                'user_id'    => $user['id'],
                'content'    => '⬡ La sessione è terminata. Alla prossima avventura!',
                'type'       => 'system',
            ]);

            // Chiudi sessione
            sb_request("/rest/v1/sessions?id=eq.{$sessId}", 'PATCH', [
                'status'   => 'ended',
                'ended_at' => date('c'),
            ]);
        }

        // Disattiva flag campagna
        sb_request("/rest/v1/campaigns?id=eq.{$campId}", 'PATCH', ['active' => false]);

        ok(null, 'Sessione chiusa.');
    }

    // ── CREATE MAP (solo GM) ──────────────────────────────────────────
    if ($action === 'create_map') {
        $campId = $body['campaign_id'] ?? '';
        if (!$campId) err('campaign_id mancante.');
        require_gm($campId);

        $title = trim($body['title'] ?? '') ?: 'Mappa senza nome';
        $res   = sb_request('/rest/v1/maps', 'POST', [
            'campaign_id'    => $campId,
            'title'          => $title,
            'cols'           => (int)($body['cols']     ?? 20),
            'rows'           => (int)($body['rows']     ?? 15),
            'hex_size'       => (int)($body['hex_size'] ?? 36),
            'background_url' => $body['background_url'] ?? null,
            'fog_of_war'     => [],
        ]);
        if (!$res['ok']) err($res['error'] ?? 'Errore creazione mappa.');
        ok($res['data'][0] ?? $res['data'], 'Mappa creata!');
    }

    // ── SET ACTIVE MAP (solo GM) ──────────────────────────────────────
    if ($action === 'set_map') {
        $sessId = $body['session_id'] ?? '';
        $mapId  = $body['map_id']     ?? '';
        if (!$sessId || !$mapId) err('Dati mancanti.');

        // Verifica GM dalla sessione
        $sRes = sb_request("/rest/v1/sessions?id=eq.{$sessId}&select=campaign_id");
        if (!$sRes['ok'] || empty($sRes['data'])) err('Sessione non trovata.', 404);
        require_gm($sRes['data'][0]['campaign_id']);

        sb_request("/rest/v1/sessions?id=eq.{$sessId}", 'PATCH', ['active_map_id' => $mapId]);
        ok(null, 'Mappa cambiata.');
    }

    err('Azione non valida.', 400);
}

err('Metodo non supportato.', 405);