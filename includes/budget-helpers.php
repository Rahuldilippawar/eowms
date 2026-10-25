<?php
/**
 * विभाग-निहाय Budget व्यवस्थापन — Helper Functions
 * आर्थिक वर्ष: एप्रिल ते मार्च (उदा. FY 2026 = एप्रिल 2026 ते मार्च 2027)
 */

/** सध्याच्या आर्थिक वर्षाचं सुरुवातीचं वर्ष परत करते (उदा. जुलै 2026 -> 2026, जानेवारी 2026 -> 2025) */
function getCurrentFinancialYear() {
    $month = (int)date('n');
    $year = (int)date('Y');
    return $month >= 4 ? $year : $year - 1;
}

/** दिलेल्या FY-सुरुवात-वर्षासाठी सुरुवात व शेवट दिनांक */
function getBudgetFinancialYearRange($fyStartYear) {
    return [
        'start' => "$fyStartYear-04-01",
        'end' => ($fyStartYear + 1) . '-03-31',
    ];
}

/** FY साठी छान लेबल — उदा. "एप्रिल 2026 – मार्च 2027" */
function formatFinancialYearLabel($fyStartYear) {
    return "एप्रिल $fyStartYear – मार्च " . ($fyStartYear + 1);
}

/** दिलेल्या दिनांकाचं आर्थिक वर्ष (FY-सुरुवात-वर्ष) — उदा. 2026-02-10 -> 2025 */
function financialYearForDate($date = null) {
    $ts = $date ? strtotime($date) : false;
    if (!$ts) { return getCurrentFinancialYear(); }
    $month = (int)date('n', $ts);
    $year = (int)date('Y', $ts);
    return $month >= 4 ? $year : $year - 1;
}

/** खर्च-Source चं लेबल (Dashboard वरच्या Breakdown साठी) */
function budgetSourceLabels() {
    return [
        'material_purchase' => '🛒 Material Purchase',
        'dead_stock' => '📦 Dead Stock खरेदी',
        'maintenance' => '🔧 देखभाल',
        'vehicle_fuel_log' => '⛽ वाहन इंधन',
        'vehicle_fuel' => '⛽ वाहन इंधन',
        'vehicle_maintenance_log' => '🚗 वाहन देखभाल',
        'vehicle_maintenance' => '🚗 वाहन देखभाल',
        'renewal_payment' => '📋 नूतनीकरण Payment',
        'letter_approval' => '✉️ पत्र-मंजुरी',
    ];
}

/** विभागाचं, दिलेल्या FY मधलं शिल्लक Budget — Budget सेट नसेल तर null */
function budgetRemainingFor($pdo, $departmentId, $fy) {
    try {
        $b = $pdo->prepare("SELECT allocated_amount FROM department_budgets WHERE department_id = ? AND financial_year = ?");
        $b->execute([$departmentId, $fy]);
        $row = $b->fetch();
        if (!$row) { return null; }
        $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) c FROM budget_transactions WHERE department_id = ? AND financial_year = ?");
        $s->execute([$departmentId, $fy]);
        return (float)$row['allocated_amount'] - (float)$s->fetch()['c'];
    } catch (Throwable $e) {
        return null;
    }
}

/** खर्च नोंदवल्यानंतर Budget ओलांडलं असेल तर — पुढच्या पानावर दाखवण्यासाठी इशारा */
function budgetFlagOverrun($pdo, $departmentId, $fy) {
    $remaining = budgetRemainingFor($pdo, $departmentId, $fy);
    if ($remaining !== null && $remaining < 0) {
        $nameStmt = $pdo->prepare("SELECT COALESCE(NULLIF(name_local,''), name) n FROM departments WHERE id = ?");
        $nameStmt->execute([$departmentId]);
        $deptName = $nameStmt->fetch()['n'] ?? '';
        $_SESSION['budget_warning'] = "⚠️ $deptName — FY $fy चं Budget ओलांडलं: ₹" . number_format(-$remaining, 0) . " जास्त खर्च झाला आहे.";
    }
}

/**
 * एखादा खर्च झाला की त्या विभागाच्या Budget मधून आपोआप वजा करण्यासाठी ही
 * नोंद टाकते. $departmentId रिकामा/शून्य असेल तर काहीही करत नाही (उदा. वाहनाला
 * विभाग नेमलेला नसेल तर तो खर्च कुठल्याही Budget मधून वजा होणार नाही).
 * आर्थिक वर्ष — "आज" नाही, तर खर्चाच्या स्वतःच्या दिनांकावरून ($spendDate).
 * एका Source-नोंदीसाठी (source_module + reference_id) एकच Row — पुन्हा बोलावलं तर Update होते.
 */
function recordBudgetTransaction($pdo, $orgId, $departmentId, $amount, $category, $sourceModule, $referenceId, $description, $userId, $spendDate = null) {
    if (!$departmentId || !$amount || (float)$amount <= 0) return;

    // विभागाची स्वतःची संस्था वापरा — जो कोणी सध्या Login आहे त्याची नाही.
    // हे विशेषतः महत्त्वाचं आहे जेव्हा Sister-Organization चं पत्र मंजूर होतं —
    // तेव्हा Approve करणारा दुसऱ्याच संस्थेचा असू शकतो, पण खर्च मात्र मूळ
    // (विनंती करणाऱ्या) विभागाच्या संस्थेच्या Budget मधूनच वजा व्हायला हवा.
    $deptStmt = $pdo->prepare("SELECT organization_id FROM departments WHERE id = ?");
    $deptStmt->execute([$departmentId]);
    $deptOrgId = $deptStmt->fetch()['organization_id'] ?? $orgId;

    $spendDate = $spendDate ?: date('Y-m-d');
    $fy = financialYearForDate($spendDate);
    try {
        if ($sourceModule && $referenceId) {
            $existStmt = $pdo->prepare("SELECT id FROM budget_transactions WHERE source_module = ? AND reference_id = ? LIMIT 1");
            $existStmt->execute([$sourceModule, $referenceId]);
            $existingId = $existStmt->fetchColumn();
            if ($existingId) {
                $pdo->prepare("UPDATE budget_transactions SET organization_id = ?, department_id = ?, financial_year = ?, amount = ?, category = ?, description = ?, spend_date = ? WHERE id = ?")
                    ->execute([$deptOrgId, $departmentId, $fy, $amount, $category, $description, $spendDate, $existingId]);
                budgetFlagOverrun($pdo, $departmentId, $fy);
                return;
            }
        }
        $pdo->prepare("
            INSERT INTO budget_transactions (organization_id, department_id, financial_year, amount, category, source_module, reference_id, description, created_by, spend_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$deptOrgId, $departmentId, $fy, $amount, $category, $sourceModule, $referenceId, $description, $userId, $spendDate]);
        budgetFlagOverrun($pdo, $departmentId, $fy);
    } catch (Throwable $e) {
        // budget_transactions table (किंवा spend_date Column) कदाचित अजून migrate झालेली नसेल — silently skip
    }
}

/**
 * मूळ नोंद (Dead Stock/इंधन/देखभाल) बदलली — त्याचा खर्च-Row सुद्धा जुळवून घेतो.
 * रक्कम शून्य/विभाग नसेल तर खर्च-Row काढून टाकतो.
 */
function syncBudgetTransaction($pdo, $orgId, $departmentId, $amount, $category, $sourceModule, $referenceId, $description, $userId, $spendDate = null) {
    if (!$departmentId || !$amount || (float)$amount <= 0) {
        reverseBudgetTransaction($pdo, $sourceModule, $referenceId);
        return;
    }
    recordBudgetTransaction($pdo, $orgId, $departmentId, $amount, $category, $sourceModule, $referenceId, $description, $userId, $spendDate);
}

/** मूळ नोंद Delete झाली — तिचा खर्च Budget मधून परत (Reverse) करतो */
function reverseBudgetTransaction($pdo, $sourceModule, $referenceId) {
    if (!$sourceModule || !$referenceId) return;
    try {
        $pdo->prepare("DELETE FROM budget_transactions WHERE source_module = ? AND reference_id = ?")->execute([$sourceModule, $referenceId]);
    } catch (Throwable $e) {}
}

/**
 * एखाद्या विभागाची, दिलेल्या आर्थिक वर्षासाठीची Budget स्थिती — दिलेलं, खर्च
 * झालेलं (श्रेणी-निहाय विभागणीसह), आणि शिल्लक.
 */
function getBudgetStatus($pdo, $orgId, $departmentId, $fyStartYear = null) {
    $fy = $fyStartYear ?? getCurrentFinancialYear();

    $allocStmt = $pdo->prepare("SELECT * FROM department_budgets WHERE department_id = ? AND financial_year = ?");
    $allocStmt->execute([$departmentId, $fy]);
    $budget = $allocStmt->fetch();
    $allocated = $budget ? (float)$budget['allocated_amount'] : 0;

    $spentStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) c FROM budget_transactions WHERE department_id = ? AND financial_year = ?");
    $spentStmt->execute([$departmentId, $fy]);
    $spent = (float)$spentStmt->fetch()['c'];

    $byCategoryStmt = $pdo->prepare("
        SELECT category, COALESCE(SUM(amount),0) total FROM budget_transactions
        WHERE department_id = ? AND financial_year = ? GROUP BY category
    ");
    $byCategoryStmt->execute([$departmentId, $fy]);
    $byCategory = [];
    foreach ($byCategoryStmt->fetchAll() as $row) { $byCategory[$row['category']] = (float)$row['total']; }

    // Source-निहाय (Material Purchase / Dead Stock / देखभाल / इंधन / नूतनीकरण / पत्र)
    $bySourceStmt = $pdo->prepare("
        SELECT COALESCE(source_module, 'other') src, COALESCE(SUM(amount),0) total FROM budget_transactions
        WHERE department_id = ? AND financial_year = ? GROUP BY src
    ");
    $bySourceStmt->execute([$departmentId, $fy]);
    $labels = budgetSourceLabels();
    $bySource = [];
    foreach ($bySourceStmt->fetchAll() as $row) {
        $lbl = $labels[$row['src']] ?? $row['src'];
        $bySource[$lbl] = ($bySource[$lbl] ?? 0) + (float)$row['total'];
    }

    return [
        'financial_year' => $fy,
        'allocated' => $allocated,
        'spent' => $spent,
        'remaining' => $allocated - $spent,
        'percent_used' => $allocated > 0 ? min(100, round(($spent / $allocated) * 100)) : ($spent > 0 ? 100 : 0),
        'by_category' => $byCategory,
        'by_source' => $bySource,
        'has_budget' => $budget !== false,
    ];
}
