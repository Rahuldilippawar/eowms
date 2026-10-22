<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/budget-helpers.php';
$user = currentUser();

header('Content-Type: application/json');

try {
    $letterId = (int)($_POST['letter_id'] ?? 0);
    $method = $_POST['method'] ?? '';
    $orgId = activeOrgId();

    $stmt = $pdo->prepare("SELECT * FROM letters WHERE id = ?");
    $stmt->execute([$letterId]);
    $letter = $stmt->fetch();
    if (!$letter) { throw new Exception('फाईल सापडली नाही.'); }

    $isHolder = (int)$letter['current_holder_id'] === (int)$user['id'] && in_array($letter['status'], ['in_progress', 'returned'], true);
    if (!$isHolder) { throw new Exception('तुम्ही या फाईलचे सध्याचे धारक नाही आहात.'); }

    // ---- ओळख पडताळणी (Identity Verification) ----
    if ($method === 'fingerprint') {
        $verifiedAt = $_SESSION['identity_verified_at'] ?? 0;
        $verifiedMethod = $_SESSION['identity_verified_method'] ?? '';
        if ($verifiedMethod !== 'fingerprint' || (time() - $verifiedAt) > 300) {
            throw new Exception('Fingerprint पडताळणी वेळेत झाली नाही — पुन्हा प्रयत्न करा.');
        }
        unset($_SESSION['identity_verified_at'], $_SESSION['identity_verified_method']); // एकदाच वापरण्यायोग्य
    } elseif ($method === 'password') {
        $password = $_POST['password'] ?? '';
        if (!$password) { throw new Exception('कृपया Password टाका.'); }
        $pwStmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $pwStmt->execute([$user['id']]);
        $storedHash = $pwStmt->fetch()['password'] ?? '';
        if (!password_verify($password, $storedHash)) {
            throw new Exception('चुकीचा Password — Sign करता आलं नाही.');
        }
    } else {
        throw new Exception('अवैध पडताळणी पद्धत.');
    }

    // ---- ओळख पडताळली गेली — आता प्रत्यक्ष फाईल पूर्ण/मंजूर करा ----
    $approvedAmount = null;
    if (!empty($letter['is_budget_expense']) && !$letter['budget_deducted']) {
        $approvedAmount = ($_POST['approved_amount'] ?? '') !== '' ? (float)$_POST['approved_amount'] : (float)$letter['requested_amount'];
        $pdo->prepare("UPDATE letters SET status='completed', current_holder_id=NULL, approved_amount=?, budget_deducted=1 WHERE id=?")
            ->execute([$approvedAmount, $letterId]);
        if ($approvedAmount > 0) {
            recordBudgetTransaction(
                $pdo, $orgId, $letter['department_id'], $approvedAmount, $letter['budget_category'] ?: 'इतर',
                'letter_approval', $letterId, $letter['subject'] . ' (' . $letter['file_number'] . ')', $user['id']
            );
        }
    } else {
        $pdo->prepare("UPDATE letters SET status='completed', current_holder_id=NULL WHERE id=?")->execute([$letterId]);
    }

    $remark = trim($_POST['remark'] ?? '');
    $pdo->prepare("INSERT INTO letter_actions (letter_id, action_type, from_user_id, remark) VALUES (?, 'completed', ?, ?)")
        ->execute([$letterId, $user['id'], $approvedAmount !== null ? 'मंजूर रक्कम: ₹' . number_format($approvedAmount, 0) . ($remark ? ' — ' . $remark : '') : ($remark ?: null)]);

    // ---- Digital Signature नोंदवा ----
    $verificationCode = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
    $pdo->prepare("
        INSERT INTO letter_signatures (letter_id, signed_by, signer_name, signer_designation, action_type, verification_method, verification_code)
        VALUES (?, ?, ?, ?, 'complete', ?, ?)
    ")->execute([$letterId, $user['id'], $user['name'], $user['designation'] ?? null, $method, $verificationCode]);

    logActivity('फाईल Digitally Sign करून पूर्ण/दाखल केली', 'letter', $letterId, $letter['file_number']);

    $watcherStmt = $pdo->prepare("SELECT user_id FROM letter_watchers WHERE letter_id = ?");
    $watcherStmt->execute([$letterId]);
    foreach ($watcherStmt->fetchAll(PDO::FETCH_COLUMN) as $wId) {
        notifyUser($wId, $orgId, 'CC केलेली फाईल पूर्ण/दाखल झाली', $letter['subject'] . ' — ' . $letter['file_number'], 'letters/view.php?id=' . $letterId);
    }

    echo json_encode([
        'success' => true,
        'message' => $approvedAmount !== null
            ? "✓ Digitally Signed — ₹" . number_format($approvedAmount, 0) . " विभागाच्या Budget मधून वजा झाले."
            : '✓ Digitally Signed — फाईल पूर्ण/दाखल झाली.',
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
