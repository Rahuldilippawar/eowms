<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$target = $stmt->fetch();

if (!$target) {
    $_SESSION['form_error'] = t('s511');
    header('Location: index.php');
    exit;
}

// Hard rule: a Super Admin account can NEVER be deleted, by anyone, ever —
// regardless of how many other Super Admins exist. This guarantees the
// system always keeps at least its original admin access path intact.
if ($target['role'] === 'super_admin') {
    $_SESSION['form_error'] = t('s569');
    header('Location: index.php');
    exit;
}

// खात्री — नाव टाइप करा (मोठी/लहान अक्षरं, जादा Space चालतात) किंवा "हटवा"
$norm = fn($x) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string)$x)));
$confirmText = $_POST['confirm_name'] ?? '';
if ($norm($confirmText) !== $norm($target['name']) && $norm($confirmText) !== 'हटवा') {
    $_SESSION['form_error'] = t('s570');
    header('Location: index.php');
    exit;
}

// 🗑️ पूर्णपणे हटवा — एकाच Transaction मध्ये:
//  1) ज्या tables मध्ये हा कर्मचारी "RESTRICT" ने अडकवतो (जुन्या Server वरच्या DB मध्ये असू शकतं) — शक्य तिथे NULL, नाहीतर ती ओळ हटवा
//  2) FK नसलेल्या ठिकाणच्या त्याच्या स्वतःच्या नोंदी (E-Campus इतिहास, Campus प्रमुख)
//  3) users मधून हटवा — बाकीचं (हजेरी, कामं, Tasks, रजा, 2-Step, …) ON DELETE CASCADE / SET NULL ने आपोआप
$removedRefs = 0;
try {
    $pdo->beginTransaction();
    $fk = $pdo->query("SELECT k.TABLE_NAME t, k.COLUMN_NAME c, col.IS_NULLABLE n
        FROM information_schema.REFERENTIAL_CONSTRAINTS rc
        JOIN information_schema.KEY_COLUMN_USAGE k ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND k.TABLE_NAME = rc.TABLE_NAME
        JOIN information_schema.COLUMNS col ON col.TABLE_SCHEMA = k.TABLE_SCHEMA AND col.TABLE_NAME = k.TABLE_NAME AND col.COLUMN_NAME = k.COLUMN_NAME
        WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = 'users' AND rc.DELETE_RULE IN ('RESTRICT', 'NO ACTION')")->fetchAll();
    foreach ($fk as $r) {
        $t = '`' . str_replace('`', '', $r['t']) . '`'; $c = '`' . str_replace('`', '', $r['c']) . '`';
        $st = $pdo->prepare($r['n'] === 'YES' ? "UPDATE $t SET $c = NULL WHERE $c = ?" : "DELETE FROM $t WHERE $c = ?");
        $st->execute([$id]);
        $removedRefs += $st->rowCount();
    }
    foreach (["DELETE FROM ecampus_updates WHERE user_id = ?", "DELETE FROM campus_heads WHERE user_id = ?"] as $sql) {
        try { $pdo->prepare($sql)->execute([$id]); } catch (PDOException $e) { /* table नसेल तर ठीक */ }
    }
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('Staff delete failed for user ' . $id . ': ' . $e->getMessage());
    $_SESSION['form_error'] = "'{$target['name']}' हटवता आले नाही — त्यांच्या काही नोंदी अजून दुसरीकडे जोडलेल्या आहेत. त्याऐवजी त्यांना निष्क्रिय करा, किंवा ही त्रुटी कळवा: " . mb_substr($e->getMessage(), 0, 120);
    header('Location: index.php');
    exit;
}

// फोटो File पण हटवा
if (!empty($target['photo_path']) && str_starts_with((string)$target['photo_path'], 'assets/uploads/')) {
    $f = __DIR__ . '/../' . $target['photo_path'];
    if (is_file($f)) { @unlink($f); }
}

logActivity('कर्मचारी कायमचा डिलीट केला', 'user', $id, $target['name'] . ' (' . $target['email'] . ')');
$_SESSION['flash'] = "🗑️ '{$target['name']}' व त्यांच्या सगळ्या नोंदी कायमच्या हटवल्या.";
header('Location: index.php');
exit;
