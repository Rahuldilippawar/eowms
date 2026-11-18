<?php
if (!function_exists('canBusPass')) { http_response_code(404); exit; }
// 🚌 बस पास विभाग — वरचे Tabs (पास / बस विद्यार्थी / Import)
$bpTabs = ['passes' => ['index.php', '🎫 बस पास'], 'students' => ['students.php', '👥 बस विद्यार्थी'], 'fees' => ['fees.php', '₹ शुल्क अहवाल'], 'import' => ['import.php', '📥 Import']];
if (!canBusPass()) unset($bpTabs['import']);
?>
<style>
  .bp-tabs{display:flex;gap:6px;margin:4px 0 14px;overflow-x:auto;scrollbar-width:none;}
  .bp-tabs a{white-space:nowrap;padding:8px 14px;border-radius:10px;border:1px solid var(--line);background:var(--surface);color:var(--text);text-decoration:none;font-size:13px;font-weight:600;}
  .bp-tabs a.on{background:var(--rail);border-color:var(--rail);color:#fff;}
</style>
<div class="bp-tabs"><?php foreach ($bpTabs as $k => [$href, $lbl]): ?><a href="<?= h($href) ?>" class="<?= ($bpTab ?? '') === $k ? 'on' : '' ?>"><?= h($lbl) ?></a><?php endforeach; ?></div>
