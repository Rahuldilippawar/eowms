<?php
/**
 * Organization-निहाय Module Visibility — Master List + Helper Functions
 *
 * इथे Sidebar मधले सगळे "बंद करता येण्यासारखे" Groups नोंदवले आहेत.
 * "मुख्य कामकाज" (Daily-Work/Tasks/Requests/Reports) आणि "प्रशासन"
 * (Super Admin Settings) — हे मुद्दाम समाविष्ट केलेले नाहीत, कारण ते
 * Core/Essential आहेत — कुठल्याही Organization साठी बंद करता येऊ नयेत.
 */
function getToggleableModules() {
    return [
        'letters' => [
            'label' => 'पत्र व विनंत्या', 'icon' => 'fa-solid fa-envelope',
            'dirs' => ['letters', 'requests', 'directory', 'announcements', 'suggestions', 'documents', 'emergency'],
        ],
        'staff' => [
            'label' => 'कर्मचारी विभाग', 'icon' => 'fa-solid fa-users',
            'dirs' => ['staff', 'reporting-tree.php', 'org-chart.php'],
        ],
        'hostel_residency' => [
            'label' => 'वसतिगृह व रहिवासी', 'icon' => 'fa-solid fa-house-user',
            'dirs' => ['hostels', 'residency'],
        ],
        'activities' => [
            'label' => 'उपक्रम व अनुपालन', 'icon' => 'fa-solid fa-om',
            'dirs' => ['meditation', 'music', 'academics', 'sop', 'teaching', 'events', 'guests'],
        ],
        'facilities' => [
            'label' => 'सुविधा व्यवस्थापन', 'icon' => 'fa-solid fa-building',
            'dirs' => ['room-booking', 'checkout', 'allocations'],
        ],
        'communication' => [
            'label' => 'संवाद व सहभाग', 'icon' => 'fa-solid fa-comments',
            'dirs' => ['messages', 'grievances'],
        ],
        'vehicles_assets' => [
            'label' => 'वाहन व मालमत्ता', 'icon' => 'fa-solid fa-car',
            'dirs' => ['vehicles', 'deadstock', 'maintenance'],
        ],
        'technical' => [
            'label' => 'तांत्रिक विभाग', 'icon' => 'fa-solid fa-server',
            'dirs' => ['cctv', 'it'],
        ],
        'financial' => [
            'label' => 'आर्थिक', 'icon' => 'fa-solid fa-sack-dollar',
            'dirs' => ['budget', 'dept-finance'],
        ],
        'gaushala' => [
            'label' => 'गौशाळा', 'icon' => 'fa-solid fa-cow',
            'dirs' => ['gaushala'],
        ],
        'store' => [
            'label' => 'भांडार Material-यादी', 'icon' => 'fa-solid fa-warehouse',
            'dirs' => ['store'],
        ],
    ];
}

/**
 * एका Organization साठी, सगळ्या Modules ची Enabled/Disabled स्थिती —
 * एकाच Query मध्ये, Request-भर Cache करून (वारंवार Database ला न विचारता).
 */
function getOrgModuleSettings($pdo, $orgId) {
    static $cache = [];
    if (isset($cache[$orgId])) { return $cache[$orgId]; }

    $settings = [];
    try {
        $stmt = $pdo->prepare("SELECT module_key, is_enabled FROM organization_module_settings WHERE organization_id = ?");
        $stmt->execute([$orgId]);
        foreach ($stmt->fetchAll() as $row) { $settings[$row['module_key']] = (bool)$row['is_enabled']; }
    } catch (PDOException $e) { /* table not migrated yet — सगळे Modules Enabled समज */ }

    $cache[$orgId] = $settings;
    return $settings;
}

/**
 * हा Module या Organization साठी दाखवायचा का — Row नसेल तर Default
 * Enabled (जुन्या Organizations चं काही अचानक गायब होऊ नये म्हणून).
 */
function isModuleEnabledForOrg($pdo, $orgId, $moduleKey) {
    $settings = getOrgModuleSettings($pdo, $orgId);
    return $settings[$moduleKey] ?? true;
}

/**
 * एखादं Directory (उदा. 'gaushala', 'hostels') कोणत्या Module-Key खाली
 * येतं ते शोधतो — URL-Level Enforcement साठी.
 */
function findModuleKeyForDir($dir) {
    foreach (getToggleableModules() as $key => $mod) {
        if (in_array($dir, $mod['dirs'], true)) { return $key; }
    }
    return null;
}

/**
 * कुठल्याही Module च्या index/मुख्य Page वर — हा Module Organization साठी
 * बंद असेल तर इथेच थांबवतो, स्पष्ट Message सह.
 */
function requireModuleEnabled($pdo, $orgId, $moduleKey, $rootPath = '../') {
    if (isModuleEnabledForOrg($pdo, $orgId, $moduleKey)) { return; }
    http_response_code(403);
    $modules = getToggleableModules();
    $label = $modules[$moduleKey]['label'] ?? $moduleKey;
    die('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Module बंद आहे</title>
    <meta name="viewport" content="width=device-width, initial-scale=1"></head>
    <body style="font-family:sans-serif;background:#F5F6FA;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px;">
    <div style="background:#fff;border-radius:16px;padding:36px 32px;max-width:460px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,.08);">
      <div style="font-size:40px;">🔒</div>
      <div style="font-size:17px;font-weight:700;margin:12px 0 8px;">"' . htmlspecialchars($label) . '" सध्या बंद आहे</div>
      <p style="font-size:13px;color:#666B7D;line-height:1.8;">हा Module तुमच्या Organization साठी Super Admin ने बंद केलेला आहे.</p>
      <a href="' . htmlspecialchars($rootPath) . 'index.php" style="display:inline-block;margin-top:14px;background:#1B8A83;color:#fff;text-decoration:none;padding:10px 26px;border-radius:9px;font-size:13px;font-weight:600;">Dashboard वर परत जा</a>
    </div>
    </body></html>');
}
