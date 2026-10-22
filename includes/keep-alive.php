<?php
require_once __DIR__ . '/../includes/auth.php';
if (currentUser()) {
    $_SESSION['last_activity'] = time();
    echo 'ok';
} else {
    http_response_code(401);
    echo 'expired';
}
