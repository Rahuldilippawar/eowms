<?php
// 🌐 भाषांतर-यादी तयार करा — Project मधली सगळी मराठी वाक्यं शोधून
// lang/i18n/catalog.php (सर्व) आणि lang/i18n/js-phrases.php (assets/js मधली) लिहितो.
// वापर (Server/Local Terminal):  php tools/i18n-extract.php
// नवीन पानं/वाक्यं जोडल्यानंतर चालवा; मग "भाषांतर व्यवस्थापन" पानावर बाकी वाक्यं दिसतील.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/i18n.php';

$root = realpath(__DIR__ . '/..');
$skipDirs = ['erp', 'database', 'lang/i18n', 'docs', 'tools', 'vendor', 'node_modules', '.git', '.claude', 'backups', 'update-staging', 'assets/uploads', 'lib', 'storage'];
$phrases = []; $jsPhrases = [];

$add = function ($text, $file, $isJs) use (&$phrases, &$jsPhrases) {
    if (!preg_match_all(i18nRunPattern(), $text, $m)) return;
    foreach ($m[0] as $run) {
        $k = i18nNormalize($run);
        if ($k === '' || mb_strlen($k) > 400) continue;
        $pieces = [$k];
        // Runtime fallback जसे तुकडे करतो तसेच इथेही — म्हणजे ते तुकडेही शब्दकोशात येतात
        // (विरामचिन्हांवरचे तुकडे घेत नाही — पूर्ण वाक्य आधीच यादीत असतं; फक्त अंकांवरचे)
        if (preg_match('/[0-9\x{0966}-\x{096F}]/u', $k)) {
            $pieces[] = preg_replace('/[0-9\x{0966}-\x{096F}]+(?:[.,][0-9\x{0966}-\x{096F}]+)*/u', '{n}', $k);
            foreach (preg_split('/[0-9\x{0966}-\x{096F}]+(?:[.,:\/][0-9\x{0966}-\x{096F}]+)*/u', $k) as $p) { $pieces[] = $p; }
        }
        foreach ($pieces as $p) {
            $p = i18nNormalize($p);
            if ($p === '' || !preg_match('/[\x{0900}-\x{097F}]/u', $p)) continue;
            $phrases[$p][$file] = true;
            if ($isJs) $jsPhrases[$p] = true;
        }
    }
};

$it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    function ($f) use ($root, $skipDirs) {
        $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
        foreach ($skipDirs as $d) { if ($rel === $d || strpos($rel, $d . '/') === 0) return false; }
        return true;
    }
));
foreach ($it as $f) {
    $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($root))), '/');
    $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
    $src = file_get_contents($f->getPathname());
    if (!preg_match('/[\x{0900}-\x{097F}]/u', $src)) continue;
    if ($ext === 'php') {
        foreach (token_get_all($src) as $tok) {
            if (!is_array($tok)) continue;
            if (in_array($tok[0], [T_INLINE_HTML, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $add(stripcslashes($tok[1]), $rel, false);
            }
        }
    } elseif ($ext === 'js' || $ext === 'html') {
        $clean = preg_replace(['~/\*.*?\*/~s', '~(^|[^:\'"])//[^\n]*~'], ['', '$1'], $src);
        $add($clean, $rel, $ext === 'js');
    }
}

ksort($phrases);
if ($dump = getenv('I18N_DUMP')) { file_put_contents($dump, json_encode(array_map('array_keys', $phrases), JSON_UNESCAPED_UNICODE)); }
$catalog = [];
foreach ($phrases as $p => $files) { $catalog[$p] = count($files); }
$dir = $root . '/lang/i18n';
if (!is_dir($dir)) mkdir($dir, 0755, true);
file_put_contents($dir . '/catalog.php', "<?php\n// आपोआप तयार — php tools/i18n-extract.php (हाताने बदलू नका)\n// वाक्य => किती Files मध्ये वापरलं\nreturn " . var_export($catalog, true) . ";\n");
$js = array_keys($jsPhrases); sort($js);
file_put_contents($dir . '/js-phrases.php', "<?php\n// आपोआप तयार — assets/js मधली वाक्यं (Browser ला फक्त इतकाच शब्दकोश पाठवला जातो)\nreturn " . var_export($js, true) . ";\n");

foreach (['hi', 'en'] as $lang) {
    $dict = i18nDict($lang);
    $done = count(array_intersect_key($catalog, $dict));
    printf("%s: %d / %d (%.1f%%)\n", $lang, $done, count($catalog), count($catalog) ? $done * 100 / count($catalog) : 0);
}
echo count($catalog) . " वाक्यं, " . count($js) . " JS मध्ये\n";
