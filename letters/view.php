<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/budget-helpers.php';
$user = currentUser();

$letterId = (int)($_GET['id'] ?? 0);
$orgId = activeOrgId();

$stmt = $pdo->prepare("
    SELECT l.*, lc.name_local AS category_name, d.name_local AS dept_name,
      creator.name AS creator_name, holder.name AS holder_name, holder.mobile AS holder_mobile,
      intended.name AS intended_recipient_name, pending.name AS pending_forward_to_name
    FROM letters l
    LEFT JOIN letter_categories lc ON lc.id = l.category_id
    LEFT JOIN departments d ON d.id = l.department_id
    LEFT JOIN users creator ON creator.id = l.created_by
    LEFT JOIN users holder ON holder.id = l.current_holder_id
    LEFT JOIN users intended ON intended.id = l.intended_recipient_id
    LEFT JOIN users pending ON pending.id = l.pending_forward_to
    WHERE l.id = ?
");
$stmt->execute([$letterId]);
$letter = $stmt->fetch();
if (!$letter) { die('फाईल सापडली नाही.'); }

$stmt = $pdo->prepare("
    SELECT lin.*, d.name_local AS dept_name FROM letter_inward_numbers lin
    JOIN departments d ON d.id = lin.department_id
    WHERE lin.letter_id = ? ORDER BY lin.created_at ASC
");
$stmt->execute([$letterId]);
$inwardNumbers = $stmt->fetchAll();

$sigStmt = $pdo->prepare("SELECT * FROM letter_signatures WHERE letter_id = ? ORDER BY signed_at ASC");
$sigStmt->execute([$letterId]);
$letterSignatures = $sigStmt->fetchAll();

// Access: creator, current holder, anyone who ever appears in the movement
// history, or management — can view. Everyone else is blocked.
$stmt = $pdo->prepare("SELECT COUNT(*) c FROM letter_actions WHERE letter_id = ? AND (from_user_id = ? OR to_user_id = ?)");
$stmt->execute([$letterId, $user['id'], $user['id']]);
$everInvolved = (int)$stmt->fetch()['c'] > 0;

$stmt = $pdo->prepare("SELECT COUNT(*) c FROM letter_watchers WHERE letter_id = ? AND user_id = ?");
$stmt->execute([$letterId, $user['id']]);
$isWatcher = (int)$stmt->fetch()['c'] > 0;

// Management/Super Admin's blanket visibility only applies within their OWN
// organization — it must NOT let them peek into a sister organization's
// internal correspondence just because they hold a senior title elsewhere.
$isOwnOrgLetter = (int)$letter['organization_id'] === (int)$orgId;
$canView = (int)$letter['created_by'] === (int)$user['id'] || (int)$letter['current_holder_id'] === (int)$user['id'] || $everInvolved || $isWatcher || ($isOwnOrgLetter && hasPermission('manage_letters_org_wide'));
if (!$canView) {
    http_response_code(403);
    die('तुम्हाला ही फाईल पाहण्याची परवानगी नाही.');
}

$isCreator = (int)$letter['created_by'] === (int)$user['id'];
$isHolder = (int)$letter['current_holder_id'] === (int)$user['id'] && in_array($letter['status'], ['in_progress', 'returned'], true);

if ($isHolder && empty($letter['current_holder_seen_at'])) {
    $pdo->prepare("UPDATE letters SET current_holder_seen_at = NOW() WHERE id = ?")->execute([$letterId]);
    $letter['current_holder_seen_at'] = date('Y-m-d H:i:s');
}

// ---------------- POST actions ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit' && $isCreator && $letter['status'] === 'draft') {
        $advanceError = null;
        $completed = false;
        $clerkStage = 'none';
        $intendedRecipientId = null;
        if ($letter['workflow_template_id']) {
            $letter['current_step_order'] = 0;
            $result = advanceLetterToNextStep($pdo, $letter, $orgId);
            $nextHolder = $result['holder'];
            $completed = $result['completed'];
            $advanceError = $result['error'];
            $clerkStage = $result['clerk_stage'];
            $intendedRecipientId = $result['intended_recipient_id'];
        } else {
            $manualTo = (int)($_POST['to_user_id'] ?? 0);
            if ($manualTo) {
                $resolved = resolveHolderForRecipient($pdo, $manualTo);
                $pdo->prepare("UPDATE letters SET status='in_progress', current_holder_id=?, intended_recipient_id=?, clerk_stage=?, current_holder_seen_at=NULL WHERE id=?")
                    ->execute([$resolved['holder_id'], $resolved['intended_recipient_id'], $resolved['clerk_stage'], $letterId]);
                $nextHolder = $resolved['holder_id'];
                $clerkStage = $resolved['clerk_stage'];
                $intendedRecipientId = $resolved['intended_recipient_id'];
            } else {
                $nextHolder = null;
            }
        }
        if ($advanceError) {
            $_SESSION['form_error'] = $advanceError;
        } else {
            $pdo->prepare("INSERT INTO letter_actions (letter_id, action_type, from_user_id, to_user_id, remark) VALUES (?, 'submitted', ?, ?, ?)")
                ->execute([$letterId, $user['id'], $nextHolder, trim($_POST['remark'] ?? '') ?: null]);
            if ($nextHolder && $clerkStage === 'awaiting_inward') {
                $recStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                $recStmt->execute([$intendedRecipientId]);
                $recName = $recStmt->fetch()['name'] ?? '';
                notifyUser($nextHolder, $orgId, 'नोंदणीसाठी नवीन पत्र', "$recName यांच्यासाठी — {$letter['subject']} ({$letter['file_number']})", 'letters/view.php?id=' . $letterId);
            } elseif ($nextHolder) {
                assignInwardForHolder($pdo, $letterId, $nextHolder, $letter['department_id'], $orgId);
                notifyUser($nextHolder, $orgId, 'नवीन फाईल तुमच्याकडे आली', $letter['subject'] . ' — ' . $letter['file_number'], 'letters/view.php?id=' . $letterId);
            }
            logActivity('फाईल सबमिट केली', 'letter', $letterId, $letter['file_number']);
            $_SESSION['flash'] = $completed ? 'फाईल सबमिट होऊन लगेच पूर्ण/दाखल झाली.' : 'फाईल पाठवली गेली.';
        }
    }

    elseif ($action === 'forward' && $isHolder) {
        $remark = trim($_POST['remark'] ?? '');
        $advanceError = null;
        $completed = false;
        $clerkStage = 'none';
        $intendedRecipientId = null;
        if ($letter['workflow_template_id']) {
            $result = advanceLetterToNextStep($pdo, $letter, $orgId);
            $nextHolder = $result['holder'];
            $completed = $result['completed'];
            $advanceError = $result['error'];
            $clerkStage = $result['clerk_stage'];
            $intendedRecipientId = $result['intended_recipient_id'];
        } else {
            $manualTo = (int)($_POST['to_user_id'] ?? 0);
            if ($manualTo) {
                $resolved = resolveHolderForRecipient($pdo, $manualTo);
                $nextHolder = $resolved['holder_id'];
                $clerkStage = $resolved['clerk_stage'];
                $intendedRecipientId = $resolved['intended_recipient_id'];
            } else {
                $nextHolder = null;
            }
        }

        // Does the person forwarding (current holder) have their own Clerk?
        // If so — and this isn't the workflow's final "completed" step, and
        // the next destination isn't itself already staged through its own
        // Clerk (avoids double-staging) — their decision is staged for the
        // Clerk to actually route onward, instead of moving the file immediately.
        $myClerkStmt = $pdo->prepare("SELECT gatekeeper_clerk_id FROM users WHERE id = ?");
        $myClerkStmt->execute([$user['id']]);
        $myClerkId = $myClerkStmt->fetch()['gatekeeper_clerk_id'] ?? null;

        if ($advanceError) {
            $_SESSION['form_error'] = $advanceError;
        } elseif ($nextHolder && !$completed && $myClerkId && $clerkStage === 'none' && !$letter['workflow_template_id']) {
            $pdo->prepare("UPDATE letters SET current_holder_id=?, pending_forward_to=?, clerk_stage='awaiting_forward_approval', current_holder_seen_at=NULL WHERE id=?")
                ->execute([$myClerkId, $nextHolder, $letterId]);
            $pdo->prepare("INSERT INTO letter_actions (letter_id, action_type, from_user_id, to_user_id, remark) VALUES (?, 'forwarded', ?, ?, ?)")
                ->execute([$letterId, $user['id'], $nextHolder, $remark ?: null]);
            notifyUser($myClerkId, $orgId, 'निर्णय झाला — पुढे पाठवण्यासाठी प्रतीक्षेत', $letter['subject'] . ' — ' . $letter['file_number'], 'letters/view.php?id=' . $letterId);
            logActivity('फाईलचा निर्णय नोंदवला — Clerk कडे रवाना', 'letter', $letterId, $letter['file_number']);
            $_SESSION['flash'] = 'तुमचा निर्णय नोंदवला गेला — तुमचे Clerk आता ते पुढे पाठवतील.';
        } else {
            if ($nextHolder && !$completed && $clerkStage !== 'none') {
                // resolveHolderForRecipient()/advanceLetterToNextStep() ने आधीच
                // clerk_stage/intended_recipient_id DB मध्ये बरोबर सेट केलेलं आहे
                // (workflow साठी थेट, manual साठी इथे करावं लागतं).
                if (!$letter['workflow_template_id']) {
                    $pdo->prepare("UPDATE letters SET current_holder_id=?, intended_recipient_id=?, clerk_stage=?, status='in_progress', current_holder_seen_at=NULL WHERE id=?")
                        ->execute([$nextHolder, $intendedRecipientId, $clerkStage, $letterId]);
                }
                $recStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                $recStmt->execute([$intendedRecipientId]);
                $recName = $recStmt->fetch()['name'] ?? '';
                notifyUser($nextHolder, $orgId, 'नोंदणीसाठी नवीन पत्र', "$recName यांच्यासाठी — {$letter['subject']} ({$letter['file_number']})", 'letters/view.php?id=' . $letterId);
            } elseif ($nextHolder && !$completed) {
                if (!$letter['workflow_template_id']) {
                    $pdo->prepare("UPDATE letters SET current_holder_id=?, status='in_progress', current_holder_seen_at=NULL WHERE id=?")->execute([$nextHolder, $letterId]);
                }
                assignInwardForHolder($pdo, $letterId, $nextHolder, $letter['department_id'], $orgId);
                notifyUser($nextHolder, $orgId, 'फाईल तुमच्याकडे पुढे आली', $letter['subject'] . ' — ' . $letter['file_number'], 'letters/view.php?id=' . $letterId);
            }
            $pdo->prepare("INSERT INTO letter_actions (letter_id, action_type, from_user_id, to_user_id, remark) VALUES (?, ?, ?, ?, ?)")
                ->execute([$letterId, $completed ? 'completed' : 'forwarded', $user['id'], $nextHolder, $remark ?: null]);
            logActivity($completed ? 'फाईल पूर्ण/दाखल झाली' : 'फाईल पुढे पाठवली', 'letter', $letterId, $letter['file_number']);
            $watcherStmt = $pdo->prepare("SELECT user_id FROM letter_watchers WHERE letter_id = ?");
            $watcherStmt->execute([$letterId]);
            foreach ($watcherStmt->fetchAll(PDO::FETCH_COLUMN) as $wId) {
                notifyUser($wId, $orgId, $completed ? 'CC केलेली फाईल पूर्ण/दाखल झाली' : 'CC केलेली फाईल पुढे गेली', $letter['subject'] . ' — ' . $letter['file_number'], 'letters/view.php?id=' . $letterId);
            }
            $_SESSION['flash'] = $completed ? 'फाईल शेवटच्या पायरीपर्यंत पोहोचून पूर्ण/दाखल झाली.' : 'फाईल पुढे पाठवली गेली.';
        }
    }

    elseif ($action === 'clerk_register_inward' && $isHolder && $letter['clerk_stage'] === 'awaiting_inward') {
        // Clerk officially registers the file and it moves to the actual
        // Executive's desk — no remark from the Clerk, just registration.
        assignInwardForHolder($pdo, $letterId, $letter['intended_recipient_id'], $letter['department_id'], $orgId);
        $pdo->prepare("UPDATE letters SET current_holder_id=?, clerk_stage='none', current_holder_seen_at=NULL WHERE id=?")
            ->execute([$letter['intended_recipient_id'], $letterId]);
        $pdo->prepare("INSERT INTO letter_actions (letter_id, action_type, from_user_id, to_user_id, remark) VALUES (?, 'forwarded', ?, ?, ?)")
            ->execute([$letterId, $user['id'], $letter['intended_recipient_id'], 'Clerk ने आवक नोंदणी केली']);
        notifyUser($letter['intended_recipient_id'], $orgId, 'नवीन फाईल तुमच्याकडे आली', $letter['subject'] . ' — ' . $letter['file_number'], 'letters/view.php?id=' . $letterId);
        logActivity('Clerk ने आवक नोंदणी केली', 'letter', $letterId, $letter['file_number']);
        $_SESSION['flash'] = 'आवक नोंदणी झाली — फाईल आता संबंधित अधिकाऱ्याच्या टेबलावर गेली.';
    }

    elseif ($action === 'clerk_approve_forward' && $isHolder && $letter['clerk_stage'] === 'awaiting_forward_approval') {
        $target = $letter['pending_forward_to'];
        // In case the next destination is itself another gatekept executive
        $resolved = resolveHolderForRecipient($pdo, $target);
        $pdo->prepare("UPDATE letters SET current_holder_id=?, intended_recipient_id=?, pending_forward_to=NULL, clerk_stage=?, current_holder_seen_at=NULL WHERE id=?")
            ->execute([$resolved['holder_id'], $resolved['intended_recipient_id'], $resolved['clerk_stage'], $letterId]);
        if ($resolved['clerk_stage'] === 'awaiting_inward') {
            $recStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
            $recStmt->execute([$target]);
            $recName = $recStmt->fetch()['name'] ?? '';
            notifyUser($resolved['holder_id'], $orgId, 'नोंदणीसाठी नवीन पत्र', "$recName यांच्यासाठी — {$letter['subject']} ({$letter['file_number']})", 'letters/view.php?id=' . $letterId);
        } else {
            assignInwardForHolder($pdo, $letterId, $resolved['holder_id'], $letter['department_id'], $orgId);
            notifyUser($resolved['holder_id'], $orgId, 'फाईल तुमच्याकडे पुढे आली', $letter['subject'] . ' — ' . $letter['file_number'], 'letters/view.php?id=' . $letterId);
        }
        $pdo->prepare("INSERT INTO letter_actions (letter_id, action_type, from_user_id, to_user_id, remark) VALUES (?, 'forwarded', ?, ?, ?)")
            ->execute([$letterId, $user['id'], $resolved['holder_id'], 'Clerk ने पुढे पाठवण्यास मान्यता दिली']);
        logActivity('Clerk ने फाईल पुढे पाठवली', 'letter', $letterId, $letter['file_number']);
        $_SESSION['flash'] = 'फाईल पुढे पाठवली गेली.';
    }

    elseif ($action === 'clerk_hold' && $isHolder && $letter['clerk_stage'] === 'awaiting_forward_approval') {
        logActivity('Clerk ने फाईल तात्पुरती थांबवली', 'letter', $letterId, $letter['file_number']);
        $_SESSION['flash'] = 'फाईल सध्या थांबवली गेली आहे — पुढे पाठवलेली नाही.';
    }

    elseif ($action === 'remark' && ($isHolder || $isCreator)) {
        $remark = trim($_POST['remark'] ?? '');
        if ($remark) {
            $pdo->prepare("INSERT INTO letter_actions (letter_id, action_type, from_user_id, remark) VALUES (?, 'remark_added', ?, ?)")
                ->execute([$letterId, $user['id'], $remark]);
            $_SESSION['flash'] = 'टिप्पणी जोडली गेली.';
        }
    }

    elseif ($action === 'return' && $isHolder) {
        // Return to whoever sent it to the current holder
        $stmt = $pdo->prepare("SELECT from_user_id FROM letter_actions WHERE letter_id = ? AND to_user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$letterId, $user['id']]);
        $prevSender = $stmt->fetch();
        $returnTo = $prevSender['from_user_id'] ?? $letter['created_by'];
        $pdo->prepare("UPDATE letters SET current_holder_id=?, status='returned', current_holder_seen_at=NULL WHERE id=?")->execute([$returnTo, $letterId]);
        $pdo->prepare("INSERT INTO letter_actions (letter_id, action_type, from_user_id, to_user_id, remark) VALUES (?, 'returned', ?, ?, ?)")
            ->execute([$letterId, $user['id'], $returnTo, trim($_POST['remark'] ?? '') ?: null]);
        notifyUser($returnTo, $orgId, 'फाईल परत पाठवली गेली', $letter['subject'] . ' — ' . $letter['file_number'], 'letters/view.php?id=' . $letterId);
        logActivity('फाईल परत पाठवली', 'letter', $letterId, $letter['file_number']);
        $_SESSION['flash'] = 'फाईल परत पाठवली गेली.';
    }

    // टीप: 'complete' कृती इथून पूर्णपणे काढली आहे — फाईल पूर्ण/दाखल
    // करण्यासाठी आता फक्त sign-approve.php (Fingerprint/Password सह
    // Digital Signature) हाच एकमेव, सुरक्षित मार्ग आहे. आधी इथे तेच काम
    // कुठल्याही पडताळणीशिवाय करणारा जुना Handler होता — जो Sign & Approve
    // पूर्णपणे Bypass करू शकत होता (कोणीही थेट action=complete POST करून).

    elseif ($action === 'reopen' && $isCreator && $letter['status'] === 'returned') {
        $pdo->prepare("UPDATE letters SET status='in_progress' WHERE id=?")->execute([$letterId]);
        logActivity('फाईल परत सुरू केली', 'letter', $letterId, $letter['file_number']);
        $_SESSION['flash'] = 'फाईल परत सुरू केली गेली.';
    }

    elseif ($action === 'reassign' && hasRole('super_admin')) {
        $newHolder = (int)($_POST['new_holder_id'] ?? 0);
        if ($newHolder) {
            $pdo->prepare("UPDATE letters SET current_holder_id=?, status='in_progress', current_holder_seen_at=NULL WHERE id=?")->execute([$newHolder, $letterId]);
            $pdo->prepare("INSERT INTO letter_actions (letter_id, action_type, from_user_id, to_user_id, remark) VALUES (?, 'forwarded', ?, ?, ?)")
                ->execute([$letterId, $user['id'], $newHolder, '⚙️ Super Admin ने पुन्हा नेमले (Reassigned)' . (trim($_POST['remark'] ?? '') ? ' — ' . trim($_POST['remark']) : '')]);
            assignInwardForHolder($pdo, $letterId, $newHolder, $letter['department_id'], $orgId);
            notifyUser($newHolder, $orgId, 'फाईल तुमच्याकडे नेमली गेली', $letter['subject'] . ' — ' . $letter['file_number'], 'letters/view.php?id=' . $letterId);
            logActivity('Super Admin ने फाईल reassign केली', 'letter', $letterId, $letter['file_number']);
            $_SESSION['flash'] = 'फाईल पुन्हा नेमली गेली.';
        }
    }

    elseif ($action === 'add_watcher' && ($isCreator || $isHolder || hasRole('super_admin'))) {
        $watcherId = (int)($_POST['watcher_id'] ?? 0);
        if ($watcherId) {
            try {
                $pdo->prepare("INSERT INTO letter_watchers (letter_id, user_id, added_by) VALUES (?, ?, ?)")->execute([$letterId, $watcherId, $user['id']]);
                notifyUser($watcherId, $orgId, 'फाईलमध्ये CC केले गेले', $letter['subject'] . ' — ' . $letter['file_number'], 'letters/view.php?id=' . $letterId);
                $_SESSION['flash'] = 'CC मध्ये जोडले गेले.';
            } catch (PDOException $e) {
                $_SESSION['form_error'] = 'ही व्यक्ती आधीच CC मध्ये आहे.';
            }
        }
    } elseif ($action === 'mark_dispatched' && ($isCreator || $isHolder || hasRole('super_admin'))) {
        $outwardNo = trim($_POST['outward_no'] ?? '');
        $dispatchMode = in_array($_POST['dispatch_mode'] ?? '', ['Post','Courier','Speed Post','Email','Hand Delivery','Other'], true) ? $_POST['dispatch_mode'] : null;
        $trackingNo = trim($_POST['dispatch_tracking_no'] ?? '') ?: null;
        $dispatchDate = ($_POST['dispatch_date'] ?? '') ?: date('Y-m-d');
        $pdo->prepare("UPDATE letters SET outward_no = COALESCE(NULLIF(?, ''), outward_no), dispatch_mode = ?, dispatch_tracking_no = ?, dispatch_date = ? WHERE id = ?")
            ->execute([$outwardNo, $dispatchMode, $trackingNo, $dispatchDate, $letterId]);
        logActivity('पत्र Dispatch नोंदवलं', 'letters', $letterId, $letter['file_number']);
        $_SESSION['flash'] = '✅ Dispatch माहिती जतन झाली.';
    } elseif ($action === 'add_attachment_remark') {
        $attachmentId = (int)($_POST['attachment_id'] ?? 0);
        $remarkText = trim($_POST['remark_text'] ?? '');
        $xPos = ($_POST['x_position'] ?? '') !== '' && ($_POST['x_position'] ?? null) !== null ? (float)$_POST['x_position'] : null;
        $yPos = ($_POST['y_position'] ?? '') !== '' && ($_POST['y_position'] ?? null) !== null ? (float)$_POST['y_position'] : null;
        // हे Attachment खरंच याच पत्राचं आहे का — तपासतो (Security)
        $attCheckStmt = $pdo->prepare("SELECT la.id, la.original_name, la.uploaded_by FROM letter_attachments la WHERE la.id = ? AND la.letter_id = ?");
        $attCheckStmt->execute([$attachmentId, $letterId]);
        $attCheck = $attCheckStmt->fetch();
        if ($attCheck && $remarkText) {
            try {
                $pdo->prepare("INSERT INTO letter_attachment_remarks (attachment_id, user_id, remark_text, x_position, y_position) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$attachmentId, $user['id'], $remarkText, $xPos, $yPos]);
            } catch (PDOException $e) {
                // x_position/y_position Column अजून Migrate झालेलं नसेल — जुन्या पद्धतीने जतन करतो
                $pdo->prepare("INSERT INTO letter_attachment_remarks (attachment_id, user_id, remark_text) VALUES (?, ?, ?)")
                    ->execute([$attachmentId, $user['id'], $remarkText]);
            }
            // ज्याने ती File Upload केली, त्यालाच थेट Notification — बाकीच्यांना त्रास न देता
            if ($attCheck['uploaded_by'] && (int)$attCheck['uploaded_by'] !== (int)$user['id']) {
                notifyUser($attCheck['uploaded_by'], $orgId, '💬 तुमच्या Attachment वर Remark आला', $attCheck['original_name'] . ': ' . mb_substr($remarkText, 0, 60), 'letters/view.php?id=' . $letterId);
            }
            $_SESSION['flash'] = 'Remark जोडला गेला.';
        }
    } elseif ($action === 'resolve_attachment_remark') {
        $remarkId = (int)($_POST['remark_id'] ?? 0);
        // हा Remark याच पत्राच्या Attachment चाच आहे का — तपासून मगच Resolve करतो
        $checkStmt = $pdo->prepare("
            SELECT lar.id FROM letter_attachment_remarks lar
            JOIN letter_attachments la ON la.id = lar.attachment_id
            WHERE lar.id = ? AND la.letter_id = ?
        ");
        $checkStmt->execute([$remarkId, $letterId]);
        if ($checkStmt->fetch()) {
            $pdo->prepare("UPDATE letter_attachment_remarks SET is_resolved = 1 - is_resolved WHERE id = ?")->execute([$remarkId]);
        }
    }

    // Attachment upload
    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
        $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts, true)) {
            $_SESSION['form_error'] = 'फक्त फोटो, PDF, Word किंवा Excel फाईल्स Attach करता येतात.';
        } else {
            $uploadDir = __DIR__ . '/../assets/uploads/letters/' . $letterId . '/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
            $safeName = uniqid('att_') . '.' . $ext;
            move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $safeName);
            $relPath = 'assets/uploads/letters/' . $letterId . '/' . $safeName;
            $pdo->prepare("INSERT INTO letter_attachments (letter_id, file_path, original_name, uploaded_by) VALUES (?, ?, ?, ?)")
                ->execute([$letterId, $relPath, $_FILES['attachment']['name'], $user['id']]);
            $_SESSION['flash'] = 'जोडपत्र (Attachment) अपलोड झाले.';
        }
    }

    header('Location: view.php?id=' . $letterId);
    exit;
}

// ---------------- Data for display ----------------
$stmt = $pdo->prepare("
    SELECT la.*, fu.name AS from_name, tu.name AS to_name
    FROM letter_actions la
    LEFT JOIN users fu ON fu.id = la.from_user_id
    LEFT JOIN users tu ON tu.id = la.to_user_id
    WHERE la.letter_id = ? ORDER BY la.id ASC
");
$stmt->execute([$letterId]);
$actions = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT la.*, u.name AS uploader_name FROM letter_attachments la LEFT JOIN users u ON u.id = la.uploaded_by WHERE la.letter_id = ? ORDER BY la.id DESC");
$stmt->execute([$letterId]);
$attachments = $stmt->fetchAll();

// प्रत्येक Attachment साठी Remarks — Attachment-ID नुसार Group करतो
$attachmentRemarks = [];
if (!empty($attachments)) {
    try {
        $attIds = array_column($attachments, 'id');
        $in = implode(',', array_fill(0, count($attIds), '?'));
        $remStmt = $pdo->prepare("SELECT lar.*, u.name AS remarker_name FROM letter_attachment_remarks lar JOIN users u ON u.id = lar.user_id WHERE lar.attachment_id IN ($in) ORDER BY lar.created_at");
        $remStmt->execute($attIds);
        foreach ($remStmt->fetchAll() as $r) { $attachmentRemarks[$r['attachment_id']][] = $r; }
    } catch (PDOException $e) { /* table not migrated yet */ }
}


// Thread — या पत्राचा मूळ पत्र (Parent) आणि याला उत्तर म्हणून आलेली पुढची पत्रं (Children)
$threadParent = null;
if (!empty($letter['parent_letter_id'])) {
    $tpStmt = $pdo->prepare("SELECT id, file_number, subject, status FROM letters WHERE id = ? AND organization_id = ?");
    $tpStmt->execute([$letter['parent_letter_id'], $orgId]);
    $threadParent = $tpStmt->fetch();
}
$tcStmt = $pdo->prepare("SELECT id, file_number, subject, status FROM letters WHERE parent_letter_id = ? AND organization_id = ? ORDER BY created_at");
$tcStmt->execute([$letterId, $orgId]);
$threadChildren = $tcStmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$orgId]);
$staffPool = $stmt->fetchAll();

// CC/Watchers साठी — फक्त विभाग-प्रमुख (HOD) निवडता यावेत
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.designation FROM users u
    JOIN role_definitions rd ON rd.role_key = u.role
    WHERE u.organization_id = ? AND u.is_active = 1 AND rd.category = 'hod'
    ORDER BY u.name
");
$stmt->execute([$orgId]);
$hodPool = $stmt->fetchAll();

$ccCampusStmt = $pdo->prepare("SELECT id, name, college_type FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$ccCampusStmt->execute([$orgId]);
$ccCampuses = $ccCampusStmt->fetchAll();

$stmt = $pdo->prepare("SELECT lw.*, u.name AS watcher_name FROM letter_watchers lw JOIN users u ON u.id = lw.user_id WHERE lw.letter_id = ?");
$stmt->execute([$letterId]);
$watchers = $stmt->fetchAll();

$priorityLabels = ['low' => 'कमी', 'normal' => 'सामान्य', 'high' => 'जास्त', 'urgent' => 'तातडीचे'];
$statusLabels = ['draft' => 'Draft', 'in_progress' => 'प्रगतीत', 'completed' => 'पूर्ण/दाखल', 'returned' => 'परत आले'];
$statusChip = ['draft' => 'pending', 'in_progress' => 'pending', 'completed' => 'done', 'returned' => 'overdue'];
$actionLabels = ['created' => 'तयार केली', 'submitted' => 'सबमिट केली', 'forwarded' => 'पुढे पाठवली', 'returned' => 'परत पाठवली', 'remark_added' => 'टिप्पणी', 'completed' => 'पूर्ण/दाखल केली'];

$pageTitle = $letter['file_number'];
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h($letter['file_number']) ?><?= $letter['outward_no'] ? ' · जावक क्र.: ' . h($letter['outward_no']) : '' ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1"><?= h($letter['subject']) ?></div>
    <?php if ($letter['reference_no']): ?>
      <div style="font-size:12px;color:var(--amber);font-weight:600;margin-bottom:4px;">संदर्भ क्र.: <?= h($letter['reference_no']) ?></div>
    <?php endif; ?>
    <?php if (!empty($letter['is_budget_expense'])): ?>
      <div style="font-size:12px;font-weight:600;margin-bottom:4px;">
        💰 <?= h($letter['budget_category'] ?? '') ?> — मागणी: ₹<?= number_format($letter['requested_amount'] ?: 0, 0) ?>
        <?php if ($letter['budget_deducted']): ?>
          <span style="color:var(--rail);"> · ✓ मंजूर: ₹<?= number_format($letter['approved_amount'], 0) ?> (Budget मधून वजा झाले)</span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:6px;">
      तयार केले: <?= h($letter['creator_name']) ?> · <?= date('d M Y', strtotime($letter['created_at'])) ?>
      <?= $letter['dept_name'] ? ' · ' . h($letter['dept_name']) : '' ?>
      <?= $letter['category_name'] ? ' · ' . h($letter['category_name']) : '' ?>
    </div>
    <?php if (!empty($inwardNumbers)): ?>
      <div style="margin-bottom:16px;">
        <?php foreach ($inwardNumbers as $inw): ?>
          <span style="display:inline-block;background:var(--rail-soft);color:var(--rail);border-radius:14px;padding:3px 12px;font-size:11px;font-weight:600;margin:0 6px 6px 0;">
            <?= h($inw['dept_name']) ?> आवक: <?= h($inw['inward_no']) ?>
          </span>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div style="margin-bottom:16px;"></div>
    <?php endif; ?>
    <?php foreach ($letterSignatures as $sig): ?>
      <div style="background:linear-gradient(135deg,#F3F4F7,#fff);border:1px solid var(--rail);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:12px;">
        <div style="font-size:22px;">🔏</div>
        <div style="flex:1;">
          <div style="font-size:12.5px;font-weight:700;color:var(--ink);">Digitally Signed by <?= h($sig['signer_name']) ?><?= $sig['signer_designation'] ? ' — ' . h($sig['signer_designation']) : '' ?></div>
          <div style="font-size:11px;color:var(--text-dim);">
            <?= date('d M Y, h:i A', strtotime($sig['signed_at'])) ?> ·
            <?= $sig['verification_method'] === 'fingerprint' ? '👆 Fingerprint/Face ID' : '🔑 Password' ?> ने पडताळलेलं ·
            Verification ID: #<?= h($sig['verification_code']) ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>पत्र व्यवहार</a>
  <div class="d-flex gap-2">
    <a href="print.php?id=<?= $letterId ?>" target="_blank" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;align-self:center;"><i class="fa-solid fa-file-pdf me-1"></i>PDF</a>
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>पत्र व्यवहार</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">स्थिती</div><div class="val" style="font-size:15px;"><span class="status-chip <?= $statusChip[$letter['status']] ?>"><?= $statusLabels[$letter['status']] ?></span></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">प्राधान्य</div><div class="val" style="font-size:15px;"><?= $priorityLabels[$letter['priority']] ?></div></div></div>
  <div class="col-6 col-lg-6"><div class="kpi-mini"><div class="lbl">सध्या कोणाकडे</div><div class="val" style="font-size:15px;">
    <?= h($letter['holder_name'] ?? '—') ?>
    <?php if ($letter['holder_name']): ?>
      <?php if (!empty($letter['current_holder_seen_at'])): ?>
        <span style="font-size:10.5px;color:var(--rail);font-weight:600;margin-left:6px;"><i class="fa-solid fa-check-double"></i> वाचली</span>
      <?php else: ?>
        <span style="font-size:10.5px;color:var(--rose);font-weight:600;margin-left:6px;"><i class="fa-solid fa-circle"></i> वाचली नाही</span>
      <?php endif; ?>
    <?php endif; ?>
  </div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <?php if ($letter['description']): ?>
      <div class="panel mb-3" style="padding:16px 20px;font-size:13px;color:var(--text-dim);"><?= nl2br(h($letter['description'])) ?></div>
    <?php endif; ?>

    <div class="panel mb-3">
      <div class="panel-head"><div class="title">हालचालीचा क्रम (Movement Timeline)</div></div>
      <div style="padding:16px 20px;">
        <?php foreach ($actions as $a): ?>
          <div style="display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--line);">
            <div style="width:32px;height:32px;border-radius:50%;background:var(--rail-soft);color:var(--rail);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;"><?= h(mb_substr($a['from_name'] ?? '?', 0, 1)) ?></div>
            <div>
              <div style="font-size:13px;"><strong><?= h($a['from_name']) ?></strong> — <?= $actionLabels[$a['action_type']] ?? $a['action_type'] ?><?= $a['to_name'] ? ' → ' . h($a['to_name']) : '' ?></div>
              <?php if ($a['remark']): ?><div style="font-size:12.5px;color:var(--text-dim);margin-top:2px;"><?= h($a['remark']) ?></div><?php endif; ?>
              <div style="font-size:11px;color:var(--text-dim);margin-top:2px;"><?= date('d M Y, h:i A', strtotime($a['created_at'])) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($threadParent || !empty($threadChildren)): ?>
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">🔗 संबंधित पत्रं (Thread)</div></div>
      <div style="padding:14px 20px;">
        <?php if ($threadParent): ?>
          <div style="font-size:11px;color:var(--text-dim);margin-bottom:6px;">⬆️ हे पत्र खालील पत्राला उत्तर आहे:</div>
          <a href="view.php?id=<?= $threadParent['id'] ?>" style="display:block;background:var(--canvas);border-radius:9px;padding:10px 14px;text-decoration:none;color:inherit;margin-bottom:<?= !empty($threadChildren) ? '14px' : '0' ?>;">
            <div style="font-size:12.5px;font-weight:600;"><?= h($threadParent['file_number']) ?></div>
            <div style="font-size:12px;color:var(--text-dim);"><?= h($threadParent['subject']) ?></div>
          </a>
        <?php endif; ?>
        <?php if (!empty($threadChildren)): ?>
          <div style="font-size:11px;color:var(--text-dim);margin-bottom:6px;">⬇️ या पत्राला पुढे उत्तर आलेली पत्रं:</div>
          <?php foreach ($threadChildren as $tc): ?>
            <a href="view.php?id=<?= $tc['id'] ?>" style="display:block;background:var(--canvas);border-radius:9px;padding:10px 14px;text-decoration:none;color:inherit;margin-bottom:6px;">
              <div style="font-size:12.5px;font-weight:600;"><?= h($tc['file_number']) ?></div>
              <div style="font-size:12px;color:var(--text-dim);"><?= h($tc['subject']) ?></div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="panel">
      <div class="panel-head d-flex justify-content-between align-items-center">
        <div class="title">जोडपत्रे (Attachments)</div>
        <?php if ($isCreator || $isHolder): ?>
          <form method="POST" enctype="multipart/form-data" class="d-flex gap-2">
            <input type="hidden" name="action" value="attach_only">
            <input type="file" name="attachment" style="font-size:12px;">
            <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:6px 12px;">अपलोड</button>
          </form>
        <?php endif; ?>
      </div>
      <div style="padding:12px 20px;">
        <?php if (empty($attachments)): ?><p style="font-size:12.5px;color:var(--text-dim);">कोणतेही जोडपत्र नाही</p><?php endif; ?>
        <?php foreach ($attachments as $att):
          $remarksForAtt = $attachmentRemarks[$att['id']] ?? [];
          $pinnedRemarks = array_filter($remarksForAtt, fn($r) => $r['x_position'] !== null);
          $textOnlyRemarks = array_filter($remarksForAtt, fn($r) => $r['x_position'] === null);
          $ext = strtolower(pathinfo($att['file_path'], PATHINFO_EXTENSION));
          $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
        ?>
          <div style="padding:10px 0;border-bottom:1px solid var(--line);">
            <div style="font-size:13px;margin-bottom:6px;"><i class="fa-solid fa-paperclip me-2" style="color:var(--rail);"></i><a href="../<?= h($att['file_path']) ?>" target="_blank"><?= h($att['original_name']) ?></a> <span style="color:var(--text-dim);font-size:11.5px;">— <?= h($att['uploader_name']) ?></span></div>

            <?php if ($isImage): ?>
              <div class="attachment-annotate-wrap" data-attachment-id="<?= $att['id'] ?>" style="position:relative;display:inline-block;max-width:100%;cursor:crosshair;border:1px solid var(--line);border-radius:8px;overflow:hidden;">
                <img src="../<?= h($att['file_path']) ?>" style="max-width:100%;max-height:420px;display:block;">
                <?php foreach ($pinnedRemarks as $pr): ?>
                  <div class="annotate-pin" style="position:absolute;left:<?= $pr['x_position'] ?>%;top:<?= $pr['y_position'] ?>%;transform:translate(-50%,-100%);cursor:pointer;<?= $pr['is_resolved'] ? 'opacity:0.45;' : '' ?>"
                    title="<?= h($pr['remarker_name']) ?>: <?= h($pr['remark_text']) ?>">
                    <div style="width:22px;height:22px;border-radius:50% 50% 50% 0;background:<?= $pr['is_resolved'] ? '#8A90AC' : '#B23A48' ?>;transform:rotate(-45deg);border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,.3);"></div>
                  </div>
                <?php endforeach; ?>
              </div>
              <div style="font-size:10.5px;color:var(--text-dim);margin-top:4px;">💡 Image वर कुठेही Click करा — त्या ठिकाणी Comment टाकता येईल. Pin वर Hover केलं की Comment दिसेल.</div>
            <?php endif; ?>

            <div style="margin-left:<?= $isImage ? '0' : '22px' ?>;margin-top:8px;">
              <?php foreach ($textOnlyRemarks as $rem): ?>
                <div style="display:flex;justify-content:space-between;align-items:start;padding:5px 10px;background:var(--canvas);border-radius:8px;margin-bottom:4px;<?= $rem['is_resolved'] ? 'opacity:0.55;' : '' ?>">
                  <div>
                    <div style="font-size:12px;<?= $rem['is_resolved'] ? 'text-decoration:line-through;' : '' ?>">💬 <?= h($rem['remark_text']) ?></div>
                    <div style="font-size:10px;color:var(--text-dim);"><?= h($rem['remarker_name']) ?> — <?= date('d M, h:i A', strtotime($rem['created_at'])) ?></div>
                  </div>
                  <form method="POST"><input type="hidden" name="action" value="resolve_attachment_remark"><input type="hidden" name="remark_id" value="<?= $rem['id'] ?>">
                    <button type="submit" style="border:none;background:none;font-size:10.5px;color:<?= $rem['is_resolved'] ? 'var(--text-dim)' : 'var(--rail)' ?>;white-space:nowrap;"><?= $rem['is_resolved'] ? '↩️ परत उघडा' : '✅ Resolved' ?></button>
                  </form>
                </div>
              <?php endforeach; ?>
              <?php if ($isImage && !empty($pinnedRemarks)): ?>
                <div style="font-size:10.5px;color:var(--text-dim);margin:6px 0 2px;">📍 Image वरचे Comments (<?= count($pinnedRemarks) ?>):</div>
                <?php foreach ($pinnedRemarks as $pr): ?>
                  <div style="display:flex;justify-content:space-between;align-items:start;padding:5px 10px;background:var(--canvas);border-radius:8px;margin-bottom:4px;<?= $pr['is_resolved'] ? 'opacity:0.55;' : '' ?>">
                    <div>
                      <div style="font-size:12px;<?= $pr['is_resolved'] ? 'text-decoration:line-through;' : '' ?>">📍 <?= h($pr['remark_text']) ?></div>
                      <div style="font-size:10px;color:var(--text-dim);"><?= h($pr['remarker_name']) ?> — <?= date('d M, h:i A', strtotime($pr['created_at'])) ?></div>
                    </div>
                    <form method="POST"><input type="hidden" name="action" value="resolve_attachment_remark"><input type="hidden" name="remark_id" value="<?= $pr['id'] ?>">
                      <button type="submit" style="border:none;background:none;font-size:10.5px;color:<?= $pr['is_resolved'] ? 'var(--text-dim)' : 'var(--rail)' ?>;white-space:nowrap;"><?= $pr['is_resolved'] ? '↩️ परत उघडा' : '✅ Resolved' ?></button>
                    </form>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
              <form method="POST" class="d-flex gap-2 mt-1">
                <input type="hidden" name="action" value="add_attachment_remark">
                <input type="hidden" name="attachment_id" value="<?= $att['id'] ?>">
                <input type="text" name="remark_text" class="form-control-custom" style="font-size:11.5px;padding:5px 10px;" placeholder="या File बद्दल साधारण Remark लिहा..." required>
                <button type="submit" style="border:none;background:none;color:var(--rail);font-size:11.5px;font-weight:600;white-space:nowrap;">पाठवा</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($isCreator || $isHolder || hasRole('super_admin')): ?>
    <div class="panel mt-3">
      <div class="panel-head"><div class="title">📮 Dispatch तपशील</div></div>
      <div style="padding:14px 20px;">
        <?php if ($letter['dispatch_mode']): ?>
          <div style="font-size:12.5px;"><strong><?= h($letter['dispatch_mode']) ?></strong><?= $letter['dispatch_tracking_no'] ? ' — Tracking: ' . h($letter['dispatch_tracking_no']) : '' ?></div>
          <div style="font-size:11px;color:var(--text-dim);"><?= $letter['dispatch_date'] ? date('d M Y', strtotime($letter['dispatch_date'])) . ' रोजी पाठवलं' : '' ?></div>
        <?php else: ?>
          <p style="font-size:12px;color:var(--text-dim);margin-bottom:8px;">अजून Dispatch नोंदवलेलं नाही</p>
        <?php endif; ?>
        <button type="button" class="btn btn-sm mt-2" style="border:1px solid var(--rail);color:var(--rail);border-radius:7px;font-size:11.5px;padding:6px 14px;" data-bs-toggle="modal" data-bs-target="#dispatchModal">
          <?= $letter['dispatch_mode'] ? '✏️ Update करा' : '📮 Dispatch नोंदवा' ?>
        </button>
      </div>
    </div>
    <?php endif; ?>

    <div class="panel mt-3">
      <div class="panel-head"><div class="title">CC (फक्त माहितीसाठी)</div></div>
      <div style="padding:12px 20px;">
        <?php if (empty($watchers)): ?><p style="font-size:12.5px;color:var(--text-dim);">कोणीही CC मध्ये नाही</p><?php endif; ?>
        <?php foreach ($watchers as $w): ?>
          <span style="display:inline-block;background:var(--canvas);border-radius:20px;padding:5px 12px;font-size:12px;margin:0 6px 6px 0;"><i class="fa-solid fa-eye me-1" style="color:var(--rail);"></i><?= h($w['watcher_name']) ?></span>
        <?php endforeach; ?>
        <?php if ($isCreator || $isHolder || hasRole('super_admin')): ?>
          <form method="POST" class="mt-2" id="ccForm">
            <input type="hidden" name="action" value="add_watcher">
            <div class="d-flex gap-2 mb-2">
              <select id="ccCampusSelect" class="form-select-custom" style="flex:1;">
                <option value="">-- College/School निवडा --</option>
                <option value="0">🏛️ Trust-स्तरीय विभाग</option>
                <?php foreach ($ccCampuses as $cc): if ($cc['college_type'] === 'Trust HQ') continue; ?>
                  <option value="<?= $cc['id'] ?>"><?= h($cc['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <select id="ccDeptSelect" class="form-select-custom" style="flex:1;" disabled>
                <option value="">-- आधी College/School निवडा --</option>
              </select>
            </div>
            <div class="d-flex gap-2">
              <select name="watcher_id" id="ccPersonSelect" class="form-select-custom" style="flex:1;" required disabled>
                <option value="">-- आधी विभाग निवडा --</option>
              </select>
              <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:6px 14px;white-space:nowrap;">CC जोडा</button>
            </div>
          </form>
          <script>
          (function () {
            var rp = <?= json_encode($rootPath) ?>;
            var cSel = document.getElementById('ccCampusSelect'), dSel = document.getElementById('ccDeptSelect'), pSel = document.getElementById('ccPersonSelect');
            cSel.addEventListener('change', function () {
              dSel.disabled = true; pSel.disabled = true; pSel.innerHTML = '<option value="">-- आधी विभाग निवडा --</option>';
              if (cSel.value === '') { dSel.innerHTML = '<option value="">-- आधी College/School निवडा --</option>'; return; }
              dSel.innerHTML = '<option value="">लोड होत आहे...</option>';
              fetch(rp + 'ajax-cascade.php?action=departments_for_campus&campus_id=' + (cSel.value === '0' ? '' : cSel.value))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                  var depts = data.departments || [];
                  dSel.innerHTML = '<option value="">-- निवडा --</option>' + depts.map(function (d) { return '<option value="' + d.id + '">' + d.name + '</option>'; }).join('');
                  dSel.disabled = false;
                });
            });
            dSel.addEventListener('change', function () {
              pSel.disabled = true;
              if (!dSel.value) { pSel.innerHTML = '<option value="">-- आधी विभाग निवडा --</option>'; return; }
              pSel.innerHTML = '<option value="">लोड होत आहे...</option>';
              fetch(rp + 'ajax-cascade.php?action=staff_for_department&department_id=' + dSel.value)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                  var staff = data.staff || [];
                  pSel.innerHTML = '<option value="">-- व्यक्ती निवडा --</option>' + staff.map(function (s) { return '<option value="' + s.id + '">' + s.name + (s.designation ? ' — ' + s.designation : '') + '</option>'; }).join('');
                  pSel.disabled = false;
                });
            });
          })();
          </script>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-head"><div class="title">कृती (Actions)</div></div>
      <div style="padding:18px 20px;">

        <?php if ($letter['clerk_stage'] === 'awaiting_inward' && $isHolder): ?>
          <div style="background:var(--amber-soft, #FBEBD6);color:#8A5A14;border-radius:9px;padding:12px 14px;font-size:12.5px;margin-bottom:14px;">
            📋 हे पत्र <strong><?= h($letter['intended_recipient_name']) ?></strong> यांच्यासाठी आहे — आधी आवक नोंदणी करा.
          </div>
          <form method="POST" onsubmit="return confirm('आवक नोंदणी करून हे पत्र त्यांच्या टेबलावर पाठवायचं?');">
            <input type="hidden" name="action" value="clerk_register_inward">
            <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:10px;font-weight:600;">✓ आवक नोंदणी करा</button>
          </form>

        <?php elseif ($letter['clerk_stage'] === 'awaiting_forward_approval' && $isHolder): ?>
          <div style="background:var(--rail-soft);color:var(--rail);border-radius:9px;padding:12px 14px;font-size:12.5px;margin-bottom:14px;">
            ✍️ <strong><?= h($letter['holder_name']) ?></strong> यांनी निर्णय घेतला असून हे पत्र <strong><?= h($letter['pending_forward_to_name']) ?></strong> कडे पुढे पाठवायला सांगितलं आहे.
          </div>
          <div class="row g-2">
            <div class="col-6">
              <form method="POST">
                <input type="hidden" name="action" value="clerk_hold">
                <button type="submit" class="btn w-100" style="border:1px solid var(--amber);color:#8A5A14;border-radius:9px;font-size:12.5px;padding:9px;">थांबवा (Hold)</button>
              </form>
            </div>
            <div class="col-6">
              <form method="POST" onsubmit="return confirm('हे पत्र पुढे पाठवायचं?');">
                <input type="hidden" name="action" value="clerk_approve_forward">
                <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;padding:9px;font-weight:600;">✓ Approve — पुढे पाठवा</button>
              </form>
            </div>
          </div>

        <?php elseif ($isCreator && $letter['status'] === 'draft'): ?>
          <form method="POST">
            <input type="hidden" name="action" value="submit">
            <?php if (!$letter['workflow_template_id']): ?>
              <label class="form-label-custom">कोणाकडे पाठवायचे</label>
              <select name="to_user_id" class="form-select-custom mb-2" required>
                <option value="">-- निवडा --</option>
                <?php foreach ($staffPool as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?> <?= $s['designation'] ? '(' . h($s['designation']) . ')' : '' ?></option><?php endforeach; ?>
              </select>
            <?php else: ?>
              <p style="font-size:12.5px;color:var(--text-dim);">कार्यप्रवाहानुसार आपोआप पहिल्या पायरीकडे जाईल.</p>
            <?php endif; ?>
            <textarea name="remark" class="form-control-custom mb-2" rows="2" placeholder="टिप्पणी (ऐच्छिक)"></textarea>
            <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:10px;font-weight:600;">पाठवा (Submit)</button>
          </form>

        <?php elseif ($isCreator && $letter['status'] === 'returned'): ?>
          <div class="alert alert-warning" style="border-radius:9px;font-size:12.5px;padding:10px 14px;margin-bottom:10px;">⚠️ हे पत्र परत पाठवलं गेलं आहे — वरची टिप्पणी बघून आवश्यक ते बदल करा, मग खालून पुन्हा सुरू करा.</div>
          <form method="POST" onsubmit="return confirm('हे पत्र पुन्हा सुरू करायचं?');">
            <input type="hidden" name="action" value="reopen">
            <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:10px;font-weight:600;">🔄 पुन्हा सुरू करा (Resubmit)</button>
          </form>

        <?php elseif ($isHolder): ?>
          <form method="POST" class="mb-3">
            <input type="hidden" name="action" value="forward">
            <?php if (!$letter['workflow_template_id']): ?>
              <label class="form-label-custom">पुढे कोणाकडे पाठवायचे</label>
              <select name="to_user_id" class="form-select-custom mb-2" required>
                <option value="">-- निवडा --</option>
                <?php foreach ($staffPool as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?> <?= $s['designation'] ? '(' . h($s['designation']) . ')' : '' ?></option><?php endforeach; ?>
              </select>
            <?php else: ?>
              <p style="font-size:12.5px;color:var(--text-dim);">कार्यप्रवाहानुसार पुढील पायरीकडे जाईल.</p>
            <?php endif; ?>
            <textarea name="remark" class="form-control-custom mb-2" rows="2" placeholder="टिप्पणी (ऐच्छिक)"></textarea>
            <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:10px;font-weight:600;">पुढे पाठवा (Forward)</button>
          </form>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <form method="POST" onsubmit="return confirm('फाईल परत पाठवायची?');">
                <input type="hidden" name="action" value="return">
                <button type="submit" class="btn w-100" style="border:1px solid var(--amber);color:#8A5A14;border-radius:9px;font-size:12.5px;padding:9px;">← परत पाठवा</button>
              </form>
            </div>
            <div class="col-6">
              <button type="button" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;padding:9px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#signApproveModal">🔏 Sign & Approve</button>
            </div>
          </div>

          <?php
            $myWebauthnStmt = $pdo->prepare("SELECT COUNT(*) c FROM webauthn_credentials WHERE user_id = ?");
            $myWebauthnStmt->execute([$user['id']]);
            $hasFingerprint = (int)$myWebauthnStmt->fetch()['c'] > 0;
          ?>
          <div class="modal fade" id="dispatchModal" tabindex="-1">
            <div class="modal-dialog">
              <div class="modal-content" style="border-radius:14px;">
                <form method="POST">
                  <input type="hidden" name="action" value="mark_dispatched">
                  <div class="modal-body" style="padding:22px 24px;">
                    <div style="font-weight:700;font-size:15px;margin-bottom:14px;">📮 Dispatch नोंदवा</div>
                    <label class="form-label-custom">जावक क्रमांक (Outward No.)</label>
                    <input type="text" name="outward_no" class="form-control-custom mb-2" value="<?= h($letter['outward_no'] ?? '') ?>">
                    <label class="form-label-custom">कसं पाठवलं</label>
                    <select name="dispatch_mode" class="form-select-custom mb-2">
                      <option value="">-- निवडा --</option>
                      <?php foreach (['Post','Courier','Speed Post','Email','Hand Delivery','Other'] as $dm): ?>
                        <option value="<?= $dm ?>" <?= ($letter['dispatch_mode'] ?? '') === $dm ? 'selected' : '' ?>><?= $dm ?></option>
                      <?php endforeach; ?>
                    </select>
                    <label class="form-label-custom">Tracking Number (ऐच्छिक)</label>
                    <input type="text" name="dispatch_tracking_no" class="form-control-custom mb-2" value="<?= h($letter['dispatch_tracking_no'] ?? '') ?>">
                    <label class="form-label-custom">दिनांक</label>
                    <input type="date" name="dispatch_date" class="form-control-custom" value="<?= h($letter['dispatch_date'] ?? date('Y-m-d')) ?>">
                  </div>
                  <div class="modal-footer" style="border-top:1px solid var(--line);">
                    <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                    <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जतन करा</button>
                  </div>
                </form>
              </div>
            </div>
          </div>

          <div class="modal fade" id="signApproveModal" tabindex="-1">
            <div class="modal-dialog">
              <div class="modal-content" style="border-radius:14px;">
                <div class="modal-body" style="padding:24px;">
                  <div style="font-family:'Space Grotesk';font-weight:600;font-size:16px;margin-bottom:4px;">🔏 Sign & Approve</div>
                  <div style="font-size:12px;color:var(--text-dim);margin-bottom:16px;">ओळख पडताळून फाईल Digitally Sign करून पूर्ण/दाखल करा</div>

                  <?php if (!empty($letter['is_budget_expense']) && !$letter['budget_deducted']): ?>
                    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:10px;">
                      मागणी रक्कम: <strong>₹<?= number_format($letter['requested_amount'] ?: 0, 0) ?></strong> — <?= h($letter['budget_category'] ?? '') ?>
                    </div>
                    <label class="form-label-custom">मंजूर रक्कम (₹) — गरज असल्यास बदला</label>
                    <input type="number" step="0.01" id="signApprovedAmount" class="form-control-custom mb-2" value="<?= h($letter['requested_amount'] ?: '') ?>" required>
                    <div style="font-size:11px;color:var(--amber);margin-bottom:10px;">⚠️ ही रक्कम संबंधित विभागाच्या Budget मधून आपोआप वजा होईल.</div>
                  <?php endif; ?>
                  <label class="form-label-custom">टिप्पणी (ऐच्छिक)</label>
                  <input type="text" id="signRemark" class="form-control-custom mb-3">

                  <div id="signApproveError" class="alert alert-danger" style="display:none;border-radius:8px;font-size:12px;padding:10px 14px;"></div>

                  <?php if ($hasFingerprint): ?>
                    <button type="button" onclick="eowmsSignApprove(<?= $letterId ?>, 'fingerprint')" class="btn w-100 mb-2" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:10px;font-weight:600;">👆 Fingerprint/Face ID ने Sign करा</button>
                    <div style="display:flex;align-items:center;gap:10px;margin:12px 0;">
                      <div style="flex:1;height:1px;background:var(--line);"></div><span style="font-size:11px;color:var(--text-dim);">किंवा</span><div style="flex:1;height:1px;background:var(--line);"></div>
                    </div>
                  <?php endif; ?>

                  <label class="form-label-custom">🔑 तुमचा Password टाकून Sign करा</label>
                  <input type="password" id="signPassword" class="form-control-custom mb-2" placeholder="Password">
                  <button type="button" onclick="eowmsSignApprove(<?= $letterId ?>, 'password')" class="btn w-100" style="border:1.5px solid var(--rail);color:var(--rail);background:#fff;border-radius:9px;font-size:13px;padding:9px;font-weight:600;">Password ने Sign करा</button>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--line);">
                  <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($isCreator || $isHolder): ?>
          <form method="POST">
            <input type="hidden" name="action" value="remark">
            <label class="form-label-custom">फक्त टिप्पणी जोडा (हालचाल न करता)</label>
            <textarea name="remark" class="form-control-custom mb-2" rows="2" required></textarea>
            <button type="submit" class="btn w-100" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px;">टिप्पणी जोडा</button>
          </form>
        <?php endif; ?>

      </div>
    </div>

    <?php if (hasRole('super_admin') && $letter['status'] !== 'completed' && $letter['status'] !== 'draft'): ?>
      <div class="panel mt-3" style="border-color:var(--amber);">
        <div class="panel-head"><div class="title" style="color:#8A5A14;">⚙️ Super Admin — पुन्हा नेमा (Reassign)</div><div class="sub">फाईल कुठे अडकली असेल तर ती कोणाकडेही थेट पाठवा</div></div>
        <form method="POST" style="padding:16px 20px;">
          <input type="hidden" name="action" value="reassign">
          <select name="new_holder_id" class="form-select-custom mb-2" required>
            <option value="">-- व्यक्ती निवडा --</option>
            <?php foreach ($staffPool as $s): ?>
              <option value="<?= $s['id'] ?>" <?= (int)$letter['current_holder_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?> <?= $s['designation'] ? '(' . h($s['designation']) . ')' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" name="remark" class="form-control-custom mb-2" placeholder="कारण (ऐच्छिक)">
          <button type="submit" class="btn w-100" style="border:1px solid var(--amber);color:#8A5A14;border-radius:9px;font-size:12.5px;padding:9px;font-weight:600;">पुन्हा नेमा</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<script src="../assets/js/webauthn.js"></script>
<script>
async function eowmsSignApprove(letterId, method) {
  const errBox = document.getElementById('signApproveError');
  errBox.style.display = 'none';
  const amountField = document.getElementById('signApprovedAmount');
  const remark = document.getElementById('signRemark').value;

  const payload = new URLSearchParams();
  payload.set('letter_id', letterId);
  payload.set('method', method);
  payload.set('remark', remark);
  if (amountField) { payload.set('approved_amount', amountField.value); }

  try {
    if (method === 'fingerprint') {
      if (!window.PublicKeyCredential) { throw new Error('हा Browser Fingerprint/Face ID ला समर्थन देत नाही.'); }
      const optRes = await fetch('../webauthn-verify-identity-options.php');
      const options = await optRes.json();
      if (!options.challenge) { throw new Error(options.message || 'Fingerprint सुरू केलेलं नाही.'); }
      options.challenge = b64urlToBuffer(options.challenge);
      options.allowCredentials = (options.allowCredentials || []).map((c) => ({ ...c, id: b64urlToBuffer(c.id) }));

      const assertion = await navigator.credentials.get({ publicKey: options });
      const verifyRes = await fetch('../webauthn-verify-identity-verify.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id: bufferToB64url(assertion.rawId),
          clientDataJSON: bufferToB64url(assertion.response.clientDataJSON),
          authenticatorData: bufferToB64url(assertion.response.authenticatorData),
          signature: bufferToB64url(assertion.response.signature),
        }),
      });
      const verifyResult = await verifyRes.json();
      if (!verifyResult.success) { throw new Error(verifyResult.message); }
    } else {
      const password = document.getElementById('signPassword').value;
      if (!password) { throw new Error('कृपया Password टाका.'); }
      payload.set('password', password);
    }

    const res = await fetch('sign-approve.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: payload.toString() });
    const result = await res.json();
    if (result.success) {
      alert(result.message);
      window.location.reload();
    } else {
      throw new Error(result.message);
    }
  } catch (err) {
    errBox.textContent = err.message || 'चूक झाली — पुन्हा प्रयत्न करा.';
    errBox.style.display = 'block';
  }
}
</script>

<script>
document.querySelectorAll('.attachment-annotate-wrap').forEach(function (wrap) {
  wrap.addEventListener('click', function (e) {
    if (e.target.closest('.annotate-pin')) return; // आधीच्या Pin वर Click झालं तर नवीन बनवू नये
    const rect = wrap.getBoundingClientRect();
    const xPct = ((e.clientX - rect.left) / rect.width) * 100;
    const yPct = ((e.clientY - rect.top) / rect.height) * 100;
    const attachmentId = wrap.getAttribute('data-attachment-id');

    // तात्पुरता, छोटा Pin + Input दाखवतो — जागेवरच Comment टाइप करण्यासाठी
    const tempPin = document.createElement('div');
    tempPin.style.cssText = 'position:absolute;left:' + xPct + '%;top:' + yPct + '%;transform:translate(-50%,-100%);z-index:10;';
    tempPin.innerHTML =
      '<div style="background:#fff;border:1px solid var(--line);border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.15);padding:8px;width:220px;">' +
        '<input type="text" placeholder="इथे काय दिसतंय ते लिहा..." style="width:100%;border:1px solid var(--line);border-radius:6px;padding:5px 8px;font-size:12px;font-family:inherit;" autofocus>' +
        '<div style="display:flex;gap:6px;margin-top:6px;">' +
          '<button type="button" class="pin-cancel" style="flex:1;border:1px solid var(--line);background:none;border-radius:6px;font-size:11px;padding:4px;">रद्द</button>' +
          '<button type="button" class="pin-save" style="flex:1;background:var(--rail);color:#fff;border:none;border-radius:6px;font-size:11px;padding:4px;">जतन करा</button>' +
        '</div>' +
      '</div>';
    wrap.appendChild(tempPin);
    const input = tempPin.querySelector('input');
    input.focus();

    function cleanup() { tempPin.remove(); }
    tempPin.querySelector('.pin-cancel').addEventListener('click', cleanup);
    function save() {
      const text = input.value.trim();
      if (!text) { cleanup(); return; }
      const form = document.createElement('form');
      form.method = 'POST';
      function addHidden(name, value) {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = name; inp.value = value;
        form.appendChild(inp);
      }
      addHidden('action', 'add_attachment_remark');
      addHidden('attachment_id', attachmentId);
      addHidden('x_position', xPct.toFixed(2));
      addHidden('y_position', yPct.toFixed(2));
      addHidden('remark_text', text); // .value वापरल्याने आपोआप Safe-Escape होतं — कुठलाही HTML-Injection Risk नाही
      document.body.appendChild(form);
      form.submit();
    }
    tempPin.querySelector('.pin-save').addEventListener('click', save);
    input.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') save(); if (ev.key === 'Escape') cleanup(); });
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
