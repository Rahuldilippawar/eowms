<?php
// Session bootstrap + shared helpers — include this at the top of every page

// Session Cookie ला अतिरिक्त सुरक्षा — session_start() च्या आधीच लावावी लागते.
// SameSite=Lax मुळे — दुसऱ्या (परक्या) Website वरून चुकून/जाणूनबुजून या Site
// कडे पाठवलेला Form-Submit (CSRF Attack) Browser आपोआप रोखतो.
require_once __DIR__ . '/security-headers.php';
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,   // HTTPS वर असाल तरच Cookie फक्त HTTPS वरूनच पाठवली जाईल
        'httponly' => true,     // JavaScript ला Cookie वाचता येणार नाही (XSS-Cookie-Theft विरुद्ध संरक्षण)
        'samesite' => 'Lax',    // CSRF Attack रोखण्यासाठी — सर्वात महत्त्वाचं
    ]);
}
session_start();
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/module-registry.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/i18n.php';
// 🌐 भाषा — Headers जाण्याआधीच ठरवा (?lang= असल्यास Cookie लिहिता यावी), मग
// पान Browser कडे जाण्याआधी मराठी मजकूर निवडलेल्या भाषेत (includes/i18n.php)
if (PHP_SAPI !== 'cli') {
    i18nLang();
    if (!in_array('i18nFilterOutput', ob_list_handlers(), true)) { ob_start('i18nFilterOutput'); }
}

function currentUser() {
    return $_SESSION['user'] ?? null;
}

// $rootPath = relative path back to the app root, e.g. '../' from inside /daily-work/
define('SESSION_IDLE_LIMIT_SECONDS', 30 * 60); // default 30 minutes — संस्था बदलू शकते (sessionIdleLimitSeconds)
const SESSION_IDLE_CHOICES = [10, 15, 20, 30, 45, 60, 120]; // मिनिटं

/** निष्क्रियतेनंतर आपोआप Logout — किती सेकंदांनी (Login वेळी संस्थेच्या सेटिंगवरून Session मध्ये ठेवलेलं) */
function sessionIdleLimitSeconds() {
    $m = (int)($_SESSION['idle_limit_min'] ?? 0);
    return in_array($m, SESSION_IDLE_CHOICES, true) ? $m * 60 : SESSION_IDLE_LIMIT_SECONDS;
}

/** App च्या मुळापासूनचा चालू पत्ता (उदा. "ecampus/index.php?month=2026-11") — Login नंतर परत येण्यासाठी */
function currentAppRelativeUrl() {
    $root = realpath(__DIR__ . '/..');
    $doc = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $base = ($doc !== '' && str_starts_with($root, $doc)) ? str_replace('\\', '/', substr($root, strlen($doc))) : '';
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($base !== '' && str_starts_with($uri, $base . '/')) { $uri = substr($uri, strlen($base) + 1); }
    return safeNextPath(ltrim($uri, '/'));
}

/** Login नंतर परत जायचं पान — फक्त याच Site मधला सापेक्ष (relative) मार्ग; बाहेरचा पत्ता/Login/Logout नाही */
function safeNextPath($next) {
    $next = (string)$next;
    if ($next === '' || strlen($next) > 500 || preg_match('#^[a-z][a-z0-9+.-]*:|^//|\\\\|[\r\n]#i', $next)) { return null; }
    $path = ltrim(parse_url($next, PHP_URL_PATH) ?? '', '/');
    if ($path === '' || preg_match('#(^|/)(login|login-2fa|logout|welcome|forgot-password|verify-reset)\.php$|\.\.#', $path)) { return null; }
    return ltrim($next, '/');
}

/**
 * नवीन, अलीकडेच जोडलेल्या Modules साठी — जर संबंधित Table अजून
 * Database मध्ये Migrate झालेली नसेल (Super Admin ने अजून
 * RUN-ALL-MIGRATIONS.sql चालवलेली नसेल), तर पान पूर्णपणे "Blank"
 * दिसण्याऐवजी (PHP Fatal Error मुळे) — इथेच एक स्पष्ट, Helpful
 * Message दाखवून थांबतो.
 */
function requireMigratedTable($pdo, $tableName, $rootPath = '', $requiredColumn = null) {
    try {
        $colExpr = $requiredColumn ? "`" . str_replace("`", "", $requiredColumn) . "`" : "1";
        $pdo->query("SELECT $colExpr FROM `$tableName` LIMIT 1");
    } catch (PDOException $e) {
        http_response_code(503);
        $isSuperAdmin = (currentUser()['role'] ?? '') === 'super_admin';
        $howTo = $isSuperAdmin
            ? 'हा Feature वापरण्याआधी Database मध्ये नवीन Tables जोडाव्या लागतील — खालचं बटण दाबा, एका Click मध्ये होईल.'
            : 'हा Feature वापरण्याआधी Database Update करावं लागेल — कृपया Super Admin ला सांगा (System Update → Database Update).';
        $updateBtn = $isSuperAdmin
            ? '<a href="' . htmlspecialchars($rootPath) . 'system-update/db-update.php" style="display:inline-block;margin:14px 6px 0;background:#D9530F;color:#fff;text-decoration:none;padding:10px 22px;border-radius:9px;font-size:13px;font-weight:600;">🛠️ Database Update करा</a>'
            : '';
        die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Database Update आवश्यक</title>
        <meta name="viewport" content="width=device-width, initial-scale=1"></head>
        <body style="font-family:\'Noto Sans Devanagari\',sans-serif;background:#F5F6FA;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;">
        <div style="background:#fff;border-radius:16px;padding:36px 32px;max-width:460px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.08);">
          <div style="font-size:40px;">🛠️</div>
          <div style="font-size:17px;font-weight:700;margin:12px 0 8px;color:#10162B;">Database Update आवश्यक आहे</div>
          <p style="font-size:13px;color:#666B7D;line-height:1.8;">' . $howTo . '</p>
          ' . $updateBtn . '
          <a href="' . htmlspecialchars($rootPath) . 'index.php" style="display:inline-block;margin:14px 6px 0;border:1px solid #D9530F;color:#D9530F;text-decoration:none;padding:10px 22px;border-radius:9px;font-size:13px;font-weight:600;">Dashboard वर परत जा</a>
        </div>
        </body></html>');
    }
}

function requireLogin($rootPath = '') {
    // Login-नंतरच्या कुठल्याही पानाला Browser कधीही Cache करू नये —
    // नाहीतर Logout केल्यावर "Back" बटण दाबल्यास जुनं पान (Server ला न
    // विचारताच) परत दाखवलं जाऊ शकतं, जे एक सुरक्षा-धोका आहे.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    if (!currentUser()) {
        header('Location: ' . $rootPath . 'login.php');
        exit;
    }

    // 30-minute idle timeout — if too long has passed since the last
    // activity on this session, force logout for security.
    if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > sessionIdleLimitSeconds()) {
        // परत Login केल्यावर याच पानावर परत आणण्यासाठी (फक्त GET — Form-Submit पुन्हा होऊ नये)
        $back = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' ? currentAppRelativeUrl() : null;
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['session_expired'] = true;
        header('Location: ' . $rootPath . 'login.php' . ($back ? '?next=' . rawurlencode($back) : ''));
        exit;
    }
    $_SESSION['last_activity'] = time();

    // सुरक्षा: Login नंतर खातं (किंवा संस्था) निष्क्रिय केलं तरी जुनं Session
    // चालूच राहू नये — दर ~60 सेकंदांनी एकदा Database मध्ये तपासतो.
    if (empty($_SESSION['user_recheck_at']) || (time() - (int)$_SESSION['user_recheck_at']) >= 60) {
        global $pdo;
        $chk = $pdo->prepare("SELECT u.is_active, o.is_active AS org_active FROM users u LEFT JOIN organizations o ON o.id = u.organization_id WHERE u.id = ?");
        $chk->execute([(int)currentUser()['id']]);
        $chkRow = $chk->fetch();
        if (!$chkRow || (int)$chkRow['is_active'] !== 1 || (int)($chkRow['org_active'] ?? 0) !== 1) {
            session_unset();
            session_destroy();
            session_start();
            $_SESSION['account_deactivated'] = true;
            header('Location: ' . $rootPath . 'login.php');
            exit;
        }
        $_SESSION['user_recheck_at'] = time();
    }

    // 🛠️ Site Maintenance Mode — Super Admin ने Site "Off" केली असेल तर,
    // Super Admin सोडून बाकी सगळ्यांना Maintenance-पानावर वळवतो.
    $currentScriptForMaintenance = basename($_SERVER['SCRIPT_NAME']);
    $maintenanceExemptPages = ['maintenance.php', 'logout.php', 'force-password-change.php'];
    if (!in_array($currentScriptForMaintenance, $maintenanceExemptPages, true)) {
        $u = currentUser();
        if (!empty($u) && ($u['role'] ?? '') !== 'super_admin') {
            static $maintenanceCheckDone = false;
            static $isInMaintenance = false;
            if (!$maintenanceCheckDone) {
                global $pdo;
                $mStmt = $pdo->prepare("SELECT maintenance_mode FROM organizations WHERE id = ?");
                $mStmt->execute([activeOrgId()]);
                $isInMaintenance = (int)($mStmt->fetch()['maintenance_mode'] ?? 0) === 1;
                $maintenanceCheckDone = true;
            }
            if ($isInMaintenance) {
                header('Location: ' . $rootPath . 'maintenance.php');
                exit;
            }
        }
    }

    // 🔒 नोंदी बदलणं / हटवणं फक्त Super Admin (org setting records_lock — default चालू)
    recordLockEnforce($rootPath);

    // 🎯 Single-Purpose Access Mode — एखादा Role फक्त काही ठराविक
    // Modules शीच मर्यादित असेल (Restricted), तर तो व्यक्ती URL मध्ये
    // थेट दुसऱ्या कुठल्याही Module चा Address टाकून गेला, तरी त्याला
    // परत त्याच्याच Module च्या Dashboard कडे वळवतो — कुठलाही Module
    // "उघडाच" राहत नाही.
    $restrictedModules = getRestrictedModules();
    if ($restrictedModules !== null) {
        $currentDirForRestriction = basename(dirname($_SERVER['SCRIPT_NAME']));
        // Root-Level (जसं की index.php, login.php) Files साठी dirname() '/' किंवा रिकामं परत देतो
        if ($currentDirForRestriction === '.' || $currentDirForRestriction === '/' || $currentDirForRestriction === '') {
            $currentDirForRestriction = '__root__';
        }
        $exemptDirs = ['account', '__root__', 'ecampus']; // Profile/Password — नेहमीच सगळ्यांना उपलब्ध; E-Campus — पानं स्वतः नेमणूक तपासतात
        $exemptRootPages = ['logout.php', 'force-password-change.php', 'maintenance.php'];
        $currentPageForRestriction = basename($_SERVER['SCRIPT_NAME']);

        $isAllowedDir = false;
        $registry = getModuleRegistry();
        foreach ($restrictedModules as $modKey) {
            $modInfo = $registry[$modKey] ?? null;
            if ($modInfo && in_array($currentDirForRestriction, $modInfo['dirs'], true)) { $isAllowedDir = true; break; }
        }
        if (in_array($currentDirForRestriction, $exemptDirs, true) && ($currentDirForRestriction !== '__root__' || in_array($currentPageForRestriction, $exemptRootPages, true))) {
            $isAllowedDir = true;
        }
        // "माझं प्रोफाइल" (पासवर्ड/फोटो) आता staff/profile.php — स्वतःचं प्रोफाइल नेहमी उपलब्ध
        if ($currentDirForRestriction === 'staff' && $currentPageForRestriction === 'profile.php'
            && (empty($_GET['id']) || (int)$_GET['id'] === (int)(currentUser()['id'] ?? 0))) {
            $isAllowedDir = true;
        }

        if (!$isAllowedDir) {
            $firstModule = $registry[$restrictedModules[0]] ?? null;
            $redirectTarget = $firstModule ? $firstModule['dashboard'] : 'index.php';
            header('Location: ' . $rootPath . $redirectTarget);
            exit;
        }
    }

    // Safety net: if this user still must change their password, don't let
    // them reach any other page by navigating directly to a URL — always
    // bounce them back to the password-change screen first.
    $currentScript = basename($_SERVER['SCRIPT_NAME']);
    if (!empty(currentUser()['must_change_password']) && !in_array($currentScript, ['force-password-change.php', 'logout.php'], true)) {
        header('Location: ' . $rootPath . 'force-password-change.php');
        exit;
    }

    // 🔐 2-Step Login अनिवार्य (उदा. Super Admin) पण अजून चालू केलं नाही — आधी ते चालू करा
    if (!empty($_SESSION['tfa_setup_required'])
        && !in_array($currentScript, ['two-factor.php', 'logout.php', 'force-password-change.php', 'js-error.php'], true)) {
        header('Location: ' . $rootPath . 'account/two-factor.php');
        exit;
    }
}

/**
 * Visitor चा खरा IP — X-Forwarded-For हा Header कोणीही खोटा पाठवू शकतो,
 * म्हणून तो फक्त तेव्हाच मानतो जेव्हा REMOTE_ADDR स्वतःच Private/Proxy पत्ता असेल.
 */
function clientIp() {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote === '') return 'unknown';
    $isPrivate = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    if ($isPrivate && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
    return $remote;
}

/**
 * सार्वजनिक (Login-शिवाय) फॉर्मसाठी साधी Rate-Limit — एका IP वरून ठराविक
 * वेळेत जास्तीत जास्त $max Submissions. Table नसेल तर अडवत नाही.
 */
function publicFormRateLimited($formKey, $max = 5, $windowSeconds = 3600) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM public_form_submissions WHERE form_key = ? AND ip_address = ? AND created_at > ?");
        $stmt->execute([$formKey, clientIp(), date('Y-m-d H:i:s', time() - $windowSeconds)]);
        return (int)$stmt->fetchColumn() >= $max;
    } catch (PDOException $e) {
        return false;
    }
}

function publicFormRecordSubmission($formKey) {
    global $pdo;
    try {
        $pdo->prepare("INSERT INTO public_form_submissions (form_key, ip_address, created_at) VALUES (?, ?, ?)")
            ->execute([$formKey, mb_substr(clientIp(), 0, 45), date('Y-m-d H:i:s')]);
        // जुन्या (एक दिवसापेक्षा जुन्या) नोंदी अधूनमधून साफ करतो
        if (random_int(1, 50) === 1) {
            $pdo->prepare("DELETE FROM public_form_submissions WHERE created_at < ?")->execute([date('Y-m-d H:i:s', time() - 86400)]);
        }
    } catch (PDOException $e) {}
}

/** Bots साठी लपवलेलं Honeypot Field — माणसाला दिसत नाही, भरलं असेल तर Bot */
function honeypotField() {
    return '<div style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off" value=""></label></div>';
}

function honeypotTripped() {
    return trim((string)($_POST['website'] ?? '')) !== '';
}

// Escapes output for safe HTML display
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function hasRole($role) {
    $u = currentUser();
    return $u && $u['role'] === $role;
}

function hasAnyRole($roles) {
    $u = currentUser();
    return $u && in_array($u['role'], $roles, true);
}

// staff & hod only ever see/enter their own department's data;
// everyone above (GM, Exec President, President, Super Admin) sees the whole organization.
function isDeptScoped() {
    return hasAnyCategory(['staff', 'hod']);
}

/**
 * लवचिक Department-Visibility — या व्यक्तीला कोणकोणते विभाग "दिसू" शकतात
 * ते ठरवतं (Task/नूतनीकरण/कामाची विनंती/Tracker — या सर्वांसाठी वापरतो):
 *   - Org-wide Permission असेल (उदा. management) — सगळे विभाग (null परत करतो,
 *     म्हणजे "सगळं" — कॉलिंग कोड ने त्यानुसार Filter न लावता सगळं दाखवावं)
 *   - नसेल तर — स्वतःचा विभाग + Super Admin ने वेगळ्याने दिलेले Extra विभाग
 */
function getVisibleDepartmentIds($orgWidePermission = null) {
    global $pdo;
    $user = currentUser();
    if (!$user) { return []; }

    if (hasRole('super_admin') || ($orgWidePermission && hasPermission($orgWidePermission)) || !isDeptScoped()) {
        return null; // null = कुठलाही Filter न लावता सगळे विभाग बघता येतील
    }

    $deptIds = [];
    if (!empty($user['department_id'])) { $deptIds[] = (int)$user['department_id']; }

    try {
        $stmt = $pdo->prepare("SELECT department_id FROM user_extra_department_access WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $extraId) { $deptIds[] = (int)$extraId; }
    } catch (PDOException $e) { /* migration not run yet — फक्त स्वतःचा विभाग दिसेल */ }

    return array_values(array_unique($deptIds));
}

/**
 * Staff Profile/Track-Record साठी — 5-Tier Visibility (स्वतःचा विभाग →
 * अनेक विभाग → एक कॉलेज → अनेक कॉलेज → संपूर्ण संस्था). Roles &
 * Permissions मधून, आणि staff/extra-access.php + extra-campus-access.php
 * मधून ठरवलेल्या Grants वरून — कोणते Department IDs बघता येतील ते
 * परत देतो. null = सगळे (Org-wide) बघता येतील.
 */
function getVisibleDepartmentIdsForStaffTracking() {
    global $pdo;
    $user = currentUser();
    if (!$user) { return []; }

    // Tier 5: संपूर्ण संस्था
    if (hasRole('super_admin') || hasPermission('view_staff_track_org_wide') || hasPermission('view_staff_directory')) {
        return null;
    }

    $deptIds = [];
    if (!empty($user['department_id'])) { $deptIds[] = (int)$user['department_id']; } // Tier 1: स्वतःचा विभाग

    // Tier 2: Super Admin ने Grant केलेले Extra विभाग (आधीच अस्तित्वात असलेली Table)
    try {
        $stmt = $pdo->prepare("SELECT department_id FROM user_extra_department_access WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) { $deptIds[] = (int)$id; }
    } catch (PDOException $e) {}

    $campusIds = [];
    // Tier 3: स्वतःचा Campus — त्यातले सगळे विभाग
    if (hasPermission('view_staff_track_own_campus') && !empty($user['campus_id'])) {
        $campusIds[] = (int)$user['campus_id'];
    }

    // Tier 4: Super Admin ने Grant केलेले Extra Campuses
    try {
        $stmt = $pdo->prepare("SELECT campus_id FROM user_extra_campus_access WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) { $campusIds[] = (int)$id; }
    } catch (PDOException $e) {}

    if (!empty($campusIds)) {
        $ph = implode(',', array_fill(0, count($campusIds), '?'));
        $stmt = $pdo->prepare("SELECT id FROM departments WHERE campus_id IN ($ph) AND is_active = 1");
        $stmt->execute($campusIds);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) { $deptIds[] = (int)$id; }
    }

    return array_values(array_unique($deptIds));
}

/**
 * Dynamic role/designation support: users.role can be ANY key defined in
 * role_definitions (Director, Trustee, Secretary, custom titles, etc.).
 * Every role_key is tagged with one of 4 access categories, so permissions
 * keep working correctly no matter what new designations get added later
 * from Super Admin -> "पदे व्यवस्थापन".
 */
function roleCategory($roleKey = null) {
    global $pdo;
    static $cache = [];

    $u = currentUser();
    $roleKey = $roleKey ?? ($u['role'] ?? null);
    if (!$roleKey) return 'staff';

    if (isset($cache[$roleKey])) return $cache[$roleKey];

    // role_definitions may not exist yet if the dynamic-roles migration
    // hasn't been run — fall back gracefully to the original 6 fixed roles.
    try {
        $stmt = $pdo->prepare("SELECT category FROM role_definitions WHERE role_key = ?");
        $stmt->execute([$roleKey]);
        $row = $stmt->fetch();
        if ($row) {
            return $cache[$roleKey] = $row['category'];
        }
    } catch (PDOException $e) {
        // role_definitions table not present yet — use legacy fallback below
    }

    $legacyManagement = ['president', 'executive_president', 'general_manager'];
    if ($roleKey === 'super_admin') return $cache[$roleKey] = 'super_admin';
    if (in_array($roleKey, $legacyManagement, true)) return $cache[$roleKey] = 'management';
    if ($roleKey === 'hod') return $cache[$roleKey] = 'hod';
    return $cache[$roleKey] = 'staff';
}

function hasCategory($category) {
    return roleCategory() === $category;
}

function hasAnyCategory($categories) {
    return in_array(roleCategory(), (array)$categories, true);
}

/** True for President/Executive President/General Manager/Director/Secretary/etc. AND Super Admin — the "sees whole org" tier. */
function isManagementOrAbove() {
    return hasAnyCategory(['management', 'super_admin']);
}

function requireRole($roles, $rootPath = '') {
    if (!hasAnyRole((array)$roles)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;">' . h(t('s98')) . ' <a href="' . h($rootPath) . 'index.php">' . h(t('s16')) . '</a></div>');
    }
}

/** Same as requireRole but checks by access category — use this for new dynamic-role-aware pages. */
function requireCategory($categories, $rootPath = '') {
    if (!hasAnyCategory((array)$categories)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;">' . h(t('s98')) . ' <a href="' . h($rootPath) . 'index.php">' . h(t('s16')) . '</a></div>');
    }
}

/**
 * The organization currently "in view". Only Super Admin can switch between
 * organizations (via the sidebar switcher, stored in session); every other
 * role always operates within their own home organization, regardless of
 * any session value, so switching never leaks cross-org access to them.
 */
function activeOrgId() {
    $u = currentUser();
    if (!$u) return null;
    if ($u['role'] === 'super_admin' && !empty($_SESSION['active_org_id'])) {
        return (int)$_SESSION['active_org_id'];
    }
    return (int)$u['organization_id'];
}

/** Display name of the currently active organization — used in page headers everywhere. */
function activeOrgName() {
    global $pdo;
    $id = activeOrgId();
    if (!$id) return 'VJMAT';
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    $stmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $cache[$id] = ($row['name'] ?? 'VJMAT');
}

/**
 * Granular per-role permissions — Super Admin decides EXACTLY which features
 * each role/designation can access from "पदे व्यवस्थापन" -> role -> Permissions.
 * Super Admin itself always has every permission, no matter what.
 * Falls back to the broader management-category check if the permissions
 * tables haven't been migrated yet, so nothing breaks mid-upgrade.
 */
function hasPermission($permissionKey) {
    global $pdo;
    $u = currentUser();
    if (!$u) return false;
    if ($u['role'] === 'super_admin') return true;

    static $cache = [];
    static $knownKeys = null;
    $roleKey = $u['role'];
    if (!isset($cache[$roleKey])) {
        try {
            $stmt = $pdo->prepare("SELECT permission_key FROM role_permissions WHERE role_key = ?");
            $stmt->execute([$roleKey]);
            $cache[$roleKey] = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            // permissions tables not migrated yet — fall back to the old
            // category-based behavior so access doesn't just disappear.
            return isManagementOrAbove();
        }
    }

    if (in_array($permissionKey, $cache[$roleKey], true)) { return true; }

    // हा permission_key तपासलेल्या Role ला दिलेला नाही — पण जर हा key
    // सिस्टीममध्ये अजून नोंदवलाच गेलेला नसेल (Migration अजून चालवलेली नाही),
    // तर जुनं Management-tier वर्तन कायम ठेवा — म्हणजे code आधी टाकला आणि
    // SQL नंतर चालवला तरी कोणाचाही access चुकून बंद होणार नाही.
    if ($knownKeys === null) {
        try {
            $knownKeys = $pdo->query("SELECT permission_key FROM permissions")->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            $knownKeys = [];
        }
    }
    if (!in_array($permissionKey, $knownKeys, true)) {
        return isManagementOrAbove();
    }

    return false;
}

function requirePermission($permissionKey, $rootPath = '') {
    if (!hasPermission($permissionKey)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;">' . h(t('s291')) . ' <a href="' . h($rootPath) . 'index.php">' . h(t('s16')) . '</a></div>');
    }
}

// सूचना (In-App + Push Queue) — notifyUser / notifyUsers / notifyDepartment /
// sendPushNotification आता includes/notify.php मध्ये (Cron मधूनही वापरता यावेत म्हणून).
require_once __DIR__ . '/notify.php';

/**
 * COUNT(*)+1 वरून बनवलेला क्रमांक (TSK-/WRK-/REQ- इ.) — एखादी नोंद Delete
 * झाली असेल, किंवा दुसऱ्या संस्थेने तोच क्रमांक आधीच घेतला असेल, तर
 * UNIQUE Column मुळे Insert Fail होतो. म्हणून आधीच वापरलेला क्रमांक
 * असेल तर पुढचा मोकळा क्रमांक शोधतो. $format मध्ये %d/%04d हा शेवटचा
 * (seq) Argument असतो.
 */
function generateUniqueCode($pdo, $table, $column, $format, $seq, ...$prefixArgs) {
    $stmt = $pdo->prepare("SELECT 1 FROM `$table` WHERE `$column` = ? LIMIT 1");
    do {
        $code = sprintf($format, ...array_merge($prefixArgs, [$seq++]));
        $stmt->execute([$code]);
    } while ($stmt->fetch());
    return $code;
}

/** Record an entry in the activity log. Fails silently if not migrated yet. */
function logActivity($action, $entityType = null, $entityId = null, $details = null) {
    global $pdo;
    $u = currentUser();
    try {
        // महत्त्वाचं: created_at साठी MySQL च्या DEFAULT CURRENT_TIMESTAMP वर
        // अवलंबून न राहता — PHP कडूनच स्पष्टपणे IST वेळ (date_default_timezone_set
        // वरून, जे विश्वासार्ह आहे) पाठवतो. काही Shared-Hosting Server वर MySQL
        // Session-Timezone Conversion (SET time_zone) सुसंगतपणे लागू होत नाही,
        // त्यामुळे Activity Log मध्ये वेळ 5.5 तास पुढे/मागे दिसण्याची समस्या
        // येते — हे टाळण्यासाठी.
        $nowIst = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("INSERT INTO activity_logs (organization_id, user_id, actor_name, action, entity_type, entity_id, details, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([activeOrgId(), $u['id'] ?? null, $u['name'] ?? null, $action, $entityType, $entityId, $details, $nowIst]);
    } catch (PDOException $e) {
        // activity_logs table not migrated yet — skip silently
    }
}

/* ============================================================
 * 🔒 नोंदी Edit / Delete फक्त Super Admin
 *  - इतर सगळे नवीन नोंद भरू शकतात, Status/मंजुरीसारखी कामं करू शकतात,
 *    पण आधीची नोंद बदलणं/हटवणं/निष्क्रिय करणं फक्त Super Admin.
 *  - Edit/Delete पानं (edit.php, delete.php, *-edit.php, *-delete.php, *-update.php, update.php, toggle-active.php …)
 *    आणि POST action = delete/remove/edit/update/deactivate/rename/rollback/clear/disable… अडवले जातात.
 *  - account/ (स्वतःचं प्रोफाइल, पासवर्ड, 2-Step) नेहमी मोकळं.
 *  - Super Admin ला "2-Step / सुरक्षा धोरण" पानावर हे बंद करता येतं.
 * ============================================================ */
const RECORD_LOCK_ACTION_RE = '/^(delete|remove|edit|update|deactivate|rename|rollback|clear|disable|unassign|end_allocation|trash|destroy)(_|$)/i';
const RECORD_LOCK_ACTION_ALLOW = ['update_status', 'update_task_status', 'update_photo'];
const RECORD_LOCK_SCRIPT_RE = '/^((edit|delete|update)\.php|[a-z0-9_-]+[-_](edit|delete|update)\.php|(edit|delete)[-_][a-z0-9_-]+\.php|toggle-active\.php)$/i';

function recordLockActive() {
    global $pdo;
    $u = currentUser();
    if (!$u || ($u['role'] ?? '') === 'super_admin') { return false; }
    require_once __DIR__ . '/app-settings.php';
    if (!($pdo instanceof PDO) || !orgSettingsReady($pdo)) { return true; }
    return (string)orgSetting($pdo, activeOrgId(), 'records_lock', '1') !== '0';
}

/** हे पान / ही POST कृती "आधीची नोंद बदलणं-हटवणं" आहे का? */
function recordLockIsBlocked($scriptPath, $method, array $post) {
    $dir = basename(dirname($scriptPath));
    if ($dir === 'account') { return false; }
    if (preg_match(RECORD_LOCK_SCRIPT_RE, basename($scriptPath))) { return true; }
    if (strtoupper((string)$method) !== 'POST') { return false; }
    foreach (['action', 'op', 'do', 'act', 'mode'] as $k) {
        $v = $post[$k] ?? null;
        if (is_string($v) && !in_array(strtolower($v), RECORD_LOCK_ACTION_ALLOW, true) && preg_match(RECORD_LOCK_ACTION_RE, $v)) { return true; }
    }
    foreach (array_keys($post) as $k) {
        if (preg_match('/^delete(_[a-z_]*id)?$/i', (string)$k) && ($post[$k] ?? '') !== '') { return true; }
    }
    return false;
}

function recordLockEnforce($rootPath = '') {
    if (PHP_SAPI === 'cli' || !recordLockActive()) { return; }
    if (!recordLockIsBlocked($_SERVER['SCRIPT_NAME'] ?? '', $_SERVER['REQUEST_METHOD'] ?? 'GET', $_POST)) { return; }
    $msg = 'आधीची नोंद बदलण्याचा (Edit) किंवा हटवण्याचा (Delete) अधिकार फक्त Super Admin ला आहे. बदल हवा असल्यास Super Admin ला कळवा.';
    if (function_exists('logActivity')) { @logActivity('Edit/Delete अडवलं (फक्त Super Admin)', 'security', null, basename($_SERVER['SCRIPT_NAME'] ?? '') . ' ' . (is_string($_POST['action'] ?? null) ? $_POST['action'] : '')); }
    http_response_code(403);
    $wantsJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'json') !== false || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || stripos($_SERVER['CONTENT_TYPE'] ?? '', 'json') !== false;
    if ($wantsJson) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok' => false, 'error' => $msg, 'msg' => $msg], JSON_UNESCAPED_UNICODE); exit; }
    $back = $rootPath . 'index.php';
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref && parse_url($ref, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '')) { $back = $ref; }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>फक्त Super Admin</title>'
        . '<div style="font-family:system-ui,sans-serif;max-width:520px;margin:60px auto;padding:28px;border:1px solid #EAE5DF;border-radius:16px;text-align:center;background:#fff;">'
        . '<div style="font-size:42px;">🔒</div><h2 style="margin:8px 0 6px;font-size:20px;">फक्त Super Admin</h2>'
        . '<p style="color:#475069;font-size:14.5px;line-height:1.6;">' . h($msg) . '</p>'
        . '<a href="' . h($back) . '" style="display:inline-block;margin-top:10px;background:#D9530F;color:#fff;text-decoration:none;padding:9px 20px;border-radius:9px;font-weight:600;">← परत जा</a></div>';
    exit;
}

/** आणखी एक Super Admin होऊ शकतो का? — एकच Super Admin नियम ($exceptUserId = आत्ताचा जो आहे तो) */
function superAdminExistsOtherThan(PDO $pdo, $exceptUserId = 0) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND id != ?");
    $st->execute([(int)$exceptUserId]);
    return (int)$st->fetchColumn() > 0;
}
