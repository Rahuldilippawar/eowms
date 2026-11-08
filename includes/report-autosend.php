<?php
/**
 * 🌙 दैनिक अहवाल — Cron नसला / बंद पडला तरी आपोआप जावा म्हणून "Backup" मार्ग
 *  - कोणीही App वापरत असताना (Login केलेलं कोणतंही पान) वेळ झाली असेल आणि आजचा अहवाल गेला नसेल
 *    तर Browser पार्श्वभूमीत ajax-report-autosend.php ला सांगतो → अहवाल एकदाच जातो
 *  - काल संध्याकाळी गेला नसेल (उदा. रात्री कोणी App उघडलं नाही) तर आज सकाळी कालचा अहवाल जातो
 *  - Cron चालू असेल तर तोच आधी पाठवतो; दोन्हींकडून दुप्पट जात नाही (MySQL GET_LOCK + last_date)
 */
require_once __DIR__ . '/app-settings.php';

/** आत्ता कोणत्या तारखेचा अहवाल पाठवायचा? (null = काही नाही) — हलकं, प्रत्येक पानावर चालतं */
function reportAutoSendDueDate(PDO $pdo, $orgId, $fresh = false) {
    if (!$orgId || !orgSettingsReady($pdo)) { return null; }
    // $fresh = orgSetting() चा Cache टाळून थेट DB मधून (Lock घेतल्यानंतरची तपासणी)
    $get = function ($k, $d = null) use ($pdo, $orgId, $fresh) {
        if (!$fresh) { return orgSetting($pdo, $orgId, $k, $d); }
        $st = $pdo->prepare("SELECT setting_value FROM org_settings WHERE organization_id = ? AND setting_key = ?");
        $st->execute([(int)$orgId, $k]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $d : $v;
    };
    if ($get('daily_report_enabled', '1') !== '1') { return null; }
    $hour = (int)$get('daily_report_hour', '20');
    if ($hour < 12 || $hour > 23) { $hour = 20; }
    $today = date('Y-m-d');
    $yday = date('Y-m-d', strtotime('-1 day'));
    $last = (string)$get('daily_report_last_date', '');
    $lastAt = $get('daily_report_last_at');
    $recent = $lastAt && strtotime($lastAt) > time() - 3600;
    $lastOk = str_starts_with((string)$get('daily_report_last_msg', ''), '✓');
    if ((int)date('G') >= $hour) {                                       // संध्याकाळ — आजचा
        if ($last === $today && ($lastOk || $recent)) { return null; }    // गेला / नुकताच प्रयत्न (अयशस्वी असेल तर तासाने पुन्हा)
        return $today;
    }
    if ($last === $yday || $last === $today || $recent) { return null; } // सकाळ — कालचा गेला नसेल तरच, एकदाच
    return $yday;
}

/** प्रत्यक्ष पाठवणं — एकाच वेळी दोन ठिकाणांहून नाही */
function reportAutoSendRun(PDO $pdo, $orgId, $via = 'web') {
    $date = reportAutoSendDueDate($pdo, $orgId);
    if (!$date) { return ['ok' => true, 'sent' => false, 'msg' => 'आत्ता काही पाठवायचं नाही']; }
    $lock = 'eadmin_daily_report_' . (int)$orgId;
    $got = (int)$pdo->query("SELECT GET_LOCK(" . $pdo->quote($lock) . ", 0)")->fetchColumn();
    if ($got !== 1) { return ['ok' => true, 'sent' => false, 'msg' => 'दुसरीकडून आधीच पाठवला जात आहे']; }
    try {
        require_once __DIR__ . '/daily-report.php';
        $date = reportAutoSendDueDate($pdo, $orgId, true); // Lock नंतर पुन्हा, थेट DB मधून
        if (!$date) { return ['ok' => true, 'sent' => false, 'msg' => 'आत्ता काही पाठवायचं नाही']; }
        $r = dailyReportSend($pdo, $orgId, $date);
        $r['sent'] = true; $r['date'] = $date;
        if (empty($r['ok']) && str_contains((string)($r['msg'] ?? ''), 'पत्ता नाही')) {
            // पत्ता नसल्याने पाठवलाच नाही — नोंद ठेवा, म्हणजे प्रत्येक पानावर पुन्हा प्रयत्न होणार नाही (तासाने पुन्हा)
            try {
                orgSettingSet($pdo, $orgId, 'daily_report_last_date', $date);
                orgSettingSet($pdo, $orgId, 'daily_report_last_at', date('Y-m-d H:i:s'));
                orgSettingSet($pdo, $orgId, 'daily_report_last_msg', '✗ ' . $r['msg']);
            } catch (PDOException $e) {}
        }
        try { orgSettingSet($pdo, $orgId, 'daily_report_last_via', $via); } catch (PDOException $e) {}
        return $r;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK(" . $pdo->quote($lock) . ")");
    }
}
