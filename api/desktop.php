<?php
/**
 * MELLON FORGE — api/desktop.php
 * Endpoint dedicato all'app desktop companion.
 * Autenticazione: header  X-Desktop-Api-Key: <DESKTOP_API_KEY>
 *
 * Tutti i metodi sono POST con un campo "action".
 *
 * POST /desktop.php {action:'ping'}
 *   → verifica connessione e API key
 *
 * POST /desktop.php {action:'list_campaigns', user_email}
 *   → campagne dell'utente (per popolare il selettore nell'app)
 *
 * POST /desktop.php {action:'create_character', campaign_id, user_email, ...dati personaggio}
 *   → inserisce un personaggio (il vero endpoint usato dall'app desktop)
 *
 * POST /desktop.php {action:'update_character', id, ...campi}
 *   → aggiorna un personaggio esistente
 *
 * POST /desktop.php {action:'list_characters', campaign_id, user_email?}
 *   → lista personaggi (con filtro opzionale per utente)
 *
 * POST /desktop.php {action:'delete_character', id}
 *   → elimina un personaggio
 */
require_once __DIR__ . '/config.php';

// ── Autenticazione API Key ─────────────────────────────────────────────────
$apiKey = $_SERVER['HTTP_X_DESKTOP_API_KEY'] ?? '';
if ($apiKey !== DESKTOP_API_KEY) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'API key non valida.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    err('Metodo non supportato. Usa POST.', 405);
}

$body   = get_body();
$action = $body['action'] ?? '';

// ── PING ──────────────────────────────────────────────────────────────────
if ($action === 'ping') {
    ok(['version' => '1.0', 'server' => 'Mellon Forge VTT'], 'Connessione OK');
}

// ── LIST CAMPAIGNS ────────────────────────────────────────────────────────
if ($action === 'list_campaigns') {
    $email = trim($body['user_email'] ?? '');
    if (!$email) err('user_email mancante.');

    $userId = _user_id_by_email($email);
    if (!$userId) err("Nessun account trovato per: {$email}", 404);

    // Campagne come GM
    $gmRes = sb_request("/rest/v1/campaigns?gm_id=eq.{$userId}&status=neq.archived&select=id,title,system,status,active&order=created_at.desc");

    // Campagne come player
    $cpRes = sb_request("/rest/v1/campaign_players?user_id=eq.{$userId}&select=role,campaigns(id,title,system,status,active)");

    $campaigns = [];
    if ($gmRes['ok']) {
        foreach ($gmRes['data'] as $c) { $c['my_role'] = 'gm'; $campaigns[$c['id']] = $c; }
    }
    if ($cpRes['ok']) {
        foreach ($cpRes['data'] as $row) {
            $c = $row['campaigns'] ?? null;
            if (!$c || isset($campaigns[$c['id']])) continue;
            $c['my_role'] = $row['role'];
            $campaigns[$c['id']] = $c;
        }
    }
    ok(array_values($campaigns));
}

// ── CREATE CHARACTER ──────────────────────────────────────────────────────
if ($action === 'create_character') {
    $campId    = $body['campaign_id'] ?? '';
    $userEmail = trim($body['user_email'] ?? '');
    $name      = trim($body['name'] ?? '');

    if (!$campId)    err('campaign_id mancante.');
    if (!$userEmail) err('user_email mancante.');
    if (!$name)      err('name mancante.');

    // Risolvi utente
    $userId = _user_id_by_email($userEmail);
    if (!$userId) err("Nessun account trovato per: {$userEmail}", 404);

    // Verifica che l'utente sia nella campagna
    $partCheck = sb_request("/rest/v1/campaign_players?campaign_id=eq.{$campId}&user_id=eq.{$userId}&select=id");
    $gmCheck   = sb_request("/rest/v1/campaigns?id=eq.{$campId}&gm_id=eq.{$userId}&select=id");
    $isParticipant = ($partCheck['ok'] && !empty($partCheck['data']))
                  || ($gmCheck['ok']  && !empty($gmCheck['data']));
    if (!$isParticipant) err('L\'utente non è un partecipante di questa campagna.', 403);

    // Verifica campagna esiste
    $campRes = sb_request("/rest/v1/campaigns?id=eq.{$campId}&select=id,title");
    if (!$campRes['ok'] || empty($campRes['data'])) err('Campagna non trovata.', 404);

    $hpMax = (int)($body['hp_max'] ?? 10);

    // Costruisci stats con valori di default + override dal body
    $defaultStats = [
        'str' => 10, 'dex' => 10, 'con' => 10,
        'int' => 10, 'wis' => 10, 'cha' => 10,
        'ac' => 10, 'speed' => 30, 'initiative' => 0,
        'skills' => [], 'saving_throws' => [],
        'proficiency_bonus' => 2,
    ];
    $stats = array_merge($defaultStats, is_array($body['stats'] ?? null) ? $body['stats'] : []);

    $res = sb_request('/rest/v1/characters', 'POST', [
        'campaign_id' => $campId,
        'user_id'     => $userId,
        'name'        => $name,
        'class'       => trim($body['class']  ?? '') ?: null,
        'race'        => trim($body['race']   ?? '') ?: null,
        'level'       => max(1, min(20, (int)($body['level'] ?? 1))),
        'hp_current'  => $hpMax,
        'hp_max'      => $hpMax,
        'avatar_url'  => $body['avatar_url']  ?? null,
        'stats'       => $stats,
        'inventory'   => is_array($body['inventory'] ?? null) ? $body['inventory'] : [],
        'notes'       => trim($body['notes'] ?? '') ?: null,
    ]);

    if (!$res['ok']) err($res['error'] ?? 'Errore creazione personaggio.');

    $char = $res['data'][0] ?? $res['data'];
    ok($char, "Personaggio '{$name}' aggiunto alla campagna!");
}

// ── UPDATE CHARACTER ──────────────────────────────────────────────────────
if ($action === 'update_character') {
    $id = $body['id'] ?? '';
    if (!$id) err('id mancante.');

    // Recupera personaggio
    $charRes = sb_request("/rest/v1/characters?id=eq.{$id}&select=id,campaign_id,user_id");
    if (!$charRes['ok'] || empty($charRes['data'])) err('Personaggio non trovato.', 404);

    $allowed = ['name','class','race','level','hp_current','hp_max','avatar_url','stats','inventory','notes'];
    $patch   = [];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $body)) $patch[$k] = $body[$k];
    }
    if (empty($patch)) err('Nessun campo da aggiornare.');

    // Validazioni
    if (isset($patch['level']))      $patch['level']      = max(1, min(20, (int)$patch['level']));
    if (isset($patch['hp_max']))     $patch['hp_max']     = max(1, (int)$patch['hp_max']);
    if (isset($patch['hp_current'])) $patch['hp_current'] = max(0, (int)$patch['hp_current']);

    $res = sb_request("/rest/v1/characters?id=eq.{$id}", 'PATCH', $patch);
    if (!$res['ok']) err($res['error'] ?? 'Errore aggiornamento.');
    ok($res['data'][0] ?? $patch, 'Personaggio aggiornato.');
}

// ── LIST CHARACTERS ───────────────────────────────────────────────────────
if ($action === 'list_characters') {
    $campId    = $body['campaign_id'] ?? '';
    $userEmail = trim($body['user_email'] ?? '');

    if (!$campId) err('campaign_id mancante.');

    $q = "/rest/v1/characters?campaign_id=eq.{$campId}&order=name.asc&select=" .
         urlencode('id,user_id,name,class,race,level,hp_current,hp_max,avatar_url,stats,created_at,users(username,email:id)');

    if ($userEmail) {
        $userId = _user_id_by_email($userEmail);
        if ($userId) $q .= "&user_id=eq.{$userId}";
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

// ── DELETE CHARACTER ──────────────────────────────────────────────────────
if ($action === 'delete_character') {
    $id = $body['id'] ?? '';
    if (!$id) err('id mancante.');

    $res = sb_request("/rest/v1/characters?id=eq.{$id}", 'DELETE');
    if (!$res['ok']) err($res['error'] ?? 'Errore eliminazione.');
    ok(null, 'Personaggio eliminato.');
}

err('Azione non valida.', 400);

// ── Helper: risolve user ID da email ──────────────────────────────────────
function _user_id_by_email(string $email): ?string {
    $ch = curl_init(SB_URL . '/auth/v1/admin/users?email=' . urlencode($email));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'apikey: '     . SB_SERVICE_KEY,
            'Authorization: Bearer ' . SB_SERVICE_KEY,
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp  = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $resp['users'][0]['id'] ?? null;
}
