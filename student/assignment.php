<?php
require_once __DIR__ . '/header.php';

$assignmentId = (int)($_GET['id'] ?? 0);
$assignStmt = $pdo->prepare("SELECT * FROM classroom_assignments WHERE id = ?");
$assignStmt->execute([$assignmentId]);
$assignment = $assignStmt->fetch();

if (!$assignment || !studentHasClassroomAccess($pdo, $student['id'], $assignment['classroom_id'])) {
    http_response_code(403);
    die('तुम्हाला हा Assignment बघण्याची परवानगी नाही.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $note = trim($_POST['submission_note'] ?? '');
    $filePath = null;
    $originalName = null;

    if (!empty($_FILES['submission_file']['tmp_name']) && $_FILES['submission_file']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
        $ext = strtolower(pathinfo($_FILES['submission_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExt, true)) {
            $uploadDir = __DIR__ . '/../assets/uploads/submissions';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
            $safeName = 'sub-' . $assignmentId . '-' . $student['id'] . '-' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['submission_file']['tmp_name'], $uploadDir . '/' . $safeName)) {
                $filePath = 'assets/uploads/submissions/' . $safeName;
                $originalName = $_FILES['submission_file']['name'];
            }
        }
    }

    if ($filePath || $note) {
        $pdo->prepare("
            INSERT INTO classroom_assignment_submissions (assignment_id, student_id, file_path, original_filename, submission_note)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE file_path = VALUES(file_path), original_filename = VALUES(original_filename), submission_note = VALUES(submission_note), submitted_at = NOW()
        ")->execute([$assignmentId, $student['id'], $filePath, $originalName, $note]);
        $_SESSION['flash'] = 'Assignment Submit झालं.';
        header('Location: assignment.php?id=' . $assignmentId);
        exit;
    }
}

$subStmt = $pdo->prepare("SELECT * FROM classroom_assignment_submissions WHERE assignment_id = ? AND student_id = ?");
$subStmt->execute([$assignmentId, $student['id']]);
$mySubmission = $subStmt->fetch();
?>

<div class="page-title">📝 <?= h($assignment['title']) ?></div>
<p class="sub"><?= h($assignment['subject_name'] ?: '') ?> · मुदत: <?= date('d M Y', strtotime($assignment['due_date'])) ?><?= $assignment['max_marks'] ? ' · कमाल गुण: ' . $assignment['max_marks'] : '' ?></p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert-success"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel" style="padding:18px;margin-bottom:16px;">
  <?= nl2br(h($assignment['description'] ?: '')) ?>
  <?php if ($assignment['attachment_path']): ?>
    <div style="margin-top:12px;"><a href="assignment-attachment.php?id=<?= $assignment['id'] ?>" class="btn-outline">📎 शिक्षकांनी दिलेली Attachment</a></div>
  <?php endif; ?>
</div>

<?php if ($mySubmission && $mySubmission['marks_obtained'] !== null): ?>
  <div class="panel" style="padding:18px;background:var(--rail-soft);">
    <div style="font-weight:700;">✅ Grade झालं — <?= $mySubmission['marks_obtained'] ?><?= $assignment['max_marks'] ? '/' . $assignment['max_marks'] : '' ?></div>
    <?php if ($mySubmission['feedback']): ?><div style="font-size:13px;margin-top:6px;">Feedback: <?= h($mySubmission['feedback']) ?></div><?php endif; ?>
  </div>
<?php endif; ?>

<div class="section-label" style="margin-top:20px;"><?= $mySubmission ? '✅ तुमचं Submission' : '📤 Submit करा' ?></div>
<div class="panel" style="padding:18px;">
  <?php if ($mySubmission): ?>
    <p style="font-size:13px;color:var(--text-dim);"><?= date('d M Y, h:i A', strtotime($mySubmission['submitted_at'])) ?> रोजी Submit केलं</p>
    <?php if ($mySubmission['submission_note']): ?><p style="font-size:13px;"><?= h($mySubmission['submission_note']) ?></p><?php endif; ?>
    <?php if ($mySubmission['file_path']): ?><p style="font-size:13px;">📎 <?= h($mySubmission['original_filename']) ?></p><?php endif; ?>
    <p style="font-size:11.5px;color:var(--text-dim);margin-top:10px;">पुन्हा File Upload केल्यास — आधीचं Replace होईल.</p>
  <?php endif; ?>
  <form method="POST" enctype="multipart/form-data">
    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:6px;">Note (ऐच्छिक)</label>
    <textarea name="submission_note" rows="2" style="width:100%;padding:10px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;margin-bottom:12px;"><?= h($mySubmission['submission_note'] ?? '') ?></textarea>
    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:6px;">File (PDF/Photo/Word)</label>
    <input type="file" name="submission_file" style="margin-bottom:14px;">
    <button type="submit" class="btn-primary"><?= $mySubmission ? 'पुन्हा Submit करा' : 'Submit करा' ?></button>
  </form>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
