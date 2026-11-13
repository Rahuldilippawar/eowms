<?php
// 🪪 कर्मचारी Form — ओळख, बँक व पत्ता (जुन्या Software च्या "Employee List" प्रमाणे):
// Emp Id, Gender, Aadhar, WhatsApp, बँक खाते, IFSC, बँक, शाखा, गावचा (कायमचा) व सध्याचा पत्ता.
// create.php / edit.php मध्ये एकच भाग; create.php / update.php मध्ये staffExtraFromPost() ने तपासून जतन.

/** Form मधला भाग — $v = आधीची माहिती (edit) किंवा [] (create) */
function staffExtraFieldsHtml(array $v = [], $withEmpId = false) {
    $g = $v['gender'] ?? '';
    $e = fn($k) => h($v[$k] ?? '');
    ob_start(); ?>
      <div class="col-12"><div style="font-weight:700;font-size:13.5px;margin-top:8px;padding-top:12px;border-top:1px dashed var(--line);">🪪 ओळख, बँक व पत्ता <small style="color:var(--text-dim);font-weight:400;">(ऐच्छिक — पगार व ओळखपत्रासाठी उपयोगी)</small></div></div>
      <?php if ($withEmpId): ?>
      <div class="col-md-4"><label class="form-label-custom">कर्मचारी क्रमांक (Emp Id)</label><input type="text" name="employee_code" class="form-control-custom font-mono" maxlength="50" value="<?= $e('employee_code') ?>" placeholder="उदा. 126"><small style="color:var(--text-dim);font-size:11px;">याने पण Login करता येतं</small></div>
      <?php endif; ?>
      <div class="col-md-4"><label class="form-label-custom">Gender</label>
        <select name="gender" class="form-select-custom"><option value="">—</option><option value="Male" <?= $g === 'Male' ? 'selected' : '' ?>>पुरुष (Male)</option><option value="Female" <?= $g === 'Female' ? 'selected' : '' ?>>स्त्री (Female)</option></select></div>
      <div class="col-md-4"><label class="form-label-custom">Aadhar क्रमांक</label><input type="text" name="aadhar_number" class="form-control-custom font-mono" inputmode="numeric" maxlength="14" pattern="[0-9 ]{12,14}" value="<?= $e('aadhar_number') ?>" placeholder="12 अंकी"></div>
      <div class="col-md-4"><label class="form-label-custom">WhatsApp क्रमांक</label><input type="tel" name="whatsapp_number" class="form-control-custom font-mono" maxlength="15" value="<?= $e('whatsapp_number') ?>"></div>
      <div class="col-md-4"><label class="form-label-custom">बँक खाते क्रमांक</label><input type="text" name="bank_account_no" class="form-control-custom font-mono" maxlength="30" value="<?= $e('bank_account_no') ?>"></div>
      <div class="col-md-4"><label class="form-label-custom">IFSC Code</label><input type="text" name="ifsc_code" class="form-control-custom font-mono" maxlength="11" style="text-transform:uppercase;" pattern="[A-Za-z]{4}0[A-Za-z0-9]{6}" value="<?= $e('ifsc_code') ?>" placeholder="उदा. HDFC0002470"></div>
      <div class="col-md-4"><label class="form-label-custom">बँकेचं नाव</label><input type="text" name="bank_name" class="form-control-custom" maxlength="150" value="<?= $e('bank_name') ?>" list="eoBanks"><datalist id="eoBanks"><option value="HDFC Bank"><option value="State Bank of India"><option value="Bank of Maharashtra"><option value="TDCC Bank"><option value="ICICI Bank"><option value="Axis Bank"></datalist></div>
      <div class="col-md-4"><label class="form-label-custom">शाखा (Branch)</label><input type="text" name="branch_name" class="form-control-custom" maxlength="150" value="<?= $e('branch_name') ?>"></div>
      <div class="col-md-6"><label class="form-label-custom">गावचा / कायमचा पत्ता</label><textarea name="permanent_address" class="form-control-custom" rows="2" maxlength="500"><?= $e('permanent_address') ?></textarea></div>
      <div class="col-md-6"><label class="form-label-custom">सध्याचा पत्ता <label style="font-weight:400;font-size:11.5px;margin-left:6px;"><input type="checkbox" onclick="if(this.checked){var f=this.closest('form');f.local_address.value=f.permanent_address.value;}"> वरच्यासारखाच</label></label><textarea name="local_address" class="form-control-custom" rows="2" maxlength="500"><?= $e('local_address') ?></textarea></div>
    <?php return ob_get_clean();
}

/** POST मधून तपासलेली माहिती — [fields, error|null] */
function staffExtraFromPost(array $post, $withEmpId = false) {
    $t = fn($k, $max = 255) => ($x = trim(preg_replace('/\s+/u', ' ', (string)($post[$k] ?? '')))) !== '' ? mb_substr($x, 0, $max) : null;
    $f = [
        'gender' => in_array($post['gender'] ?? '', ['Male', 'Female'], true) ? $post['gender'] : null,
        'aadhar_number' => ($a = preg_replace('/\D/', '', (string)($post['aadhar_number'] ?? ''))) !== '' ? $a : null,
        'whatsapp_number' => ($w = preg_replace('/[^\d+]/', '', (string)($post['whatsapp_number'] ?? ''))) !== '' ? mb_substr($w, 0, 15) : null,
        'bank_account_no' => ($b = preg_replace('/\s+/', '', (string)($post['bank_account_no'] ?? ''))) !== '' ? mb_substr($b, 0, 30) : null,
        'ifsc_code' => ($i = strtoupper(trim((string)($post['ifsc_code'] ?? '')))) !== '' ? $i : null,
        'bank_name' => $t('bank_name', 150), 'branch_name' => $t('branch_name', 150),
        'permanent_address' => $t('permanent_address', 500), 'local_address' => $t('local_address', 500),
    ];
    if ($withEmpId) { $f['employee_code'] = $t('employee_code', 50); }
    if ($f['aadhar_number'] !== null && strlen($f['aadhar_number']) !== 12) return [$f, 'Aadhar क्रमांक 12 अंकी हवा.'];
    if ($f['ifsc_code'] !== null && !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $f['ifsc_code'])) return [$f, 'IFSC Code चुकीचा — उदा. HDFC0002470 (11 अक्षरं).'];
    if ($withEmpId && $f['employee_code'] !== null && !preg_match('/^[A-Za-z0-9._\/-]{1,50}$/', $f['employee_code'])) return [$f, 'कर्मचारी क्रमांकात फक्त अक्षरं / अंक / - . / चालतात.'];
    return [$f, null];
}

/** users मध्ये जतन — Database मध्ये असलेलेच Columns */
function staffExtraSave(PDO $pdo, $userId, array $f) {
    static $cols = null;
    if ($cols === null) { $cols = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(), 'Field'); }
    $f = array_filter($f, fn($k) => in_array($k, $cols, true), ARRAY_FILTER_USE_KEY);
    if (!$f) return;
    $pdo->prepare("UPDATE users SET " . implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f))) . " WHERE id = ?")->execute(array_merge(array_values($f), [(int)$userId]));
}
