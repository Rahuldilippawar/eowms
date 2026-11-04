<?php
// ⚠️ Error Handler — पांढऱ्या/अर्धवट पानाऐवजी "काय चुकलं" ते स्पष्ट सांगणारं पान.
// • प्रत्येकाला: सोप्या भाषेत कारण + Error क्रमांक (Super Admin ला सांगण्यासाठी)
// • Super Admin ला: पूर्ण तांत्रिक तपशील (संदेश, File:ओळ, Trace)
// • प्रत्येक Error ची नोंद storage/logs/ मध्ये (Web वरून उघडता येत नाही) —
//   Super Admin → System Update → ⚠️ Error Log मध्ये दिसते.

if (defined('EOWMS_ERROR_HANDLER')) return;
define('EOWMS_ERROR_HANDLER', 1);
define('EOWMS_ERROR_LOG_DIR', dirname(__DIR__) . '/storage/logs');

/** Error ची नोंद — एक ओळ = एक JSON (महिन्यानुसार File). नोंद अपयशी झाली तरी पान थांबू नये. */
function eowmsLogError(array $e) {
    try {
        if (!is_dir(EOWMS_ERROR_LOG_DIR)) @mkdir(EOWMS_ERROR_LOG_DIR, 0750, true);
        $u = $_SESSION['user'] ?? null;
        $e += [
            'time' => date('Y-m-d H:i:s'),
            'url' => PHP_SAPI === 'cli' ? ('cli: ' . implode(' ', $_SERVER['argv'] ?? [])) : (($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' ' . ($_SERVER['REQUEST_URI'] ?? '')),
            'user' => $u ? ((int)($u['id'] ?? 0) . ' · ' . ($u['name'] ?? '')) : '',
            'org' => $u['organization_id'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 160),
        ];
        @file_put_contents(EOWMS_ERROR_LOG_DIR . '/errors-' . date('Y-m') . '.log', json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $x) {}
    error_log('[' . ($e['ref'] ?? '-') . '] ' . ($e['type'] ?? '') . ': ' . ($e['message'] ?? '') . ' @ ' . ($e['file'] ?? '') . ':' . ($e['line'] ?? ''));
}

/** सोप्या भाषेत कारण — ओळखीच्या Database चुकांसाठी थेट उपायही */
function eowmsExplainError($type, $message, $code = '') {
    $m = (string)$message; $sqlState = '';
    if (preg_match('/SQLSTATE\[(\w+)\]/', $m, $mm)) $sqlState = $mm[1];
    $map = [
        '42S02' => ['Database मध्ये आवश्यक Table नाही', 'नवीन सुविधेसाठी Database Update बाकी आहे. Super Admin ने System Update → DB Update चालवावं.'],
        '42S22' => ['Database मध्ये आवश्यक Column नाही', 'Database Update बाकी आहे. Super Admin ने System Update → DB Update चालवावं.'],
        '23000' => ['ही नोंद आधीच अस्तित्वात आहे किंवा जोडलेली माहिती चुकीची आहे', 'तीच माहिती पुन्हा भरली गेली असेल (उदा. तोच Email / क्रमांक). तपासून पुन्हा प्रयत्न करा.'],
        'HY000' => ['Database ने विनंती नाकारली', 'काही वेळाने पुन्हा प्रयत्न करा. वारंवार झाल्यास Super Admin ला Error क्रमांक सांगा.'],
        '08004' => ['Database शी जोडणी होऊ शकली नाही', 'Server वर Database तात्पुरता बंद असू शकतो. काही वेळाने पुन्हा प्रयत्न करा.'],
        '2002' => ['Database शी जोडणी होऊ शकली नाही', 'Server वर Database तात्पुरता बंद असू शकतो. काही वेळाने पुन्हा प्रयत्न करा.'],
        '22001' => ['भरलेली माहिती खूप मोठी आहे', 'एखाद्या रकान्यात जास्त मजकूर भरला आहे — तो कमी करून पुन्हा प्रयत्न करा.'],
        '22007' => ['तारीख / वेळ चुकीच्या स्वरूपात आहे', 'तारीख योग्य स्वरूपात निवडा आणि पुन्हा प्रयत्न करा.'],
        '40001' => ['एकाच वेळी अनेक बदल झाले', 'पुन्हा प्रयत्न करा — बहुधा दुसऱ्यांदा यशस्वी होईल.'],
    ];
    if ($sqlState && isset($map[$sqlState])) return [$map[$sqlState][0], $map[$sqlState][1], 'Database (SQLSTATE ' . $sqlState . ')'];
    if ($sqlState) return ['Database मध्ये त्रुटी', 'काही वेळाने पुन्हा प्रयत्न करा. वारंवार झाल्यास Super Admin ला Error क्रमांक सांगा.', 'Database (SQLSTATE ' . $sqlState . ')'];
    if (stripos($m, 'Allowed memory size') !== false) return ['Server ची Memory कमी पडली', 'खूप मोठा अहवाल / File असेल — तारखांचा कालावधी कमी करून पुन्हा प्रयत्न करा.', 'Memory'];
    if (stripos($m, 'Maximum execution time') !== false) return ['काम पूर्ण व्हायला खूप वेळ लागला', 'कालावधी / नोंदी कमी करून पुन्हा प्रयत्न करा.', 'Timeout'];
    if (stripos($m, 'Call to undefined function') !== false || stripos($m, 'not found') !== false) return ['प्रणालीतील एखादा भाग सापडला नाही', 'अलीकडील Update अर्धवट पोहोचला असू शकतो. Super Admin ला Error क्रमांक सांगा.', 'Code'];
    if (stripos($m, 'Permission denied') !== false || stripos($m, 'failed to open stream') !== false) return ['File वाचता / लिहिता आली नाही', 'Server वरील Folder ची परवानगी तपासावी लागेल. Super Admin ला Error क्रमांक सांगा.', 'File'];
    return ['प्रणालीत अनपेक्षित त्रुटी आली', 'पुन्हा प्रयत्न करा. वारंवार झाल्यास खालचा Error क्रमांक Super Admin ला सांगा.', $type];
}

function eowmsIsSuperAdmin() {
    try { return function_exists('hasRole') && !empty($_SESSION['user']) && hasRole('super_admin'); } catch (Throwable $e) { return false; }
}

/** Error पान — आधीचा अर्धवट Output काढून पूर्ण पान दाखवतो */
function eowmsRenderError(array $e) {
    [$title, $hint, $kind] = eowmsExplainError($e['type'], $e['message'], $e['code'] ?? '');
    $e['kind'] = $kind;
    eowmsLogError($e);
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "[{$e['ref']}] {$e['type']}: {$e['message']} @ {$e['file']}:{$e['line']}\n"); return; }
    while (ob_get_level() > 0) { @ob_end_clean(); }
    if (function_exists('i18nFilterOutput')) { try { ob_start('i18nFilterOutput'); } catch (Throwable $x) {} } // निवडलेल्या भाषेत
    if (!headers_sent()) {
        http_response_code(500);
        header('Cache-Control: no-store');
    }
    $isAjax = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false && stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') === false;
    $super = eowmsIsSuperAdmin();
    if ($isAjax) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $title, 'hint' => $hint, 'ref' => $e['ref']] + ($super ? ['detail' => $e['message'], 'at' => $e['file'] . ':' . $e['line']] : []), JSON_UNESCAPED_UNICODE);
        return;
    }
    if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
    $h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $root = dirname(__DIR__);
    $rel = function ($f) use ($root) { return ltrim(str_replace([$root, '\\'], ['', '/'], (string)$f), '/'); };
    $base = '';
    $script = str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '');
    if ($script && strpos($script, $root) === 0) { $base = str_repeat('../', substr_count(ltrim(substr($script, strlen($root)), '/'), '/')); }
    ?><!DOCTYPE html><html lang="mr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>त्रुटी — <?= $h($e['ref']) ?></title><meta name="robots" content="noindex">
<style>
  :root{--bg:#F7F5F2;--card:#fff;--line:#EAE5DF;--ink:#14162B;--dim:#6A6E80;--acc:#D9530F;--bad:#C2364A;--bad-soft:#FBE4E7}
  @media (prefers-color-scheme:dark){:root{--bg:#0B1020;--card:#141B2D;--line:#253049;--ink:#E3E8F2;--dim:#8E99B6;--bad-soft:#3D1C23}}
  *{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;background:var(--bg);color:var(--ink);font-family:'Noto Sans Devanagari','Segoe UI',sans-serif}
  .c{width:100%;max-width:620px;background:var(--card);border:1px solid var(--line);border-radius:20px;padding:28px;box-shadow:0 20px 50px -20px rgba(0,0,0,.25)}
  .ic{width:56px;height:56px;border-radius:16px;background:var(--bad-soft);color:var(--bad);display:flex;align-items:center;justify-content:center;font-size:28px;margin-bottom:14px}
  h1{font-size:21px;margin:0 0 6px}.hint{color:var(--dim);font-size:14.5px;line-height:1.7;margin:0 0 16px}
  .ref{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--bg);border:1px dashed var(--line);border-radius:12px;padding:10px 14px;font-size:13px;margin-bottom:16px}
  .ref b{font-family:ui-monospace,Menlo,monospace;font-size:14px;letter-spacing:.5px}.ref button{margin-left:auto;border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:8px;padding:5px 10px;cursor:pointer;font-family:inherit;font-size:12.5px}
  .what{font-size:13px;color:var(--dim);margin:-6px 0 16px}.what b{color:var(--ink)}
  .act{display:flex;gap:8px;flex-wrap:wrap}.act a,.act button{flex:1;min-width:130px;text-align:center;text-decoration:none;border-radius:11px;padding:11px;font-weight:700;font-size:14px;cursor:pointer;font-family:inherit;border:1px solid var(--line);background:var(--card);color:var(--ink)}
  .act .p{background:var(--acc);border-color:var(--acc);color:#fff}
  details{margin-top:18px;border:1px solid var(--line);border-radius:12px;overflow:hidden}summary{cursor:pointer;padding:11px 14px;font-weight:700;font-size:13.5px;background:var(--bg)}
  .tech{padding:12px 14px;font-size:12.5px;line-height:1.6}.tech code,.tech pre{font-family:ui-monospace,Menlo,monospace;font-size:12px}
  .tech pre{white-space:pre-wrap;word-break:break-word;background:var(--bg);border-radius:8px;padding:10px;margin:8px 0 0;max-height:260px;overflow:auto}
  .msg{color:var(--bad);font-weight:600;word-break:break-word}
</style></head><body>
<div class="c" role="alert">
  <div class="ic">⚠️</div>
  <h1><?= $h($title) ?></h1>
  <p class="hint"><?= $h($hint) ?></p>
  <div class="what">त्रुटीचा प्रकार: <b><?= $h($kind) ?></b> · वेळ: <b><?= $h(date('d M Y, h:i A')) ?></b></div>
  <div class="ref">Error क्रमांक: <b id="eref"><?= $h($e['ref']) ?></b><button type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?= $h($e['ref']) ?>');this.textContent='✓ Copy झाला'">Copy</button></div>
  <div class="act">
    <button type="button" class="p" onclick="location.reload()">↻ पुन्हा प्रयत्न करा</button>
    <button type="button" onclick="history.length>1?history.back():location.href='<?= $h($base) ?>index.php'">← मागे जा</button>
    <a href="<?= $h($base) ?>index.php">मुख्य पान</a>
  </div>
  <?php if ($super): ?>
  <details open><summary>🛠️ तांत्रिक तपशील (फक्त Super Admin ला दिसतो)</summary><div class="tech">
    <div class="msg"><?= $h($e['type']) ?>: <?= $h($e['message']) ?></div>
    <div>📄 <code><?= $h($rel($e['file'])) ?>:<?= (int)$e['line'] ?></code></div>
    <div>🔗 <code><?= $h($e['url'] ?? (($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? ''))) ?></code></div>
    <?php if (!empty($e['trace'])): ?><pre><?= $h(str_replace($root . '/', '', $e['trace'])) ?></pre><?php endif; ?>
    <div style="margin-top:8px"><a href="<?= $h($base) ?>system-update/error-log.php" style="color:var(--acc);font-weight:700">⚠️ सर्व Errors पहा →</a></div>
  </div></details>
  <?php endif; ?>
</div></body></html><?php
}

function eowmsErrorRef() { return 'ERR-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)); }

// ── न पकडलेले Exceptions (उदा. PDOException) ──
set_exception_handler(function (Throwable $ex) {
    $trace = implode("\n", array_slice(explode("\n", $ex->getTraceAsString()), 0, 12));
    eowmsRenderError(['ref' => eowmsErrorRef(), 'type' => get_class($ex), 'message' => $ex->getMessage(), 'code' => (string)$ex->getCode(), 'file' => $ex->getFile(), 'line' => $ex->getLine(), 'trace' => $trace]);
});

// ── Warnings / Notices — पान थांबवत नाहीत; फक्त नोंद (Deprecated सोडून) ──
set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) return false;           // @ ने दाबलेले
    if (in_array($no, [E_DEPRECATED, E_USER_DEPRECATED], true)) return false;
    static $seen = [];
    $k = $file . ':' . $line;
    if (!isset($seen[$k]) && count($seen) < 20) {
        $seen[$k] = 1;
        $names = [E_WARNING => 'Warning', E_NOTICE => 'Notice', E_USER_WARNING => 'Warning', E_USER_NOTICE => 'Notice', E_USER_ERROR => 'Error', E_RECOVERABLE_ERROR => 'Error'];
        eowmsLogError(['ref' => eowmsErrorRef(), 'type' => 'PHP ' . ($names[$no] ?? 'Warning'), 'message' => $str, 'file' => $file, 'line' => $line, 'level' => 'warning']);
    }
    return false; // PHP चं नेहमीचं Logging चालू राहू द्या
});

// ── Fatal Errors (Parse, Memory, Undefined function…) — Shutdown वेळी पकडतो ──
register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    eowmsRenderError(['ref' => eowmsErrorRef(), 'type' => 'PHP Fatal Error', 'message' => $err['message'], 'file' => $err['file'], 'line' => $err['line']]);
});
