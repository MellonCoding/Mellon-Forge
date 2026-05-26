<?php
/**
 * MELLON FORGE — api/auth.php
 * Azioni: login | register | logout | me
 */
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];

// GET ?action=me  — verifica sessione attiva
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'me') {
        $user = session_user();
        if ($user) ok($user);
        else err('Non autenticato.', 401);
    }
    err('Azione non valida.', 400);
}

// POST — login | register | logout
if ($method === 'POST') {
    $body   = get_body();
    $action = $body['action'] ?? '';

    // ── LOGIN ──────────────────────────────────────────────────────────
    if ($action === 'login') {
        $email    = trim($body['email']    ?? '');
        $password = trim($body['password'] ?? '');

        if (!$email || !$password) err('Email e password sono obbligatori.');

        // 1. Autenticazione con Supabase Auth
        $auth = sb_auth('/token?grant_type=password', [
            'email'    => $email,
            'password' => $password,
        ]);

        if (!$auth['ok']) {
            $msg = $auth['data']['error_description'] ?? 'Credenziali non valide.';
            err($msg, 401);
        }

        $sbUser   = $auth['data']['user'];
        $sbUserId = $sbUser['id'];

        // 2. Recupera profilo dalla tabella public.users
        $profileRes = sb_request("/rest/v1/users?id=eq.{$sbUserId}&select=id,username,avatar_url");
        if (!$profileRes['ok'] || empty($profileRes['data'])) {
            err('Profilo utente non trovato. Contatta il supporto.', 500);
        }

        $profile = $profileRes['data'][0];
        $user = [
            'id'         => $sbUserId,
            'email'      => $email,
            'username'   => $profile['username'],
            'avatar_url' => $profile['avatar_url'],
        ];

        // 3. Salva in sessione PHP
        $_SESSION['mf_user']        = $user;
        $_SESSION['mf_access_token'] = $auth['data']['access_token'];

        ok($user);
    }

    // ── REGISTER ───────────────────────────────────────────────────────
    if ($action === 'register') {
        $email    = trim($body['email']    ?? '');
        $password = trim($body['password'] ?? '');
        $username = trim($body['username'] ?? '');

        if (!$email || !$password || !$username) err('Tutti i campi sono obbligatori.');
        if (strlen($password) < 8) err('La password deve contenere almeno 8 caratteri.');
        if (strlen($username) < 3 || strlen($username) > 30) err('Il nome deve essere tra 3 e 30 caratteri.');

        // 1. Verifica username duplicato
        $checkRes = sb_request("/rest/v1/users?username=eq." . urlencode($username) . "&select=id");
        if ($checkRes['ok'] && !empty($checkRes['data'])) {
            err('Nome utente già in uso. Scegline un altro.');
        }

        // 2. Crea utente in Supabase Auth (via Admin API con service key)
        $ch = curl_init(SB_URL . '/auth/v1/admin/users');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'email'          => $email,
                'password'       => $password,
                'email_confirm'  => true,      // auto-conferma per ora
            ]),
            CURLOPT_HTTPHEADER     => [
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
            // Email già usata
            if (str_contains(strtolower($msg), 'already')) $msg = 'Email già registrata.';
            err($msg);
        }

        $sbUserId = $authData['id'];

        // 3. Inserisci profilo in public.users
        $profileRes = sb_request('/rest/v1/users', 'POST', [
            'id'       => $sbUserId,
            'username' => $username,
        ]);

        if (!$profileRes['ok']) {
            // Rollback: cancella utente Auth (best effort)
            $ch2 = curl_init(SB_URL . "/auth/v1/admin/users/{$sbUserId}");
            curl_setopt_array($ch2, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>'DELETE',
                CURLOPT_HTTPHEADER=>['apikey: '.SB_SERVICE_KEY, 'Authorization: Bearer '.SB_SERVICE_KEY], CURLOPT_TIMEOUT=>10]);
            curl_exec($ch2); curl_close($ch2);
            err('Errore creazione profilo. Riprova.');
        }

        // 4. Login automatico dopo registrazione
        $auth = sb_auth('/token?grant_type=password', ['email' => $email, 'password' => $password]);
        if (!$auth['ok']) err('Registrazione completata ma login fallito. Prova ad accedere manualmente.', 500);

        $user = [
            'id'       => $sbUserId,
            'email'    => $email,
            'username' => $username,
        ];
        $_SESSION['mf_user']         = $user;
        $_SESSION['mf_access_token'] = $auth['data']['access_token'];

        ok($user, 'Benvenuto in Mellon Forge!');
    }

    // ── LOGOUT ─────────────────────────────────────────────────────────
    if ($action === 'logout') {
        // Invalida token Supabase (best effort)
        $token = $_SESSION['mf_access_token'] ?? null;
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

        session_destroy();
        ok(null, 'Logout effettuato.');
    }

    err('Azione non valida.', 400);
}

err('Metodo non supportato.', 405);
