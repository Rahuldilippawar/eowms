<?php
/**
 * 🕘 संपूर्ण Project ची वेळ — भारत / मुंबई-कोलकाता (Asia/Kolkata, IST +05:30)
 *  - PHP ची वेळ (date(), PDF वरची "तयार:" वेळ, Email, Cron ची वेळ) — IST
 *  - Database (MySQL/MariaDB) Session ची वेळ — NOW()/CURDATE()/CURRENT_TIMESTAMP सुद्धा IST
 * config/db.php नंतर लगेच हे require करा (Server वरच्या config/db.php वर अवलंबून राहू नये म्हणून इथे).
 */
const EOWMS_TIMEZONE = 'Asia/Kolkata';
const EOWMS_DB_OFFSET = '+05:30';

date_default_timezone_set(EOWMS_TIMEZONE);
@ini_set('date.timezone', EOWMS_TIMEZONE);

function eowmsDbTimezone($pdo) {
    if ($pdo instanceof PDO) {
        try { $pdo->exec("SET time_zone = '" . EOWMS_DB_OFFSET . "'"); } catch (Throwable $e) { /* परवानगी नसेल तर PHP ची वेळ तरी IST राहते */ }
    }
}

if (isset($pdo)) { eowmsDbTimezone($pdo); }
