<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$action = $_POST['action'] ?? '';

if ($action === 'delete_document') {
    $docId = (int)($_POST['doc_id'] ?? 0);
    $docStmt = $pdo->prepare("SELECT user_id, file_path FROM staff_documents WHERE id = ?");
    $docStmt->execute([$docId]);
    $doc = $docStmt->fetch();
    if ($doc && ($doc['user_id'] === (int)$user['id'] || hasRole('super_admin'))) {
        $pdo->prepare("DELETE FROM staff_documents WHERE id = ?")->execute([$docId]);
        $filePath = __DIR__ . '/../' . $doc['file_path'];
        if (is_file($filePath)) { unlink($filePath); }
        $_SESSION['flash'] = 'Document काढलं गेलं.';
    }
    header('Location: profile.php?id=' . ($doc['user_id'] ?? $user['id']));
    exit;
}

if ($action === 'add_achievement') {
    $targetId = (int)($_POST['user_id'] ?? 0);
    $canEdit = $targetId === (int)$user['id'] || hasRole('super_admin');
    if ($canEdit) {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $achievementDate = $_POST['achievement_date'] ?? date('Y-m-d');
        if ($title) {
            $pdo->prepare("INSERT INTO staff_achievements (user_id, title, description, achievement_date, added_by) VALUES (?, ?, ?, ?, ?)")
                ->execute([$targetId, $title, $description ?: null, $achievementDate, $user['id']]);
            logActivity('Achievement जोडलं', 'staff_achievements', $targetId, $title);
            $_SESSION['flash'] = 'Achievement जोडलं गेलं.';
        }
    }
    header('Location: profile.php?id=' . $targetId);
    exit;
}

if ($action === 'delete_achievement') {
    $achId = (int)($_POST['ach_id'] ?? 0);
    $achStmt = $pdo->prepare("SELECT user_id FROM staff_achievements WHERE id = ?");
    $achStmt->execute([$achId]);
    $ach = $achStmt->fetch();
    if ($ach && ($ach['user_id'] === (int)$user['id'] || hasRole('super_admin'))) {
        $pdo->prepare("DELETE FROM staff_achievements WHERE id = ?")->execute([$achId]);
        $_SESSION['flash'] = 'काढलं गेलं.';
    }
    header('Location: profile.php?id=' . ($ach['user_id'] ?? $user['id']));
    exit;
}

header('Location: profile.php');
exit;
