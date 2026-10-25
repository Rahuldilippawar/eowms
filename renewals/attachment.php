<?php
// नूतनीकरण प्रमाणपत्र — Upload / Download / काढणे (फक्त परवानगी तपासूनच; थेट URL ने File मिळत नाही)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/renewal-helpers.php';
$user = currentUser();
$orgId = activeOrgId();
requireMigratedTable($pdo, 'renewal_attachments', '../');

function renewalAttachmentBack($itemId, $back) {
    header('Location: ' . ($back === 'history' ? 'history.php?id=' : 'edit.php?id=') . (int)$itemId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $back = $_POST['back'] ?? 'edit';

    if ($action === 'upload') {
        $item = renewalFetchAccessibleItem($pdo, (int)($_POST['item_id'] ?? 0));
        if (!$item) { http_response_code(403); die('परवानगी नाही.'); }
        $cycle = $_POST['cycle_expiry_date'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cycle)) { $cycle = $item['expiry_date']; }
        [$ok, $res] = renewalStoreAttachment($pdo, $item, $_FILES['file'] ?? [], $cycle, $user['id'], trim($_POST['title'] ?? ''));
        if ($ok) {
            logActivity('नूतनीकरण प्रमाणपत्र जोडलं', 'renewal_item', $item['id'], $item['item_name'] . ' — ' . ($_FILES['file']['name'] ?? ''));
            $_SESSION['flash'] = 'प्रमाणपत्र जोडलं.';
        } else {
            $_SESSION['form_error'] = $res;
        }
        renewalAttachmentBack($item['id'], $back);
    }

    if ($action === 'delete') {
        $stmt = $pdo->prepare("SELECT * FROM renewal_attachments WHERE id = ? AND organization_id = ?");
        $stmt->execute([(int)($_POST['id'] ?? 0), $orgId]);
        $att = $stmt->fetch();
        $item = $att ? renewalFetchAccessibleItem($pdo, $att['renewal_item_id'], true) : null;
        if (!$att || !$item) { http_response_code(403); die('परवानगी नाही.'); }
        $pdo->prepare("DELETE FROM renewal_attachments WHERE id = ?")->execute([$att['id']]);
        $path = __DIR__ . '/../' . $att['file_path'];
        if (is_file($path) && str_starts_with($att['file_path'], 'assets/uploads/renewals/')) { @unlink($path); }
        logActivity('नूतनीकरण प्रमाणपत्र काढलं', 'renewal_item', $item['id'], $item['item_name'] . ' — ' . $att['original_filename']);
        $_SESSION['flash'] = 'File काढली.';
        renewalAttachmentBack($item['id'], $back);
    }

    header('Location: index.php');
    exit;
}

// Download
$stmt = $pdo->prepare("SELECT * FROM renewal_attachments WHERE id = ? AND organization_id = ?");
$stmt->execute([(int)($_GET['id'] ?? 0), $orgId]);
$att = $stmt->fetch();
if (!$att) { http_response_code(404); die('File सापडली नाही.'); }
if (!renewalFetchAccessibleItem($pdo, $att['renewal_item_id'], true)) {
    http_response_code(403);
    die('तुम्हाला ही File बघण्याची परवानगी नाही.');
}
$filePath = __DIR__ . '/../' . $att['file_path'];
if (!str_starts_with($att['file_path'], 'assets/uploads/renewals/') || !is_file($filePath)) { http_response_code(404); die('File Server वर सापडली नाही.'); }

$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$name = str_replace(['"', "\r", "\n"], '', basename($att['original_filename'] ?: $att['file_path']));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . (isset($types[$ext]) ? 'inline' : 'attachment') . '; filename="' . $name . '"');
header('Content-Length: ' . filesize($filePath));
csrfStopOutputRewrite();
readfile($filePath);
exit;
