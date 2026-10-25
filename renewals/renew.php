<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/renewal-helpers.php';
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
// दिसणाऱ्या विभागांपैकीच आहे का) — नाहीतर दुसऱ्या संस्थेचा/विभागाचा
// डेटा चुकून/जाणूनबुजून बदलला जाऊ शकला असता.
$renewalItem = renewalFetchAccessibleItem($pdo, $id);

if ($newExpiry && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newExpiry)) { $newExpiry = null; }

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
    // स्थिती 'active' च राहते — पुढच्या Cycle चे Reminders नवीन expiry_date वरून आपोआप येतील
    $stmt = $pdo->prepare("
        UPDATE renewal_items SET issued_date = CURDATE(), expiry_date = ?, renewal_cost = COALESCE(?, renewal_cost),
          vendor_name = COALESCE(?, vendor_name), reference_no = COALESCE(?, reference_no), status = 'active'
        WHERE id = ?
    ");
    $stmt->execute([$newExpiry, $renewalCost, $newVendorName, $newReferenceNo, $id]);

    // मूळ Module (वाहन / IT Asset / College) मध्येही नवीन तारीख लिहितो
    renewalWriteBackSource($pdo, $renewalItem, $newExpiry);

    // नवीन Cycle चं प्रमाणपत्र (ऐच्छिक)
    $attachMsg = '';
    if (!empty($_FILES['certificate']['tmp_name'])) {
        try {
            [$ok, $res] = renewalStoreAttachment($pdo, $renewalItem, $_FILES['certificate'], $newExpiry, $user['id'], 'नूतनीकरण प्रमाणपत्र');
            $attachMsg = $ok ? '' : ' (प्रमाणपत्र: ' . $res . ')';
        } catch (PDOException $e) { $attachMsg = ' (प्रमाणपत्र साठवता आलं नाही — Database Update आवश्यक)'; }
    }

    logActivity('नूतनीकरण केलं', 'renewal_item', $id, $renewalItem['item_name'] . ' — नवीन मुदत ' . $newExpiry);
    $_SESSION['flash'] = t('s461') . $attachMsg;
} elseif (!$renewalItem) {
    $_SESSION['form_error'] = 'ही नोंद सापडली नाही किंवा तुम्हाला ती बदलण्याची परवानगी नाही.';
}

header('Location: index.php');
exit;
