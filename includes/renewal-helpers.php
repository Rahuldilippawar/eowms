<?php
/**
 * नूतनीकरण व अनुपालन — Shared Helper Functions
 *
 * स्थिती (Active / लवकरच / मुदत संपली) आता कधीही DB मध्ये कायमची साठवली
 * जात नाही — ती नेहमी expiry_date वरून काढली जाते. status Column मध्ये
 * फक्त 'active' किंवा 'inactive' (Soft-Delete) एवढंच अर्थपूर्ण आहे.
 */

// किती दिवस आधी "लवकरच संपणार" समजायचं
if (!defined('RENEWAL_DUE_SOON_DAYS')) { define('RENEWAL_DUE_SOON_DAYS', 30); }

/** SQL अट — निष्क्रिय (Soft-Deleted) नोंदी वगळण्यासाठी */
function renewalActiveSql($alias = 'r') {
    $p = $alias ? $alias . '.' : '';
    return "{$p}status <> 'inactive'";
}

/** SQL अट — expiry_date वरून स्थिती ('expired' / 'due_soon' / 'active') */
function renewalStatusSql($status, $alias = 'r') {
    $p = $alias ? $alias . '.' : '';
    $n = (int)RENEWAL_DUE_SOON_DAYS;
    if ($status === 'expired') { return "{$p}expiry_date < CURDATE()"; }
    if ($status === 'due_soon') { return "{$p}expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL $n DAY)"; }
    return "{$p}expiry_date > DATE_ADD(CURDATE(), INTERVAL $n DAY)";
}

/** उरलेल्या दिवसांवरून दाखवायची स्थिती — Label, Chip-Class आणि रंग */
function renewalStatusInfo($daysLeft) {
    $daysLeft = (int)$daysLeft;
    if ($daysLeft < 0) {
        return ['key' => 'expired', 'label' => 'मुदत संपली', 'chip' => 'overdue', 'color' => 'rose'];
    }
    if ($daysLeft <= RENEWAL_DUE_SOON_DAYS) {
        return ['key' => 'due_soon', 'label' => 'लवकरच संपणार', 'chip' => 'pending', 'color' => $daysLeft <= 7 ? 'rose' : 'amber'];
    }
    return ['key' => 'active', 'label' => 'सक्रिय', 'chip' => 'done', 'color' => 'rail'];
}

/** उरलेले दिवस (आजच्या तारखेवरून) */
function renewalDaysLeft($expiryDate, $today = null) {
    $today = $today ?: date('Y-m-d');
    return (int)round((strtotime($expiryDate) - strtotime($today)) / 86400);
}

/** वाहनाच्या renewal_field → vehicles मधला Column */
function renewalVehicleColumnMap() {
    return [
        'permit' => 'permit_validity', 'puc' => 'puc_validity', 'insurance' => 'insurance_validity',
        'fitness' => 'fitness_validity', 'road_tax' => 'road_tax_validity', 'green_tax' => 'green_tax_validity',
        'mv_tax' => 'mv_tax_validity',
    ];
}

/**
 * ही नोंद दुसऱ्या Module मधून आपोआप तयार झाली आहे का (वाहन / IT Asset
 * Warranty / College NAAC-UGC) — असेल तर तो Source परत देतो, नाहीतर null.
 */
function renewalSourceInfo($item) {
    if (!empty($item['vehicle_id']) && isset(renewalVehicleColumnMap()[$item['renewal_field'] ?? ''])) {
        return ['type' => 'vehicle', 'id' => (int)$item['vehicle_id'], 'column' => renewalVehicleColumnMap()[$item['renewal_field']], 'label' => 'वाहन'];
    }
    if (($item['item_type'] ?? '') === 'IT Asset Warranty' && preg_match('/^IT Asset (\S+) \(/u', $item['item_name'] ?? '', $m)) {
        return ['type' => 'it_asset', 'tag' => $m[1], 'column' => 'warranty_expiry', 'label' => 'IT Asset'];
    }
    if (in_array($item['item_type'] ?? '', ['NAAC Accreditation', 'UGC Recognition'], true) && !empty($item['campus_id'])) {
        return ['type' => 'campus', 'id' => (int)$item['campus_id'], 'column' => $item['item_type'] === 'NAAC Accreditation' ? 'naac_valid_until' : 'ugc_valid_until', 'label' => 'College'];
    }
    return null;
}

/**
 * Renew/Edit केल्यावर — नवीन मुदत-दिनांक मूळ Module मध्ये (वाहन/IT Asset/College)
 * परत लिहितो. नाहीतर त्या Module मधून पुढच्या Edit ला जुनी तारीख Sync होऊन
 * नूतनीकरण "उलटं" होत असे.
 */
function renewalWriteBackSource($pdo, $item, $newExpiry) {
    $src = renewalSourceInfo($item);
    if (!$src) { return false; }
    $orgId = (int)$item['organization_id'];
    try {
        if ($src['type'] === 'vehicle') {
            $pdo->prepare("UPDATE vehicles SET `{$src['column']}` = ? WHERE id = ? AND organization_id = ?")->execute([$newExpiry, $src['id'], $orgId]);
        } elseif ($src['type'] === 'it_asset') {
            $pdo->prepare("UPDATE it_assets SET warranty_expiry = ? WHERE asset_tag = ? AND organization_id = ?")->execute([$newExpiry, $src['tag'], $orgId]);
        } elseif ($src['type'] === 'campus') {
            $pdo->prepare("UPDATE campuses SET `{$src['column']}` = ? WHERE id = ? AND organization_id = ?")->execute([$newExpiry, $src['id'], $orgId]);
        }
        return true;
    } catch (PDOException $e) {
        return false; // Source Table/Column नसेल तर — मुख्य नूतनीकरण तरीही झालं आहे
    }
}

/**
 * नोंद शोधते आणि ही व्यक्ती ती बघू/बदलू शकते का ते तपासते
 * (याच संस्थेची + Department-Scoped असाल तर दिसणाऱ्या विभागांपैकी).
 */
function renewalFetchAccessibleItem($pdo, $id, $includeInactive = false) {
    $stmt = $pdo->prepare("SELECT r.*, d.name_local AS dept_name, d.name AS dept_name_en FROM renewal_items r JOIN departments d ON d.id = r.department_id WHERE r.id = ? AND r.organization_id = ?" . ($includeInactive ? "" : " AND " . renewalActiveSql('r')));
    $stmt->execute([(int)$id, activeOrgId()]);
    $item = $stmt->fetch();
    if (!$item) { return null; }
    $visible = getVisibleDepartmentIds('view_org_wide_dashboards');
    if ($visible !== null && !in_array((int)$item['department_id'], $visible, true)) { return null; }
    return $item;
}

// ---------------- Attachments (प्रमाणपत्र / दस्तऐवज) ----------------

/** Upload Folder — Browser वरून थेट उघडता येत नाही (.htaccess deny), फक्त download script मधून */
function renewalAttachmentDir() {
    $dir = __DIR__ . '/../assets/uploads/renewals';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "# फक्त renewals/attachment.php मधूनच (परवानगी तपासून) मिळतात\nRequire all denied\n");
    }
    return $dir;
}

/**
 * एक File (PDF/प्रतिमा) साठवते — परत: [true, attachmentId] किंवा [false, 'त्रुटी']
 */
function renewalStoreAttachment($pdo, $item, $file, $cycleExpiry, $userId, $title = null) {
    if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [false, 'File Upload अयशस्वी झाली.'];
    }
    $allowed = ['pdf' => ['application/pdf'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp']];
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) { return [false, 'फक्त PDF किंवा प्रतिमा (JPG/PNG/WEBP) चालतील.']; }
    if (($file['size'] ?? 0) > 10 * 1024 * 1024) { return [false, 'File 10 MB पेक्षा मोठी आहे.']; }
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($fi, $file['tmp_name']);
        finfo_close($fi);
        if (!in_array($mime, $allowed[$ext], true)) { return [false, 'File चा प्रकार (' . $mime . ') स्वीकारार्ह नाही.']; }
    }

    $dir = renewalAttachmentDir();
    $safeFilename = 'ren-' . (int)$item['id'] . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $moved = is_uploaded_file($file['tmp_name']) ? move_uploaded_file($file['tmp_name'], $dir . '/' . $safeFilename) : false;
    if (!$moved) { return [false, 'File साठवता आली नाही.']; }

    $pdo->prepare("INSERT INTO renewal_attachments (renewal_item_id, organization_id, cycle_expiry_date, title, file_path, original_filename, file_size, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$item['id'], $item['organization_id'], $cycleExpiry ?: null, $title ?: null, 'assets/uploads/renewals/' . $safeFilename, mb_substr($file['name'], 0, 255), (int)$file['size'], $userId]);
    return [true, (int)$pdo->lastInsertId()];
}

/** एका नोंदीच्या सगळ्या Attachments — Cycle (मुदत-दिनांक) नुसार गटबद्ध */
function renewalAttachmentsByCycle($pdo, $itemId) {
    $out = [];
    try {
        $stmt = $pdo->prepare("SELECT a.*, u.name AS uploaded_by_name FROM renewal_attachments a LEFT JOIN users u ON u.id = a.uploaded_by WHERE a.renewal_item_id = ? ORDER BY a.cycle_expiry_date DESC, a.id DESC");
        $stmt->execute([$itemId]);
        foreach ($stmt->fetchAll() as $a) { $out[$a['cycle_expiry_date'] ?? ''][] = $a; }
    } catch (PDOException $e) { /* migration not run yet */ }
    return $out;
}

function renewalTypeIconFor($type) {
    $type = mb_strtolower((string)$type);
    if (str_contains($type, 'insurance') || str_contains($type, 'विमा')) return '🛡️';
    if (str_contains($type, 'puc')) return '🌫️';
    if (str_contains($type, 'license') || str_contains($type, 'परवाना')) return '📜';
    if (str_contains($type, 'permit')) return '📋';
    if (str_contains($type, 'fitness')) return '✅';
    if (str_contains($type, 'tax') || str_contains($type, 'कर')) return '💰';
    if (str_contains($type, 'amc') || str_contains($type, 'contract') || str_contains($type, 'करार')) return '🔧';
    if (str_contains($type, 'warranty')) return '🛠️';
    if (str_contains($type, 'domain') || str_contains($type, 'hosting') || str_contains($type, 'software')) return '💻';
    return '📄';
}
