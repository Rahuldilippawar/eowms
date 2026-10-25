<?php
// Session bootstrap + shared helpers — include this at the top of every page

// Session Cookie ला अतिरिक्त सुरक्षा — session_start() च्या आधीच लावावी लागते.
// SameSite=Lax मुळे — दुसऱ्या (परक्या) Website वरून चुकून/जाणूनबुजून या Site
// कडे पाठवलेला Form-Submit (CSRF Attack) Browser आपोआप रोखतो.
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

function currentUser() {
    return $_SESSION['user'] ?? null;
}

// $rootPath = relative path back to the app root, e.g. '../' from inside /daily-work/
define('SESSION_IDLE_LIMIT_SECONDS', 30 * 60); // 30 minutes

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
            ? '<a href="' . htmlspecialchars($rootPath) . 'system-update/db-update.php" style="display:inline-block;margin:14px 6px 0;background:#1B8A83;color:#fff;text-decoration:none;padding:10px 22px;border-radius:9px;font-size:13px;font-weight:600;">🛠️ Database Update करा</a>'
            : '';
        die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Database Update आवश्यक</title>
        <meta name="viewport" content="width=device-width, initial-scale=1"></head>
        <body style="font-family:\'Noto Sans Devanagari\',sans-serif;background:#F5F6FA;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;">
        <div style="background:#fff;border-radius:16px;padding:36px 32px;max-width:460px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.08);">
          <div style="font-size:40px;">🛠️</div>
          <div style="font-size:17px;font-weight:700;margin:12px 0 8px;color:#10162B;">Database Update आवश्यक आहे</div>
          <p style="font-size:13px;color:#666B7D;line-height:1.8;">' . $howTo . '</p>
          ' . $updateBtn . '
          <a href="' . htmlspecialchars($rootPath) . 'index.php" style="display:inline-block;margin:14px 6px 0;border:1px solid #1B8A83;color:#1B8A83;text-decoration:none;padding:10px 22px;border-radius:9px;font-size:13px;font-weight:600;">Dashboard वर परत जा</a>
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
    if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_IDLE_LIMIT_SECONDS) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['session_expired'] = true;
        header('Location: ' . $rootPath . 'login.php');
        exit;
    }
    $_SESSION['last_activity'] = time();

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
        $exemptDirs = ['account', '__root__']; // Profile/Password — नेहमीच सगळ्यांना उपलब्ध
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
