<?php
// 🌐 E-Admin भाषांतर (मराठी / हिंदी / English) — Google Translate शिवाय, स्वतःचं.
//
// कसं चालतं: App चा सगळा मजकूर मराठीत लिहिलेला आहे. पान Browser कडे जाण्याआधी
// (includes/csrf.php मधला Output Buffer) त्या पानावरची मराठी वाक्यं निवडलेल्या
// भाषेच्या शब्दकोशातून (lang/i18n/hi.php, en.php + Admin ने केलेल्या दुरुस्त्या —
// translation_overrides Table) बदलली जातात. त्यामुळे कोणत्याही पानात बदल न करता
// संपूर्ण Project भाषांतरित होतो, आणि नवीन पानं आपोआप सामील होतात.
//
// सुरक्षितता: कर्मचाऱ्यांनी भरलेला Data (input value, textarea, option value) कधीच
// बदलत नाही — नाहीतर Form Submit करताना तो भाषांतरित होऊन जतन झाला असता.

const EOWMS_LANGS = [
    'mr' => ['label' => 'मराठी', 'short' => 'मरा', 'html' => 'mr'],
    'hi' => ['label' => 'हिंदी', 'short' => 'हिं', 'html' => 'hi'],
    'en' => ['label' => 'English', 'short' => 'EN', 'html' => 'en'],
];

/** मराठी "वाक्य-तुकडा" ओळखणारा Pattern — Extractor आणि Runtime दोघेही हाच वापरतात */
function i18nRunPattern() {
    // देवनागरी अक्षराने सुरू; मध्ये देवनागरी/इंग्रजी अक्षरं, अंक, Space व साधी विरामचिन्हं;
    // शेवट देवनागरी अक्षर किंवा . ! ? ) ने.
    return '/[\x{0900}-\x{0963}\x{0970}-\x{097F}](?:[\x{0900}-\x{097F}\x{200C}\x{200D}A-Za-z0-9 \x{00A0}.,:;!?()\x{2019}\-\x{2013}\x{2014}\/&%+]*[\x{0900}-\x{0963}\x{0970}-\x{097F}.!?)])?/u';
}

function i18nNormalize($s) {
    return trim(preg_replace('/[ \x{00A0}]+/u', ' ', $s));
}

/** सध्याची भाषा — ?lang= → Session → Cookie → User ची आवड → मराठी */
function i18nLang() {
    static $lang = null;
    if ($lang !== null) return $lang;
    $pick = null;
    if (isset($_GET['lang']) && isset(EOWMS_LANGS[$_GET['lang']])) {
        $pick = $_GET['lang'];
        i18nRemember($pick);
    }
    if (!$pick && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['lang'], EOWMS_LANGS[$_SESSION['lang']])) $pick = $_SESSION['lang'];
    if (!$pick && isset($_COOKIE['eowms_lang'], EOWMS_LANGS[$_COOKIE['eowms_lang']])) $pick = $_COOKIE['eowms_lang'];
    if (!$pick && session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user']['preferred_language']) && isset(EOWMS_LANGS[$_SESSION['user']['preferred_language']])) $pick = $_SESSION['user']['preferred_language'];
    $lang = $pick ?: 'mr';
    if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['lang'] = $lang;
    return $lang;
}

/** निवडलेली भाषा लक्षात ठेवा — Cookie (Login आधीसुद्धा) + Login असल्यास खात्यात */
function i18nRemember($lang) {
    global $pdo;
    if (!isset(EOWMS_LANGS[$lang])) return;
    if (!headers_sent()) {
        setcookie('eowms_lang', $lang, ['expires' => time() + 86400 * 365, 'path' => '/', 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => false]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['lang'] = $lang;
        if (!empty($_SESSION['user']['id']) && ($_SESSION['user']['preferred_language'] ?? null) !== $lang && isset($pdo)) {
            try {
                $pdo->prepare("UPDATE users SET preferred_language = ? WHERE id = ?")->execute([$lang, $_SESSION['user']['id']]);
                $_SESSION['user']['preferred_language'] = $lang;
            } catch (PDOException $e) { /* Column अजून नसेल (DB Update बाकी) — Cookie पुरेसं */ }
        }
    }
}

/** शब्दकोश — File (lang/i18n/<lang>.php) + Admin च्या दुरुस्त्या (DB), एकदाच Load */
function i18nDict($lang) {
    global $pdo;
    static $cache = [];
    if (isset($cache[$lang])) return $cache[$lang];
    // lang/i18n/src/*.php — प्रत्येक File: 'मराठी वाक्य' => ['हिंदी', 'English']
    $idx = $lang === 'hi' ? 0 : 1;
    $dict = [];
    foreach (glob(__DIR__ . '/../lang/i18n/src/*.php') ?: [] as $f) {
        foreach ((require $f) as $mr => $pair) { if (isset($pair[$idx]) && $pair[$idx] !== '') $dict[i18nNormalize($mr)] = $pair[$idx]; }
    }
    if (isset($pdo)) {
        try {
            $st = $pdo->prepare("SELECT source_text, translated_text FROM translation_overrides WHERE lang = ?");
            $st->execute([$lang]);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$src, $tr]) { if ($tr !== '') $dict[i18nNormalize($src)] = $tr; }
        } catch (PDOException $e) { /* Table अजून नसेल */ }
    }
    return $cache[$lang] = $dict;
}

/** न सापडलेली वाक्यं — Admin ला "काय भाषांतर बाकी आहे" दाखवण्यासाठी (प्रत्येक पानावर जास्तीत जास्त ४०) */
function i18nMiss($phrase, $lang) {
    $GLOBALS['__i18n_misses'][$lang][$phrase] = true;
}

function i18nFlushMisses() {
    global $pdo;
    if (empty($GLOBALS['__i18n_misses']) || !isset($pdo)) return;
    try {
        $st = $pdo->prepare("INSERT INTO translation_misses (lang, source_hash, source_text, page, hits, last_seen) VALUES (?, ?, ?, ?, 1, NOW())
            ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = NOW(), page = VALUES(page)");
        $page = substr(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', 0, 190);
        foreach ($GLOBALS['__i18n_misses'] as $lang => $list) {
            foreach (array_slice(array_keys($list), 0, 40) as $p) {
                if (mb_strlen($p) > 500) continue;
                $st->execute([$lang, sha1($p), $p, $page]);
            }
        }
    } catch (PDOException $e) {}
    $GLOBALS['__i18n_misses'] = [];
}

/** एक तुकडा भाषांतरित करा — थेट; अंक बदलून; विरामचिन्हांवर तोडून; नाहीतर जसाच्या तसा */
function i18nTranslateRun($run, $lang, $dict, $depth = 0) {
    $key = i18nNormalize($run);
    if ($key === '') return $run;
    $lead = substr($run, 0, strlen($run) - strlen(ltrim($run)));
    $trail = substr($run, strlen(rtrim($run)));
    if (isset($dict[$key])) return $lead . $dict[$key] . $trail;
    // HTML मधलं &quot; / &amp; — शब्दकोशात साधं " असतं; उलगडून पूर्ण प्रक्रिया, मग परत Escape
    if ($depth < 9 && strpos($key, '&') !== false) {
        $dk = html_entity_decode($key, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($dk !== $key) {
            $t = i18nTranslateRun($dk, $lang, $dict, 9);
            if ($t !== $dk) return $lead . htmlspecialchars($t, ENT_QUOTES, 'UTF-8', false) . $trail;
        }
    }

    // अंक असलेली वाक्यं — "{n} दिवस बाकी" सारखा साचा
    if (preg_match('/[0-9\x{0966}-\x{096F}]/u', $key)) {
        $nums = [];
        $tpl = preg_replace_callback('/[0-9\x{0966}-\x{096F}]+(?:[.,][0-9\x{0966}-\x{096F}]+)*/u', function ($m) use (&$nums) { $nums[] = $m[0]; return '{n}'; }, $key);
        if (isset($dict[$tpl])) {
            $i = 0;
            $out = preg_replace_callback('/\{n\}/', function () use (&$i, $nums, $lang) { return i18nDigits($nums[$i++] ?? '', $lang); }, $dict[$tpl]);
            return $lead . $out . $trail;
        }
    }
    if ($depth > 2 && $depth !== 9) { i18nMiss($key, $lang); return $run; }

    // अंक / इंग्रजी Data वर तोडा, मग विरामचिन्हांवर
    foreach (['/([0-9\x{0966}-\x{096F}]+(?:[.,:\/][0-9\x{0966}-\x{096F}]+)*)/u', '/(\s*[\x{2014}\x{2013}:;,.!?()\/|]\s*)/u'] as $splitter) {
        $parts = preg_split($splitter, $run, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($parts) <= 1) continue;
        $out = ''; $any = false;
        foreach ($parts as $i => $p) {
            if ($i % 2 === 1 || !preg_match('/[\x{0900}-\x{097F}]/u', $p)) { $out .= ($i % 2 === 1 ? i18nDigits($p, $lang) : $p); continue; }
            $t = i18nTranslateRun($p, $lang, $dict, $depth === 9 ? 10 : $depth + 1);
            if ($t !== $p) $any = true;
            $out .= $t;
        }
        if ($any) return $out;
    }
    i18nMiss($key, $lang);
    return $run;
}

/** English मध्ये देवनागरी अंक (१२३) → 123 */
function i18nDigits($s, $lang) {
    if ($lang !== 'en') return $s;
    return strtr($s, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
}

/** साधा मजकूर (HTML नसलेला) भाषांतरित करा — JS/JSON साठी $jsSafe = true (Quotes टाळतो) */
function i18nText($text, $lang = null, $jsSafe = false) {
    $lang = $lang ?: i18nLang();
    if ($lang === 'mr' || $text === '' || !preg_match('/[\x{0900}-\x{097F}]/u', $text)) return $text;
    $dict = i18nDict($lang);
    return preg_replace_callback(i18nRunPattern(), function ($m) use ($lang, $dict, $jsSafe) {
        $t = i18nTranslateRun($m[0], $lang, $dict);
        return $jsSafe ? strtr($t, ["'" => '’', '"' => '”', '\\' => '/', "\n" => ' ', '`' => '’']) : $t;
    }, $text);
}

/** संपूर्ण HTML पान — फक्त दिसणारा मजकूर व ठराविक Attributes; Form मधला Data नाही */
function i18nTranslateHtml($html, $lang = null) {
    $lang = $lang ?: i18nLang();
    if ($lang === 'mr' || !preg_match('/[\x{0900}-\x{097F}]/u', $html)) return $html;
    $html = preg_replace('/<html\b([^>]*)\blang="[a-z-]*"/i', '<html$1lang="' . EOWMS_LANGS[$lang]['html'] . '"', $html, 1);
    // <!--i18n:off--> … <!--i18n:on--> मधला भाग जसाच्या तसा (उदा. भाषांची नावं)
    $kept = [];
    $html = preg_replace_callback('/<!--i18n:off-->.*?<!--i18n:on-->/s', function ($m) use (&$kept) { $kept[] = $m[0]; return "\x01I18N" . (count($kept) - 1) . "\x01"; }, $html);
    $safeAttrs = 'placeholder|title|aria-label|alt|data-bs-original-title|data-confirm|data-title|data-label|label|content|onclick|onsubmit|onchange|oninput';
    $html = preg_replace_callback(
        '~(<script\b[^>]*>)(.*?)(</script>)|<style\b.*?</style>|(<textarea\b[^>]*>)(.*?)(</textarea>)|<!--.*?-->|(<[a-zA-Z][^>]*>)|([^<]+)~su',
        function ($m) use ($lang, $safeAttrs) {
            if (!empty($m[1])) {                       // <script> — JSON-LD / Template वगळून, JS मधली वाक्यं
                if (preg_match('/type="(?!text\/javascript|module)/i', $m[1])) return $m[0];
                // फक्त JS Strings मधली वाक्यं (Comments नाहीत)
                $js = preg_replace_callback('/(["\'`])((?:\\\\.|(?!\1)[^\\\\\n])*)\1/u', function ($s) use ($lang) {
                    return $s[1] . i18nText($s[2], $lang, true) . $s[1];
                }, $m[2]);
                return $m[1] . $js . $m[3];
            }
            if (!empty($m[4])) {                       // <textarea> — फक्त Placeholder, आतला मजकूर नाही
                return i18nTag($m[4], $lang, $safeAttrs) . $m[5] . $m[6];
            }
            if (!empty($m[7])) return i18nTag($m[7], $lang, $safeAttrs);
            if (isset($m[8]) && $m[8] !== '') return i18nText($m[8], $lang);
            return $m[0];                              // <style>, Comments
        },
        $html
    );
    return $kept ? preg_replace_callback('/\x01I18N(\d+)\x01/', fn($m) => $kept[(int)$m[1]], $html) : $html;
}

/** एका Tag चे फक्त सुरक्षित Attributes; Submit/Button चं value */
function i18nTag($tag, $lang, $safeAttrs) {
    if (!preg_match('/[\x{0900}-\x{097F}]/u', $tag)) return $tag;
    $isButton = preg_match('/^<input\b/i', $tag) && preg_match('/\btype=["\']?(submit|button|reset)\b/i', $tag);
    $attrs = $safeAttrs . ($isButton ? '|value' : '');
    return preg_replace_callback('/(\s(?:' . $attrs . ')\s*=\s*)("([^"]*)"|\'([^\']*)\')/i', function ($m) use ($lang) {
        $isJs = preg_match('/^\s*on/i', $m[1]);
        if ($m[2][0] === '"') return $m[1] . '"' . i18nText($m[3], $lang, $isJs) . '"';
        return $m[1] . "'" . i18nText($m[4], $lang, true) . "'";
    }, $tag);
}

/** JSON उत्तर (AJAX) — फक्त String Values */
function i18nTranslateJson($json, $lang = null) {
    $lang = $lang ?: i18nLang();
    if ($lang === 'mr') return $json;
    $data = json_decode($json, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) return $json;
    $walk = function ($v) use (&$walk, $lang) {
        if (is_string($v)) return preg_match('/^\s*</', $v) ? i18nTranslateHtml($v, $lang) : i18nText($v, $lang);
        if (is_array($v)) { foreach ($v as $k => $x) { if (!in_array($k, ['value', 'id', 'key', 'url', 'href'], true)) $v[$k] = $walk($x); } }
        return $v;
    };
    return json_encode($walk($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Output Buffer मधून बोलावलं जातं (includes/csrf.php) */
function i18nFilterOutput($out) {
    $lang = i18nLang();
    if ($lang === 'mr' || $out === '') return $out;
    $ctype = '';
    foreach (headers_list() as $h) { if (stripos($h, 'Content-Type:') === 0) $ctype = strtolower($h); }
    if ($ctype && strpos($ctype, 'json') !== false) $res = i18nTranslateJson($out, $lang);
    elseif ($ctype && strpos($ctype, 'text/html') === false) return $out;   // CSV, PDF, Images — जसंच्या तसं
    else $res = i18nTranslateHtml($out, $lang);
    i18nFlushMisses();
    return $res;
}

/** Browser मधल्या JS Files च्या वाक्यांसाठी — त्या पानाला लागणारा छोटा शब्दकोश (assets/js मधली वाक्यं) */
function i18nClientDict($lang = null) {
    $lang = $lang ?: i18nLang();
    if ($lang === 'mr') return [];
    $file = __DIR__ . '/../lang/i18n/js-phrases.php';
    $keys = is_file($file) ? (require $file) : [];
    $dict = i18nDict($lang); $out = [];
    foreach ($keys as $k) { if (isset($dict[$k])) $out[$k] = $dict[$k]; }
    return $out;
}

/** भाषा निवडण्याचा Dropdown — $attrs: त्या पानाची जुनी Style/Class तशीच ठेवण्यासाठी */
function i18nSwitcher($attrs = '') {
    $cur = i18nLang();
    $html = '<!--i18n:off--><select id="eowmsLangSelect" onchange="eowmsSetLang(this.value)" aria-label="भाषा / Language" ' . $attrs . '>';
    foreach (EOWMS_LANGS as $k => $l) {
        $html .= '<option value="' . $k . '"' . ($k === $cur ? ' selected' : '') . ' >' . ($k === 'mr' ? '🌐 ' : '') . $l['label'] . '</option>';
    }
    return $html . '</select><!--i18n:on-->';
}

/** Browser साठी — भाषा बदलणं, आणि JS ने नंतर टाकलेला मजकूर (assets/js) भाषांतरित करणं */
function i18nClientScript() {
    $lang = i18nLang();
    $dict = i18nClientDict($lang);
    $js = 'window.EOWMS_LANG=' . json_encode($lang) . ';'
        . 'window.eowmsSetLang=function(l){try{var u=new URL(location.href);u.searchParams.set("lang",l);location.href=u.toString();}catch(e){location.search="?lang="+l;}};';
    if ($lang !== 'mr' && $dict) {
        $js .= 'window.EOWMS_I18N=' . json_encode($dict, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';'
            . '(function(){var D=window.EOWMS_I18N,R=/[\\u0900-\\u097F][\\u0900-\\u097F\\u200C\\u200DA-Za-z0-9 \\u00A0.,:;!?()\\u2019\-\\u2013\\u2014\/&%+]*[\\u0900-\\u097F.!?)]|[\\u0900-\\u097F]/g;'
            . 'function tr(s){return s.replace(R,function(r){var k=r.replace(/[  ]+/g," ").trim();if(D[k]){var a=r.match(/^\s*/)[0],b=r.match(/\s*$/)[0];return a+D[k]+b;}return r;});}'
            . 'function walk(n){if(n.nodeType===3){if(/[\\u0900-\\u097F]/.test(n.nodeValue)){var t=tr(n.nodeValue);if(t!==n.nodeValue)n.nodeValue=t;}return;}'
            . 'if(n.nodeType!==1||/^(SCRIPT|STYLE)$/.test(n.nodeName)||n.isContentEditable)return;'
            . '["placeholder","title","aria-label"].forEach(function(a){var v=n.getAttribute&&n.getAttribute(a);if(v&&/[\\u0900-\\u097F]/.test(v)){var t=tr(v);if(t!==v)n.setAttribute(a,t);}});'
            . 'if(/^(TEXTAREA|INPUT|SELECT)$/.test(n.nodeName))return;'
            . 'for(var c=n.firstChild;c;c=c.nextSibling)walk(c);}'
            . 'window.eowmsT=tr;'
            . 'new MutationObserver(function(ms){ms.forEach(function(m){if(m.type==="characterData")walk(m.target);else m.addedNodes.forEach(walk);});})'
            . '.observe(document.documentElement,{childList:true,subtree:true,characterData:true});})();';
    } else {
        $js .= 'window.eowmsT=function(s){return s;};';
    }
    return '<!--i18n:off--><script>' . $js . '</script><!--i18n:on-->';
}
