-- ============================================================
-- Migration: Teaching Module पूर्णपणे काढलं — Virtual Classroom
-- (academics/) हेच Class/Timetable साठी एकमेव ठिकाण.
-- टीप: या Tables चा Data कायमचा जातो (मालकाची मंजुरी आहे).
-- teacher_feedback ठेवली आहे — Student Portal (give-feedback) तिथे लिहितो.
-- ============================================================

DROP TABLE IF EXISTS substitute_assignments;
DROP TABLE IF EXISTS teacher_timetable;
DROP TABLE IF EXISTS syllabus_trackers;
DROP TABLE IF EXISTS teacher_qualifications;
DROP TABLE IF EXISTS class_teacher_assignments;
DROP TABLE IF EXISTS exam_duty_allocations;

-- Teaching Module ची Permission (फक्त त्याच Module मध्ये वापरली जात होती)
DELETE FROM role_permissions WHERE permission_key = 'manage_teaching_academics';
DELETE FROM permissions WHERE permission_key = 'manage_teaching_academics';

-- Help Center मधला "Teaching Module" लेख
DELETE FROM help_articles WHERE title LIKE '%Teaching Module%';

-- Student Feedback — एक विद्यार्थी एका शिक्षकाला महिन्यातून एकदाच (कोणी दिलं ते Anonymous च दाखवतो)
ALTER TABLE teacher_feedback ADD COLUMN student_id INT NULL AFTER teacher_id;
ALTER TABLE teacher_feedback ADD FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE;
ALTER TABLE teacher_feedback ADD INDEX idx_fb_student_teacher (student_id, teacher_id, submitted_at);
