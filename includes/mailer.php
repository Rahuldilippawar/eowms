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
    $subject = "$orgName — तुमचं EOWMS खातं तयार झालं";

    $body = "
    <div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;background:#F3F4F7;'>
      <div style='background:#10162B;padding:20px 24px;border-radius:10px 10px 0 0;'>
        <div style='color:#fff;font-size:20px;font-weight:bold;'>$orgName</div>
        <div style='color:#DCEFEC;font-size:13px;margin-top:4px;'>Enterprise Operations & Workflow Management System</div>
      </div>
      <div style='background:#fff;padding:24px;border-radius:0 0 10px 10px;'>
        <p style='font-size:14px;color:#1A1D29;'>नमस्कार <strong>$name</strong>,</p>
        <p style='font-size:14px;color:#1A1D29;line-height:1.6;'>$orgName च्या EOWMS प्रणालीमध्ये तुमचं खातं तयार करण्यात आलं आहे. $intro</p>
        <div style='background:#F3F4F7;border-radius:8px;padding:16px 20px;margin:18px 0;'>
          <div style='font-size:12px;color:#666B7D;text-transform:uppercase;'>Login ID</div>
          <div style='font-size:16px;font-weight:bold;color:#1B8A83;margin-bottom:10px;'>$username</div>
          <div style='font-size:12px;color:#666B7D;text-transform:uppercase;'>तात्पुरता पासवर्ड</div>
          <div style='font-size:16px;font-weight:bold;color:#1B8A83;'>$plainPassword</div>
        </div>
        <p style='font-size:13px;color:#B23A48;'>⚠️ पहिल्यांदा Login केल्यावर तुम्हाला स्वतःचा नवीन पासवर्ड तयार करावा लागेल — सुरक्षिततेसाठी हे अनिवार्य आहे.</p>
        <a href='$loginUrl' style='display:inline-block;background:#1B8A83;color:#fff;text-decoration:none;padding:10px 22px;border-radius:8px;font-size:14px;font-weight:bold;margin-top:10px;'>Login करा</a>
        <p style='font-size:11.5px;color:#8A90AC;margin-top:20px;'>🙏 जय जंगली महाराज — हा ईमेल आपोआप पाठवला गेला आहे.</p>
      </div>
    </div>";

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $orgName <no-reply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n";

    try {
        return @mail($email, "=?UTF-8?B?" . base64_encode($subject) . "?=", $body, $headers);
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
          <div style='font-size:32px;font-weight:bold;color:#1B8A83;letter-spacing:6px;'>$code</div>
        </div>
        <p style='font-size:13px;color:#666B7D;'>हा code फक्त १५ मिनिटांसाठी वैध आहे. जर तुम्ही ही विनंती केली नसेल, तर हा ईमेल दुर्लक्षित करा — तुमचा पासवर्ड बदलणार नाही.</p>
        <p style='font-size:11.5px;color:#8A90AC;margin-top:20px;'>🙏 जय जंगली महाराज — हा ईमेल आपोआप पाठवला गेला आहे.</p>
      </div>
    </div>";

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $orgName <no-reply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n";

    try {
        return @mail($email, "=?UTF-8?B?" . base64_encode($subject) . "?=", $body, $headers);
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
        <a href='$link' style='display:inline-block;background:#1B8A83;color:#fff;text-decoration:none;padding:10px 22px;border-radius:8px;font-size:14px;font-weight:bold;margin-top:6px;'>System मध्ये बघा</a>
        <p style='font-size:11.5px;color:#8A90AC;margin-top:20px;'>🙏 जय जंगली महाराज — हा ईमेल आपोआप पाठवला गेला आहे.</p>
      </div>
    </div>";

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $orgName <no-reply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n";

    try {
        return @mail($email, "=?UTF-8?B?" . base64_encode($subject) . "?=", $body, $headers);
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
        <a href='$link' style='display:inline-block;background:#1B8A83;color:#fff;text-decoration:none;padding:10px 22px;border-radius:8px;font-size:14px;font-weight:bold;margin-top:20px;'>संपूर्ण Register बघा</a>
        <p style='font-size:11.5px;color:#8A90AC;margin-top:20px;'>🙏 जय जंगली महाराज — हा ईमेल आपोआप, दर आठवड्याला पाठवला जातो.</p>
      </div>
    </div>";

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $orgName <no-reply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n";

    try {
        return @mail($email, "=?UTF-8?B?" . base64_encode($subject) . "?=", $body, $headers);
    } catch (Throwable $e) {
        return false;
    }
}

/** वाढदिवसाच्या शुभेच्छांचा Email पाठवते. */
function sendBirthdayEmail($email, $name, $orgName) {
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $subject = "🎂 वाढदिवसाच्या हार्दिक शुभेच्छा, $name!";
    $body = "
    <div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;background:#F3F4F7;'>
      <div style='background:#10162B;padding:20px 24px;border-radius:10px 10px 0 0;text-align:center;'>
        <div style='color:#fff;font-size:20px;font-weight:bold;'>$orgName</div>
      </div>
      <div style='background:#fff;padding:28px 24px;border-radius:0 0 10px 10px;text-align:center;'>
        <div style='font-size:36px;margin-bottom:10px;'>🎂🎉</div>
        <p style='font-size:16px;color:#1A1D29;'>प्रिय <strong>$name</strong>,</p>
        <p style='font-size:14px;color:#1A1D29;line-height:1.7;'>आजच्या तुमच्या वाढदिवसानिमित्त $orgName परिवाराकडून तुम्हाला मनःपूर्वक शुभेच्छा! तुमचं हे वर्ष आरोग्यदायी, आनंदी आणि यशस्वी जावो अशी सद्गुरूंच्या चरणी प्रार्थना.</p>
        <p style='font-size:24px;margin:16px 0;'>🎈🎁🎊</p>
        <p style='font-size:11.5px;color:#8A90AC;margin-top:20px;'>🙏 जय जंगली महाराज — हा ईमेल आपोआप पाठवला गेला आहे.</p>
      </div>
    </div>";
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $orgName <no-reply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n";
    try {
        return @mail($email, "=?UTF-8?B?" . base64_encode($subject) . "?=", $body, $headers);
    } catch (Throwable $e) {
        return false;
    }
}

/** कामाच्या वर्धापनदिनाच्या (Work Anniversary) अभिनंदनाचा Email पाठवते. */
function sendWorkAnniversaryEmail($email, $name, $yearsCompleted, $orgName) {
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $subject = "🎊 $orgName सोबत $yearsCompleted वर्षं पूर्ण — अभिनंदन!";
    $body = "
    <div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;background:#F3F4F7;'>
      <div style='background:#10162B;padding:20px 24px;border-radius:10px 10px 0 0;text-align:center;'>
        <div style='color:#fff;font-size:20px;font-weight:bold;'>$orgName</div>
      </div>
      <div style='background:#fff;padding:28px 24px;border-radius:0 0 10px 10px;text-align:center;'>
        <div style='font-size:36px;margin-bottom:10px;'>🏆🎊</div>
        <p style='font-size:16px;color:#1A1D29;'>प्रिय <strong>$name</strong>,</p>
        <p style='font-size:14px;color:#1A1D29;line-height:1.7;'>आज तुम्ही $orgName परिवारासोबत <strong style='color:#1B8A83;'>$yearsCompleted वर्षं</strong> पूर्ण करत आहात. तुमच्या समर्पणाबद्दल आणि योगदानाबद्दल मनःपूर्वक धन्यवाद — पुढील वाटचालीसाठी शुभेच्छा!</p>
        <p style='font-size:11.5px;color:#8A90AC;margin-top:20px;'>🙏 जय जंगली महाराज — हा ईमेल आपोआप पाठवला गेला आहे.</p>
      </div>
    </div>";
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $orgName <no-reply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n";
    try {
        return @mail($email, "=?UTF-8?B?" . base64_encode($subject) . "?=", $body, $headers);
    } catch (Throwable $e) {
        return false;
    }
}
