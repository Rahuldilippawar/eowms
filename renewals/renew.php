<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$newExpiry = $_POST['new_expiry_date'] ?? null;
$renewalCost = ($_POST['renewal_cost'] ?? '') !== '' ? $_POST['renewal_cost'] : null;

// ही नोंद खरंच याच संस्थेची आहे का (आणि Department-Scoped असाल तर
// स्वतःच्याच विभागाची आहे का) — नाहीतर दुसऱ्या संस्थेचा/विभागाचा
// डेटा चुकून/जाणूनबुजून बदलला जाऊ शकला असता.
$checkStmt = $pdo->prepare("SELECT * FROM renewal_items WHERE id = ? AND organization_id = ?");
$checkStmt->execute([$id, $orgId]);
$renewalItem = $checkStmt->fetch();

if ($renewalItem && isDeptScoped() && (int)$renewalItem['department_id'] !== (int)$user['department_id']) {
    $renewalItem = null;
}

if ($renewalItem && $newExpiry) {
    // Renew करण्याआधी — जुनी माहिती कायमची Renewal-इतिहासात जतन करतो
    try {
        $pdo->prepare("
            INSERT INTO renewal_history (renewal_item_id, old_expiry_date, old_issued_date, old_renewal_cost, old_vendor_name, old_reference_no, renewed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$id, $renewalItem['expiry_date'], $renewalItem['issued_date'], $renewalItem['renewal_cost'], $renewalItem['vendor_name'], $renewalItem['reference_no'], $user['id']]);
    } catch (PDOException $e) { /* renewal_history table not migrated yet — skip history, still proceed with renewal */ }

    $newVendorName = trim($_POST['vendor_name'] ?? '') ?: null;
    $newReferenceNo = trim($_POST['reference_no'] ?? '') ?: null;
    $stmt = $pdo->prepare("
        UPDATE renewal_items SET issued_date = CURDATE(), expiry_date = ?, renewal_cost = COALESCE(?, renewal_cost),
          vendor_name = COALESCE(?, vendor_name), reference_no = COALESCE(?, reference_no), status = 'renewed'
        WHERE id = ?
    ");
    $stmt->execute([$newExpiry, $renewalCost, $newVendorName, $newReferenceNo, $id]);
    logActivity('नूतनीकरण केलं', 'renewal_item', $id, $renewalItem['item_name']);
    $_SESSION['flash'] = t('s461');
} elseif (!$renewalItem) {
    $_SESSION['form_error'] = 'ही नोंद सापडली नाही किंवा तुम्हाला ती बदलण्याची परवानगी नाही.';
}

header('Location: index.php');
exit;
