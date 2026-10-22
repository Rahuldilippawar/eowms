<?php
require_once __DIR__ . '/../includes/student-auth.php';
requireStudentLogin('');
$student = currentStudent();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $classroomId = (int)($_POST['classroom_id'] ?? 0);
    $subjectName = trim($_POST['subject_name'] ?? '') ?: null;
    $questionText = trim($_POST['question_text'] ?? '');

    if (!studentHasClassroomAccess($pdo, $student['id'], $classroomId)) {
        http_response_code(403);
        die('तुम्ही या वर्गात नाही आहात.');
    }

    if ($questionText) {
        $pdo->prepare("INSERT INTO classroom_questions (classroom_id, student_id, subject_name, question_text) VALUES (?, ?, ?, ?)")
            ->execute([$classroomId, $student['id'], $subjectName, $questionText]);

        // त्या Classroom च्या सगळ्या शिक्षकांना कळवतो (थेट Insert — पूर्ण
        // includes/auth.php Include करण्याची गरज नाही, Session-Conflict
        // टाळण्यासाठी)
        $teachersStmt = $pdo->prepare("SELECT DISTINCT user_id FROM classroom_staff WHERE classroom_id = ?");
        $teachersStmt->execute([$classroomId]);
        $notifyMessage = $questionText;
        $notifyLink = 'academics/classroom-questions.php?id=' . $classroomId;
        foreach ($teachersStmt->fetchAll(PDO::FETCH_COLUMN) as $teacherId) {
            try {
                $pdo->prepare("INSERT INTO notifications (organization_id, user_id, title, message, link) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$student['organization_id'], $teacherId, '💬 नवीन प्रश्न — ' . $student['name'], $notifyMessage, $notifyLink]);
            } catch (PDOException $e) { /* notifications table not migrated */ }
        }
    }
}

header('Location: classroom.php?id=' . $classroomId);
exit;
