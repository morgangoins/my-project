<?php
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo 'Invalid request method.';
    exit;
}

$name = htmlspecialchars($_POST['name'] ?? '');
$email = htmlspecialchars($_POST['email'] ?? '');
$message = htmlspecialchars($_POST['message'] ?? '');

if ($name === '' || $email === '' || $message === '') {
    http_response_code(400);
    echo 'Please fill out all fields.';
    exit;
}

// Send via Formsubmit over HTTPS to avoid local mail server dependencies
$endpoint = 'https://formsubmit.co/ajax/goinsmorgan@gmail.com';
$postData = [
    'name' => $name,
    'email' => $email,
    'message' => $message,
];

if (!function_exists('curl_init')) {
    http_response_code(500);
    echo 'Server missing cURL extension.';
    exit;
}

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json'
]);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);

$responseBody = curl_exec($ch);
$curlErrNo = curl_errno($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErrNo !== 0) {
    error_log('Contact form cURL error: ' . $curlError);
    http_response_code(502);
    echo 'Error sending message.';
    exit;
}

if ($httpCode >= 200 && $httpCode < 300) {
    // Redirect back to contact section with success flag
    header('Location: /index.html?success=1#contact');
    exit;
}

// Log the response for debugging
error_log('Contact form HTTP ' . $httpCode . ' response: ' . $responseBody);
http_response_code(502);
echo 'Error sending message.';
?>
