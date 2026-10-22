-- ============================================================
-- Migration: विभाग-निहाय जावक (Outward) / आवक (Inward) क्रमांक + संदर्भ क्रमांक
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

-- फाईल तयार करणाऱ्या विभागाला मिळणारा जावक क्रमांक (उदा. MTN/2026/0001)
ALTER TABLE letters ADD COLUMN outward_no VARCHAR(50) NULL AFTER file_number;

-- संदर्भ क्रमांक — दुसऱ्या जुन्या पत्राचा हवाला देण्यासाठी (विषयाच्या खाली दिसतो)
ALTER TABLE letters ADD COLUMN reference_no VARCHAR(100) NULL AFTER outward_no;

-- प्रत्येक विभागाला, जेव्हा फाईल पहिल्यांदा त्यांच्याकडे येते, तेव्हा त्यांचा
-- स्वतःचा आवक क्रमांक मिळतो — एका फाईलला अनेक विभागांचे अनेक आवक क्रमांक असू शकतात
CREATE TABLE letter_inward_numbers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  letter_id INT NOT NULL,
  department_id INT NOT NULL,
  inward_no VARCHAR(50) NOT NULL,
  year INT NOT NULL,
  sequence_no INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (letter_id) REFERENCES letters(id) ON DELETE CASCADE,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_letter_dept (letter_id, department_id)
) ENGINE=InnoDB;
