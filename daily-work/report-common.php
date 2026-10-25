<?php
/**
 * कामाचा अहवाल — report.php / report-print.php / export-csv.php तिन्हींसाठी सामायिक:
 * Scope (व्यक्तिगत / विभाग / संपूर्ण संस्था), कालावधी, आणि परवानगी-तपासणी.
 * (पूर्वीचं reports/ Module इथे एकत्र केलं आहे.)
 */

function dwReportPeriodLabels() {
    return [
        'daily' => 'दैनिक', 'weekly' => 'साप्ताहिक', 'monthly' => 'मासिक',
        'six_monthly' => 'सहामाही', 'yearly' => 'वार्षिक', 'all' => 'सर्व कालावधी',
    ];
}

function dwReportScopeLabels() {
    return ['personal' => 'व्यक्तिगत अहवाल', 'department' => 'विभागनिहाय अहवाल', 'organization' => 'संपूर्ण संस्था अहवाल'];
}

/**
 * GET मधून Scope/कालावधी/विभाग वाचून — परवानगी तपासून — Context परत देतो.
 * परवानगी नसेल तर 403 देऊन थांबतो.
 */
function dwReportContext($pdo, $user) {
    $orgId = activeOrgId();
    $deptScoped = isDeptScoped();
    $ownDeptId = (int)($user['department_id'] ?? 0);

    // कोणते Scopes उपलब्ध
    $allowed = ['personal'];
    if (!$deptScoped || $ownDeptId) { $allowed[] = 'department'; }
    if (!$deptScoped || hasPermission('view_org_wide_reports')) { $allowed[] = 'organization'; }

    $default = $deptScoped ? ($ownDeptId ? 'department' : 'personal') : 'organization';
    $scope = $_GET['scope'] ?? $default;
    if (!in_array($scope, ['personal', 'department', 'organization'], true)) { $scope = $default; }
    if (!in_array($scope, $allowed, true)) {
        http_response_code(403);
        die($scope === 'organization' ? t('s552') : t('s553'));
    }

    $period = $_GET['period'] ?? 'weekly';
    switch ($period) {
        case 'daily': $start = date('Y-m-d'); $end = date('Y-m-d'); break;
        case 'monthly': $start = date('Y-m-01'); $end = date('Y-m-t'); break;
        case 'six_monthly': $start = date('Y-m-d', strtotime('-6 months')); $end = date('Y-m-d'); break;
        case 'yearly': $start = date('Y-01-01'); $end = date('Y-12-31'); break;
        case 'all': $start = '2000-01-01'; $end = date('Y-m-d'); break;
        default: $period = 'weekly'; $start = date('Y-m-d', strtotime('monday this week')); $end = date('Y-m-d', strtotime('sunday this week'));
    }

    // विभाग — Dept-Scoped व्यक्तीसाठी नेहमी स्वतःचाच; इतरांसाठी निवडलेला, पण याच संस्थेचा असेल तरच
    $deptId = 0;
    if ($deptScoped) {
        $deptId = $ownDeptId;
    } elseif (!empty($_GET['department_id'])) {
        $chk = $pdo->prepare("SELECT id FROM departments WHERE id = ? AND organization_id = ?");
        $chk->execute([(int)$_GET['department_id'], $orgId]);
        $deptId = (int)($chk->fetchColumn() ?: 0);
        if (!$deptId) {
            http_response_code(403);
            die(t('s554'));
        }
    }

    // विभाग-Scope साठी विभाग आवश्यक — नसेल तर पहिला उपलब्ध विभाग
    if ($scope === 'department' && !$deptId) {
        $first = $pdo->prepare("SELECT id FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local LIMIT 1");
        $first->execute([$orgId]);
        $deptId = (int)($first->fetchColumn() ?: 0);
    }

    return [
        'org_id' => $orgId, 'scope' => $scope, 'allowed_scopes' => $allowed, 'period' => $period,
        'start' => $start, 'end' => $end, 'department_id' => $deptId, 'dept_locked' => $deptScoped,
    ];
}

/**
 * कामाच्या नोंदी (विभाग/व्यक्तिगत Scope) — वापरलेलं साहित्य व कर्मचारी-नावांसह, नेहमी याच संस्थेच्या
 * $filterDeptId: विभाग-Scope मध्ये तो विभाग; Organization-Scope मध्ये ऐच्छिक Filter
 */
function dwReportRecords($pdo, $ctx, $user, $limit = null) {
    $where = "wr.organization_id = ? AND wr.work_date BETWEEN ? AND ?";
    $params = [$ctx['org_id'], $ctx['start'], $ctx['end']];
    if ($ctx['scope'] === 'personal') {
        $where .= " AND EXISTS (SELECT 1 FROM work_record_team wt WHERE wt.work_record_id = wr.id AND wt.user_id = ?)";
        $params[] = $user['id'];
    } elseif ($ctx['department_id']) {
        $where .= " AND wr.department_id = ?";
        $params[] = $ctx['department_id'];
    }
    $limitSql = $limit ? " LIMIT " . (int)$limit : "";
    $base = "SELECT wr.*, d.name_local AS dept_name, d.name AS dept_name_en,
          (SELECT GROUP_CONCAT(u2.name SEPARATOR ', ') FROM work_record_team wt2 JOIN users u2 ON u2.id = wt2.user_id WHERE wt2.work_record_id = wr.id) AS emp_name";
    $from = " FROM work_records wr LEFT JOIN departments d ON d.id = wr.department_id WHERE $where ORDER BY wr.work_date DESC, wr.id DESC$limitSql";
    $materials = ", (SELECT GROUP_CONCAT(CONCAT(si.item_name, ' (', wrm.quantity_used, ' ', si.unit, ')') SEPARATOR ', ') FROM work_record_materials wrm JOIN store_items si ON si.id = wrm.item_id WHERE wrm.work_record_id = wr.id) AS materials_used";
    $custom = ", (SELECT GROUP_CONCAT(CONCAT(cf.field_label, ': ', cfv.field_value) SEPARATOR ' | ')
           FROM custom_field_values cfv JOIN custom_fields cf ON cf.field_key = cfv.field_key AND cf.organization_id = wr.organization_id
           WHERE cfv.work_record_id = wr.id) AS custom_fields_display";
    // जुन्या Install मध्ये साहित्य/Custom-Field Tables नसतील — तर त्या Column शिवाय
    foreach ([[$materials, $custom], [$materials, ", NULL AS custom_fields_display"], [", NULL AS materials_used", ", NULL AS custom_fields_display"]] as [$m, $c]) {
        try {
            $stmt = $pdo->prepare($base . $m . $c . $from);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) { continue; }
    }
    return [];
}

/** संस्था-Scope — विभाग-निहाय एकूण/पूर्ण */
function dwReportOrgSummary($pdo, $ctx) {
    $stmt = $pdo->prepare("
        SELECT d.id, d.name_local, d.name, u.name AS hod_name, c.name AS campus_name,
          (SELECT COUNT(*) FROM work_records wr WHERE wr.department_id = d.id AND wr.organization_id = d.organization_id AND wr.work_date BETWEEN ? AND ?) AS total,
          (SELECT COUNT(*) FROM work_records wr WHERE wr.department_id = d.id AND wr.organization_id = d.organization_id AND wr.work_date BETWEEN ? AND ? AND wr.status = 'completed') AS done
        FROM departments d LEFT JOIN users u ON u.id = d.hod_id LEFT JOIN campuses c ON c.id = d.campus_id
        WHERE d.organization_id = ? AND d.is_active = 1" . ($ctx['department_id'] ? " AND d.id = " . (int)$ctx['department_id'] : "") . "
        ORDER BY c.name, d.name_local
    ");
    $stmt->execute([$ctx['start'], $ctx['end'], $ctx['start'], $ctx['end'], $ctx['org_id']]);
    $out = [];
    foreach ($stmt->fetchAll() as $d) {
        $out[] = [
            'id' => (int)$d['id'], 'name' => $d['name_local'] ?: $d['name'], 'hod_name' => $d['hod_name'] ?: '—', 'campus_name' => $d['campus_name'],
            'total' => (int)$d['total'], 'done' => (int)$d['done'], 'pct' => $d['total'] > 0 ? round(($d['done'] / $d['total']) * 100) : 0,
        ];
    }
    return $out;
}
