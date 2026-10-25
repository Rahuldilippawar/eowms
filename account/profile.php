<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($currentPassword, $row['password'])) {
        $error = t('s502');
    } elseif (strlen($newPassword) < 6) {
        $error = t('s503');
    } elseif ($newPassword !== $confirmPassword) {
        $error = t('s504');
    } else {
        $update = $pdo->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
        $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
        $_SESSION['user']['must_change_password'] = 0;
        $success = t('s505');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_webauthn') {
    $pdo->prepare("DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?")->execute([(int)($_POST['webauthn_id'] ?? 0), $user['id']]);
    $success = 'डिव्हाइस काढलं गेलं.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_photo') {
    if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $error = 'फक्त jpg, jpeg, png, webp फाईल्स चालतात.';
        } elseif ($_FILES['photo']['size'] > 3 * 1024 * 1024) {
            $error = 'फोटो 3 MB पेक्षा मोठा नसावा.';
        } else {
            $photoDir = __DIR__ . '/../assets/uploads/profile-photos';
            if (!is_dir($photoDir)) { mkdir($photoDir, 0755, true); }
            $safeFilename = 'user-' . $user['id'] . '-' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $photoDir . '/' . $safeFilename)) {
                // जुना फोटो असल्यास काढून टाका (जागा वाया जाऊ नये)
                $oldStmt = $pdo->prepare("SELECT photo_path FROM users WHERE id = ?");
                $oldStmt->execute([$user['id']]);
                $oldPhoto = $oldStmt->fetch()['photo_path'] ?? null;
                if ($oldPhoto && strpos($oldPhoto, 'profile-photos/') !== false) {
                    $oldFullPath = __DIR__ . '/../' . $oldPhoto;
                    if (is_file($oldFullPath)) { @unlink($oldFullPath); }
                }
                $newPhotoPath = 'assets/uploads/profile-photos/' . $safeFilename;
                $pdo->prepare("UPDATE users SET photo_path = ? WHERE id = ?")->execute([$newPhotoPath, $user['id']]);
                // Session मधली प्रतही अद्ययावत — नवीन फोटो लगेच सगळीकडे दिसावा
                $_SESSION['user']['photo_path'] = $newPhotoPath;
                $user['photo_path'] = $newPhotoPath;
                logActivity('स्वतःचा Profile फोटो बदलला');
                $success = 'फोटो यशस्वीरित्या अपडेट झाला.';
            } else {
                $error = 'फोटो Upload होऊ शकला नाही — पुन्हा प्रयत्न करा.';
            }
        }
    }
}

// "माझं प्रोफाइल" आता एकच पान — staff/profile.php (त्यातला "🔐 खाते" विभाग).
// वरचे Password/फोटो/Fingerprint-Device Form अजूनही इथेच Submit होतात — निकाल
// Flash मध्ये ठेवून प्रोफाइल-पानावर परत पाठवतो. GET → कायमचा Redirect (जुने Bookmarks).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($error) { $_SESSION['form_error'] = $error; } elseif ($success) { $_SESSION['flash'] = $success; }
    header('Location: ../staff/profile.php#account', true, 303);
    exit;
}
header('Location: ../staff/profile.php#account', true, 301);
exit;

