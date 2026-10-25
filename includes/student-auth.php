<?php
// विद्यार्थी Session Bootstrap — Staff Login पासून पूर्णपणे वेगळं
// ($_SESSION['student'] वापरतो, $_SESSION['user'] नाही) — जेणेकरून
// दोन्ही Systems एकमेकांत कधीच मिसळणार नाहीत.

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'domain' => '',
        'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax',
    ]);
}
session_start();
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/classroom-helpers.php';

function currentStudent() {
    return $_SESSION['student'] ?? null;
}

function requireStudentLogin($rootPath = '') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    if (empty($_SESSION['student'])) {
        header('Location: ' . $rootPath . 'login.php');
        exit;
    }
    // दर मिनिटाला एकदा — विद्यार्थी Deactivate झाला असेल तर Session संपवतो
    if (time() - (int)($_SESSION['student_checked_at'] ?? 0) >= 60) {
        global $pdo;
        $chk = $pdo->prepare("SELECT is_active, must_change_password FROM students WHERE id = ?");
        $chk->execute([$_SESSION['student']['id']]);
        $row = $chk->fetch();
        if (!$row || !(int)$row['is_active']) {
            unset($_SESSION['student'], $_SESSION['student_checked_at']);
            header('Location: ' . $rootPath . 'login.php?inactive=1');
            exit;
        }
        $_SESSION['student']['must_change_password'] = (int)$row['must_change_password'];
        $_SESSION['student_checked_at'] = time();
    }
}

// notifyStudent() / notifyClassroomStudents() — includes/classroom-helpers.php मध्ये आहेत (वर require केलं आहे).
