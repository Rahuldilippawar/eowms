<?php
/**
 * 🖥️ Computer Lab — Machine-निहाय रोजची तपासणी (Screen / CPU / Keyboard / Mouse / Power / Internet)
 * व एकाच वेळी अनेक Machines जोडणं. it/lab.php वापरतं.
 */
require_once __DIR__ . '/it-helpers.php';

const IT_LAB_PARTS = ['screen' => ['🖥️', 'Screen'], 'cpu' => ['💻', 'CPU'], 'keyboard' => ['⌨️', 'Keyboard'], 'mouse' => ['🖱️', 'Mouse']];

/** Migration झालं आहे का (it_machine_checks Table + it_assets चे नवीन Columns) */
function itLabChecksReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query("SELECT 1 FROM it_machine_checks LIMIT 0");
        $pdo->query("SELECT screen_status, internet_status, last_check_at FROM it_assets LIMIT 0");
        return $ok = true;
    } catch (PDOException $e) { return $ok = false; }
}

/** Lab तपासणी/नोंदी करू शकतो का — IT व्यवस्थापन, Management, IT विभाग किंवा त्या Lab चा Assistant */
function itCanCheckLab(PDO $pdo, array $user, $labId) {
    return canManageLabAssets($pdo, $user, $labId) || isItDeptStaff($pdo, $user);
}

/** एकाच वेळी अनेक Machines जोडू शकतो का — Super Admin / IT व्यवस्थापन */
function itCanBulkAdd() {
    return hasRole('super_admin') || hasPermission('manage_it');
}

/** Machine ची एकूण स्थिती: ok / warn / fault / unknown */
function itMachineHealth(array $a) {
    if (empty($a['last_check_at'])) return 'unknown';
    foreach (array_keys(IT_LAB_PARTS) as $p) { if (($a[$p . '_status'] ?? '') === 'fault') return 'fault'; }
    if (($a['status'] ?? '') === 'faulty' || ($a['status'] ?? '') === 'under_repair') return 'fault';
    if (($a['internet_status'] ?? '') === 'down') return 'fault';
    if (($a['internet_status'] ?? '') === 'slow') return 'warn';
    return 'ok';
}

/**
 * एका Machine ची आजची तपासणी जतन (त्याच दिवशी पुन्हा केली तर बदलते) + Asset वर ताजी स्थिती.
 * $openTicket = true आणि बिघाड असेल तर (आधी उघडं Ticket नसल्यास) दुरुस्ती Ticket उघडतो.
 * परत: ['ok' => bool, 'msg' => string, 'ticket' => id|null]
 */
function itSaveMachineCheck(PDO $pdo, $orgId, array $asset, array $in, $userId, $openTicket = false) {
    $v = [
        'power' => ($in['power'] ?? 'on') === 'off' ? 'off' : 'on',
        'internet' => in_array($in['internet'] ?? '', ['ok', 'slow', 'down'], true) ? $in['internet'] : 'ok',
    ];
    foreach (array_keys(IT_LAB_PARTS) as $p) { $v[$p] = ($in[$p] ?? 'ok') === 'fault' ? 'fault' : 'ok'; }
    $remarks = mb_substr(trim((string)($in['remarks'] ?? '')), 0, 255) ?: null;
    $now = date('Y-m-d H:i:s');

    $pdo->prepare("INSERT INTO it_machine_checks (organization_id, lab_id, asset_id, check_date, power, screen, cpu, keyboard, mouse, internet, remarks, checked_by, checked_at)
        VALUES (?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE power = VALUES(power), screen = VALUES(screen), cpu = VALUES(cpu), keyboard = VALUES(keyboard), mouse = VALUES(mouse),
          internet = VALUES(internet), remarks = VALUES(remarks), checked_by = VALUES(checked_by), checked_at = VALUES(checked_at)")
        ->execute([$orgId, $asset['lab_id'], $asset['id'], $v['power'], $v['screen'], $v['cpu'], $v['keyboard'], $v['mouse'], $v['internet'], $remarks, $userId, $now]);

    $faults = array_keys(array_filter(array_intersect_key($v, IT_LAB_PARTS), fn($s) => $s === 'fault'));
    // बिघाड असेल तर Asset "faulty"; सगळं ठीक आणि आधी faulty (repair नाही) असेल तर पुन्हा "working"
    $statusSql = $faults ? ", status = IF(status = 'working', 'faulty', status)" : ", status = IF(status = 'faulty', 'working', status)";
    $pdo->prepare("UPDATE it_assets SET power_status = ?, power_status_updated_at = ?, power_status_updated_by = ?,
            screen_status = ?, cpu_status = ?, keyboard_status = ?, mouse_status = ?, internet_status = ?, last_check_at = ?, last_check_by = ?$statusSql
        WHERE id = ? AND organization_id = ?")
        ->execute([$v['power'], $now, $userId, $v['screen'], $v['cpu'], $v['keyboard'], $v['mouse'], $v['internet'], $now, $userId, $asset['id'], $orgId]);

    $ticketId = null;
    if ($openTicket && ($faults || $v['internet'] === 'down')) {
        $open = $pdo->prepare("SELECT id FROM it_repair_tickets WHERE asset_id = ? AND status IN ('pending','in_progress') LIMIT 1");
        $open->execute([$asset['id']]);
        $ticketId = $open->fetchColumn() ?: null;
        if (!$ticketId) {
            $parts = array_map(fn($p) => IT_LAB_PARTS[$p][1], $faults);
            if ($v['internet'] === 'down') $parts[] = 'Internet';
            $desc = implode(', ', $parts) . ' बिघाड (Lab तपासणी)' . ($remarks ? ' — ' . $remarks : '');
            $pdo->prepare("INSERT INTO it_repair_tickets (organization_id, asset_id, lab_id, issue_description, reported_by) VALUES (?, ?, ?, ?, ?)")
                ->execute([$orgId, $asset['id'], $asset['lab_id'], $desc, $userId]);
            $ticketId = (int)$pdo->lastInsertId();
            $hod = $pdo->prepare("SELECT hod_id FROM departments WHERE organization_id = ? AND code = 'IT'");
            $hod->execute([$orgId]);
            $hodId = $hod->fetchColumn();
            if ($hodId && function_exists('notifyUser')) {
                notifyUser($hodId, $orgId, 'नवीन IT तक्रार', ($asset['workstation_no'] ?: $asset['asset_tag']) . ' (' . ($asset['lab_name'] ?? 'Lab') . ') — ' . $desc, 'it/general-requests-manage.php?assets=1');
            }
            if (function_exists('logActivity')) logActivity('IT तक्रार नोंदवली (Lab तपासणी)', 'it_repair_ticket', $ticketId, $asset['asset_tag']);
        }
    }
    return ['ok' => true, 'msg' => 'जतन झालं', 'ticket' => $ticketId, 'values' => $v, 'at' => $now];
}

/**
 * एकाच वेळी N Computers जोडा — Workstation क्रमांक प्रत्यय + क्रमाने (उदा. PC-01…PC-40)
 * परत: जोडलेल्यांची संख्या; आधीच असलेले Workstation क्रमांक वगळतो.
 */
function itBulkAddMachines(PDO $pdo, $orgId, $labId, array $in, $userId) {
    $count = max(1, min(200, (int)($in['count'] ?? 0)));
    $prefix = mb_substr(trim((string)($in['prefix'] ?? 'PC-')), 0, 20);
    $start = max(0, (int)($in['start'] ?? 1));
    $pad = max(1, min(4, (int)($in['pad'] ?? 2)));
    $type = in_array($in['asset_type'] ?? 'computer', ['computer', 'cpu', 'monitor', 'smartboard', 'projector', 'printer', 'ups', 'other'], true) ? $in['asset_type'] : 'computer';
    $brand = mb_substr(trim((string)($in['brand'] ?? '')), 0, 100) ?: null;
    $model = mb_substr(trim((string)($in['model'] ?? '')), 0, 100) ?: null;
    $pDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['purchase_date'] ?? '') ? $in['purchase_date'] : null;
    $warranty = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['warranty_expiry'] ?? '') ? $in['warranty_expiry'] : null;
    $cost = ($in['purchase_cost'] ?? '') !== '' ? max(0, (float)$in['purchase_cost']) : null;
    $os = mb_substr(trim((string)($in['os_license'] ?? '')), 0, 150) ?: null;

    $have = $pdo->prepare("SELECT workstation_no FROM it_assets WHERE lab_id = ? AND workstation_no IS NOT NULL");
    $have->execute([$labId]);
    $existing = array_flip(array_map('mb_strtolower', $have->fetchAll(PDO::FETCH_COLUMN)));
    $ins = $pdo->prepare("INSERT INTO it_assets (organization_id, lab_id, workstation_no, asset_tag, asset_type, brand, model, purchase_date, purchase_cost, warranty_expiry, os_license, added_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $added = 0; $skipped = 0;
    $pdo->beginTransaction();
    try {
        for ($i = 0; $i < $count; $i++) {
            $ws = $prefix . str_pad((string)($start + $i), $pad, '0', STR_PAD_LEFT);
            if (isset($existing[mb_strtolower($ws)])) { $skipped++; continue; }
            $tag = itGenerateAssetTag($pdo, $orgId, $type);
            $ins->execute([$orgId, $labId, $ws, $tag, $type, $brand, $model, $pDate, $cost, $warranty, $os, $userId]);
            if ($warranty) { syncItAssetWarranty($pdo, (int)$pdo->lastInsertId(), $orgId, $labId, $tag, $type, $warranty); }
            $added++;
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return [$added, $skipped];
}
