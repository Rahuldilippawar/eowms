<?php
require_once __DIR__ . '/includes/auth.php';

// कोणती संस्था? — Login असल्यास सध्याची संस्था (about-manage.php इथेच जतन करतं);
// नाहीतर About माहिती असलेली पहिली सक्रिय संस्था.
$loggedIn = (bool)currentUser();
$orgId = $loggedIn ? (int)activeOrgId() : 0;
if (!$orgId) {
    $orgId = (int)$pdo->query("SELECT o.id FROM organizations o
        WHERE o.is_active = 1
        ORDER BY (SELECT COUNT(*) FROM about_sections s WHERE s.organization_id = o.id AND s.is_active = 1) DESC, o.id
        LIMIT 1")->fetchColumn();
}
$st = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
$st->execute([$orgId]);
$org = $st->fetch() ?: [];
$orgName = $org['name'] ?? 'E-Admin';
$isSuper = $loggedIn && (currentUser()['role'] ?? '') === 'super_admin';

$sectionsStmt = $pdo->prepare("SELECT * FROM about_sections WHERE organization_id = ? AND is_active = 1 ORDER BY FIELD(section_type,'god','sadguru','guide'), display_order, id");
$sectionsStmt->execute([$orgId]);
$allSections = $sectionsStmt->fetchAll();
$byType = ['god' => [], 'sadguru' => [], 'guide' => []];
foreach ($allSections as $s) $byType[$s['section_type']][] = $s;

$contribStmt = $pdo->prepare("SELECT * FROM about_contributors WHERE organization_id = ? AND is_active = 1 ORDER BY display_order, name");
$contribStmt->execute([$orgId]);
$contributors = $contribStmt->fetchAll();

/** सुरक्षित मोजणी — Table/Column नसेल तर null */
function aboutScalar($sql, $params) {
    global $pdo;
    try { $q = $pdo->prepare($sql); $q->execute($params); return $q->fetchColumn(); }
    catch (PDOException $e) { return null; }
}

// ── एका नजरेत (फक्त एकूण आकडे — वैयक्तिक माहिती नाही) ──
$stats = [];
$push = function ($icon, $label, $n) use (&$stats) { if ($n !== null && $n !== false && (int)$n > 0) $stats[] = [$icon, $label, (int)$n]; };
$push('fa-building-columns', 'कॅम्पस', aboutScalar("SELECT COUNT(*) FROM campuses WHERE organization_id = ? AND is_active = 1", [$orgId]));
$push('fa-sitemap', 'विभाग', aboutScalar("SELECT COUNT(*) FROM departments WHERE organization_id = ? AND is_active = 1", [$orgId]));
$push('fa-users', 'कर्मचारी', aboutScalar("SELECT COUNT(*) FROM users WHERE organization_id = ? AND is_active = 1 AND role <> 'super_admin'", [$orgId]));
$push('fa-clipboard-check', 'नोंदवलेली कामं', aboutScalar("SELECT COUNT(*) FROM work_records WHERE organization_id = ?", [$orgId]));

// ── प्रणालीत काय-काय आहे — वापरात असलेली Modules आपोआप ओळखली जातात ──
$moduleDefs = [
    ['fa-clipboard-list', 'दैनंदिन कामाचा अहवाल', 'प्रत्येक कामाची नोंद, फोटो, साहित्य व टीम', 'work_records'],
    ['fa-list-check', 'टास्क असाइनमेंट', 'काम सोपवा, प्रगती व मुदत पाहा', 'tasks'],
    ['fa-rotate', 'नूतनीकरण व अनुपालन', 'परवाने, विमा, AMC — मुदतीपूर्वी आठवण', 'renewal_items'],
    ['fa-envelope-open-text', 'पत्रव्यवहार व मंजुरी', 'फाईल पुढे पाठवणं, Digital Signature', 'letters'],
    ['fa-screwdriver-wrench', 'कामाच्या विनंत्या', 'तक्रार नोंदवा, Track करा', 'work_requests'],
    ['fa-fingerprint', 'हजेरी', 'Biometric Machine सह हजेरी व Shift', 'staff_attendance'],
    ['fa-plane-departure', 'रजा व्यवस्थापन', 'अर्ज, मंजुरी व रजा-शिल्लक', 'leave_applications'],
    ['fa-bed', 'वसतिगृह', 'विद्यार्थी, खोल्या, हजेरी, शुल्क', 'hostels'],
    ['fa-house-chimney-user', 'निवासस्थान', 'Staff निवास, बिलं व तपासणी', 'residency_occupants'],
    ['fa-van-shuttle', 'वाहन व्यवस्थापन', 'फेऱ्या, इंधन, देखभाल', 'vehicles'],
    ['fa-boxes-stacked', 'भंडार व खरेदी', 'वस्तू, Vendor, PO ते Invoice', 'store_items'],
    ['fa-box-archive', 'Dead Stock', 'मालमत्ता नोंदवही', 'dead_stock_items'],
    ['fa-computer', 'IT विभाग', 'Labs, Assets, दुरुस्ती Tickets', 'it_assets'],
    ['fa-video', 'CCTV', 'DVR, Cameras व रोजची तपासणी', 'cctv_cameras'],
    ['fa-calendar-days', 'कार्यक्रम', 'नियोजन, Budget, फोटो', 'events'],
    ['fa-id-badge', 'अतिथी नोंद', 'आगमन-निर्गमन व पूर्व-नोंदणी', 'guests'],
    ['fa-door-open', 'खोली आरक्षण', 'सभागृह व खोल्यांचं Booking', 'bookable_rooms'],
    ['fa-cow', 'गोशाळा', 'जनावरं, दूध, चारा, आरोग्य', 'gaushala_cattle'],
    ['fa-chalkboard-user', 'Virtual Classroom', 'Material, Assignment, परीक्षा', 'virtual_classrooms'],
    ['fa-music', 'संगीत विभाग', 'विद्यार्थी, वाद्यं व कार्यक्रम', 'music_students'],
    ['fa-hands-praying', 'सत्संग', 'कार्यक्रम, सेवा व उपस्थिती', 'satsang_events'],
    ['fa-book-open', 'SOP', 'कार्यपद्धती व पालन', 'sops'],
    ['fa-comment-dots', 'तक्रार निवारण', 'गोपनीय तक्रारी व पाठपुरावा', 'grievances'],
    ['fa-indian-rupee-sign', 'विभाग Finance', 'Budget, खर्च व Ledger', 'department_budgets'],
];
$modules = [];
foreach ($moduleDefs as [$icon, $name, $desc, $table]) {
    $n = aboutScalar("SELECT COUNT(*) FROM `$table` WHERE organization_id = ?", [$orgId]);
    if ($n === null) continue;               // Table नाही — Module बसवलेलं नाही
    $modules[] = ['icon' => $icon, 'name' => $name, 'desc' => $desc, 'n' => (int)$n];
}
usort($modules, fn($a, $b) => ($b['n'] > 0) <=> ($a['n'] > 0));
$modulesInUse = count(array_filter($modules, fn($m) => $m['n'] > 0));
if ($modulesInUse) $stats[] = ['fa-cubes', 'वापरातील Modules', $modulesInUse];

// ── प्रवास — डेटावरून आपोआप टप्पे ──
$milestones = [];
$mile = function ($date, $icon, $text) use (&$milestones) { if ($date) $milestones[] = ['d' => substr($date, 0, 10), 'icon' => $icon, 'text' => $text]; };
$mile($org['created_at'] ?? null, 'fa-seedling', 'संस्था प्रणालीत नोंदवली');
$mile(aboutScalar("SELECT MIN(created_at) FROM campuses WHERE organization_id = ?", [$orgId]), 'fa-building-columns', 'पहिला कॅम्पस जोडला');
$mile(aboutScalar("SELECT MIN(work_date) FROM work_records WHERE organization_id = ?", [$orgId]), 'fa-clipboard-check', 'पहिल्या कामाची नोंद');
foreach ([100, 500, 1000, 5000, 10000] as $nth) {
    $mile(aboutScalar("SELECT work_date FROM work_records WHERE organization_id = ? ORDER BY id LIMIT 1 OFFSET " . ($nth - 1), [$orgId]), 'fa-trophy', $nth . ' कामांची नोंद पूर्ण');
}
$mile(aboutScalar("SELECT MIN(created_at) FROM letters WHERE organization_id = ?", [$orgId]), 'fa-envelope', 'पहिलं पत्र प्रणालीतून');
$mile(aboutScalar("SELECT MIN(created_at) FROM work_requests WHERE organization_id = ?", [$orgId]), 'fa-screwdriver-wrench', 'पहिली कामाची विनंती');
$mile(aboutScalar("SELECT MIN(created_at) FROM events WHERE organization_id = ?", [$orgId]), 'fa-calendar-check', 'पहिला कार्यक्रम नोंदवला');
usort($milestones, fn($a, $b) => strcmp($a['d'], $b['d']));
// जुना Data Import केला असेल तर 'संस्था नोंदवली' नंतर येतं — तेव्हा तो टप्पा गाळा
if ($milestones && $milestones[0]['icon'] !== 'fa-seedling') $milestones = array_values(array_filter($milestones, fn($m) => $m['icon'] !== 'fa-seedling'));
$since = $milestones ? (int)substr($milestones[0]['d'], 0, 4) : null;

// ── आजचा विचार — ध्यान-सुविचार, नाहीतर सद्गुरू/मार्गदर्शकांच्या शब्दांतून; रोज बदलतो ──
$thoughts = [];
try {
    $q = $pdo->prepare("SELECT quote_text, author FROM meditation_quotes WHERE organization_id = ? AND is_active = 1");
    $q->execute([$orgId]);
    foreach ($q->fetchAll() as $r) $thoughts[] = [$r['quote_text'], $r['author']];
} catch (PDOException $e) {}
if (!$thoughts) foreach (array_merge($byType['sadguru'], $byType['guide']) as $s) if (trim((string)$s['description']) !== '') $thoughts[] = [$s['description'], $s['title']];
$thought = $thoughts ? $thoughts[(int)date('z') % count($thoughts)] : null;

// Section Nav — फक्त जे आहे तेच
$nav = [];
if ($byType['god']) $nav['god'] = '🕉️ ईश्वर';
if ($byType['sadguru']) $nav['sadguru'] = '🙏 सद्गुरू';
if ($byType['guide']) $nav['guide'] = '🧭 मार्गदर्शक';
if ($milestones) $nav['journey'] = '🛤️ प्रवास';
if ($modules) $nav['modules'] = '🧩 प्रणाली';
$nav['team'] = '👥 योगदान';

$fmtDate = function ($d) {
    static $m = ['', 'जानेवारी', 'फेब्रुवारी', 'मार्च', 'एप्रिल', 'मे', 'जून', 'जुलै', 'ऑगस्ट', 'सप्टेंबर', 'ऑक्टोबर', 'नोव्हेंबर', 'डिसेंबर'];
    $t = strtotime($d);
    return $t ? (int)date('j', $t) . ' ' . $m[(int)date('n', $t)] . ' ' . date('Y', $t) : $d;
};
$initial = fn($s) => mb_strtoupper(mb_substr(trim((string)$s), 0, 1));
?>
<!DOCTYPE html>
<html lang="<?= h(i18nLang()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>आमच्याबद्दल — <?= h($orgName) ?></title>
<meta name="description" content="<?= h($orgName) ?> — आमची श्रद्धा, मार्गदर्शक आणि E-Admin प्रणालीचा प्रवास">
<meta property="og:title" content="आमच्याबद्दल — <?= h($orgName) ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --bg:#0A0E1A;--bg2:#10162B;--card:rgba(255,255,255,.04);--line:rgba(220,239,236,.13);
    --text:#EAF3F1;--dim:#9FA6C0;--mute:#666B7D;--teal:#1B8A83;--teal2:#2BB3A9;--gold:#C97F1D;--gold2:#F0B35A;
  }
  *{box-sizing:border-box;}
  html{scroll-behavior:smooth;scroll-padding-top:70px;}
  body{font-family:'Noto Sans Devanagari','Inter',sans-serif;background:var(--bg);color:var(--text);margin:0;overflow-x:hidden;}
  a{color:inherit;}
  .wrap{max-width:980px;margin:0 auto;padding:0 20px;}

  /* ── Hero ── */
  .top{position:relative;padding:78px 20px 56px;text-align:center;overflow:hidden;
    background:radial-gradient(ellipse 120% 80% at 50% -10%,#1B2340 0%,#0F1424 55%,var(--bg) 100%);}
  .orb{position:absolute;border-radius:50%;filter:blur(70px);opacity:.2;pointer-events:none;animation:drift 16s ease-in-out infinite alternate;}
  .orb.a{width:420px;height:420px;background:var(--teal);top:-160px;left:-100px;}
  .orb.b{width:380px;height:380px;background:var(--gold);bottom:-180px;right:-100px;animation-delay:-8s;}
  @keyframes drift{to{transform:translate(40px,30px) scale(1.08);}}
  #stars{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;}
  .top-inner{position:relative;z-index:2;}
  .toolbar{position:absolute;top:16px;left:16px;right:16px;display:flex;justify-content:space-between;align-items:center;gap:8px;z-index:5;}
  .tb-left,.tb-right{display:flex;gap:8px;align-items:center;}
  .tb-btn{display:inline-flex;align-items:center;gap:6px;color:#DCEFEC;text-decoration:none;font-size:12.5px;padding:7px 12px;border-radius:10px;
    background:rgba(255,255,255,.06);border:1px solid var(--line);cursor:pointer;font-family:inherit;backdrop-filter:blur(6px);}
  .tb-btn:hover{background:rgba(255,255,255,.12);}
  .lang-sel{border:1px solid var(--line);border-radius:10px;padding:7px 10px;font-size:12.5px;color:#DCEFEC;background:rgba(255,255,255,.06);cursor:pointer;font-family:inherit;}
  .lang-sel option{color:#111;}
  .logo{width:84px;height:84px;border-radius:22px;object-fit:contain;background:#fff;padding:8px;margin:0 auto 14px;display:block;box-shadow:0 10px 30px rgba(0,0,0,.35);}
  .om-big{font-family:'Space Grotesk',serif;font-size:48px;color:#DCEFEC;margin-bottom:4px;text-shadow:0 0 30px rgba(220,239,236,.55);animation:glow 3.2s ease-in-out infinite;}
  @keyframes glow{0%,100%{opacity:.7;}50%{opacity:1;}}
  .top h1{font-family:'Space Grotesk';font-weight:700;font-size:clamp(24px,4.5vw,34px);margin:8px 0 8px;letter-spacing:-.3px;}
  .top p.lead{color:var(--dim);font-size:14px;max-width:520px;margin:0 auto;line-height:1.7;}
  .since{display:inline-block;margin-top:14px;font-size:12px;color:var(--gold2);border:1px solid rgba(240,179,90,.35);border-radius:99px;padding:4px 12px;}

  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;max-width:820px;margin:34px auto 0;}
  .stat{background:rgba(255,255,255,.045);border:1px solid var(--line);border-radius:16px;padding:16px 12px;backdrop-filter:blur(8px);}
  .stat i{color:var(--teal2);font-size:15px;}
  .stat .n{font-family:'Space Grotesk';font-size:28px;font-weight:700;margin:6px 0 2px;font-variant-numeric:tabular-nums;}
  .stat .l{font-size:12px;color:var(--dim);}

  /* ── Sticky Nav ── */
  .snav{position:sticky;top:0;z-index:40;background:rgba(10,14,26,.82);backdrop-filter:blur(12px);border-bottom:1px solid var(--line);}
  .snav .wrap{display:flex;gap:6px;overflow-x:auto;padding:10px 20px;scrollbar-width:none;}
  .snav .wrap::-webkit-scrollbar{display:none;}
  .snav a{flex-shrink:0;text-decoration:none;font-size:12.5px;color:var(--dim);padding:6px 13px;border-radius:99px;border:1px solid transparent;transition:.2s;}
  .snav a:hover{color:#fff;}
  .snav a.on{color:#fff;background:rgba(27,138,131,.25);border-color:rgba(43,179,169,.45);}

  /* ── Sections ── */
  .section{padding:56px 0 20px;}
  .section-heading{text-align:center;font-family:'Space Grotesk';font-size:15px;color:var(--gold);font-weight:600;margin-bottom:10px;}
  .section-sub{text-align:center;color:var(--mute);font-size:12.5px;margin:-2px 0 26px;}
  .divider{display:flex;align-items:center;justify-content:center;gap:12px;margin:0 auto 28px;max-width:200px;}
  .divider span{height:1px;flex:1;background:linear-gradient(90deg,transparent,rgba(220,239,236,.4),transparent);}
  .divider i{color:var(--teal);font-size:10px;}

  .thought{max-width:720px;margin:44px auto 0;text-align:center;padding:28px 26px;border-radius:20px;position:relative;
    background:linear-gradient(135deg,rgba(201,127,29,.13),rgba(27,138,131,.10));border:1px solid rgba(240,179,90,.25);}
  .thought .tag{font-size:12px;color:var(--gold2);margin-bottom:10px;}
  .thought blockquote{margin:0;font-size:16px;line-height:1.9;font-style:italic;color:#F5EBDD;}
  .thought cite{display:block;margin-top:10px;font-size:12.5px;color:var(--dim);font-style:normal;}

  .rev-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:24px;justify-items:center;}
  .rev-card{background:linear-gradient(160deg,rgba(27,138,131,.14),rgba(255,255,255,.03));border:1px solid rgba(220,239,236,.2);border-radius:22px;
    padding:34px 30px;text-align:center;width:100%;max-width:460px;box-shadow:0 12px 40px rgba(0,0,0,.25);position:relative;}
  .rev-card::before,.rev-card::after{content:'';position:absolute;width:24px;height:24px;border-color:rgba(220,239,236,.3);}
  .rev-card::before{top:10px;left:10px;border-top:1.5px solid;border-left:1.5px solid;border-radius:6px 0 0 0;}
  .rev-card::after{bottom:10px;right:10px;border-bottom:1.5px solid;border-right:1.5px solid;border-radius:0 0 6px 0;}
  .rev-photo{width:156px;height:156px;border-radius:50%;object-fit:cover;margin:0 auto 20px;display:block;cursor:zoom-in;
    border:3px solid var(--gold);box-shadow:0 0 0 6px rgba(201,127,29,.15),0 0 40px rgba(240,179,90,.25),0 10px 30px rgba(0,0,0,.3);animation:halo 4s ease-in-out infinite;}
  @keyframes halo{50%{box-shadow:0 0 0 8px rgba(201,127,29,.22),0 0 60px rgba(240,179,90,.4),0 10px 30px rgba(0,0,0,.3);}}
  .rev-photo-fallback{width:156px;height:156px;border-radius:50%;margin:0 auto 20px;display:flex;align-items:center;justify-content:center;
    background:linear-gradient(160deg,rgba(201,127,29,.2),rgba(255,255,255,.04));border:2px dashed rgba(251,235,214,.35);color:#FBEBD6;font-size:42px;}
  .rev-title{font-family:'Space Grotesk';font-size:19px;font-weight:700;color:#fff;margin-bottom:10px;}
  .rev-desc{font-size:14px;color:#DCEFEC;font-style:italic;line-height:1.85;}
  .clamp{display:-webkit-box;-webkit-line-clamp:5;-webkit-box-orient:vertical;overflow:hidden;}
  .more{background:none;border:0;color:var(--teal2);font-size:12px;cursor:pointer;margin-top:8px;font-family:inherit;}

  .guide-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;}
  .guide-card{background:var(--bg2);border:1px solid var(--line);border-radius:18px;padding:26px 22px;text-align:center;transition:transform .25s,border-color .25s;}
  .guide-card:hover{transform:translateY(-5px);border-color:rgba(43,179,169,.45);}
  .guide-photo{width:96px;height:96px;border-radius:50%;object-fit:cover;margin:0 auto 14px;border:2.5px solid var(--teal);cursor:zoom-in;display:block;}
  .ph-fallback{border-radius:50%;display:flex;align-items:center;justify-content:center;background:rgba(27,138,131,.16);color:var(--teal2);font-family:'Space Grotesk';font-weight:700;}
  .guide-card .ph-fallback{width:96px;height:96px;margin:0 auto 14px;font-size:30px;}
  .guide-name{font-family:'Space Grotesk';font-size:15px;font-weight:600;color:#fff;margin-bottom:6px;}
  .guide-desc{font-size:12.5px;color:var(--dim);line-height:1.7;}

  /* ── Timeline ── */
  .tl{position:relative;max-width:640px;margin:0 auto;padding-left:34px;}
  .tl::before{content:'';position:absolute;left:13px;top:6px;bottom:6px;width:2px;background:linear-gradient(var(--teal),var(--gold));opacity:.5;border-radius:2px;}
  .tl-item{position:relative;padding:0 0 22px;}
  .tl-dot{position:absolute;left:-34px;top:0;width:28px;height:28px;border-radius:50%;background:var(--bg2);border:2px solid var(--teal);display:flex;align-items:center;justify-content:center;font-size:11px;color:var(--teal2);}
  .tl-item.gold .tl-dot{border-color:var(--gold);color:var(--gold2);}
  .tl-date{font-size:11.5px;color:var(--mute);}
  .tl-text{font-size:14px;color:#fff;margin-top:2px;}

  /* ── Modules ── */
  .mod-tools{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;margin-bottom:18px;}
  .chip{font-size:12px;padding:6px 13px;border-radius:99px;border:1px solid var(--line);background:none;color:var(--dim);cursor:pointer;font-family:inherit;}
  .chip.on{background:rgba(27,138,131,.25);border-color:rgba(43,179,169,.45);color:#fff;}
  .mod-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px;}
  .mod{display:flex;gap:12px;align-items:flex-start;background:var(--bg2);border:1px solid var(--line);border-radius:14px;padding:14px;transition:border-color .2s,transform .2s;}
  .mod:hover{border-color:rgba(43,179,169,.4);transform:translateY(-2px);}
  .mod .ic{width:38px;height:38px;flex-shrink:0;border-radius:11px;display:flex;align-items:center;justify-content:center;background:rgba(27,138,131,.16);color:var(--teal2);}
  .mod.idle .ic{background:rgba(255,255,255,.05);color:var(--mute);}
  .mod .nm{font-size:13.5px;font-weight:600;color:#fff;}
  .mod .ds{font-size:11.5px;color:var(--dim);line-height:1.5;margin-top:2px;}
  .mod .bd{display:inline-block;margin-top:6px;font-size:10.5px;padding:2px 8px;border-radius:99px;background:rgba(43,179,169,.15);color:var(--teal2);}
  .mod.idle .bd{background:rgba(255,255,255,.05);color:var(--mute);}

  /* ── Contributors ── */
  .search{display:block;width:100%;max-width:320px;margin:0 auto 18px;padding:9px 14px;border-radius:12px;border:1px solid var(--line);background:var(--bg2);color:var(--text);font-family:inherit;font-size:13px;}
  .contrib-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:16px;}
  .contrib-card{background:var(--bg2);border:1px solid var(--line);border-radius:16px;padding:20px;display:flex;gap:16px;align-items:flex-start;transition:border-color .2s;}
  .contrib-card:hover{border-color:rgba(43,179,169,.4);}
  .contrib-photo{width:60px;height:60px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid var(--teal);cursor:zoom-in;}
  .contrib-card .ph-fallback{width:60px;height:60px;flex-shrink:0;font-size:20px;}
  .contrib-name{font-family:'Space Grotesk';font-size:14.5px;font-weight:600;color:#fff;}
  .contrib-role{font-size:11.5px;color:var(--gold2);margin:3px 0 6px;}
  .contrib-bio{font-size:12px;color:var(--dim);line-height:1.6;}
  .empty-note{text-align:center;color:var(--mute);font-size:13px;padding:22px;border:1px dashed var(--line);border-radius:14px;}
  .empty-note a{color:var(--teal2);}

  .dev-footer{text-align:center;padding:56px 24px 44px;border-top:1px solid rgba(220,239,236,.08);margin-top:50px;}
  .dev-footer .thanks{font-size:13px;color:var(--dim);margin-bottom:14px;}
  .dev-footer .dev-name{font-family:'Space Grotesk';font-size:22px;font-weight:700;background:linear-gradient(90deg,var(--teal),#DCEFEC,var(--gold2));background-size:200%;
    -webkit-background-clip:text;background-clip:text;color:transparent;animation:shine 6s linear infinite;}
  @keyframes shine{to{background-position:200% 0;}}
  .dev-footer .dev-label{font-size:11px;color:var(--mute);margin-top:6px;letter-spacing:2px;}
  .dev-footer .om-small{font-size:20px;color:#DCEFEC;opacity:.7;margin-top:20px;}

  /* ── Lightbox, Reveal, Toast ── */
  .lb{position:fixed;inset:0;background:rgba(5,7,14,.92);display:none;align-items:center;justify-content:center;flex-direction:column;z-index:100;padding:20px;cursor:zoom-out;}
  .lb.open{display:flex;}
  .lb img{max-width:min(92vw,720px);max-height:78vh;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.6);}
  .lb .cap{color:#fff;margin-top:14px;font-size:14px;}
  .reveal{opacity:0;transform:translateY(22px);transition:opacity .7s ease,transform .7s ease;}
  .reveal.in{opacity:1;transform:none;}
  .toast{position:fixed;bottom:22px;left:50%;transform:translateX(-50%) translateY(80px);background:#fff;color:#111;padding:10px 16px;border-radius:12px;font-size:13px;z-index:120;transition:transform .3s;}
  .toast.show{transform:translateX(-50%) translateY(0);}
  .to-top{position:fixed;right:18px;bottom:18px;width:42px;height:42px;border-radius:50%;border:1px solid var(--line);background:rgba(16,22,43,.9);color:#fff;cursor:pointer;opacity:0;pointer-events:none;transition:.3s;z-index:30;}
  .to-top.show{opacity:1;pointer-events:auto;}

  @media (max-width:560px){
    .top{padding-top:96px;}
    .tb-btn span{display:none;}
    .stat .n{font-size:24px;}
    .rev-grid{grid-template-columns:1fr;}
  }
  @media (prefers-reduced-motion:reduce){
    *,*::before,*::after{animation:none !important;transition:none !important;scroll-behavior:auto !important;}
    .reveal{opacity:1;transform:none;}
  }
  @media print{
    body{background:#fff;color:#111;}
    .toolbar,.snav,.to-top,.lb,#stars,.orb,.mod-tools,.search,.more{display:none !important;}
    .top{background:none;padding:20px;}
    .top h1,.rev-title,.guide-name,.contrib-name,.mod .nm,.tl-text{color:#111;}
    .rev-card,.guide-card,.contrib-card,.mod,.stat,.thought{background:#fff;border-color:#ccc;box-shadow:none;break-inside:avoid;}
    .rev-desc,.thought blockquote{color:#333;}
    .reveal{opacity:1;transform:none;}
    .clamp{-webkit-line-clamp:unset;display:block;}
    .dev-footer .dev-name{color:#111;-webkit-text-fill-color:#111;}
  }
</style>
</head>
<body>

<header class="top" id="top">
  <canvas id="stars" aria-hidden="true"></canvas>
  <div class="orb a"></div><div class="orb b"></div>
  <div class="toolbar">
    <div class="tb-left">
      <a href="<?= $loggedIn ? 'index.php' : 'welcome.php' ?>" class="tb-btn"><i class="fa-solid fa-arrow-left"></i> <span><?= $loggedIn ? 'Dashboard' : 'मुख्य पानावर' ?></span></a>
      <?php if ($isSuper): ?><a href="about-manage.php" class="tb-btn" title="माहिती व फोटो बदला"><i class="fa-solid fa-pen"></i> <span>बदला</span></a><?php endif; ?>
    </div>
    <div class="tb-right">
      <button type="button" class="tb-btn" id="shareBtn" title="Share"><i class="fa-solid fa-share-nodes"></i> <span>Share</span></button>
      <button type="button" class="tb-btn" onclick="window.print()" title="Print"><i class="fa-solid fa-print"></i></button>
      <?= i18nSwitcher('class="lang-sel" aria-label="भाषा"') ?>
    </div>
  </div>
  <div class="top-inner">
    <?php if (!empty($org['logo_path'])): ?>
      <img src="<?= h($org['logo_path']) ?>" class="logo" alt="">
    <?php else: ?>
      <div class="om-big">ॐ</div>
    <?php endif; ?>
    <h1><?= h($orgName) ?></h1>
    <p class="lead">आमच्या श्रद्धेचा, गुरुकृपेचा आणि या प्रकल्पामागील सर्वांच्या योगदानाचा हा एक छोटासा परिचय</p>
    <?php if ($since): ?><div class="since"><i class="fa-regular fa-calendar"></i> <?= $since ?> पासून सेवेत</div><?php endif; ?>
    <?php if ($stats): ?>
      <div class="stats">
        <?php foreach ($stats as [$icon, $label, $n]): ?>
          <div class="stat reveal"><i class="fa-solid <?= $icon ?>"></i><div class="n" data-count="<?= $n ?>"><?= number_format($n) ?></div><div class="l"><?= h($label) ?></div></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</header>

<nav class="snav" aria-label="विभाग">
  <div class="wrap">
    <?php foreach ($nav as $id => $label): ?><a href="#<?= $id ?>" data-spy="<?= $id ?>"><?= $label ?></a><?php endforeach; ?>
  </div>
</nav>

<main class="wrap">

<?php if ($thought): ?>
  <div class="thought reveal">
    <div class="tag">✨ आजचा विचार</div>
    <blockquote>“<?= nl2br(h(mb_strimwidth(trim($thought[0]), 0, 320, '…'))) ?>”</blockquote>
    <?php if (!empty($thought[1])): ?><cite>— <?= h($thought[1]) ?></cite><?php endif; ?>
  </div>
<?php endif; ?>

<?php foreach (['god' => ['🕉️ ईश्वर चरणी', '🕉️'], 'sadguru' => ['🙏 सद्गुरू आशीर्वाद', '🙏']] as $type => [$heading, $sym]): ?>
  <?php if ($byType[$type]): ?>
  <section class="section" id="<?= $type ?>">
    <div class="section-heading"><?= $heading ?></div>
    <div class="divider"><span></span><i class="fa-solid fa-diamond"></i><span></span></div>
    <div class="rev-grid">
      <?php foreach ($byType[$type] as $s): ?>
        <div class="rev-card reveal">
          <?php if ($s['photo_path']): ?>
            <img src="<?= h($s['photo_path']) ?>" class="rev-photo" alt="<?= h($s['title']) ?>" data-lb loading="lazy">
          <?php else: ?>
            <div class="rev-photo-fallback"><?= $sym ?></div>
          <?php endif; ?>
          <div class="rev-title"><?= h($s['title']) ?></div>
          <?php if ($s['description']): ?>
            <div class="rev-desc<?= mb_strlen($s['description']) > 280 ? ' clamp' : '' ?>">“<?= nl2br(h($s['description'])) ?>”</div>
            <?php if (mb_strlen($s['description']) > 280): ?><button type="button" class="more">पुढे वाचा ▾</button><?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
<?php endforeach; ?>

<?php if ($byType['guide']): ?>
  <section class="section" id="guide">
    <div class="section-heading">🧭 मार्गदर्शक</div>
    <div class="divider"><span></span><i class="fa-solid fa-diamond"></i><span></span></div>
    <div class="guide-grid">
      <?php foreach ($byType['guide'] as $s): ?>
        <div class="guide-card reveal">
          <?php if ($s['photo_path']): ?>
            <img src="<?= h($s['photo_path']) ?>" class="guide-photo" alt="<?= h($s['title']) ?>" data-lb loading="lazy">
          <?php else: ?>
            <div class="ph-fallback"><?= h($initial($s['title'])) ?></div>
          <?php endif; ?>
          <div class="guide-name"><?= h($s['title']) ?></div>
          <?php if ($s['description']): ?><div class="guide-desc"><?= nl2br(h($s['description'])) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php elseif (!$byType['god'] && !$byType['sadguru']): ?>
  <section class="section">
    <div class="empty-note">देव / सद्गुरू / मार्गदर्शक यांची माहिती व फोटो अजून जोडलेले नाहीत<?php if ($isSuper): ?> — <a href="about-manage.php">इथून जोडा</a><?php endif; ?>.</div>
  </section>
<?php endif; ?>

<?php if ($milestones): ?>
  <section class="section" id="journey">
    <div class="section-heading">🛤️ आमचा प्रवास</div>
    <div class="section-sub">प्रणालीतील नोंदींवरून आपोआप तयार झालेले टप्पे</div>
    <div class="tl">
      <?php foreach ($milestones as $m): ?>
        <div class="tl-item reveal<?= $m['icon'] === 'fa-trophy' ? ' gold' : '' ?>">
          <div class="tl-dot"><i class="fa-solid <?= $m['icon'] ?>"></i></div>
          <div class="tl-date"><?= h($fmtDate($m['d'])) ?></div>
          <div class="tl-text"><?= h($m['text']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<?php if ($modules): ?>
  <section class="section" id="modules">
    <div class="section-heading">🧩 प्रणालीत काय-काय आहे</div>
    <div class="section-sub"><?= count($modules) ?> Modules · <?= $modulesInUse ?> सध्या वापरात</div>
    <div class="mod-tools" role="group">
      <button type="button" class="chip on" data-f="all">सर्व</button>
      <button type="button" class="chip" data-f="used">वापरात</button>
      <button type="button" class="chip" data-f="idle">अजून सुरू नाही</button>
    </div>
    <div class="mod-grid">
      <?php foreach ($modules as $m): $used = $m['n'] > 0; ?>
        <div class="mod reveal<?= $used ? '' : ' idle' ?>" data-used="<?= $used ? 1 : 0 ?>">
          <div class="ic"><i class="fa-solid <?= $m['icon'] ?>"></i></div>
          <div>
            <div class="nm"><?= h($m['name']) ?></div>
            <div class="ds"><?= h($m['desc']) ?></div>
            <span class="bd"><?= $used ? ($loggedIn ? number_format($m['n']) . ' नोंदी' : '✓ वापरात') : 'उपलब्ध' ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<section class="section" id="team">
  <div class="section-heading">👥 या प्रकल्पासाठी योगदान</div>
  <div class="divider"><span></span><i class="fa-solid fa-diamond"></i><span></span></div>
  <?php if ($contributors): ?>
    <?php if (count($contributors) > 6): ?><input type="search" class="search" id="cSearch" placeholder="नाव / योगदान शोधा…"><?php endif; ?>
    <div class="contrib-grid" id="cGrid">
      <?php foreach ($contributors as $c): ?>
        <div class="contrib-card reveal" data-q="<?= h(mb_strtolower($c['name'] . ' ' . $c['contribution'])) ?>">
          <?php if ($c['photo_path']): ?>
            <img src="<?= h($c['photo_path']) ?>" class="contrib-photo" alt="<?= h($c['name']) ?>" data-lb loading="lazy">
          <?php else: ?>
            <div class="ph-fallback"><?= h($initial($c['name'])) ?></div>
          <?php endif; ?>
          <div>
            <div class="contrib-name"><?= h($c['name']) ?></div>
            <?php if ($c['contribution']): ?><div class="contrib-role"><?= h($c['contribution']) ?></div><?php endif; ?>
            <?php if ($c['bio']): ?><div class="contrib-bio"><?= nl2br(h($c['bio'])) ?></div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="empty-note" id="cNone" style="display:none;margin-top:12px;">जुळणारं नाव नाही</div>
  <?php else: ?>
    <div class="empty-note">या प्रकल्पासाठी योगदान देणाऱ्यांची यादी अजून जोडलेली नाही<?php if ($isSuper): ?> — <a href="about-manage.php">इथून जोडा</a><?php endif; ?>.</div>
  <?php endif; ?>
</section>

</main>

<footer class="dev-footer">
  <div class="thanks">या सर्वांच्या आशीर्वादाने व सहकार्याने साकार झालेली प्रणाली</div>
  <div class="dev-name">Rahul Pawar</div>
  <div class="dev-label">DEVELOPED BY</div>
  <div class="om-small">ॐ</div>
</footer>

<div class="lb" id="lb" role="dialog" aria-modal="true"><img alt=""><div class="cap"></div></div>
<div class="toast" id="toast"></div>
<button type="button" class="to-top" id="toTop" aria-label="वर जा"><i class="fa-solid fa-arrow-up"></i></button>

<?= i18nClientScript() ?>
<script>
(function () {
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Reveal + count-up
  var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) {
    es.forEach(function (e) { if (!e.isIntersecting) return; e.target.classList.add('in'); io.unobserve(e.target);
      var n = e.target.querySelector('[data-count]'); if (n && !reduce) countUp(n); });
  }, { threshold: 0.12 }) : null;
  document.querySelectorAll('.reveal').forEach(function (el, i) {
    el.style.transitionDelay = Math.min(i % 6, 5) * 60 + 'ms';
    if (io) io.observe(el); else el.classList.add('in');
  });
  function countUp(el) {
    var end = +el.dataset.count, t0 = null, fmt = new Intl.NumberFormat('en-IN');
    function step(t) { if (!t0) t0 = t; var p = Math.min((t - t0) / 1200, 1), v = Math.round(end * (1 - Math.pow(1 - p, 3)));
      el.textContent = fmt.format(v); if (p < 1) requestAnimationFrame(step); }
    requestAnimationFrame(step);
  }

  // Scroll-spy, back to top
  var links = document.querySelectorAll('.snav a'), bar = document.querySelector('.snav .wrap'), toTop = document.getElementById('toTop'), last = null;
  function spy() {
    var cur = null;
    links.forEach(function (a) { var s = document.getElementById(a.dataset.spy); if (s && s.getBoundingClientRect().top < 120) cur = a; });
    if (cur !== last) {
      links.forEach(function (a) { a.classList.toggle('on', a === cur); });
      // फक्त Nav आडवं सरकवा — पानाचा scroll थांबवू नका
      if (cur && bar.scrollWidth > bar.clientWidth) bar.scrollLeft = cur.offsetLeft - (bar.clientWidth - cur.offsetWidth) / 2;
      last = cur;
    }
    toTop.classList.toggle('show', scrollY > 600);
  }
  addEventListener('scroll', spy, { passive: true }); spy();
  toTop.onclick = function () { scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' }); };

  // Lightbox
  var lb = document.getElementById('lb');
  document.querySelectorAll('[data-lb]').forEach(function (img) {
    img.addEventListener('click', function () { lb.querySelector('img').src = img.src; lb.querySelector('.cap').textContent = img.alt; lb.classList.add('open'); });
  });
  lb.onclick = function () { lb.classList.remove('open'); };
  addEventListener('keydown', function (e) { if (e.key === 'Escape') lb.classList.remove('open'); });

  // "पुढे वाचा"
  document.querySelectorAll('.more').forEach(function (b) {
    var lbl = b.textContent;
    b.onclick = function () { var d = b.previousElementSibling, open = d.classList.toggle('clamp'); b.textContent = open ? lbl : '▴'; };
  });

  // Module filter
  document.querySelectorAll('.mod-tools .chip').forEach(function (c) {
    c.onclick = function () {
      document.querySelectorAll('.mod-tools .chip').forEach(function (x) { x.classList.toggle('on', x === c); });
      document.querySelectorAll('.mod').forEach(function (m) {
        var u = m.dataset.used === '1'; m.style.display = c.dataset.f === 'all' || (c.dataset.f === 'used') === u ? '' : 'none';
      });
    };
  });

  // Contributor search
  var cs = document.getElementById('cSearch');
  if (cs) cs.oninput = function () {
    var q = cs.value.trim().toLowerCase(), any = false;
    document.querySelectorAll('#cGrid .contrib-card').forEach(function (c) { var hit = !q || c.dataset.q.indexOf(q) > -1; c.style.display = hit ? '' : 'none'; any = any || hit; });
    document.getElementById('cNone').style.display = any ? 'none' : '';
  };

  // Share
  var toast = document.getElementById('toast');
  function say(t) { toast.textContent = t; toast.classList.add('show'); setTimeout(function () { toast.classList.remove('show'); }, 2200); }
  document.getElementById('shareBtn').onclick = function () {
    var url = location.href.split('#')[0];
    if (navigator.share) { navigator.share({ title: document.title, url: url }).catch(function () {}); return; }
    if (navigator.clipboard) navigator.clipboard.writeText(url).then(function () { say(window.eowmsT ? eowmsT('Link copy केली') : 'Link copy केली'); });
  };

  // ताऱ्यांचं हलकं आकाश
  var cv = document.getElementById('stars');
  if (cv && cv.getContext && !reduce) {
    var ctx = cv.getContext('2d'), stars = [], dpr = Math.min(devicePixelRatio || 1, 2);
    function size() { cv.width = cv.offsetWidth * dpr; cv.height = cv.offsetHeight * dpr;
      stars = []; for (var i = 0; i < Math.round(cv.offsetWidth / 9); i++) stars.push({ x: Math.random() * cv.width, y: Math.random() * cv.height, r: (Math.random() * 1.2 + .3) * dpr, p: Math.random() * 6.28 }); }
    function draw(t) { ctx.clearRect(0, 0, cv.width, cv.height);
      stars.forEach(function (s) { ctx.globalAlpha = .25 + .55 * (0.5 + 0.5 * Math.sin(t / 900 + s.p)); ctx.beginPath(); ctx.arc(s.x, s.y, s.r, 0, 6.28); ctx.fillStyle = '#DCEFEC'; ctx.fill(); });
      if (document.getElementById('top').getBoundingClientRect().bottom > 0) requestAnimationFrame(draw); else setTimeout(function () { requestAnimationFrame(draw); }, 400); }
    size(); addEventListener('resize', size); requestAnimationFrame(draw);
  }
})();
</script>
</body>
</html>
