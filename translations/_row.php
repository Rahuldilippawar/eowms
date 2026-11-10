<?php
// एक वाक्य — मराठी मूळ (भाषांतरित होऊ नये म्हणून i18n:off) + हिंदी/English दुरुस्ती Form
// अपेक्षित: $p (मराठी), $uses (मजकूर), $hi, $en (शब्दकोश)
if (!isset($p)) { http_response_code(404); exit; }   // थेट URL ने उघडलं तर (हा फक्त index.php मधला भाग)
$hv = $hi[$p] ?? ''; $ev = $en[$p] ?? '';
?>
<form method="POST" class="tr-row">
  <input type="hidden" name="action" value="save">
  <!--i18n:off--><input type="hidden" name="source" value="<?= h($p) ?>">
  <div class="src"><?= h($p) ?><small><?= $uses ?></small></div><!--i18n:on-->
  <textarea name="hi" rows="1" class="form-control-custom <?= $hv === '' ? 'empty' : '' ?>" placeholder="हिंदी"><?= h($hv) ?></textarea>
  <textarea name="en" rows="1" class="form-control-custom <?= $ev === '' ? 'empty' : '' ?>" placeholder="English"><?= h($ev) ?></textarea>
  <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:12px;padding:6px 14px;">जतन</button>
</form>
