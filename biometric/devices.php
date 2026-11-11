<?php
// 🖥️ Biometric Machines — मंजुरी, माहिती, Online/Offline, Remote आदेश, Setup मार्गदर्शन (फक्त Super Admin)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/_common.php';
$user = currentUser();
$orgId = activeOrgId();
$ready = bioReady($pdo);
if ($ready) { bioEnsureV2Reset($pdo); }

$back = function ($msg, $err = false) { $_SESSION[$err ? 'form_error' : 'flash'] = $msg; header('Location: devices.php'); exit; };
$ownDevice = function ($id) use ($pdo, $orgId) {
    $st = $pdo->prepare("SELECT * FROM bio_devices WHERE id = ? AND (organization_id = ? OR status = 'pending')");
    $st->execute([(int)$id, $orgId]);
    return $st->fetch();
};

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $dev = $ownDevice($_POST['device_id'] ?? 0);
    if ($action === 'notify_toggle') {
        orgSettingSet($pdo, $orgId, 'bio_punch_notify', !empty($_POST['on']) ? '1' : '0');
        $back(!empty($_POST['on']) ? 'Punch झाल्यावर कर्मचाऱ्याला सूचना — चालू.' : 'Punch सूचना बंद केल्या.');
    }
    if (!$dev) { $back('Machine सापडली नाही.', true); }
    $name = mb_substr(trim($_POST['device_name'] ?? ''), 0, 150);
    $loc = mb_substr(trim($_POST['location'] ?? ''), 0, 150) ?: null;
    $campus = ($_POST['campus_id'] ?? '') !== '' ? (int)$_POST['campus_id'] : null;
    if ($campus) { $c = $pdo->prepare("SELECT id FROM campuses WHERE id = ? AND organization_id = ?"); $c->execute([$campus, $orgId]); if (!$c->fetchColumn()) { $campus = null; } }

    if ($action === 'approve' && $dev['status'] === 'pending') {
        $pdo->prepare("UPDATE bio_devices SET status = 'active', organization_id = ?, campus_id = ?, device_name = ?, location = ?, approved_by = ?, approved_at = NOW() WHERE id = ?")
            ->execute([$orgId, $campus, $name ?: 'Biometric Machine', $loc, $user['id'], $dev['id']]);
        // आधीचे (प्रलंबित असतानाचे) Punches — आता कर्मचाऱ्यांशी जोडून हिशोब; Machine वरचे कर्मचारी व माहिती मागवतो
        bioProcessPending($pdo);
        bioQueueCommand($pdo, $dev['id'], 'INFO', 'Machine ची माहिती', $user['id']);
        logActivity('Biometric Machine मंजूर — ' . $dev['serial_number'], 'bio_device', $dev['id']);
        $back('Machine मंजूर केली — आता तिचे Punches हजेरीत धरले जातील. पुढची पायरी: PIN जोडणी.');
    }
    if ($action === 'reject' && $dev['status'] === 'pending') {
        $pdo->prepare("DELETE FROM bio_devices WHERE id = ? AND status = 'pending'")->execute([$dev['id']]);
        $back('प्रलंबित Machine ची नोंद (व तिचे Punches) काढली.');
    }
    if ($dev['status'] === 'pending') { $back('आधी Machine मंजूर करा.', true); }
    switch ($action) {
        case 'save':
            $pdo->prepare("UPDATE bio_devices SET device_name = ?, location = ?, campus_id = ? WHERE id = ?")->execute([$name ?: $dev['device_name'], $loc, $campus, $dev['id']]);
            $back('Machine ची माहिती जतन झाली.');
        case 'disable':
        case 'enable':
            $pdo->prepare("UPDATE bio_devices SET status = ? WHERE id = ?")->execute([$action === 'disable' ? 'disabled' : 'active', $dev['id']]);
            logActivity('Biometric Machine ' . ($action === 'disable' ? 'बंद' : 'चालू') . ' — ' . $dev['serial_number'], 'bio_device', $dev['id']);
            $back($action === 'disable' ? 'Machine बंद केली — तिचे Punches आता हजेरीत धरले जाणार नाहीत.' : 'Machine पुन्हा चालू केली.');
        case 'delete':
            if (trim($_POST['confirm'] ?? '') !== $dev['serial_number']) { $back('हटवण्यासाठी Machine चा Serial Number अचूक टाइप करा.', true); }
            $pdo->prepare("DELETE FROM bio_devices WHERE id = ?")->execute([$dev['id']]);
            logActivity('Biometric Machine हटवली — ' . $dev['serial_number'], 'bio_device', $dev['id']);
            $back('Machine व तिचे सगळे Raw Punches काढले (आधीची हजेरी तशीच राहते).');
        case 'cmd':
            $cmd = $_POST['cmd'] ?? '';
            if ($cmd === 'sync_users') {
                $st = $pdo->prepare("SELECT biometric_pin, name, employee_code FROM users WHERE organization_id = ? AND is_active = 1 AND biometric_pin IS NOT NULL AND biometric_pin <> '' ORDER BY biometric_pin LIMIT 2000");
                $st->execute([$orgId]);
                $n = 0;
                foreach ($st->fetchAll() as $u) { bioQueueCommand($pdo, $dev['id'], bioUserCommand($u['biometric_pin'], $u['name'], $u['employee_code']), 'कर्मचारी पाठवला — PIN ' . $u['biometric_pin'], $user['id']); $n++; }
                $back($n ? "$n कर्मचारी (नाव + PIN) Machine कडे पाठवण्यासाठी रांगेत — Machine वर बोट/चेहरा नोंदवायचा बाकी असेल तर Machine वरच नोंदवा." : 'PIN दिलेले कर्मचारी नाहीत — आधी PIN जोडणी पानावर PIN द्या.', !$n);
            }
            if ($cmd === 'pull_all') {
                $pdo->prepare("UPDATE bio_devices SET att_stamp = NULL, op_stamp = NULL WHERE id = ?")->execute([$dev['id']]);
                bioQueueCommand($pdo, $dev['id'], 'CHECK', 'सगळे Logs पुन्हा पाठव', $user['id']);
                $back('Machine ला सगळे जुने Logs व कर्मचारी पुन्हा पाठवायला सांगितलं — आधीच आलेले Punches दुप्पट होत नाहीत.');
            }
            if ($cmd === 'pull_days') {
                $days = max(1, min(90, (int)($_POST['days'] ?? 7)));
                bioQueueCommand($pdo, $dev['id'], "DATA QUERY ATTLOG StartTime=" . date('Y-m-d 00:00:00', strtotime("-$days days")) . "\tEndTime=" . date('Y-m-d 23:59:59'), "मागचे $days दिवसांचे Logs", $user['id']);
                $back("मागच्या $days दिवसांचे Logs मागवले (नवीन Firmware साठी; जुन्या Machine ला 'सगळे Logs पुन्हा' वापरा).");
            }
            if ($cmd === 'info') { bioQueueCommand($pdo, $dev['id'], 'INFO', 'Machine ची माहिती', $user['id']); $back('Machine ची माहिती मागवली.'); }
            if ($cmd === 'reboot') { bioQueueCommand($pdo, $dev['id'], 'REBOOT', 'Restart (वेळ पुन्हा Sync)', $user['id']); $back('Restart चा आदेश रांगेत — Machine सुरू झाल्यावर Server ची वेळ पुन्हा घेते.'); }
            if ($cmd === 'delete_user') {
                $pin = trim($_POST['pin'] ?? '');
                if (!preg_match('/^[A-Za-z0-9]{1,20}$/', $pin)) { $back('PIN अवैध.', true); }
                bioQueueCommand($pdo, $dev['id'], "DATA DELETE USERINFO PIN=$pin", "Machine वरून कर्मचारी काढला — PIN $pin", $user['id']);
                $pdo->prepare("DELETE FROM bio_device_users WHERE device_id = ? AND pin = ?")->execute([$dev['id'], $pin]);
                $back("PIN $pin Machine वरून काढण्याचा आदेश रांगेत.");
            }
            $back('अवैध आदेश.', true);
    }
    $back('अवैध कृती.', true);
}

$devices = $pending = $cmds = [];
$campuses = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campuses->execute([$orgId]);
$campuses = $campuses->fetchAll();
if ($ready) {
    $pending = $pdo->query("SELECT d.*, (SELECT COUNT(*) FROM bio_punches p WHERE p.device_id = d.id) punches FROM bio_devices d WHERE d.status = 'pending' ORDER BY d.last_seen_at DESC")->fetchAll();
    $st = $pdo->prepare("SELECT d.*, c.name campus,
            (SELECT COUNT(*) FROM bio_punches p WHERE p.device_id = d.id AND p.punch_time >= CURDATE()) today,
            (SELECT COUNT(*) FROM bio_device_users du WHERE du.device_id = d.id) dusers,
            (SELECT COUNT(*) FROM bio_commands bc WHERE bc.device_id = d.id AND bc.status IN ('queued','sent')) qcmd
        FROM bio_devices d LEFT JOIN campuses c ON c.id = d.campus_id WHERE d.organization_id = ? AND d.status <> 'pending' ORDER BY d.status, d.device_name");
    $st->execute([$orgId]);
    $devices = $st->fetchAll();
    $st = $pdo->prepare("SELECT bc.*, d.device_name, u.name byname FROM bio_commands bc JOIN bio_devices d ON d.id = bc.device_id LEFT JOIN users u ON u.id = bc.created_by
        WHERE d.organization_id = ? ORDER BY bc.id DESC LIMIT 25");
    $st->execute([$orgId]);
    $cmds = $st->fetchAll();
}
$notifyOn = orgSetting($pdo, $orgId, 'bio_punch_notify', '1') === '1';

$pageTitle = 'Biometric Machines';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">🖐️ Biometric — Machines</div>
<?= bioTabs('devices') ?>
<?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?><div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div><?php unset($_SESSION['form_error']); endif; ?>
<?php if (!$ready): ?><div class="alert alert-warning">⚠ System Update → Database Update चालवा — मग Biometric v2 सुरू होईल.</div><?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<details class="panel mb-3" <?= !$devices && !$pending ? 'open' : '' ?>>
  <summary style="padding:13px 18px;cursor:pointer;font-weight:600;">⚙️ नवीन eSSL Machine कशी जोडायची?</summary>
  <div style="padding:0 20px 16px;font-size:13px;line-height:1.9;">
    <b>1.</b> Machine वर <b>Menu → Comm. (COMM) → Cloud Server Setting / ADMS</b> उघडा:
    <div style="background:var(--canvas);border-radius:10px;padding:10px 14px;margin:6px 0;font-family:monospace;font-size:13px;">
      Enable Domain Name: <b>ON</b><br>Server Address: <b><?= h(bioServerHost()) ?></b><br>Server Port: <b>80</b><br>Enable Proxy Server: <b>OFF</b>
    </div>
    (काही Models मध्ये पूर्ण पत्ता मागतात: <code>http://<?= h(bioServerHost()) ?>/iclock/cdata</code>) — Machine ला LAN/WiFi ने Internet हवं.<br>
    <b>2.</b> 1–2 मिनिटांत Machine इथे <b>"मंजुरी-प्रलंबित"</b> मध्ये दिसेल — नाव/ठिकाण देऊन <b>मंजूर करा</b>.<br>
    <b>3.</b> <a href="pins.php">PIN जोडणी</a> पानावर Machine वरचे कर्मचारी आपल्या कर्मचाऱ्यांशी जोडा (नावावरून आपोआप जोडण्याची सोय आहे).<br>
    <b>4.</b> झालं — Punch होताच हजेरी, उशीर/Overtime हिशोब व कर्मचाऱ्याला सूचना आपोआप. Hostinger मध्ये <b>Force HTTPS चालू करू नका</b> (Machine फक्त HTTP बोलते).
  </div>
</details>

<?php if ($pending): ?>
<div class="panel mb-3" style="border-color:#F59E0B;">
  <div class="panel-head"><div class="title"><span class="bio-dot pend"></span>मंजुरी-प्रलंबित Machines (<?= count($pending) ?>)</div><div class="sub">Punches जपून ठेवले आहेत — मंजूर केल्यावर हजेरीत धरले जातील</div></div>
  <?php foreach ($pending as $d): ?>
    <form method="POST" class="d-flex flex-wrap gap-2 align-items-end" style="padding:12px 18px;border-top:1px solid var(--line);">
      <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>">
      <div style="min-width:180px;"><div style="font-size:11px;color:var(--text-dim);">Serial Number</div><b class="font-mono"><?= h($d['serial_number']) ?></b>
        <div style="font-size:11px;color:var(--text-dim);">IP <?= h($d['ip_address'] ?: '—') ?> · <?= (int)$d['punches'] ?> Punches · <?= h(bioAgo($d['last_seen_at'])) ?></div></div>
      <div><label class="form-label-custom" style="font-size:11px;">नाव</label><input name="device_name" class="form-control-custom" placeholder="उदा. मुख्य गेट" required></div>
      <div><label class="form-label-custom" style="font-size:11px;">ठिकाण</label><input name="location" class="form-control-custom" placeholder="उदा. Trust Office"></div>
      <div><label class="form-label-custom" style="font-size:11px;">Campus</label><select name="campus_id" class="form-select-custom"><option value="">—</option><?php foreach ($campuses as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
      <button name="action" value="approve" class="btn" style="background:#16A34A;color:#fff;border-radius:9px;">✓ मंजूर करा</button>
      <button name="action" value="reject" formnovalidate class="btn" style="border:1px solid var(--rose);color:var(--rose);border-radius:9px;" onclick="return confirm('ही Machine व तिचे Punches काढायचे?');">✕ नाकारा</button>
    </form>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div><div class="title">🖥️ मंजूर Machines (<?= count($devices) ?>)</div><div class="sub">Online = शेवटचा संपर्क <?= BIO_ONLINE_MINUTES ?> मिनिटांत</div></div>
    <form method="POST" class="d-flex align-items-center gap-2" style="font-size:12.5px;"><input type="hidden" name="action" value="notify_toggle"><input type="hidden" name="device_id" value="0">
      <label class="d-flex align-items-center gap-1" style="cursor:pointer;"><input type="checkbox" name="on" value="1" <?= $notifyOn ? 'checked' : '' ?> onchange="this.form.submit()"> Punch झाल्यावर कर्मचाऱ्याला सूचना</label></form>
  </div>
  <?php if (!$devices): ?><div class="sm-empty" style="padding:18px;">अजून एकही Machine मंजूर नाही.</div><?php endif; ?>
  <?php foreach ($devices as $d): $on = $d['status'] === 'active' && bioDeviceOnline($d); $drift = bioClockDrift($pdo, $d['id']); ?>
    <div style="padding:14px 18px;border-top:1px solid var(--line);">
      <div class="d-flex flex-wrap justify-content-between gap-2">
        <div>
          <div style="font-weight:700;font-size:14.5px;"><span class="bio-dot <?= $on ? 'on' : 'off' ?>"></span><?= h(bioDeviceName($d)) ?>
            <?php if ($d['status'] === 'disabled'): ?><span class="bio-chip" style="background:#F3F4F6;color:#6B7280;">बंद</span><?php endif; ?></div>
          <div style="font-size:12px;color:var(--text-dim);">
            SN <span class="font-mono"><?= h($d['serial_number']) ?></span> · <?= $on ? '<b style="color:#16A34A;">Online</b>' : '<b style="color:#DC2626;">Offline</b>' ?> (<?= h(bioAgo($d['last_seen_at'])) ?>)
            · IP <?= h($d['ip_address'] ?: '—') ?><?= $d['campus'] ? ' · 🏫 ' . h($d['campus']) : '' ?><?= $d['firmware'] ? ' · ' . h($d['firmware']) : '' ?>
          </div>
          <div style="font-size:12px;margin-top:3px;">
            👥 Machine वर <b><?= (int)($d['user_count'] ?? $d['dusers']) ?></b> · 👆 बोटं <b><?= (int)$d['fp_count'] ?></b> · 🙂 चेहरे <b><?= (int)$d['face_count'] ?></b>
            · आज <b><?= (int)$d['today'] ?></b> Punches · शेवटचा Punch <?= h(bioAgo($d['last_punch_at'])) ?>
            <?php if ($drift !== null && abs($drift) >= 3): ?> · <span style="color:#B45309;font-weight:600;">⏰ Machine ची वेळ <?= abs($drift) ?> मि. <?= $drift > 0 ? 'पुढे' : 'मागे' ?> — Restart करा</span><?php endif; ?>
            <?php if ($d['qcmd']): ?> · <span style="color:var(--rail);"><?= (int)$d['qcmd'] ?> आदेश रांगेत</span><?php endif; ?>
          </div>
        </div>
        <?php if ($d['status'] === 'active'): ?>
        <form method="POST" class="d-flex flex-wrap gap-2 align-items-center bio-cmd">
          <input type="hidden" name="action" value="cmd"><input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>">
          <select name="cmd" class="form-select-custom" style="width:auto;font-size:12.5px;" onchange="var f=this.form;f.querySelector('.bio-days').style.display=this.value==='pull_days'?'':'none';f.querySelector('.bio-pin').style.display=this.value==='delete_user'?'':'none';">
            <option value="sync_users">👥 सगळे कर्मचारी (नाव+PIN) Machine वर पाठवा</option>
            <option value="pull_days">📥 मागचे दिवसांचे Logs मागवा</option>
            <option value="pull_all">📥 सगळे Logs व कर्मचारी पुन्हा मागवा</option>
            <option value="info">ℹ️ Machine ची माहिती मागवा</option>
            <option value="delete_user">🗑️ Machine वरून कर्मचारी काढा (PIN)</option>
            <option value="reboot">🔄 Restart (वेळ Sync)</option>
          </select>
          <input type="number" name="days" class="form-control-custom bio-days" value="7" min="1" max="90" style="width:80px;display:none;" title="किती दिवस">
          <input name="pin" class="form-control-custom bio-pin" placeholder="PIN" style="width:90px;display:none;">
          <button class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;">पाठवा</button>
        </form>
        <?php endif; ?>
      </div>
      <details style="margin-top:8px;"><summary style="font-size:12px;color:var(--text-dim);cursor:pointer;">✏️ नाव / ठिकाण / बंद / हटवा</summary>
        <form method="POST" class="d-flex flex-wrap gap-2 align-items-end mt-2">
          <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>">
          <input name="device_name" class="form-control-custom" value="<?= h($d['device_name']) ?>" placeholder="नाव" style="width:auto;">
          <input name="location" class="form-control-custom" value="<?= h($d['location']) ?>" placeholder="ठिकाण" style="width:auto;">
          <select name="campus_id" class="form-select-custom" style="width:auto;"><option value="">— Campus —</option><?php foreach ($campuses as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$d['campus_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select>
          <button name="action" value="save" class="btn" style="border:1px solid var(--line);border-radius:9px;">जतन</button>
          <button name="action" value="<?= $d['status'] === 'disabled' ? 'enable' : 'disable' ?>" class="btn" style="border:1px solid var(--amber);color:var(--amber);border-radius:9px;"><?= $d['status'] === 'disabled' ? '▶ चालू करा' : '⏸ बंद करा' ?></button>
        </form>
        <form method="POST" class="d-flex flex-wrap gap-2 align-items-end mt-2" onsubmit="return confirm('Machine व तिचे सगळे Raw Punches कायमचे काढायचे? (आधीची हजेरी राहते)');">
          <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="action" value="delete">
          <input name="confirm" class="form-control-custom" placeholder="हटवण्यासाठी SN टाइप करा" style="width:auto;">
          <button class="btn" style="border:1px solid var(--rose);color:var(--rose);border-radius:9px;">🗑️ हटवा</button>
        </form>
      </details>
    </div>
  <?php endforeach; ?>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">📜 आदेशांचा इतिहास</div><div class="sub">Machine पुढच्या संपर्कात (काही सेकंदांत) आदेश घेते</div></div>
  <?php if (!$cmds): ?><div class="sm-empty" style="padding:16px;">अजून आदेश नाहीत.</div><?php else: ?>
  <div class="table-responsive"><table class="rtable"><thead><tr><th>वेळ</th><th>Machine</th><th>आदेश</th><th>कोणी</th><th>स्थिती</th></tr></thead><tbody>
    <?php foreach ($cmds as $c): $col = ['queued' => '#6B7280', 'sent' => '#2563EB', 'done' => '#16A34A', 'failed' => '#DC2626'][$c['status']]; $lbl = ['queued' => 'रांगेत', 'sent' => 'पाठवला', 'done' => '✓ पूर्ण', 'failed' => '✕ अयशस्वी'][$c['status']]; ?>
      <tr><td style="font-size:12px;white-space:nowrap;"><?= date('d/m h:i A', strtotime($c['created_at'])) ?></td><td><?= h($c['device_name']) ?></td><td><?= h($c['label'] ?: $c['command']) ?></td>
        <td style="font-size:12px;"><?= h($c['byname'] ?: '—') ?></td><td><span class="bio-chip" style="color:<?= $col ?>;border:1px solid <?= $col ?>;"><?= $lbl ?></span><?= $c['status'] === 'failed' ? ' <small>(' . h($c['return_code']) . ')</small>' : '' ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
