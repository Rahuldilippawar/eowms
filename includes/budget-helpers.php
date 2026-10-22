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

/**
 * एखादा खर्च झाला की त्या विभागाच्या Budget मधून आपोआप वजा करण्यासाठी ही
 * नोंद टाकते. $departmentId रिकामा/शून्य असेल तर काहीही करत नाही (उदा. वाहनाला
 * विभाग नेमलेला नसेल तर तो खर्च कुठल्याही Budget मधून वजा होणार नाही).
 */
function recordBudgetTransaction($pdo, $orgId, $departmentId, $amount, $category, $sourceModule, $referenceId, $description, $userId) {
    if (!$departmentId || !$amount || (float)$amount <= 0) return;

    // विभागाची स्वतःची संस्था वापरा — जो कोणी सध्या Login आहे त्याची नाही.
    // हे विशेषतः महत्त्वाचं आहे जेव्हा Sister-Organization चं पत्र मंजूर होतं —
    // तेव्हा Approve करणारा दुसऱ्याच संस्थेचा असू शकतो, पण खर्च मात्र मूळ
    // (विनंती करणाऱ्या) विभागाच्या संस्थेच्या Budget मधूनच वजा व्हायला हवा.
    $deptStmt = $pdo->prepare("SELECT organization_id FROM departments WHERE id = ?");
    $deptStmt->execute([$departmentId]);
    $deptOrgId = $deptStmt->fetch()['organization_id'] ?? $orgId;

    $fy = getCurrentFinancialYear();
    try {
        $pdo->prepare("
            INSERT INTO budget_transactions (organization_id, department_id, financial_year, amount, category, source_module, reference_id, description, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$deptOrgId, $departmentId, $fy, $amount, $category, $sourceModule, $referenceId, $description, $userId]);
    } catch (Throwable $e) {
        // budget_transactions table कदाचित अजून migrate झालेली नसेल — silently skip
    }
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

    return [
        'financial_year' => $fy,
        'allocated' => $allocated,
        'spent' => $spent,
        'remaining' => $allocated - $spent,
        'percent_used' => $allocated > 0 ? min(100, round(($spent / $allocated) * 100)) : ($spent > 0 ? 100 : 0),
        'by_category' => $byCategory,
        'has_budget' => $budget !== false,
    ];
}
