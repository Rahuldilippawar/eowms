# E-Admin — Smart Administration (EOWMS) — Plain PHP + XAMPP Version (no Composer/Laravel needed)

This is a **Composer-free** version of the project — plain PHP + PDO + MySQL.
Drop it into `htdocs` and it runs directly on XAMPP.

## Setup — step by step

### 1. XAMPP suru kara
XAMPP Control Panel open kara → **Apache** आणि **MySQL** दोन्ही Start kara.

### 2. Files copy kara
This whole `eowms-php` folder copy karून `C:\xampp\htdocs\` madhe paste kara.
(So the path becomes `C:\xampp\htdocs\eowms-php\`)

### 3. Database banवा
Browser madhe `http://localhost/phpmyadmin` uघडा:
1. **New** click kara → database name `eowms` type kara → **Create**
2. `eowms` database select kara → **Import** tab
3. **Choose File** → `database/schema.sql` select kara (project folder madhun)
4. Khali **Go** button click kara

Ha 13 departments, demo users, ani sample renewal data automatically load karel.

### 4. Browser madhe uघडा
```
http://localhost/eowms-php/login.php
```

### 5. Login kara
- Email: `mtn.staff1@shivajitrust.edu.in`
- Password: `password`

(इतर demo accounts: `trp.staff1@...`, `mtn.hod@...`, `trp.hod@...`, `admin@...`, `gm@...`, `president@...` — sagळ्यांचा password `password`)

## Konातahi error aala tar
- **"Database connection failed"** → `config/db.php` madhe `$DB_NAME`, `$DB_USER`, `$DB_PASS` check kara. XAMPP cha default: user `root`, password rikama (`''`).
- **Blank/white page** → XAMPP cha `php_error_log` check kara, kiंवा `config/db.php` madhe top var he add kara temporarily:
  ```php
  ini_set('display_errors', 1);
  error_reporting(E_ALL);
  ```
- **"Table doesn't exist"** → Step 3 (Import) parत kara, `database/schema.sql` nit import zाला ka check kara.
- **Login "चुकीचा ईमेल किंवा पासवर्ड" दाखवत असेल, तरी email/password बरोबर वाटत असेल तर** →
  1. आधी `diagnose.php` (browser मध्ये `http://localhost/eowms-php/diagnose.php`) उघडा — तो users table मध्ये खरंच काय आहे, आणि password hash valid आहे का, हे लगेच दाखवतो, guessing न करता.
  2. Password hash invalid असेल / users table रिकामी असेल → `reset-password-tool.php` किंवा `add-user-tool.php` वापरा (दोन्ही login शिवाय चालतात, खऱ्या PHP `password_hash()` ने काम करतात — 100% reliable).
  3. **हे सगळे tools वापर झाल्यावर सुरक्षिततेसाठी `htdocs` मधून DELETE करा**: `diagnose.php`, `reset-password-tool.php`, `add-user-tool.php` — हे तिन्ही login शिवाय काम करतात, म्हणजे कायमचे ठेवणे सुरक्षित नाही.
- **`require_once(...header.php): Failed to open stream` सारखा error** → तुमच्या `htdocs` मध्ये जुन्या zip चे files मिसळले असण्याची शक्यता आहे. सगळ्यात सुरक्षित उपाय: `htdocs\eowms-php` पूर्ण डिलीट करून, नवीन zip पूर्णपणे नव्याने extract करा (jुन्या-नव्याची सरमिसळ टाळण्यासाठी).
- **Database मधले users/organizations चुकून delete झाले** → `database/add-super-admin.sql` (self-healing — org/campus नसतील तर तेही बनवतो) किंवा `add-user-tool.php` वापरून परत बनवा.

## Kay implemented aahe
- Login/logout (session-based, plain PHP — no framework)
- **Multi-Organization support** — Super Admin एकाच वेळी अनेक संस्था (Organizations) व्यवस्थापित करू शकतो. Sidebar madhe संस्था switcher (dropdown) आहे — संस्था बदलल्यावर संपूर्ण Dashboard, कर्मचारी, विभाग, नूतनीकरण, टास्क — सगळं आपोआप त्या संस्थेपुरतं मर्यादित होतं. प्रत्येक संस्था स्वतःचे विभाग आणि कर्मचारी स्वतंत्रपणे जोडू शकते.
- **Self-service password change** (`account/change-password.php`) — कोणताही logged-in user आपला स्वतःचा पासवर्ड बदलू शकतो (सध्याचा पासवर्ड पडताळून). इतर कोणाचाही पासवर्ड बदलण्याची परवानगी फक्त Super Admin ला (Staff Directory मधून).
- **Role-based access + Department scoping** — staff/hod फक्त स्वतःच्या विभागाचा data पाहू/नोंदू शकतात (entry forms, reports, renewals, staff directory — सगळीकडे server-side enforced)
- **Centralized visibility** — President/Executive President/General Manager यांना staff directory आणि daily-work reports संपूर्ण संस्थेभर (सर्व विभाग) दिसतात; HOD/Staff ला फक्त त्यांच्या स्वतःच्या विभागाचे
- Command Center dashboard — live KPIs, departments list, upcoming renewals
- दैनंदिन कामाचा अहवाल — entry form (before/after फोटो upload सहित) + साप्ताहिक/मासिक/वार्षिक report (Marathi)
- नूतनीकरण व अनुपालन — list with filters, add new, renew action (generic — कोणत्याही विभागासाठी वापरता येतो, आणि तो ज्या विभागाचा आहे त्यालाच दिसतो)
- टास्क असाइनमेंट — GM/Exec President/President कोणत्याही विभागाच्या कोणत्याही कर्मचाऱ्याला target date सह टास्क देऊ शकतात (tasks/assign.php), management tracker (tasks/index.php), आणि कर्मचाऱ्याचे स्वतःचे "माझे टास्क" (tasks/my-tasks.php) जिथे तो status अपडेट करतो — लगेच tracker वर दिसते
- कर्मचारी सूची (Staff Directory) — केंद्रीकृत यादी (staff/index.php), Super Admin द्वारे edit (staff/edit.php) — designation, विभाग, role, active/inactive, password reset
- CSV Bulk Staff Import (staff/import.php) — Super Admin only. Password column ऐच्छिक — रिकामे ठेवल्यास सुरक्षित random password auto-generate होतो आणि import नंतर एकदाच स्क्रीनवर दाखवला जातो (plaintext कधीही database मध्ये साठवला जात नाही)
- Organization / Campus / Department CRUD — Super Admin (संस्था — create/edit/**delete**), Super Admin+President+Exec President+GM (कॅम्पस/विभाग create/edit; **delete फक्त Super Admin**) — add/edit forms, HOD assignment

## Folder structure
```
eowms-php/
├── config/db.php              — DB connection
├── includes/                   — auth.php (role helpers + scoping), header.php, sidebar.php, footer.php
├── daily-work/                 — entry.php, save.php, report.php
├── renewals/                   — index.php, create.php, save.php, renew.php
├── tasks/                       — assign.php, save.php, index.php, my-tasks.php, update-status.php
├── staff/                       — index.php, edit.php, update.php, import.php, import-save.php, template.php
├── organizations/               — index.php, create.php, edit.php
├── campuses/                    — index.php, create.php, edit.php
├── departments/                 — index.php, create.php, edit.php
├── assets/css/eowms.css        — shared design system
├── assets/uploads/work-photos/  — uploaded before/after photos land here
├── database/schema.sql          — full schema + demo data
├── index.php                    — dashboard
├── login.php / logout.php
```

Pudhle modules (Store, Purchase, Asset, Vehicle, इ.) याच pattern ने add karता yetील —
konताही next module lagla tar sanga, mi याच style madhे banवून deto.
