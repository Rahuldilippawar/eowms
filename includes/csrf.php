<?php
// CSRF संरक्षण — includes/auth.php आणि includes/student-auth.php दोन्हीकडून
// session_start() नंतर लगेच Include होते.
//
// 1) प्रत्येक Session साठी एक गुप्त Token.
// 2) प्रत्येक POST Request — Form मधला `_csrf` Field किंवा `X-CSRF-Token`
//    Header जुळला तरच पुढे जाते; नाहीतर 403.
// 3) प्रत्येक HTML पानातल्या method="post" Form मध्ये Token चा Hidden Field
//    आपोआप घातला जातो (Output Buffer), आणि <head> मध्ये एक छोटी Script —
//    जी JavaScript ने बनवलेल्या Forms ना आणि fetch() POST ना Token जोडते.
//    त्यामुळे प्रत्येक पानात हाताने बदल करावा लागत नाही.

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="_csrf" value="' . csrfToken() . '">';
}

function csrfRequestIsValid() {
    // दुसऱ्या Website वरून आलेली Request — Browser Origin Header पाठवतो
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && $origin !== 'null') {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $ownHost = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        if (!$originHost || !$ownHost || strcasecmp($originHost, $ownHost) !== 0) {
            return false;
        }
    }

    $expected = $_SESSION['csrf_token'] ?? '';
    // Form Field किंवा Header — दोन्हीपैकी कुठलाही जुळला तरी चालेल (Offline
    // Queue मधल्या जुन्या Form मध्ये जुना Field असू शकतो, Header ताजा असतो)
    foreach ([$_POST['_csrf'] ?? null, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null] as $sent) {
        if ($expected !== '' && is_string($sent) && hash_equals($expected, $sent)) {
            return true;
        }
    }

    // JSON Body असलेल्या Requests (Android App, WebAuthn) — परकी Website
    // Browser मधून application/json Content-Type सह Cross-Site POST पाठवू
    // शकत नाही (CORS Preflight अडवतो), त्यामुळे या सुरक्षित आहेत.
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    return $contentType === 'application/json';
}

function csrfRejectRequest() {
    http_response_code(403);
    $isJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
        || !empty($_SERVER['HTTP_X_CSRF_TOKEN'])
        || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'पान जुनं झालं आहे — कृपया पान Refresh करून पुन्हा प्रयत्न करा.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo '<!DOCTYPE html><html lang="mr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>पुन्हा प्रयत्न करा</title></head>'
        . '<body style="font-family:\'Noto Sans Devanagari\',sans-serif;background:#F5F6FA;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;">'
        . '<div style="background:#fff;border-radius:16px;padding:32px;max-width:440px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.08);">'
        . '<div style="font-size:36px;">🔒</div>'
        . '<div style="font-size:16px;font-weight:700;margin:10px 0;color:#10162B;">पान जुनं झालं आहे</div>'
        . '<p style="font-size:13px;color:#666B7D;line-height:1.8;">सुरक्षिततेसाठी ही माहिती जतन केली नाही. कृपया मागे जाऊन पान Refresh करा आणि पुन्हा प्रयत्न करा.</p>'
        . '<a href="javascript:history.back()" style="display:inline-block;margin-top:10px;background:#1B8A83;color:#fff;text-decoration:none;padding:10px 24px;border-radius:9px;font-size:13px;font-weight:600;">मागे जा</a>'
        . '</div></body></html>';
    exit;
}

/**
 * Output Buffer Callback — HTML मधल्या प्रत्येक POST Form मध्ये Token घालतो,
 * आणि <head> मध्ये Meta Tag + Script.
 */
function csrfRewriteOutput($html) {
    if (stripos($html, '<form') === false && stripos($html, '<head') === false) {
        return $html;
    }
    // फक्त HTML पानं — JSON/CSV/File Responses मध्ये काहीही बदलत नाही
    foreach (headers_list() as $hdr) {
        if (stripos($hdr, 'Content-Type:') === 0 && stripos($hdr, 'text/html') === false) {
            return $html;
        }
    }
    $token = csrfToken();
    $field = '<input type="hidden" name="_csrf" value="' . $token . '">';
    $html = preg_replace_callback('/<form\b[^>]*>/i', function ($m) use ($field) {
        return preg_match('/\bmethod\s*=\s*["\']?post\b/i', $m[0]) ? $m[0] . $field : $m[0];
    }, $html);

    $script = '<meta name="csrf-token" content="' . $token . '"><script>(function(){'
        . 'var t=' . json_encode($token) . ';'
        . 'function add(f){if(!f||String(f.method).toLowerCase()!=="post"||(f.elements&&f.elements._csrf))return;'
        . 'var i=document.createElement("input");i.type="hidden";i.name="_csrf";i.value=t;f.appendChild(i);}'
        . 'document.addEventListener("submit",function(e){add(e.target);},true);'
        . 'var s=HTMLFormElement.prototype.submit;HTMLFormElement.prototype.submit=function(){add(this);return s.call(this);};'
        . 'if(window.fetch){var of=window.fetch;window.fetch=function(u,o){o=o||{};'
        . 'if(String(o.method||"GET").toUpperCase()!=="GET"){try{var a=new URL(typeof u==="string"?u:u.url,location.href);'
        . 'if(a.origin===location.origin){var h=new Headers(o.headers||{});h.set("X-CSRF-Token",t);o.headers=h;}}catch(x){}}'
        . 'return of.call(this,u,o);};}'
        . '})();</script>';
    return preg_replace_callback('/<head\b[^>]*>/i', fn($m) => $m[0] . $script, $html, 1);
}

/** मोठ्या File Downloads आधी — Output Buffer बंद करतो (पूर्ण File Memory मध्ये धरून ठेवू नये म्हणून) */
function csrfStopOutputRewrite() {
    while (ob_get_level() > 0 && in_array('csrfRewriteOutput', ob_list_handlers(), true)) {
        ob_end_flush();
    }
}

if (PHP_SAPI !== 'cli') {
    csrfToken();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrfRequestIsValid()) {
        csrfRejectRequest();
    }
    if (!in_array('csrfRewriteOutput', ob_list_handlers(), true)) {
        ob_start('csrfRewriteOutput');
    }
}
