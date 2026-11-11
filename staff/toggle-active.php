<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bio-helpers.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

$id = (int)($_POST['id'] ?? 0);
$orgId = activeOrgId();

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND organization_id = ?");
$stmt->execute([$id, $orgId]);
$target = $stmt->fetch();

if (!$target) {
    header('Location: index.php');
    exit;
}

$newStatus = $target['is_active'] ? 0 : 1;

// Safety: never let the last active Super Admin be deactivated
if ($target['role'] === 'super_admin' && $newStatus === 0) {
    $countStmt = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE role = 'super_admin' AND is_active = 1 AND id != ?");
    $countStmt->execute([$id]);
    if ((int)$countStmt->fetch()['c'] < 1) {
        $_SESSION['form_error'] = 'हा शेवटचा सक्रिय Super Admin आहे — निष्क्रिय करता येणार नाही.';
        header('Location: profile.php?id=' . $id);
        exit;
    }
}

$leftDate = $newStatus === 0 ? date('Y-m-d') : null;
$pdo->prepare("UPDATE users SET is_active = ?, left_date = ? WHERE id = ?")->execute([$newStatus, $leftDate, $id]);

logActivity($newStatus ? 'कर्मचारी सक्रिय केला' : 'कर्मचारी निष्क्रिय केला', 'user', $id, $target['name']);
// 🖐️ निष्क्रिय — Machine वरून काढतो (सोडून गेलेले Punch करू शकत नाहीत); पुन्हा सक्रिय — परत पाठवतो
if (!empty($target['biometric_pin'])) { bioSyncStaff($pdo, $orgId, $id, null, $user['id'] ?? null); }
$_SESSION['flash'] = $target['name'] . ($newStatus ? ' सक्रिय केले गेले.' : ' निष्क्रिय केले गेले.');
header('Location: profile.php?id=' . $id);
exit;
