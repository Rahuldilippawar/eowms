-- ============================================================
-- Migration: Help Center / User Manual (System च्या आतच)
-- ============================================================

CREATE TABLE help_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  icon VARCHAR(60) DEFAULT 'fa-solid fa-circle-question',
  sort_order INT DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE help_articles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  content MEDIUMTEXT NOT NULL COMMENT 'HTML — Step-by-Step सूचना',
  keywords VARCHAR(500) NULL COMMENT 'Search साठी — Comma-Separated',
  sort_order INT DEFAULT 0,
  updated_by INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES help_categories(id) ON DELETE CASCADE,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO help_categories (name, icon, sort_order) VALUES
('सुरुवात कशी करावी', 'fa-solid fa-play', 1),
('दैनंदिन कामकाज', 'fa-solid fa-pen', 2),
('उपस्थिती व रजा', 'fa-solid fa-calendar-check', 3),
('पत्रव्यवहार', 'fa-solid fa-envelope', 4),
('Task व कामाची विनंती', 'fa-solid fa-list-check', 5),
('खरेदी व भांडार', 'fa-solid fa-cart-shopping', 6),
('कर्मचारी व्यवस्थापन', 'fa-solid fa-users', 7),
('Virtual Classroom (Teaching)', 'fa-solid fa-chalkboard-user', 8),
('Backup व Settings', 'fa-solid fa-gears', 9);

INSERT INTO help_articles (category_id, title, content, keywords, sort_order) VALUES
(1, 'Login कसं करायचं',
'<p>प्रत्येक कर्मचाऱ्याला Email आणि Password दिलेला असतो — तो HR/Super Admin कडून मिळतो.</p>
<ol>
<li>Website उघडा — तुमच्या संस्थेचा Link</li>
<li>वरच्या "Login" Button वर Click करा</li>
<li>Email आणि Password टाका</li>
<li>पहिल्यांदा Login करताना — नवीन Password सेट करायला सांगेल, तो लक्षात ठेवा</li>
</ol>
<p><b>Password विसरलात?</b> Login Page वर "Password विसरलात?" Link वर Click करा — Email वर Reset-Link येईल.</p>',
'login, पासवर्ड, प्रवेश', 1),

(1, 'Command Center — मुख्य पान समजून घ्या',
'<p>Login केल्यावर तुम्हाला "Command Center" दिसतं — हे तुमचं मुख्य Dashboard आहे.</p>
<ul>
<li><b>डावीकडे Sidebar</b> — तुम्हाला दिलेले सगळे Modules इथे दिसतात</li>
<li><b>वरती Search Bar</b> — कुठेही, काहीही पटकन शोधण्यासाठी (Ctrl+K दाबूनही उघडता येतं)</li>
<li><b>मधोमध Numbers/Charts</b> — तुमच्या कामाचा आजचा सारांश</li>
</ul>',
'dashboard, command center, मुख्य पान', 2),

(2, 'रोजची काम-नोंद कशी करायची',
'<ol>
<li>Sidebar मध्ये "दैनंदिन काम-नोंद" वर Click करा</li>
<li>"+ नवीन नोंद" Button दाबा</li>
<li>कामाचं वर्णन, दिनांक, आणि आवश्यक असल्यास Photo टाका</li>
<li>"जतन करा" दाबा</li>
</ol>
<p>तुमचा HOD/Management ही नोंद बघू शकतील आणि आवश्यक असल्यास Feedback देऊ शकतील.</p>',
'daily work, काम नोंद, रोजचं काम', 1),

(3, 'Attendance कशी मार्क होते',
'<p>बहुतेक ठिकाणी Attendance Biometric Machine वरून आपोआप येते. पण Manual नोंदही करता येते (HOD/Management साठी).</p>
<ol>
<li>Sidebar → "उपस्थिती" वर जा</li>
<li>Calendar वर तुमची महिन्याची Attendance दिसेल</li>
<li>कुठे चूक वाटली, तर "Regularization Request" पाठवू शकता — HOD Approve करेल</li>
</ol>',
'attendance, हजेरी, उपस्थिती', 1),

(3, 'रजेसाठी अर्ज कसा करायचा',
'<ol>
<li>Sidebar → "रजा अर्ज करा" वर जा</li>
<li>कोणती रजा (CL/Medical/Vacation), किती दिवस, कारण — भरा</li>
<li>"पाठवा" दाबा — तुमचा HOD ला Notification जाईल</li>
<li>Approve झाल्यावर — ती आपोआप तुमच्या Attendance मध्ये "रजेवर" म्हणून नोंदवली जाईल</li>
</ol>
<p>तुमची शिल्लक रजा — "माझी रजा" Page वर कायम दिसते.</p>',
'leave, रजा, सुट्टी', 2),

(4, 'नवीन पत्र कसं तयार करायचं',
'<ol>
<li>Sidebar → "पत्रव्यवहार" → "नवीन पत्र"</li>
<li>कोणत्या विभागाला/College ला पत्र आहे ते निवडा</li>
<li>विषय आणि तपशील लिहा</li>
<li>गरज असल्यास Attachment जोडा</li>
<li>"पाठवा" दाबा — Workflow-नुसार योग्य व्यक्तीकडे Approval साठी जाईल</li>
</ol>',
'letter, पत्र, correspondence', 1),

(5, 'Task कसं Complete करायचं',
'<ol>
<li>Sidebar → "Task" वर जा — तुम्हाला दिलेली सगळी Tasks दिसतील</li>
<li>ज्या Task वर काम केलंय, त्यावर Click करा</li>
<li>Status "पूर्ण झालं" करा, आवश्यक असल्यास टिप्पणी लिहा</li>
</ol>',
'task, काम, complete', 1),

(5, 'कामाची विनंती (Work Request) कशी पाठवायची',
'<p>हे Feature — कोणत्याही विभागाला, दुरुस्ती/मदत हवी असल्यास वापरतात.</p>
<ol>
<li>Sidebar → "कामाची विनंती" → "नवीन विनंती"</li>
<li>कोणत्या विभागाला विनंती आहे, आणि तपशील लिहा</li>
<li>पाठवल्यावर — त्या विभागाला Notification जाईल, ते Status Update करत राहतील</li>
</ol>',
'work request, विनंती, दुरुस्ती', 2),

(6, 'खरेदीची Requisition कशी करायची',
'<ol>
<li>Sidebar → "खरेदी व भांडार" → "नवीन Requisition"</li>
<li>कोणती वस्तू, किती Quantity, कशासाठी हवी — भरा</li>
<li>पाठवल्यावर — Approval-Chain नुसार पुढे जाईल (HOD → Management → Purchase Dept)</li>
</ol>',
'purchase, खरेदी, requisition', 1),

(7, 'नवीन कर्मचारी कसा जोडायचा (Super Admin/HR साठी)',
'<ol>
<li>Sidebar → "कर्मचारी" → "+ नवीन कर्मचारी जोडा"</li>
<li>नाव, Email, Mobile, विभाग, पद — भरा</li>
<li>Role निवडा (Staff/HOD/Management)</li>
<li>"जतन करा" — आपोआप Login Details असलेला Email त्यांना जाईल</li>
</ol>',
'staff, कर्मचारी, नवीन, add employee', 1),

(8, 'शिक्षकांसाठी — Teaching Module काय आहे',
'<p>जर तुम्ही "Teaching" Staff असाल, तर Sidebar मध्ये "Teaching Module" दिसेल — इथे:</p>
<ul>
<li><b>माझं Timetable</b> — तुमचं आठवड्याचं Lecture-Schedule</li>
<li><b>Syllabus Tracker</b> — किती Chapters पूर्ण झाले याची नोंद</li>
<li><b>Substitute</b> — तुम्ही रजेवर असाल तर कोण Class घेणार</li>
<li><b>Exam Duty</b> — तुमची Invigilation Duty कधी आहे</li>
</ul>',
'teaching, शिक्षक, lecture, timetable', 1),

(9, 'Backup कसा घ्यायचा (Super Admin साठी)',
'<ol>
<li>Sidebar → "System Update" → "Backup"</li>
<li>"Data Download करा" — साधा Backup (जलद)</li>
<li>"Full Backup (Files सकट)" — सगळे Photo/Document सुद्धा हवे असतील तर</li>
</ol>
<p>ही File सुरक्षित ठिकाणी (Google Drive, Pendrive) ठेवा — गरज पडल्यास "Restore" Page वरून परत आणता येईल.</p>',
'backup, बॅकअप, restore', 1);
