<?php
/**
 * MELLON FORGE — api/dice.php
 *
 * GET  /dice.php?session_id=UUID&limit=30   → storico tiri della sessione
 * POST /dice.php {session_id, dice_expression, results:[...], total, reason?, is_private?}
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ───────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $user      = require_auth();
    $uid       = $user['id'];
    $sessionId = $_GET['session_id'] ?? '';
    if (!$sessionId) err('session_id mancante.');

    $sessRes = sb_request("/rest/v1/sessions?id=eq.{$sessionId}&select=campaign_id");
    if (!$sessRes['ok'] || empty($sessRes['data'])) err('Sessione non trovata.', 404);
    $campId = $sessRes['data'][0]['campaign_id'];
    require_participant($campId);
    $isGM   = _is_gm($uid, $campId);

    $limit = min((int)($_GET['limit'] ?? 30), 100);
    $res   = sb_request(
        "/rest/v1/dice_rolls?session_id=eq.{$sessionId}&order=created_at.desc&limit={$limit}&select=" .
        urlencode('id,dice_expression,results,total,reason,is_private,created_at,user_id,users(username)')
    );
    if (!$res['ok']) err($res['error'] ?? 'Errore recupero tiri.');

    $rolls = $res['data'] ?? [];

    // Filtra tiri privati
    $filtered = array_values(array_filter($rolls, fn($r) =>
        !$r['is_private'] || $r['user_id'] === $uid || $isGM
    ));

    foreach ($filtered as &$r) {
        $r['sender_username'] = $r['users']['username'] ?? 'Unknown';
        unset($r['users']);
    }

    ok(array_reverse($filtered)); // ordine cronologico
}

// ── POST ──────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $user      = require_auth();
    $uid       = $user['id'];
    $body      = get_body();
    $sessionId = $body['session_id'] ?? '';

    if (!$sessionId) err('session_id mancante.');

    // Verifica sessione attiva
    $sessRes = sb_request("/rest/v1/sessions?id=eq.{$sessionId}&select=campaign_id,status");
    if (!$sessRes['ok'] || empty($sessRes['data'])) err('Sessione non trovata.', 404);
    $sess = $sessRes['data'][0];
    if ($sess['status'] === 'ended') err('La sessione è terminata.');
    require_participant($sess['campaign_id']);

    // Valida espressione
    $expr = trim($body['dice_expression'] ?? '');
    if (!$expr) err('dice_expression mancante.');
    if (!preg_match('/^[0-9d+\-\s]+$/i', $expr)) err('Espressione dadi non valida.');

    $results = $body['results'] ?? [];
    $total   = (int)($body['total'] ?? 0);
    $reason  = trim($body['reason'] ?? '') ?: null;
    $private = (bool)($body['is_private'] ?? false);

    // Validazione minima dei risultati
    if (!is_array($results)) err('results deve essere un array.');

    $res = sb_request('/rest/v1/dice_rolls', 'POST', [
        'session_id'      => $sessionId,
        'user_id'         => $uid,
        'dice_expression' => strtolower($expr),
        'results'         => $results,
        'total'           => $total,
        'reason'          => $reason,
        'is_private'      => $private,
    ]);
    if (!$res['ok']) err($res['error'] ?? 'Errore salvataggio tiro.');

    $roll = $res['data'][0] ?? $res['data'];
    $roll['sender_username'] = $user['username'];
    ok($roll, 'Tiro salvato.');
}

err('Metodo non supportato.', 405);

function _is_gm(string $userId, string $campaignId): bool {
    $res = sb_request("/rest/v1/campaigns?id=eq.{$campaignId}&gm_id=eq.{$userId}&select=id");
    return $res['ok'] && !empty($res['data']);
}
