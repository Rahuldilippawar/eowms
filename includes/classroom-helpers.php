<?php
// Virtual Classroom — Notification व Access-Check Helpers.
// या File मध्ये मुद्दाम कुठलाही Session/Auth Bootstrap नाही — जेणेकरून
// Staff Pages (includes/auth.php वापरणारी) आणि Student Pages
// (includes/student-auth.php वापरणारी) — दोन्हीकडून Safely Include
// करता येईल, Session Conflict न होता.

function studentHasClassroomAccess($pdo, $studentId, $classroomId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM classroom_students WHERE student_id = ? AND classroom_id = ?");
    $stmt->execute([$studentId, $classroomId]);
    return (int)$stmt->fetch()['c'] > 0;
}

/** विद्यार्थ्याला Notification पाठवण्यासाठी — users.notifyUser() पासून वेगळं. */
function notifyStudent($pdo, $studentId, $title, $message = null, $link = null) {
    try {
        $pdo->prepare("INSERT INTO student_notifications (student_id, title, message, link) VALUES (?, ?, ?, ?)")
            ->execute([$studentId, $title, $message, $link]);
    } catch (PDOException $e) { /* table not migrated yet */ }
}

/** एका Classroom मधल्या सगळ्या विद्यार्थ्यांना एकत्र Notify करण्यासाठी. */
function notifyClassroomStudents($pdo, $classroomId, $title, $message = null, $link = null) {
    $stmt = $pdo->prepare("SELECT student_id FROM classroom_students WHERE classroom_id = ?");
    $stmt->execute([$classroomId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
        notifyStudent($pdo, $sid, $title, $message, $link);
    }
}
