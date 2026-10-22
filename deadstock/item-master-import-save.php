<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_dead_stock_master', '../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['csv_file']['tmp_name'])) {
    header('Location: item-master.php');
    exit;
}

$orgId = activeOrgId();

$handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") { rewind($handle); }

$header = array_map(fn($h) => strtolower(trim($h)), fgetcsv($handle));
if (!in_array('item_name', $header, true)) {
    fclose($handle);
    $_SESSION['form_error'] = 'CSV मध्ये "item_name" हा column असणे आवश्यक आहे.';
    header('Location: item-master.php');
    exit;
}

$colIndex = array_flip($header);
$insert = $pdo->prepare("INSERT INTO dead_stock_item_master (organization_id, item_name, category, default_unit, created_by) VALUES (?, ?, ?, ?, ?)");

$created = 0;
$errors = [];
$rowNum = 1;

while (($row = fgetcsv($handle)) !== false) {
    $rowNum++;
    if (count($row) === 1 && trim($row[0]) === '') continue;

    $get = fn($col) => isset($colIndex[$col]) && isset($row[$colIndex[$col]]) ? trim($row[$colIndex[$col]]) : null;

    $itemName = $get('item_name');
    if (!$itemName) { $errors[] = "ओळ {$rowNum}: वस्तूचे नाव रिकामे आहे — वगळले"; continue; }

    try {
        $insert->execute([$orgId, $itemName, $get('category') ?: null, $get('default_unit') ?: null, $user['id']]);
        $created++;
    } catch (PDOException $e) {
        $errors[] = "ओळ {$rowNum}: '{$itemName}' आधीच यादीत आहे — वगळले";
    }
}
fclose($handle);

logActivity('वस्तू यादी CSV आयात केली', 'dead_stock_item_master', null, "$created वस्तू जोडल्या");
$_SESSION['flash'] = "$created वस्तू यशस्वीरित्या यादीत जोडल्या गेल्या.";
if (!empty($errors)) { $_SESSION['import_errors'] = $errors; }
header('Location: item-master.php');
exit;
