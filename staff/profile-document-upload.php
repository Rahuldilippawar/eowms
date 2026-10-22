<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$targetId = (int)($_POST['user_id'] ?? 0);
$canEdit = $targetId === (int)$user['id'] || hasRole('super_admin');
if (!$canEdit) { http_response_code(403); die('परवानगी नाही.'); }

$docType = trim($_POST['document_type'] ?? '');

if ($docType && !empty($_FILES['document']['tmp_name']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'], true) && $_FILES['document']['size'] <= 5 * 1024 * 1024) {
        $uploadDir = __DIR__ . '/../assets/uploads/staff-documents';
        if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
        $safeName = 'doc-' . $targetId . '-' . uniqid() . '.' . $ext;
        if (move_uploaded_file($_FILES['document']['tmp_name'], $uploadDir . '/' . $safeName)) {
            $pdo->prepare("INSERT INTO staff_documents (user_id, document_type, file_path, original_filename) VALUES (?, ?, ?, ?)")
                ->execute([$targetId, $docType, 'assets/uploads/staff-documents/' . $safeName, $_FILES['document']['name']]);
            logActivity('Document Upload केलं', 'staff_documents', $targetId, $docType);
            $_SESSION['flash'] = 'Document जतन झालं.';
        }
    } else {
        $_SESSION['flash'] = '⚠️ फक्त JPG/PNG/PDF (5MB पर्यंत) Upload करता येईल.';
    }
}
header('Location: profile.php?id=' . $targetId);
exit;
