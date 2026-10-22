<?php
require_once __DIR__ . '/../includes/student-auth.php';
requireStudentLogin('');
$student = currentStudent();
$orgId = $student['organization_id'];
// शैक्षणिक वर्ष जूनपासून सुरू — जानेवारी-मे मध्ये मागच्या वर्षाचंच Academic Year
$fyStartYear = (int)date('Y') - ((int)date('n') < 6 ? 1 : 0);
$fy = $fyStartYear . '-' . substr((string)($fyStartYear + 1), 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $teacherId = (int)($_POST['teacher_id'] ?? 0);
    $rating = (int)($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    if ($teacherId && $rating >= 1 && $rating <= 5) {
        $pdo->prepare("INSERT INTO teacher_feedback (teacher_id, academic_year, rating, comment, submitted_by_type) VALUES (?, ?, ?, ?, 'student')")
            ->execute([$teacherId, $fy, $rating, $comment ?: null]);
        $_SESSION['feedback_flash'] = '✅ धन्यवाद! तुमचा Feedback पाठवला गेला.';
    }
    header('Location: give-feedback.php');
    exit;
}

require_once __DIR__ . '/header.php';
// विद्यार्थी ज्या-ज्या Classroom मध्ये आहे, त्यातले सगळे Unique शिक्षक
$teachersStmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.name, u.designation, cs.subject_name
    FROM classroom_students cst
    JOIN classroom_staff cs ON cs.classroom_id = cst.classroom_id
    JOIN users u ON u.id = cs.user_id
    WHERE cst.student_id = ?
    ORDER BY u.name
");
$teachersStmt->execute([$student['id']]);
$teachers = $teachersStmt->fetchAll();
?>

<div class="page-title mb-1">💬 शिक्षकांना Feedback द्या</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">तुमचं मत महत्त्वाचं आहे — शिक्षकांना सुधारणा करायला मदत होते</p>

<?php if (!empty($_SESSION['feedback_flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['feedback_flash']) ?></div>
  <?php unset($_SESSION['feedback_flash']); ?>
<?php endif; ?>

<div class="row g-3">
  <?php if (empty($teachers)): ?><div class="col-12"><p style="color:var(--text-dim);">अजून कुठलेही शिक्षक नेमलेले नाहीत.</p></div><?php endif; ?>
  <?php foreach ($teachers as $t): ?>
    <div class="col-md-6">
      <div class="panel" style="padding:18px;">
        <div style="font-weight:700;font-size:14px;"><?= h($t['name']) ?></div>
        <div style="font-size:11.5px;color:var(--text-dim);margin-bottom:10px;"><?= h($t['subject_name']) ?></div>
        <form method="POST">
          <input type="hidden" name="teacher_id" value="<?= $t['id'] ?>">
          <div class="star-rating" style="font-size:22px;margin-bottom:8px;" data-name="rating-<?= $t['id'] ?>">
            <?php for ($i = 1; $i <= 5; $i++): ?>
              <label style="cursor:pointer;color:#D7DAE0;" data-val="<?= $i ?>">☆</label>
            <?php endfor; ?>
          </div>
          <input type="hidden" name="rating" class="rating-input" value="0" required>
          <textarea name="comment" class="form-control-custom mb-2" rows="2" placeholder="काही सुचवायचंय का? (ऐच्छिक)"></textarea>
          <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12.5px;padding:8px;">पाठवा</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<script>
document.querySelectorAll('.star-rating').forEach(function (widget) {
  const labels = widget.querySelectorAll('label');
  const input = widget.parentElement.querySelector('.rating-input') || widget.nextElementSibling;
  labels.forEach(function (label) {
    label.addEventListener('click', function () {
      const val = parseInt(label.getAttribute('data-val'));
      widget.nextElementSibling.value = val;
      labels.forEach(function (l, i) { l.textContent = (i < val) ? '★' : '☆'; l.style.color = (i < val) ? '#C97F1D' : '#D7DAE0'; });
    });
  });
});
</script>

<?php require_once __DIR__ . '/../student/footer.php'; ?>
