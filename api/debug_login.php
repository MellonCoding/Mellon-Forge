<?php
require_once __DIR__ . '/config.php';

$email    = 'admin@admin.com';
$password = 'admin';

$ch = curl_init(SB_URL . '/auth/v1/token?grant_type=password');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['email' => $email, 'password' => $password]),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'apikey: ' . SB_ANON_KEY,
    ],
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";