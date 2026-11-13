<?php
/**
 * पहिल्यांदा कर्मचारी जोडल्यावर स्वागत ईमेल पाठवण्यासाठी helper.
 * PHP च्या built-in mail() function वापरतो — Hostinger सारख्या shared
 * hosting वर हे आपोआप काम करतं (server-level sendmail/SMTP आधीच सेट असतो).
 * XAMPP वर local testing साठी mail() काम करणार नाही (SMTP सेट नसल्यास) —
 * त्यामुळे ही फंक्शन silently fail होते, कुठलाही fatal error देत नाही.
 */

/**
 * Role/Category नुसार छोटी, वेगळी ओळख करून देणारी ओळ — जेणेकरून प्रत्येकाला
 * आपल्या भूमिकेनुसार काय अपेक्षित आहे हे लगेच कळेल.
 */
function roleWiseIntroLine($category) {
    $lines = [
        'super_admin' => 'तुमच्याकडे संपूर्ण प्रणालीचं सर्वोच्च नियंत्रण असेल — संस्था, कर्मचारी, पदे, आणि सर्व मॉड्यूल्स व्यवस्थापित करता येतील.',
        'management'  => 'तुम्ही संपूर्ण संस्थेचे अहवाल, टास्क असाइनमेंट आणि कर्मचाऱ्यांची माहिती पाहू शकाल — तुमच्या भूमिकेनुसार.',
        'hod'         => 'तुम्ही तुमच्या विभागाचे डॅशबोर्ड, कामाच्या नोंदी आणि तुमच्या विभागातील कर्मचाऱ्यांचं व्यवस्थापन पाहू शकाल.',
        'staff'       => 'तुम्ही रोजच्या कामाची नोंद करू शकाल, तुम्हाला दिलेले टास्क पाहू व अपडेट करू शकाल.',
    ];
    return $lines[$category] ?? $lines['staff'];
}

/**
 * स्वागत ईमेल पाठवते. $email रिकामा किंवा अवैध असल्यास काहीही करत नाही.
 * Returns true जर पाठवण्याचा प्रयत्न झाला (mail() ने खरंच पोहोचवलं याची खात्री देत नाही).
 */
function sendWelcomeEmail($email, $name, $username, $plainPassword, $orgName, $category, $loginUrl) {
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $intro = roleWiseIntroLine($category);
    $subject = "$orgName — तुमचं E-Admin खातं तयार झालं";

    $body = "
    <div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;background:#F3F4F7;'>
      <div style='background:#10162B;padding:20px 24px;border-radius:10px 10px 0 0;'>
        <div style='color:#fff;font-size:20px;font-weight:bold;'>$orgName</div>
        <div style='color:#DCEFEC;font-size:13px;margin-top:4px;'>E-Admin — Smart Administration</div>
      </div>
      <div style='background:#fff;padding:24px;border-radius:0 0 10px 10px;'>
        <p style='font-size:14px;color:#1A1D29;'>नमस्कार <strong>$name</strong>,</p>
        <p style='font-size:14px;color:#1A1D29;line-height:1.6;'>$orgName च्या E-Admin प्रणालीमध्ये तुमचं खातं तयार करण्यात आलं आहे. $intro</p>
        <div style='background:#F3F4F7;border-radius:8px;padding:16px 20px;margin:18px 0;'>
          <div style='font-size:12px;color:#666B7D;text-transform:uppercase;'>Login ID</div>
          <div style='font-size:16px;font-weight:bold;color:#D9530F;margin-bottom:10px;'>$username</div>
          <div style='font-size:12px;color:#666B7D;text-transform:uppercase;'>तात्पुरता पासवर्ड</div>
          <div style='font-size:16px;font-weight:bold;color:#D9530F;'>$plainPassword</div>
        </div>
        <p style='font-size:13px;color:#B23A48;'>⚠️ पहिल्यांदा Login केल्यावर तुम्हाला स्वतःचा नवीन पासवर्ड तयार करावा लागेल — सुरक्षिततेसाठी हे अनिवार्य आहे.</p>
        <a href='$loginUrl' style='display:inline-block;background:#D9530F;color:#fff;text-decoration:none;padding:10px 22px;border-radius:8px;font-size:14px;font-weight:bold;margin-top:10px;'>Login करा</a>
        <p style='font-size:11.5px;color:#8A90AC;margin-top:20px;'>!! आत्मा मालिक !! — हा ईमेल आपोआप पाठवला गेला आहे.</p>
      </div>
    </div>";

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode(trim(preg_replace('/[\r\n"]+/', ' ', (string)$orgName))) . "?= <no-reply@" . eowmsMailDomain() . ">\r\n";

    try {
        return eowmsMailRaw($email, $subject, $body, $headers);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * पासवर्ड रिसेट verification code ईमेल करते.
 */
function sendPasswordResetEmail($email, $name, $code, $orgName) {
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = "$orgName — पासवर्ड रिसेट Verification Code";

    $body = "
    <div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;background:#F3F4F7;'>
      <div style='background:#10162B;padding:20px 24px;border-radius:10px 10px 0 0;'>
        <div style='color:#fff;font-size:20px;font-weight:bold;'>$orgName</div>
        <div style='color:#DCEFEC;font-size:13px;margin-top:4px;'>पासवर्ड रिसेट विनंती</div>
      </div>
      <div style='background:#fff;padding:24px;border-radius:0 0 10px 10px;'>
        <p style='font-size:14px;color:#1A1D29;'>नमस्कार <strong>$name</strong>,</p>
        <p style='font-size:14px;color:#1A1D29;line-height:1.6;'>तुमच्या खात्यासाठी पासवर्ड रिसेट करण्याची विनंती आली आहे. खालील verification code वापरा:</p>
        <div style='background:#F3F4F7;border-radius:8px;padding:18px 20px;margin:18px 0;text-align:center;'>
          <div style='font-size:32px;font-weight:bold;color:#D9530F;letter-spacing:6px;'>$code</div>
        </div>
        <p style='font-size:13px;color:#666B7D;'>हा code फक्त १५ मिनिटांसाठी वैध आहे. जर तुम्ही ही विनंती केली नसेल, तर हा ईमेल दुर्लक्षित करा — तुमचा पासवर्ड बदलणार नाही.</p>
        <p style='font-size:11.5px;color:#8A90AC;margin-top:20px;'>!! आत्मा मालिक !! — हा ईमेल आपोआप पाठवला गेला आहे.</p>
      </div>
    </div>";

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode(trim(preg_replace('/[\r\n"]+/', ' ', (string)$orgName))) . "?= <no-reply@" . eowmsMailDomain() . ">\r\n";

    try {
        return eowmsMailRaw($email, $subject, $body, $headers);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * नूतनीकरण-मुदत जवळ आल्याची/संपल्याची Email पाठवते. Escalating Reminder
 * (cron/send-renewal-reminders.php) मधून वापरली जाते — त्याच माईलस्टोन्स
 * आणि त्याच व्यक्तींना, In-App Notification बरोबरच Email सुद्धा जाईल.
 */
function sendRenewalReminderEmail($email, $name, $itemName, $daysLeft, $expiryDate, $orgName, $link) {
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $isExpired = $daysLeft < 0;
    $statusLine = $isExpired ? "आधीच <strong style='color:#B23A48;'>" . abs($daysLeft) . " दिवस उलटून</strong> मुदत संपली आहे" : "<strong style='color:#C97F1D;'>$daysLeft दिवसात</strong> मुदत संपणार आहे";
    $subject = ($isExpired ? "⚠️ मुदत संपली — " : "🔔 नूतनीकरण इशारा — ") . $itemName;

    $body = "
    <div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;background:#F3F4F7;'>
      <div style='background:#10162B;padding:20px 24px;border-radius:10px 10px 0 0;'>
        <div style='color:#fff;font-size:20px;font-weight:bold;'>$orgName</div>
        <div style='color:#DCEFEC;font-size:13px;margin-top:4px;'>नूतनीकरण व अनुपालन — Reminder</div>
      </div>
      <div style='background:#fff;padding:24px;border-radius:0 0 10px 10px;'>
        <p style='font-size:14px;color:#1A1D29;'>नमस्कार <strong>$name</strong>,</p>
        <div style='background:#F7E2E3;border-radius:8px;padding:16px 20px;margin:14px 0;'>
          <div style='font-size:15px;font-weight:bold;color:#1A1D29;'>$itemName</div>
          <div style='font-size:13px;color:#666B7D;margin-top:6px;'>$statusLine</div>
          <div style='font-size:12px;color:#666B7D;margin-top:4px;'>मुदत-दिनांक: $expiryDate</div>
        </div>
        <a href='$link' style='display:inline-block;background:#D9530F;color:#fff;text-decoration:none;padding:10px 22px;border-radius:8px;font-size:14px;font-weight:bold;margin-top:6px;'>System मध्ये बघा</a>
        <p style='font-size:11.5px;color:#8A90AC;margin-top:20px;'>!! आत्मा मालिक !! — हा ईमेल आपोआप पाठवला गेला आहे.</p>
      </div>
    </div>";

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode(trim(preg_replace('/[\r\n"]+/', ' ', (string)$orgName))) . "?= <no-reply@" . eowmsMailDomain() . ">\r\n";

    try {
        return eowmsMailRaw($email, $subject, $body, $headers);
    } catch (Throwable $e) {
        return false;
    }
}

/** खरेदी बाकी नोंदवहीचा साप्ताहिक Summary Email — Trust Leadership ला. */
function sendPurchasePendingSummaryEmail($email, $name, $orgName, $fy, $totalCount, $overdueCount, $totalAmount, $deptRows, $link) {
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = "📋 खरेदी बाकी नोंदवही — साप्ताहिक सारांश ($fy)";

    $deptRowsHtml = '';
    foreach ($deptRows as $dn => $ds) {
        $deptRowsHtml .= "<tr><td style='padding:8px 12px;border-bottom:1px solid #EEF0F4;'>" . htmlspecialchars($dn) . "</td>"
            . "<td style='padding:8px 12px;border-bottom:1px solid #EEF0F4;text-align:center;'>{$ds['count']}</td>"
            . "<td style='padding:8px 12px;border-bottom:1px solid #EEF0F4;text-align:right;'>₹" . number_format($ds['amount'], 0) . "</td></tr>";
    }

    $body = "<div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;'>
      <div style='background:#10162B;padding:20px 24px;border-radius:12px 12px 0 0;'>
        <div style='color:#fff;font-size:17px;font-weight:bold;'>📋 खरेदी बाकी नोंदवही</div>
        <div style='color:#B7BBD6;font-size:12.5px;'>साप्ताहिक सारांश — आर्थिक वर्ष $fy</div>
      </div>
      <div style='background:#fff;border:1px solid #E4E7ED;border-top:none;padding:22px 24px;border-radius:0 0 12px 12px;'>
        <p style='font-size:14px;color:#1A1D29;'>नमस्कार " . htmlspecialchars($name) . ",</p>
        <div style='display:flex;gap:10px;margin:16px 0;'>
          <div style='flex:1;background:#F3F4F7;border-radius:9px;padding:12px;text-align:center;'><div style='font-size:20px;font-weight:bold;color:#10162B;'>$totalCount</div><div style='font-size:11px;color:#666B7D;'>एकूण नोंदी</div></div>
          <div style='flex:1;background:#F7E2E3;border-radius:9px;padding:12px;text-align:center;'><div style='font-size:20px;font-weight:bold;color:#B23A48;'>$overdueCount</div><div style='font-size:11px;color:#666B7D;'>60+ दिवस Pending</div></div>
          <div style='flex:1;background:#F3F4F7;border-radius:9px;padding:12px;text-align:center;'><div style='font-size:16px;font-weight:bold;color:#10162B;'>₹" . number_format($totalAmount, 0) . "</div><div style='font-size:11px;color:#666B7D;'>एकूण रक्कम</div></div>
        </div>
        <table style='width:100%;border-collapse:collapse;font-size:12.5px;margin-top:10px;'>
          <thead><tr style='background:#F3F4F7;'><th style='padding:8px 12px;text-align:left;'>विभाग</th><th style='padding:8px 12px;'>नोंदी</th><th style='padding:8px 12px;text-align:right;'>रक्कम</th></tr></thead>
          <tbody>$deptRowsHtml</tbody>
        </table>
        <a href='$link' style='display:inline-block;background:#D9530F;color:#fff;text-decoration:none;padding:10px 22px;border-radius:8px;font-size:14px;font-weight:bold;margin-top:20px;'>संपूर्ण Register बघा</a>
        <p style='font-size:11.5px;color:#8A90AC;margin-top:20px;'>!! आत्मा मालिक !! — हा ईमेल आपोआप, दर आठवड्याला पाठवला जातो.</p>
      </div>
    </div>";

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: =?UTF-8?B?" . base64_encode(trim(preg_replace('/[\r\n"]+/', ' ', (string)$orgName))) . "?= <no-reply@" . eowmsMailDomain() . ">\r\n";

    try {
        return eowmsMailRaw($email, $subject, $body, $headers);
    } catch (Throwable $e) {
        return false;
    }
}

/** वाढदिवसाचा Email — HTML (Admin पानावर Preview साठी पण) */
function birthdayEmailHtml($name, $orgName) {
    $n = htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8'); $o = htmlspecialchars((string)$orgName, ENT_QUOTES, 'UTF-8');
    return "
    <div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;background:#F3F4F7;'>
      <div style='background:#10162B;padding:20px 24px;border-radius:10px 10px 0 0;text-align:center;'>
        <div style='color:#fff;font-size:20px;font-weight:bold;'>$o</div>
      </div>
      <div style='background:#fff;padding:28px 24px;border-radius:0 0 10px 10px;text-align:center;'>
        <div style='font-size:36px;margin-bottom:10px;'>🎂🎉</div>
        <p style='font-size:16px;color:#1A1D29;'>प्रिय <strong>$n</strong>,</p>
        <p style='font-size:14px;color:#1A1D29;line-height:1.7;'>आजच्या तुमच्या वाढदिवसानिमित्त $o परिवाराकडून तुम्हाला मनःपूर्वक शुभेच्छा! तुमचं हे वर्ष आरोग्यदायी, आनंदी आणि यशस्वी जावो अशी सद्गुरूंच्या चरणी प्रार्थना.</p>
        <p style='font-size:24px;margin:16px 0;'>🎈🎁🎊</p>
        <p style='font-size:11.5px;color:#8A90AC;margin-top:20px;'>!! आत्मा मालिक !! — हा ईमेल आपोआप पाठवला गेला आहे.</p>
      </div>
    </div>";
}

/** वाढदिवसाच्या शुभेच्छांचा Email पाठवते. */
function sendBirthdayEmail($email, $name, $orgName) {
    return eowmsSendMail($email, "🎂 वाढदिवसाच्या हार्दिक शुभेच्छा, $name!", birthdayEmailHtml($name, $orgName), [], $orgName) > 0;
}

/** कामाच्या वर्धापनदिनाचा Email — HTML */
function workAnniversaryEmailHtml($name, $yearsCompleted, $orgName) {
    $n = htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8'); $o = htmlspecialchars((string)$orgName, ENT_QUOTES, 'UTF-8'); $y = (int)$yearsCompleted;
    return "
    <div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;background:#F3F4F7;'>
      <div style='background:#10162B;padding:20px 24px;border-radius:10px 10px 0 0;text-align:center;'>
        <div style='color:#fff;font-size:20px;font-weight:bold;'>$o</div>
      </div>
      <div style='background:#fff;padding:28px 24px;border-radius:0 0 10px 10px;text-align:center;'>
        <div style='font-size:36px;margin-bottom:10px;'>🏆🎊</div>
        <p style='font-size:16px;color:#1A1D29;'>प्रिय <strong>$n</strong>,</p>
        <p style='font-size:14px;color:#1A1D29;line-height:1.7;'>आज तुम्ही $o परिवारासोबत <strong style='color:#D9530F;'>$y वर्षं</strong> पूर्ण करत आहात. तुमच्या समर्पणाबद्दल आणि योगदानाबद्दल मनःपूर्वक धन्यवाद — पुढील वाटचालीसाठी शुभेच्छा!</p>
        <p style='font-size:11.5px;color:#8A90AC;margin-top:20px;'>!! आत्मा मालिक !! — हा ईमेल आपोआप पाठवला गेला आहे.</p>
      </div>
    </div>";
}

/** कामाच्या वर्धापनदिनाच्या (Work Anniversary) अभिनंदनाचा Email पाठवते. */
function sendWorkAnniversaryEmail($email, $name, $yearsCompleted, $orgName) {
    return eowmsSendMail($email, "🎊 $orgName सोबत $yearsCompleted वर्षं पूर्ण — अभिनंदन!", workAnniversaryEmailHtml($name, $yearsCompleted, $orgName), [], $orgName) > 0;
}

/** From-पत्त्याचं Domain — Cron (CLI) मधून HTTP_HOST नसतो, म्हणून SITE_BASE_URL वरून */
function eowmsMailDomain() {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ((!$host || $host === 'localhost') && defined('SITE_BASE_URL')) { $host = parse_url(SITE_BASE_URL, PHP_URL_HOST) ?: $host; }
    $host = preg_replace('/:\d+$/', '', strtolower((string)$host));
    $host = preg_replace('/^www\./', '', $host);
    return $host ?: 'localhost';
}

/**
 * HTML Email — हवं तर Attachments सह (PDF, Encrypted ZIP).
 * $attachments = [['name' => 'report.pdf', 'data' => <bytes>, 'type' => 'application/pdf'], ...]
 * $to = एक पत्ता किंवा पत्त्यांची यादी (प्रत्येकाला वेगळा Email — एकमेकांचे पत्ते दिसत नाहीत).
 * परत: किती पत्त्यांवर mail() यशस्वी झालं.
 */
function eowmsSendMail($to, $subject, $html, array $attachments = [], $fromName = 'E-Admin') {
    $list = array_values(array_filter((array)$to, fn($e) => $e && filter_var($e, FILTER_VALIDATE_EMAIL)));
    if (!$list) { return 0; }
    $fromName = trim(preg_replace('/[\r\n"]+/', ' ', (string)$fromName)) ?: 'E-Admin';
    $from = 'no-reply@' . eowmsMailDomain();
    $headers = "MIME-Version: 1.0\r\nFrom: =?UTF-8?B?" . base64_encode($fromName) . "?= <$from>\r\n";
    if (!$attachments) {
        $headers .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
        $body = chunk_split(base64_encode($html));
    } else {
        $b = 'eowms-' . bin2hex(random_bytes(12));
        $headers .= "Content-Type: multipart/mixed; boundary=\"$b\"\r\n";
        $body = "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html));
        foreach ($attachments as $a) {
            $fname = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)($a['name'] ?? 'file'));
            $type = preg_match('#^[\w.+-]+/[\w.+-]+$#', $a['type'] ?? '') ? $a['type'] : 'application/octet-stream';
            $body .= "--$b\r\nContent-Type: $type; name=\"$fname\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$fname\"\r\n\r\n" . chunk_split(base64_encode((string)$a['data']));
        }
        $body .= "--$b--\r\n";
    }
    $sent = 0;
    foreach ($list as $addr) {
        if (eowmsMailRaw($addr, (string)$subject, $body, $headers, count($attachments), strlen($body))) { $sent++; }
    }
    return $sent;
}

/**
 * ✉️ प्रत्यक्ष mail() — आणि प्रत्येक Email ची नोंद email_log मध्ये (कधी, कोणाला, कोणता, यशस्वी/अयशस्वी).
 * Body (पासवर्ड/OTP असू शकतात) कधीच साठवत नाही; OTP/पासवर्डच्या Subject मधले आकडे लपवतो.
 */
function eowmsMailRaw($addr, $subject, $body, $headers, $attachCount = 0, $size = null) {
    $ok = false; $err = null;
    try { $ok = (bool)@mail($addr, '=?UTF-8?B?' . base64_encode((string)$subject) . '?=', $body, $headers); }
    catch (Throwable $e) { $err = $e->getMessage(); }
    if (!$ok && !$err) { $le = error_get_last(); $err = $le && stripos($le['message'] ?? '', 'mail') !== false ? $le['message'] : 'Server ने Email स्वीकारला नाही (mail() अयशस्वी)'; }
    eowmsMailLog($addr, (string)$subject, $ok, $err, $attachCount, $size ?? strlen((string)$body));
    return $ok;
}

/** कोणत्या कामातून Email गेला — बोलावणाऱ्या function/फाईलवरून ओळखतो */
function eowmsMailCategory() {
    $byFn = ['sendWelcomeEmail' => 'welcome', 'sendPasswordResetEmail' => 'password', 'sendRenewalReminderEmail' => 'renewal',
        'sendPurchasePendingSummaryEmail' => 'purchase', 'sendBirthdayEmail' => 'birthday', 'sendWorkAnniversaryEmail' => 'anniversary'];
    $byFile = ['two-factor.php' => 'otp', 'offsite-backup.php' => 'backup', 'daily-report.php' => 'daily', 'weekly-report.php' => 'weekly',
        'emergency-helpers.php' => 'sos', 'update.php' => 'email_change', 'celebrations.php' => 'birthday'];
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $f) {
        if (isset($f['function'], $byFn[$f['function']])) return $byFn[$f['function']];
    }
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12) as $f) {
        $b = basename($f['file'] ?? '');
        if ($b && $b !== 'mailer.php' && isset($byFile[$b])) return $byFile[$b];
    }
    return 'other';
}

/** Email प्रकारांची नावं (नोंद पानासाठी) */
function eowmsMailCategories() {
    return ['welcome' => '👋 नवीन खातं', 'password' => '🔑 पासवर्ड रिसेट', 'otp' => '🔐 Login Code (2FA)', 'email_change' => '📧 Email बदल',
        'renewal' => '⏳ नूतनीकरण आठवण', 'purchase' => '🛒 खरेदी प्रलंबित', 'birthday' => '🎂 वाढदिवस', 'anniversary' => '🎊 वर्धापनदिन',
        'daily' => '📰 दैनिक अहवाल', 'weekly' => '📊 साप्ताहिक अहवाल', 'backup' => '☁️ Backup', 'sos' => '🚨 SOS', 'other' => '✉️ इतर'];
}

function eowmsMailLog($addr, $subject, $ok, $err = null, $attachCount = 0, $size = 0) {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) return;
    try {
        $cat = eowmsMailCategory();
        if (in_array($cat, ['otp', 'password'], true)) $subject = preg_replace('/\d{4,}/', '••••••', $subject);
        $st = $pdo->prepare("SELECT id, organization_id FROM users WHERE email = ? ORDER BY is_active DESC LIMIT 1");
        $st->execute([$addr]); $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $orgId = $u['organization_id'] ?? null;
        if (!$orgId && PHP_SAPI !== 'cli' && function_exists('activeOrgId')) { try { $orgId = activeOrgId() ?: null; } catch (Throwable $e) {} }
        $by = null;
        $src = PHP_SAPI === 'cli' ? 'cron:' . basename($_SERVER['argv'][0] ?? $_SERVER['SCRIPT_NAME'] ?? '') : (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
        // Page उघडल्यावर पार्श्वभूमीत आपोआप जाणारे (उदा. दैनिक अहवाल) — ते "आपोआप", व्यक्तीने पाठवलेले नाहीत
        if (preg_match('/autosend|cron/i', $src)) { $src = 'cron:' . basename($src); }
        elseif (PHP_SAPI !== 'cli' && function_exists('currentUser')) { try { $cu = currentUser(); $by = $cu['id'] ?? null; } catch (Throwable $e) {} }
        $pdo->prepare("INSERT INTO email_log (organization_id, to_email, to_user_id, subject, category, status, error_message, attachments, size_bytes, sent_by, source, sent_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$orgId, mb_substr($addr, 0, 190), $u['id'] ?? null, mb_substr($subject, 0, 255), $cat, $ok ? 'sent' : 'failed',
                $err ? mb_substr($err, 0, 255) : null, (int)$attachCount, (int)$size, $by, mb_substr($src, 0, 120)]);
        if (mt_rand(1, 300) === 1) { $pdo->exec("DELETE FROM email_log WHERE sent_at < NOW() - INTERVAL 365 DAY"); }
    } catch (Throwable $e) {}
}
