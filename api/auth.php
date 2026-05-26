<?php
/**
 * MELLON FORGE — api/auth.php
 * Autenticazione stateless via JWT nel header Authorization.
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'me') {
        $user = get_user_from_token();
        if ($user) ok($user);
        else err('Non autenticato.', 401);
    }
    err('Azione non valida.', 400);
}

if ($method === 'POST') {
    $body   = get_body();
    $action = $body['action'] ?? '';

    // ── LOGIN ──────────────────────────────────────────────────────────
    if ($action === 'login') {
        $email    = trim($body['email']    ?? '');
        $password = trim($body['password'] ?? '');
        if (!$email || !$password) err('Email e password sono obbligatori.');

        $auth = sb_auth('/token?grant_type=password', [
            'email'    => $email,
            'password' => $password,
        ]);

        if (!$auth['ok']) {
            $msg = $auth['data']['error_description']
                ?? $auth['data']['msg']
                ?? 'Credenziali non valide.';
            err($msg, 401);
        }

        $sbUserId    = $auth['data']['user']['id'];
        $accessToken = $auth['data']['access_token'];

        $profileRes = sb_request("/rest/v1/users?id=eq.{$sbUserId}&select=id,username,avatar_url");
        if (!$profileRes['ok'] || empty($profileRes['data'])) {
            err('Profilo utente non trovato.', 500);
        }

        $profile = $profileRes['data'][0];
        $user = [
            'id'         => $sbUserId,
            'email'      => $email,
            'username'   => $profile['username'],
            'avatar_url' => $profile['avatar_url'],
        ];

        ok(['user' => $user, 'token' => $accessToken]);
    }

    // ── REGISTER ───────────────────────────────────────────────────────
    if ($action === 'register') {
        $email    = trim($body['email']    ?? '');
        $password = trim($body['password'] ?? '');
        $username = trim($body['username'] ?? '');

        if (!$email || !$password || !$username) err('Tutti i campi sono obbligatori.');
        if (strlen($password) < 8) err('La password deve contenere almeno 8 caratteri.');
        if (strlen($username) < 3 || strlen($username) > 30) err('Il nome deve essere tra 3 e 30 caratteri.');

        $checkRes = sb_request("/rest/v1/users?username=eq." . urlencode($username) . "&select=id");
        if ($checkRes['ok'] && !empty($checkRes['data'])) {
            err('Nome utente già in uso.');
        }

        // Crea utente via Admin API
        $ch = curl_init(SB_URL . '/auth/v1/admin/users');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'email'         => $email,
                'password'      => $password,
                'email_confirm' => true,
            ]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'apikey: '     . SB_SERVICE_KEY,
                'Authorization: Bearer ' . SB_SERVICE_KEY,
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $authData = json_decode($response, true);
        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = $authData['msg'] ?? $authData['message'] ?? 'Registrazione fallita.';
            if (str_contains(strtolower($msg), 'already')) $msg = 'Email già registrata.';
            err($msg);
        }

        $sbUserId = $authData['id'];

        $profileRes = sb_request('/rest/v1/users', 'POST', [
            'id'       => $sbUserId,
            'username' => $username,
        ]);
        if (!$profileRes['ok']) err('Errore creazione profilo. Riprova.');

        // Login automatico
        $auth = sb_auth('/token?grant_type=password', ['email' => $email, 'password' => $password]);
        if (!$auth['ok']) err('Registrazione completata ma login fallito.', 500);

        $user = [
            'id'       => $sbUserId,
            'email'    => $email,
            'username' => $username,
        ];

        ok(['user' => $user, 'token' => $auth['data']['access_token']], 'Benvenuto in Mellon Forge!');
    }

    // ── LOGOUT ─────────────────────────────────────────────────────────
    if ($action === 'logout') {
        $token = get_bearer_token();
        if ($token) {
            $ch = curl_init(SB_URL . '/auth/v1/logout');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => '',
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'apikey: ' . SB_ANON_KEY,
                    'Authorization: Bearer ' . $token,
                ],
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_exec($ch); curl_close($ch);
        }
        ok(null, 'Logout effettuato.');
    }

    err('Azione non valida.', 400);
}

err('Metodo non supportato.', 405);
