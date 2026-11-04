<?php
// t('sNNN') → lang/mr.php मधला मराठी मजकूर.
// हिंदी / English भाषांतर includes/i18n.php (lang/i18n शब्दकोश) आपोआप करतं.

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
