<?php
/**
 * MELLON FORGE VTT — api/config.php
 * Autenticazione stateless via JWT — niente sessioni PHP.
 */

define('SB_URL',         rtrim(getenv('SUPABASE_URL')         ?: '', '/'));
define('SB_ANON_KEY',    getenv('SUPABASE_ANON_KEY')          ?: '');
define('SB_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY')       ?: '');
define('DESKTOP_API_KEY',getenv('DESKTOP_API_KEY')            ?: '');

if (!SB_URL || !SB_SERVICE_KEY) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Variabili d\'ambiente mancanti su Railway.']);
    exit;
}

// ── CORS ──────────────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . (getenv('FRONTEND_URL') ?: '*'));
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Desktop-Api-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Helper: body JSON ─────────────────────────────────────────────────────
function get_body(): array {
    static $body = null;
    if ($body === null) {
        $raw  = file_get_contents('php://input');
        $body = $raw ? (json_decode($raw, true) ?? []) : [];
    }
    return $body;
}

// ── Helper: risposte JSON ─────────────────────────────────────────────────
function respond(bool $success, $data = null, string $message = '', int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => $success, 'data' => $data, 'message' => $message]);
    exit;
}
function ok($data = null, string $msg = ''): void { respond(true,  $data, $msg, 200); }
function err(string $msg, int $code = 400): void  { respond(false, null,  $msg, $code); }

// ── Helper: richiesta REST a Supabase ─────────────────────────────────────
function sb_request(string $path, string $method = 'GET', array $body = [], array $headers = [], bool $service = true): array {
    $apiKey = $service ? SB_SERVICE_KEY : SB_ANON_KEY;
    $defaultHeaders = [
        'Content-Type: application/json',
        'apikey: ' . $apiKey,
        'Authorization: Bearer ' . $apiKey,
        'Prefer: return=representation',
    ];
    $ch = curl_init(SB_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => array_merge($defaultHeaders, $headers),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if (!empty($body) && in_array(strtoupper($method), ['POST','PUT','PATCH'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    if ($curlErr) return ['ok' => false, 'code' => 0, 'data' => null, 'error' => $curlErr];
    $data = json_decode($response, true);
    return [
        'ok'    => $httpCode >= 200 && $httpCode < 300,
        'code'  => $httpCode,
        'data'  => $data,
        'error' => is_array($data) && isset($data['message']) ? $data['message'] : null,
    ];
}

// ── Helper: Supabase Auth REST ────────────────────────────────────────────
function sb_auth(string $path, array $body): array {
    $ch = curl_init(SB_URL . '/auth/v1' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . SB_ANON_KEY],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['ok' => $httpCode >= 200 && $httpCode < 300, 'code' => $httpCode, 'data' => json_decode($response, true)];
}

// ── Helper: Bearer token dall'header Authorization ────────────────────────
function get_bearer_token(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $header, $m)) return $m[1];
    return null;
}

// ── Helper: verifica JWT con Supabase e ritorna utente ────────────────────
function get_user_from_token(): ?array {
    $token = get_bearer_token();
    if (!$token) return null;

    $ch = curl_init(SB_URL . '/auth/v1/user');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . SB_ANON_KEY,
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return null;
    $data = json_decode($response, true);
    if (empty($data['id'])) return null;

    $profile  = sb_request("/rest/v1/users?id=eq.{$data['id']}&select=username,avatar_url");
    $username = $profile['data'][0]['username'] ?? 'Unknown';

    return [
        'id'         => $data['id'],
        'email'      => $data['email'],
        'username'   => $username,
        'avatar_url' => $profile['data'][0]['avatar_url'] ?? null,
    ];
}

// ── Auth guards ───────────────────────────────────────────────────────────
function require_auth(): array {
    $user = get_user_from_token();
    if (!$user) err('Non autenticato.', 401);
    return $user;
}

function require_gm(string $campaign_id): array {
    $user = require_auth();
    $res  = sb_request("/rest/v1/campaigns?id=eq.{$campaign_id}&select=id,gm_id");
    if (!$res['ok'] || empty($res['data'])) err('Campagna non trovata.', 404);
    if ($res['data'][0]['gm_id'] !== $user['id']) err('Solo il GM può eseguire questa azione.', 403);
    return $user;
}

function require_participant(string $campaign_id): array {
    $user = require_auth();
    $uid  = $user['id'];
    $res  = sb_request("/rest/v1/campaign_players?campaign_id=eq.{$campaign_id}&user_id=eq.{$uid}&select=role");
    if (!$res['ok'] || empty($res['data'])) {
        $camp = sb_request("/rest/v1/campaigns?id=eq.{$campaign_id}&gm_id=eq.{$uid}&select=id");
        if (!$camp['ok'] || empty($camp['data'])) err('Non sei partecipante di questa campagna.', 403);
    }
    return $user;
}
