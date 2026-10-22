<?php
// फक्त मराठी — Native Multi-Language System काढून टाकलं आहे.
// इतर कुठलीही भाषा हवी असल्यास — header.php मधलं Google Translate
// Widget वापरून संपूर्ण पान भाषांतरित करता येतं.

function currentLang() {
    return 'mr';
}

function loadTranslations() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = require __DIR__ . '/../lang/mr.php';
    return $cache;
}

/** Translate a key. Falls back to the key itself (or $default) if missing. */
function t($key, $default = null) {
    $strings = loadTranslations();
    return $strings[$key] ?? ($default ?? $key);
}
