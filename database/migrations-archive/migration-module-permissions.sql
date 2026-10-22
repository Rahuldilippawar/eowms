-- ============================================================
-- Migration: 3 नवीन Module Permissions — Virtual Classroom, संगीत
-- विभाग (आधी पूर्णपणे Unprotected होता!), ध्यान व सत्संग
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

INSERT IGNORE INTO permissions (permission_key, label, label_local, sort_order) VALUES
('manage_academics', 'Manage Virtual Classroom (students, classrooms, materials, assignments) for any department — beyond own HOD scope', 'Virtual Classroom व्यवस्थापन (कोणत्याही विभागासाठी — स्वतःच्या HOD-मर्यादेपलीकडे)', 24),
('manage_music', 'Manage Music Department (instruments, students, exams, events, bookings)', 'संगीत विभाग व्यवस्थापन (वाद्य, विद्यार्थी, परीक्षा, कार्यक्रम, Booking)', 25),
('manage_meditation', 'Manage Meditation & Satsang (duty chart, quotes, events) — beyond top Management', 'ध्यान व सत्संग व्यवस्थापन (Duty Chart, सुविचार, कार्यक्रम) — फक्त वरिष्ठ Management शिवायही', 26);

-- Super Admin ला हे Permission आपोआप देतो — बाकी कोणालाही Auto-Grant
-- केलेलं नाही (जाणूनबुजून — Super Admin नंतर Roles & Permissions मधून
-- हवं त्याला निवडून देऊ शकतो).
