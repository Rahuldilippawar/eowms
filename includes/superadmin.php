<?php
/**
 * 🛡️ Super Admin साधनं — दर Request चे Hooks (requireLogin मधून) आणि सामायिक Helpers
 *  - "या व्यक्तीच्या नजरेतून पाहा" (View as) — फक्त पाहणं; कोणताही बदल (POST) अडवला जातो
 *  - Active Sessions — कोण कुठे Login आहे; Super Admin ने काढलेलं Session पुढच्या Click ला Logout
 *  - Module वापर — दिवसागणिक मोजणी
 *  - Recycle Bin — हटवण्याआधी नोंदीची प्रत (30 दिवस परत आणता येते)
 */

const SA_RECYCLE_DAYS = 30;

/** Table आहे का (Database Update आधी काहीच तुटू नये) — Request पुरतं लक्षात */
function saTableReady(PDO $pdo, $table) {
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        try { $pdo->query("SELECT 1 FROM `$table` LIMIT 0"); $cache[$table] = true; } catch (PDOException $e) { $cache[$table] = false; }
    }
    return $cache[$table];
}

/* ======================= 👁️ View as ======================= */

function saViewingAs() { return !empty($_SESSION['sa_view_as']); }

/** खरा Login (Super Admin) — View as चालू असताना */
function saRealUser() { return $_SESSION['sa_view_as']['admin'] ?? currentUser(); }

/** Super Admin → $target च्या नजरेतून (फक्त पाहणं) */
function saViewAsStart(PDO $pdo, array $target) {
    $admin = currentUser();
    if (!$admin || ($admin['role'] ?? '') !== 'super_admin' || saViewingAs()) { return false; }
    if (($target['role'] ?? '') === 'super_admin' || !(int)$target['is_active']) { return false; }
    if ((int)$target['organization_id'] !== (int)activeOrgId()) { return false; }
    logActivity('View as सुरू — ' . $target['name'] . ' च्या नजरेतून पाहिलं', 'user', (int)$target['id'], 'फक्त पाहणं (Read-only)');
    unset($target['password'], $target['tfa_secret'], $target['tfa_recovery'], $target['tfa_last_step']);
    $_SESSION['sa_view_as'] = ['admin' => $admin, 'admin_org' => $_SESSION['active_org_id'] ?? null, 'target_id' => (int)$target['id'], 'target_name' => $target['name'], 'started' => time()];
    $_SESSION['user'] = $target;
    unset($_SESSION['user_recheck_at']);
    return true;
}

function saViewAsStop() {
    if (!saViewingAs()) { return; }
    $v = $_SESSION['sa_view_as'];
    $_SESSION['user'] = $v['admin'];
    if ($v['admin_org'] !== null) { $_SESSION['active_org_id'] = $v['admin_org']; }
    unset($_SESSION['sa_view_as'], $_SESSION['user_recheck_at']);
    logActivity('View as बंद — ' . $v['target_name'], 'user', $v['target_id'], round((time() - $v['started']) / 60, 1) . ' मिनिटं');
}

/* ======================= 🔌 दर Request (requireLogin मधून) ======================= */

function saRequestHooks($rootPath = '') {
    global $pdo;
    if (PHP_SAPI === 'cli' || !($pdo instanceof PDO) || !currentUser()) { return; }
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

    // 👁️ View as — काहीही बदलू देत नाही
    if (saViewingAs()) {
        $isStop = substr($script, -strlen('admin/view-as.php')) === 'admin/view-as.php';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$isStop) {
            http_response_code(403);
            $msg = 'तुम्ही "' . ($_SESSION['sa_view_as']['target_name'] ?? '') . '" च्या नजरेतून फक्त पाहत आहात — इथे काहीही बदलता/जतन करता येत नाही.';
            $ajax = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'json') !== false || !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'json') !== false;
            if ($ajax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'error' => $msg]); exit; }
            echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><div style="font-family:system-ui,sans-serif;max-width:520px;margin:60px auto;padding:28px;border:1px solid #EAE5DF;border-radius:16px;text-align:center;">'
                . '<div style="font-size:40px;">👁️</div><h2 style="font-size:19px;">फक्त पाहण्यासाठी</h2><p style="color:#475069;">' . htmlspecialchars($msg) . '</p>'
                . '<a href="javascript:history.back()" style="display:inline-block;margin-top:8px;background:#D9530F;color:#fff;padding:9px 20px;border-radius:9px;text-decoration:none;">← मागे</a></div>';
            exit;
        }
        return;   // View as मध्ये — Session/वापर मोजणी Super Admin च्या नावाने करत नाही
    }

    // 🔐 Session नोंद / Force Logout तपासणी (दर ~60 सेकंदांनी एकदा)
    if (saTableReady($pdo, 'user_sessions') && session_id() !== '') {
        $hash = hash('sha256', session_id());
        $u = currentUser();
        if (($_SESSION['sa_sid'] ?? '') !== $hash) {
            try {
                $pdo->prepare("INSERT INTO user_sessions (user_id, organization_id, session_hash, ip, user_agent, created_at, last_seen) VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE last_seen = NOW()")
                    ->execute([(int)$u['id'], $u['organization_id'] ?? null, $hash, function_exists('clientIp') ? clientIp() : ($_SERVER['REMOTE_ADDR'] ?? null), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
                $_SESSION['sa_sid'] = $hash; $_SESSION['sa_sid_at'] = time();
            } catch (PDOException $e) {}
        } elseif (time() - (int)($_SESSION['sa_sid_at'] ?? 0) >= 60) {
            try {
                $st = $pdo->prepare("SELECT ended_at, revoked_by FROM user_sessions WHERE session_hash = ?");
                $st->execute([$hash]);
                $row = $st->fetch();
                if ($row && $row['ended_at'] !== null && $row['revoked_by'] !== null) {
                    session_unset(); session_destroy(); session_start();
                    $_SESSION['force_logged_out'] = true;
                    header('Location: ' . $rootPath . 'login.php');
                    exit;
                }
                $pdo->prepare("UPDATE user_sessions SET last_seen = NOW() WHERE session_hash = ?")->execute([$hash]);
                $_SESSION['sa_sid_at'] = time();
            } catch (PDOException $e) {}
        }
    }

    // 📊 Module वापर (फक्त पानं — AJAX/POST नाही)
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_SERVER['HTTP_X_REQUESTED_WITH']) && saTableReady($pdo, 'module_usage_daily')) {
        $dir = basename(dirname($script));
        $module = ($dir === '' || $dir === '.' || $dir === '/') ? 'dashboard' : $dir;
        if (preg_match('/^[a-z0-9\-]{2,40}$/', $module)) {
            $u = currentUser();
            try {
                $pdo->prepare("INSERT INTO module_usage_daily (user_id, organization_id, module, day, hits) VALUES (?, ?, ?, CURDATE(), 1) ON DUPLICATE KEY UPDATE hits = hits + 1")
                    ->execute([(int)$u['id'], (int)($u['organization_id'] ?? 0), $module]);
            } catch (PDOException $e) {}
        }
    }
}

/** Logout — हे Session संपलं म्हणून नोंद */
function saSessionEnd() {
    global $pdo;
    if (!($pdo instanceof PDO) || session_id() === '' || !saTableReady($pdo, 'user_sessions')) { return; }
    try { $pdo->prepare("UPDATE user_sessions SET ended_at = NOW() WHERE session_hash = ? AND ended_at IS NULL")->execute([hash('sha256', session_id())]); } catch (PDOException $e) {}
}

/** Session "चालू" मानायचं — गेल्या किती मिनिटांत हालचाल */
function saActiveWindowMinutes() { return 120; }

/* ======================= 🗑️ Recycle Bin ======================= */

/** $table मधील $id ला थेट (ON DELETE CASCADE) जोडलेल्या Tables: [[table, column], …] */
function saCascadeChildren(PDO $pdo, $table) {
    try {
        $st = $pdo->prepare("SELECT k.TABLE_NAME t, k.COLUMN_NAME c FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
            WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = ? AND k.REFERENCED_COLUMN_NAME = 'id' AND r.DELETE_RULE = 'CASCADE'");
        $st->execute([$table]);
        return array_map(fn($r) => [$r['t'], $r['c']], $st->fetchAll());
    } catch (PDOException $e) { return []; }
}

/**
 * हटवण्याआधी प्रत ठेवा (नोंद + थेट जोडलेल्या नोंदी). Table नसेल / त्रुटी आली तर शांतपणे — हटवणं थांबत नाही.
 * @return int|null recycle_bin id
 */
function recycleCapture(PDO $pdo, $table, $id, $label = null, $orgId = null) {
    if (!preg_match('/^\w+$/', $table) || !saTableReady($pdo, 'recycle_bin')) { return null; }
    try {
        $st = $pdo->prepare("SELECT * FROM `$table` WHERE id = ?");
        $st->execute([(int)$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { return null; }
        if ($label === null) {   // ओळखण्याजोगं नाव Row मधूनच
            foreach (['name', 'title', 'item_name', 'vehicle_number', 'subject', 'name_local', 'quote_text', 'duty_title', 'from_location'] as $k) {
                if (!empty($row[$k])) { $label = mb_strimwidth((string)$row[$k], 0, 120, '…'); break; }
            }
        }
        $children = [];
        foreach (saCascadeChildren($pdo, $table) as [$ct, $cc]) {
            $c = $pdo->prepare("SELECT * FROM `$ct` WHERE `$cc` = ? LIMIT 2000");
            $c->execute([(int)$id]);
            $rows = $c->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) { $children[] = ['table' => $ct, 'rows' => $rows]; }
        }
        $payload = json_encode(['row' => $row, 'children' => $children], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false || strlen($payload) > 8000000) { return null; }
        $u = function_exists('saRealUser') ? saRealUser() : currentUser();
        $pdo->prepare("INSERT INTO recycle_bin (organization_id, entity_table, entity_id, label, payload, deleted_by, deleted_at) VALUES (?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$orgId ?? ($row['organization_id'] ?? (function_exists('activeOrgId') ? activeOrgId() : null)), $table, (int)$id, $label ? mb_substr($label, 0, 255) : null, $payload, $u['id'] ?? null]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) { return null; }
}

/** परत आणा — [ok(bool), संदेश] */
function recycleRestore(PDO $pdo, $binId, $orgId) {
    $st = $pdo->prepare("SELECT * FROM recycle_bin WHERE id = ? AND organization_id = ? AND restored_at IS NULL");
    $st->execute([(int)$binId, (int)$orgId]);
    $bin = $st->fetch();
    if (!$bin) { return [false, 'नोंद सापडली नाही (किंवा आधीच परत आणली).']; }
    $data = json_decode($bin['payload'], true);
    if (!$data || empty($data['row'])) { return [false, 'प्रत वाचता आली नाही.']; }
    $ins = function ($table, array $row) use ($pdo) {
        $cols = array_keys($row);
        $pdo->prepare("INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")->execute(array_values($row));
    };
    $pdo->beginTransaction();
    try {
        $ins($bin['entity_table'], $data['row']);
        $skipped = 0;
        foreach ($data['children'] ?? [] as $grp) {
            foreach ($grp['rows'] as $r) { try { $ins($grp['table'], $r); } catch (PDOException $e) { $skipped++; } }
        }
        $u = saRealUser();
        $pdo->prepare("UPDATE recycle_bin SET restored_at = NOW(), restored_by = ? WHERE id = ?")->execute([$u['id'] ?? null, (int)$binId]);
        $pdo->commit();
        logActivity('Recycle Bin मधून परत आणलं', $bin['entity_table'], (int)$bin['entity_id'], $bin['label']);
        return [true, '"' . ($bin['label'] ?: $bin['entity_table'] . ' #' . $bin['entity_id']) . '" परत आणलं' . ($skipped ? " ($skipped जोडलेल्या नोंदी परत घालता आल्या नाहीत)" : '') . '.'];
    } catch (PDOException $e) {
        $pdo->rollBack();
        $why = (int)($e->errorInfo[1] ?? 0) === 1062 ? 'याच ID / नावाची नोंद आधीच परत आहे'
            : ((int)($e->errorInfo[1] ?? 0) === 1452 ? 'ज्याच्याशी ही नोंद जोडलेली होती (उदा. Campus/विभाग) ते आता नाही' : 'Database त्रुटी');
        return [false, 'परत आणता आलं नाही — ' . $why . '.'];
    }
}

/** 30 दिवसांपेक्षा जुन्या प्रती कायमच्या काढा */
function recyclePurgeOld(PDO $pdo) {
    if (!saTableReady($pdo, 'recycle_bin')) { return; }
    try { $pdo->exec("DELETE FROM recycle_bin WHERE deleted_at < DATE_SUB(NOW(), INTERVAL " . SA_RECYCLE_DAYS . " DAY)"); } catch (PDOException $e) {}
}

/** Super Admin साधनांचा वरचा Nav (सगळ्या admin/ पानांवर) */
function saNav($active) {
    $items = ['index' => ['🛡️', 'Control Center'], 'sessions' => ['🔐', 'Active Sessions'], 'security' => ['🚨', 'सुरक्षा इशारे'], 'email-log' => ['✉️', 'Email नोंद'],
        'broadcast' => ['📢', 'Broadcast'], 'usage' => ['📊', 'Module वापर'], 'data-quality' => ['🗂️', 'Data दर्जा'], 'recycle-bin' => ['🗑️', 'Recycle Bin'], 'view-as' => ['👁️', 'View as']];
    $h = '<div class="sa-nav">';
    foreach ($items as $k => [$i, $l]) { $h .= '<a href="' . $k . '.php" class="' . ($k === $active ? 'on' : '') . '">' . $i . ' ' . $l . '</a>'; }
    return $h . '</div><style>.sa-nav{display:flex;gap:6px;flex-wrap:wrap;margin:4px 0 16px;}.sa-nav a{border:1px solid var(--line);border-radius:999px;padding:5px 13px;font-size:12.5px;color:var(--text);text-decoration:none;background:var(--panel,#fff);}.sa-nav a.on{background:var(--rail);border-color:var(--rail);color:#fff;}.sa-nav a:hover{border-color:var(--rail);}</style>';
}
