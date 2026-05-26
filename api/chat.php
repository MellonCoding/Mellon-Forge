<?php
/**
 * MELLON FORGE — api/chat.php
 *
 * GET  /chat.php?session_id=UUID&limit=50&before=ISO_TIMESTAMP
 * POST /chat.php {session_id, content, type:'ooc'|'ic'|'whisper'|'system', whisper_to?}
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ───────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $user      = require_auth();
    $uid       = $user['id'];
    $sessionId = $_GET['session_id'] ?? '';
    if (!$sessionId) err('session_id mancante.');

    $limit  = min((int)($_GET['limit']  ?? 50), 200);
    $before = $_GET['before'] ?? null;

    // Verifica partecipazione
    $sessRes = sb_request("/rest/v1/sessions?id=eq.{$sessionId}&select=campaign_id");
    if (!$sessRes['ok'] || empty($sessRes['data'])) err('Sessione non trovata.', 404);
    $campId = $sessRes['data'][0]['campaign_id'];
    require_participant($campId);
    $isGM = _is_gm($uid, $campId);

    // Costruisci query
    $q = "/rest/v1/chat_messages?session_id=eq.{$sessionId}&order=created_at.asc&limit={$limit}";
    if ($before) $q .= '&created_at=lt.' . urlencode($before);
    $q .= '&select=' . urlencode('id,content,type,whisper_to,created_at,user_id,users(username,avatar_url)');

    $res = sb_request($q);
    if (!$res['ok']) err($res['error'] ?? 'Errore recupero messaggi.');

    $messages = $res['data'] ?? [];

    // Filtra whisper: mostra solo se mittente/destinatario o GM
    $filtered = array_values(array_filter($messages, function($m) use ($uid, $isGM) {
        if ($m['type'] !== 'whisper') return true;
        return $isGM || $m['user_id'] === $uid || $m['whisper_to'] === $uid;
    }));

    // Flatten user info
    foreach ($filtered as &$m) {
        $m['sender_username']   = $m['users']['username']   ?? 'Unknown';
        $m['sender_avatar_url'] = $m['users']['avatar_url'] ?? null;
        unset($m['users']);
    }

    ok($filtered);
}

// ── POST ──────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $user      = require_auth();
    $uid       = $user['id'];
    $body      = get_body();
    $sessionId = $body['session_id'] ?? '';
    $content   = trim($body['content'] ?? '');

    if (!$sessionId) err('session_id mancante.');
    if ($content === '') err('Il messaggio non può essere vuoto.');
    if (strlen($content) > 2000) err('Messaggio troppo lungo (max 2000 caratteri).');

    // Verifica partecipazione + recupera campagna
    $sessRes = sb_request("/rest/v1/sessions?id=eq.{$sessionId}&select=campaign_id,status");
    if (!$sessRes['ok'] || empty($sessRes['data'])) err('Sessione non trovata.', 404);
    $sess   = $sessRes['data'][0];
    $campId = $sess['campaign_id'];
    if ($sess['status'] === 'ended') err('La sessione è terminata.');

    require_participant($campId);

    $type      = in_array($body['type'] ?? 'ooc', ['ic','ooc','whisper','system']) ? $body['type'] : 'ooc';
    $whisperTo = null;

    // Solo GM può inviare messaggi di sistema
    if ($type === 'system' && !_is_gm($uid, $campId)) err('Solo il GM può inviare messaggi di sistema.', 403);

    // Whisper: valida destinatario
    if ($type === 'whisper') {
        $wTo = $body['whisper_to'] ?? null;
        if (!$wTo) {
            // Whisper al GM di default
            $gmRes = sb_request("/rest/v1/campaigns?id=eq.{$campId}&select=gm_id");
            $wTo   = $gmRes['data'][0]['gm_id'] ?? null;
        }
        if (!$wTo) err('Destinatario whisper non valido.');
        $whisperTo = $wTo;
    }

    $res = sb_request('/rest/v1/chat_messages', 'POST', [
        'session_id' => $sessionId,
        'user_id'    => $uid,
        'content'    => $content,
        'type'       => $type,
        'whisper_to' => $whisperTo,
    ]);
    if (!$res['ok']) err($res['error'] ?? 'Errore invio messaggio.');

    $msg = $res['data'][0] ?? $res['data'];
    $msg['sender_username'] = $user['username'];
    ok($msg, 'Messaggio inviato.');
}

err('Metodo non supportato.', 405);

function _is_gm(string $userId, string $campaignId): bool {
    $res = sb_request("/rest/v1/campaigns?id=eq.{$campaignId}&gm_id=eq.{$userId}&select=id");
    return $res['ok'] && !empty($res['data']);
}
