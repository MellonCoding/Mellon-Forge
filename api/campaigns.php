<?php
/**
 * MELLON FORGE — api/campaigns.php
 *
 * GET  /campaigns.php                  → lista campagne dell'utente
 * GET  /campaigns.php?id=UUID          → dettaglio campagna
 * POST /campaigns.php  {action:'create', title, system, description}
 * POST /campaigns.php  {action:'update', id, ...fields}
 * POST /campaigns.php  {action:'delete', id}
 * POST /campaigns.php  {action:'invite', campaign_id, user_email}
 * POST /campaigns.php  {action:'kick',   campaign_id, user_id}
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ───────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $user = require_auth();
    $uid  = $user['id'];

    // Dettaglio singola campagna
    if (!empty($_GET['id'])) {
        $id  = $_GET['id'];
        require_participant($id);

        $res = sb_request(
            "/rest/v1/campaigns?id=eq.{$id}&select=" . urlencode(
                'id,title,description,system,status,active,gm_id,created_at,' .
                'campaign_players(user_id,role,users(id,username,avatar_url))'
            )
        );
        if (!$res['ok'] || empty($res['data'])) err('Campagna non trovata.', 404);
        ok($res['data'][0]);
    }

    // Lista campagne in cui l'utente è GM o giocatore
    // Subcampaign come GM
    $gmRes = sb_request(
        "/rest/v1/campaigns?gm_id=eq.{$uid}&select=" . urlencode(
            'id,title,description,system,status,active,gm_id,created_at,' .
            'sessions(id,status,active_map_id)'
        ) . '&status=neq.archived&order=created_at.desc'
    );

    // Campagne come giocatore
    $cpRes = sb_request(
        "/rest/v1/campaign_players?user_id=eq.{$uid}&select=" . urlencode(
            'role,campaigns(id,title,description,system,status,active,gm_id,created_at,' .
            'sessions(id,status,active_map_id))'
        )
    );

    $campaigns = [];

    if ($gmRes['ok']) {
        foreach ($gmRes['data'] as $c) {
            $c['my_role']         = 'gm';
            $c['active_session_id'] = _active_session($c['sessions'] ?? []);
            $c['player_count']    = _count_players($c['id']);
            unset($c['sessions']);
            $campaigns[$c['id']] = $c;
        }
    }

    if ($cpRes['ok']) {
        foreach ($cpRes['data'] as $row) {
            $c = $row['campaigns'] ?? null;
            if (!$c) continue;
            if (isset($campaigns[$c['id']])) continue; // già come GM
            $c['my_role']         = $row['role'];
            $c['active_session_id'] = _active_session($c['sessions'] ?? []);
            unset($c['sessions']);
            $campaigns[$c['id']] = $c;
        }
    }

    ok(array_values($campaigns));
}

// ── POST ──────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $user   = require_auth();
    $uid    = $user['id'];
    $body   = get_body();
    $action = $body['action'] ?? 'create';

    // ── CREATE ────────────────────────────────────────────────────────
    if ($action === 'create') {
        $title = trim($body['title'] ?? '');
        if (!$title) err('Il titolo è obbligatorio.');

        // Inserisci campagna
        $campRes = sb_request('/rest/v1/campaigns', 'POST', [
            'gm_id'       => $uid,
            'title'       => $title,
            'description' => trim($body['description'] ?? '') ?: null,
            'system'      => trim($body['system'] ?? 'D&D 5e'),
            'status'      => 'active',
            'active'      => false,
        ]);
        if (!$campRes['ok']) err($campRes['error'] ?? 'Errore creazione campagna.');

        $campaign = is_array($campRes['data']) && isset($campRes['data'][0])
            ? $campRes['data'][0] : $campRes['data'];

        // Aggiungi GM come player con ruolo 'gm'
        sb_request('/rest/v1/campaign_players', 'POST', [
            'campaign_id' => $campaign['id'],
            'user_id'     => $uid,
            'role'        => 'gm',
        ]);

        ok($campaign, 'Campagna creata!');
    }

    // ── UPDATE ────────────────────────────────────────────────────────
    if ($action === 'update') {
        $id = $body['id'] ?? '';
        if (!$id) err('ID campagna mancante.');
        require_gm($id);

        $allowed = ['title','description','system','status'];
        $patch   = [];
        foreach ($allowed as $k) {
            if (isset($body[$k])) $patch[$k] = $body[$k];
        }
        if (empty($patch)) err('Nessun campo da aggiornare.');

        $res = sb_request("/rest/v1/campaigns?id=eq.{$id}", 'PATCH', $patch);
        if (!$res['ok']) err($res['error'] ?? 'Errore aggiornamento.');
        ok($res['data'][0] ?? null, 'Campagna aggiornata.');
    }

    // ── DELETE ────────────────────────────────────────────────────────
    if ($action === 'delete') {
        $id = $body['id'] ?? '';
        require_gm($id);

        $res = sb_request("/rest/v1/campaigns?id=eq.{$id}", 'DELETE');
        if (!$res['ok']) err($res['error'] ?? 'Errore eliminazione.');
        ok(null, 'Campagna eliminata.');
    }

    // ── INVITE player ─────────────────────────────────────────────────
    if ($action === 'invite') {
        $campId    = $body['campaign_id'] ?? '';
        $userEmail = trim($body['user_email'] ?? '');
        if (!$campId || !$userEmail) err('Dati mancanti.');
        require_gm($campId);

        // Trova utente per email (cerca in auth.users via Admin)
        $ch = curl_init(SB_URL . '/auth/v1/admin/users?email=' . urlencode($userEmail));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['apikey: '.SB_SERVICE_KEY, 'Authorization: Bearer '.SB_SERVICE_KEY],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = json_decode(curl_exec($ch), true);
        curl_close($ch);

        $users = $resp['users'] ?? [];
        if (empty($users)) err('Nessun utente trovato con questa email.');
        $targetId = $users[0]['id'];

        // Verifica non già iscritto
        $check = sb_request("/rest/v1/campaign_players?campaign_id=eq.{$campId}&user_id=eq.{$targetId}&select=id");
        if ($check['ok'] && !empty($check['data'])) err('Utente già iscritto a questa campagna.');

        $res = sb_request('/rest/v1/campaign_players', 'POST', [
            'campaign_id' => $campId,
            'user_id'     => $targetId,
            'role'        => 'player',
        ]);
        if (!$res['ok']) err($res['error'] ?? 'Errore aggiunta giocatore.');
        ok(null, 'Giocatore aggiunto!');
    }

    // ── KICK player ───────────────────────────────────────────────────
    if ($action === 'kick') {
        $campId = $body['campaign_id'] ?? '';
        $userId = $body['user_id']     ?? '';
        if (!$campId || !$userId) err('Dati mancanti.');
        require_gm($campId);

        $res = sb_request("/rest/v1/campaign_players?campaign_id=eq.{$campId}&user_id=eq.{$userId}", 'DELETE');
        if (!$res['ok']) err($res['error'] ?? 'Errore rimozione giocatore.');
        ok(null, 'Giocatore rimosso.');
    }

    err('Azione non valida.', 400);
}

err('Metodo non supportato.', 405);

// ── Helpers locali ─────────────────────────────────────────────────────────
function _active_session(array $sessions): ?string {
    foreach ($sessions as $s) {
        if ($s['status'] === 'active') return $s['id'];
    }
    return null;
}

function _count_players(string $campId): int {
    $r = sb_request("/rest/v1/campaign_players?campaign_id=eq.{$campId}&role=eq.player&select=id");
    return ($r['ok'] && is_array($r['data'])) ? count($r['data']) : 0;
}
