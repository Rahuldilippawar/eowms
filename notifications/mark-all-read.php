<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

// स्थिती बदलणारी कृती — फक्त POST (CSRF Token csrf.php आपोआप तपासतो)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

try {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$user['id']]);
} catch (PDOException $e) {}

// फक्त याच Website वरच्या पानावर परत — बाहेरच्या Site कडे Redirect नाही
$back = $_SERVER['HTTP_REFERER'] ?? '';
if (parse_url($back, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
    $back = '../index.php';
}
header('Location: ' . $back);
exit;
