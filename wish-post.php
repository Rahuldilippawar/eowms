<?php
require_once __DIR__ . '/includes/auth.php';
$user = currentUser();

if (!$user) {
    // Login न करताच शुभेच्छा टाकण्याचा प्रयत्न झाला — गप्प Welcome Page वर परत
    header('Location: welcome.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: welcome.php');
    exit;
}

$targetUserId = (int)($_POST['target_user_id'] ?? 0);
$wishType = $_POST['wish_type'] ?? 'birthday';
// झटपट शुभेच्छा-बटण (wish_text) किंवा स्वतः लिहिलेली (wish_custom)
$wishText = trim((string)($_POST['wish_text'] ?? '')) ?: trim((string)($_POST['wish_custom'] ?? ''));
$wishDate = date('Y-m-d');

if (!in_array($wishType, ['birthday', 'anniversary'], true)) { $wishType = 'birthday'; }

if ($targetUserId && $wishText) {
    $wishText = mb_substr($wishText, 0, 200); // खूप मोठी शुभेच्छा नको — भिंत नीट दिसावी म्हणून
    // फक्त आपल्याच संस्थेच्या, सक्रिय कर्मचाऱ्याला — आणि खरंच आज त्याचा वाढदिवस / वर्धापनदिन असेल तरच
    // (नाहीतर कोणीही कोणत्याही संस्थेतल्या कोणालाही कधीही "शुभेच्छा" सूचना पाठवू शकत होतं)
    $dateCol = $wishType === 'birthday' ? 'date_of_birth' : 'joining_date';
    $targetStmt = $pdo->prepare("SELECT organization_id FROM users WHERE id = ? AND is_active = 1 AND organization_id IN (?, ?)
        AND $dateCol IS NOT NULL AND MONTH($dateCol) = MONTH(CURDATE()) AND DAY($dateCol) = DAY(CURDATE())"
        . ($wishType === 'anniversary' ? " AND YEAR(joining_date) < YEAR(CURDATE())" : ''));
    $targetStmt->execute([$targetUserId, (int)($user['organization_id'] ?? 0), (int)activeOrgId()]);
    $target = $targetStmt->fetch();

    if ($target) {
        try {
            $pdo->prepare("INSERT INTO birthday_wishes (organization_id, target_user_id, wish_date, wish_type, wish_text, posted_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$target['organization_id'], $targetUserId, $wishDate, $wishType, $wishText, $user['id']]);
            if ((int)$targetUserId !== (int)$user['id']) {
                notifyUser($targetUserId, $target['organization_id'], "🎉 {$user['name']} यांनी तुम्हाला शुभेच्छा दिल्या", $wishText, 'welcome.php#wishes');
            }
            $_SESSION['flash'] = 'तुमची शुभेच्छा जोडली गेली! 🎉';
        } catch (PDOException $e) {
            // आधीच याच दिवशी याच व्यक्तीला शुभेच्छा दिलेली आहे (Unique Constraint) — शांतपणे दुर्लक्षित करतो
            $_SESSION['flash'] = 'तुम्ही आधीच शुभेच्छा दिलेल्या आहेत.';
        }
    }
}

header('Location: welcome.php#wishes');
exit;
