<?php
require_once __DIR__ . '/config.php';

$ch = curl_init(SB_URL . '/auth/v1/admin/users');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'email'         => 'admin@admin.com',
        'password'      => 'admin',
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

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";