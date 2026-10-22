<?php
// विभाग Finance — सामायिक Helper Functions (Material Purchase, Renewal, Maintenance तिन्हीसाठी)

/** सध्याचा User कोणत्या Department चं Finance बघू शकतो — HOD फक्त स्वतःचं, Management सगळं */
function deptFinanceAccessibleDeptId($user) {
    if (isManagementOrAbove() || hasRole('super_admin')) { return null; } // null = सगळे विभाग
    return $user['department_id'] ?? 0;
}

/** एका Source (Purchase/Renewal/Maintenance) साठी आतापर्यंत किती भरलं */
function deptFinancePaidTotal($pdo, $sourceType, $sourceId) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) total FROM dept_payment_ledger WHERE source_type = ? AND source_id = ?");
    $stmt->execute([$sourceType, $sourceId]);
    return (float)$stmt->fetch()['total'];
}

/** एकाच वेळी अनेक Sources साठी Paid-Totals — N+1 Query टाळण्यासाठी */
function deptFinancePaidTotalsBulk($pdo, $sourceType, array $sourceIds) {
    if (empty($sourceIds)) { return []; }
    $in = implode(',', array_fill(0, count($sourceIds), '?'));
    $stmt = $pdo->prepare("SELECT source_id, COALESCE(SUM(amount), 0) total FROM dept_payment_ledger WHERE source_type = ? AND source_id IN ($in) GROUP BY source_id");
    $stmt->execute(array_merge([$sourceType], $sourceIds));
    $out = [];
    foreach ($stmt->fetchAll() as $r) { $out[$r['source_id']] = (float)$r['total']; }
    return $out;
}

function deptFinanceStatusLabel($totalAmount, $paidAmount) {
    if ($paidAmount <= 0) { return ['🔴 Pending', 'overdue']; }
    if ($paidAmount >= $totalAmount) { return ['🟢 पूर्ण Paid', 'done']; }
    return ['🟡 अंशतः Paid', 'pending'];
}

/** Recurring-Monthly Bill साठी — Start-Month पासून आजपर्यंत किती Months "Due" आहेत, आणि कोणत्या महिन्यांचं अजून Payment आलेलं नाही */
function deptFinanceRecurringMonths($startMonth, $monthlyAmount, array $paidMonths) {
    $months = [];
    if (empty($startMonth) || empty($monthlyAmount)) { return $months; }
    $cursor = new DateTime(date('Y-m-01', strtotime($startMonth)));
    $today = new DateTime(date('Y-m-01'));
    while ($cursor <= $today) {
        $key = $cursor->format('Y-m');
        $months[] = ['month' => $key, 'label' => $cursor->format('M Y'), 'paid' => in_array($key, $paidMonths, true)];
        $cursor->modify('+1 month');
    }
    return $months;
}
