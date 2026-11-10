<?php
// 🎓 E-Campus Dashboard — Excel Sheet सारखा तक्ता: Progress Notes आणि % Complete थेट भरता येतात (आपोआप जतन),
// Status आपोआप; वर एकूण सारांश, खाली Charts. Management/Super Admin ला सगळ्या ओळी, कर्मचाऱ्याला फक्त स्वतःच्या.
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'ecampus_progress', '../');
require_once __DIR__ . '/../includes/ecampus-helpers.php';
require_once __DIR__ . '/../includes/daily-report.php'; // drDonut / drBars
$me = currentUser();
$orgId = activeOrgId();
$viewAll = ecampusCanViewAll();
$isSA = ecampusCanManage();

$canRemind = $viewAll && ($isSA || isManagementOrAbove());

// 🔔 आठवण — नोंद जुनी / सुरू न केलेल्या कर्मचाऱ्यांना (सगळ्यांना किंवा एकाला)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remind' && $canRemind) {
    $one = (int)($_POST['user_id'] ?? 0);
    $n = ecampusSendReminders($pdo, $orgId, $one ? [$one] : null);
    logActivity('E-Campus आठवण पाठवली', 'ecampus', $one ?: null, $n . ' कर्मचारी');
    $_SESSION['flash'] = $n ? "🔔 $n कर्मचाऱ्यांना E-Campus प्रगती भरण्याची आठवण पाठवली." : 'आठवण पाठवण्यासारखं कोणी नाही — सगळ्यांच्या नोंदी अद्ययावत आहेत.';
    header('Location: index.php');
    exit;
}

$period = ecampusPeriod($_GET['month'] ?? null);
$isCurrent = $period === date('Y-m');
$rows = ecampusRows($pdo, $orgId, $period, $viewAll ? null : $me['id']);
if (!$viewAll && !$rows && !ecampusMyTaskCount($pdo, $me['id'])) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">E-Campus चं कोणतंही काम तुम्हाला नेमलेलं नाही. <a href="../index.php">' . h(t('s16')) . '</a></div>'); }
$S = ecampusSummary($rows, $period);
$trend = $viewAll ? ecampusDailyTrend($pdo, $orgId, $period, $rows) : [];
$prevPeriod = date('Y-m', strtotime($period . '-01 -1 month'));
$prevS = $viewAll ? ecampusSummary(ecampusRows($pdo, $orgId, $prevPeriod), $prevPeriod) : ['overall' => null];
// 🧠 स्मार्ट विश्लेषण — अंदाज, कर्मचारीनिहाय, आठवड्यातली प्रगती, मागचे 6 महिने
$forecast = $viewAll ? ecampusForecast($trend, $period, $S['overall']) : null;
$board = $viewAll ? ecampusStaffBoard($rows, $period) : [];
$movers = $viewAll ? ecampusMovers($pdo, $period, $rows) : [];
$history = $viewAll ? ecampusMonthlyHistory($pdo, $orgId, 6) : [];
$attention = array_values(array_filter($board, fn($b) => $b['stale'] || $b['pending']));
$months = [];
for ($i = 0; $i < 12; $i++) { $months[] = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); }
$wings = array_keys($S['wings']);
sort($wings);
$col = ['good' => '#16A34A', 'warn' => '#F59E0B', 'bad' => '#DC2626'];
$barCol = fn($p) => $p >= 100 ? '#16A34A' : ($p >= 75 ? '#65A30D' : ($p >= 50 ? '#F59E0B' : '#DC2626'));
$canEdit = fn($r) => ecampusEditablePeriod($period) && $r['access_type'] === 'work' && ((int)$r['user_id'] === (int)$me['id'] || $isSA);

$pageTitle = 'E-Campus Dashboard';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .es-ring{--p:0;width:86px;height:86px;border-radius:50%;display:grid;place-items:center;background:conic-gradient(var(--c) calc(var(--p)*1%), var(--canvas) 0);transition:background .4s;}
  .es-ring span{width:68px;height:68px;border-radius:50%;background:var(--surface,#fff);display:grid;place-items:center;font-weight:800;font-size:18px;line-height:1;text-align:center;}
  .es-ring small{display:block;font-size:9.5px;font-weight:600;color:var(--text-dim);}
  .es-tools{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px;}
  .es-tools .form-control-custom,.es-tools .form-select-custom{max-width:220px;}
  .es-tools .sbtn{border:1px solid var(--line);background:var(--surface,#fff);border-radius:99px;font-size:12.5px;font-weight:700;padding:6px 12px;cursor:pointer;}
  .es-tools .sbtn.on{background:var(--rail);border-color:var(--rail);color:#fff;}

  /* ---------- Sheet ---------- */
  .es-wrap{overflow:auto;max-height:76vh;border:1px solid var(--line);border-radius:14px;background:var(--surface,#fff);}
  table.es{border-collapse:separate;border-spacing:0;width:100%;min-width:1000px;font-size:12.5px;}
  table.es th{position:sticky;top:0;z-index:2;background:#191C3A;color:#fff;font-weight:700;text-align:left;padding:9px 8px;font-size:12px;border-right:1px solid rgba(255,255,255,.08);white-space:nowrap;}
  table.es th small{display:block;font-weight:500;font-size:10px;color:#FDBA8C;}
  table.es td{padding:7px 8px;border-bottom:1px solid var(--line);border-right:1px solid var(--line);vertical-align:top;background:var(--surface,#fff);}
  table.es tr:hover td{background:color-mix(in srgb,var(--rail) 3%,var(--surface,#fff));}
  table.es td.sr{color:var(--text-dim);text-align:center;width:36px;}
  table.es td.wing{font-weight:700;border-left:4px solid var(--wc,#ccc);}
  table.es td.wing.rep{color:var(--text-dim);font-weight:500;}
  table.es td.task{white-space:pre-line;min-width:190px;max-width:260px;}
  table.es td.edit{background:color-mix(in srgb,#FEF3C7 45%,var(--surface,#fff));}
  table.es tr.mine td.staff b::after{content:' • मी';color:var(--rail);font-size:11px;}
  .es-note{width:100%;min-width:170px;min-height:38px;border:1px solid transparent;background:transparent;border-radius:8px;padding:5px 7px;font:inherit;resize:vertical;line-height:1.45;color:inherit;}
  .es-note:hover{border-color:var(--line);} .es-note:focus{outline:none;border-color:var(--rail);background:var(--surface,#fff);box-shadow:0 0 0 3px color-mix(in srgb,var(--rail) 15%,transparent);}
  .es-pct{display:flex;align-items:center;gap:4px;}
  .es-pct input{width:58px;text-align:center;font-weight:800;font-size:14px;border:1px solid var(--line);border-radius:8px;padding:5px 4px;background:var(--surface,#fff);color:inherit;}
  .es-pct input:focus{outline:none;border-color:var(--rail);box-shadow:0 0 0 3px color-mix(in srgb,var(--rail) 15%,transparent);}
  .es-pct button{width:26px;height:28px;border-radius:7px;border:1px solid var(--line);background:var(--canvas);font-weight:800;cursor:pointer;line-height:1;}
  .es-bar{height:6px;border-radius:99px;background:var(--canvas);overflow:hidden;margin-top:6px;} .es-bar i{display:block;height:100%;border-radius:99px;transition:width .4s,background .4s;}
  .es-ro{font-size:15px;font-weight:800;}
  .es-st{display:inline-block;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:99px;color:#fff;white-space:nowrap;transition:background .3s;}
  .es-saved{font-size:10.5px;color:var(--text-dim);margin-top:4px;min-height:14px;white-space:nowrap;}
  .es-saved.ok{color:#16A34A;} .es-saved.err{color:#DC2626;} .es-saved.busy{color:#B45309;}
  table.es tfoot td{position:sticky;bottom:0;background:#F3F1EE;font-weight:800;border-top:2px solid #191C3A;}
  html[data-theme="dark"] table.es tfoot td{background:#1B2236;}
  html[data-theme="dark"] table.es td.edit{background:color-mix(in srgb,#F59E0B 8%,var(--surface,#141B2D));}
  .es-hidden{display:none;}
  /* ---------- 🎓 Hero ---------- */
  .es-hero{position:relative;overflow:hidden;border-radius:20px;padding:20px 22px;margin-bottom:14px;color:#fff;
    background:radial-gradient(120% 140% at 100% 0%,rgba(217,83,15,.38) 0%,transparent 46%),radial-gradient(90% 120% at 0% 100%,rgba(123,63,158,.30) 0%,transparent 55%),linear-gradient(135deg,#141833 0%,#1F2450 60%,#262B5E 100%);
    box-shadow:0 14px 34px -18px rgba(20,24,51,.65);}
  .es-hero::after{content:'';position:absolute;right:-60px;top:-60px;width:220px;height:220px;border-radius:50%;border:1px solid rgba(255,255,255,.08);box-shadow:0 0 0 30px rgba(255,255,255,.025),0 0 0 62px rgba(255,255,255,.02);pointer-events:none;}
  .es-hero-top{display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;position:relative;z-index:1;}
  .es-hero-t{min-width:0;flex:1 1 380px;}
  .es-eyebrow{font-size:11px;font-weight:800;letter-spacing:.12em;color:#FDBA8C;text-transform:uppercase;}
  .es-hero h1{font-size:22px;font-weight:800;margin:4px 0 4px;letter-spacing:.01em;line-height:1.25;color:#fff;}
  .es-hero h1 span{color:#FDBA8C;}
  .es-hero p{font-size:12.5px;color:rgba(255,255,255,.72);margin:0;max-width:620px;}
  .es-actions{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
  .es-hsel{background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.22);border-radius:10px;padding:7px 10px;font-size:13px;font-weight:600;cursor:pointer;}
  .es-hsel option{color:#191C3A;}
  .es-hbtn{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:10px;padding:7px 12px;font-size:12.5px;font-weight:600;text-decoration:none;transition:background .15s,transform .15s;}
  .es-hbtn:hover{background:rgba(255,255,255,.2);color:#fff;transform:translateY(-1px);}
  .es-hbtn.pri{background:var(--rail,#D9530F);border-color:var(--rail,#D9530F);box-shadow:0 6px 16px -6px rgba(217,83,15,.7);}
  .es-hero-body{display:flex;gap:18px;align-items:center;flex-wrap:wrap;margin-top:18px;position:relative;z-index:1;}
  .es-hring{display:flex;gap:14px;align-items:center;padding-right:18px;border-right:1px solid rgba(255,255,255,.12);}
  .es-hero .es-ring{--p:0;width:96px;height:96px;background:conic-gradient(var(--c) calc(var(--p)*1%), rgba(255,255,255,.12) 0);filter:drop-shadow(0 4px 12px rgba(0,0,0,.25));}
  .es-hero .es-ring span{width:76px;height:76px;background:#1A1E44;color:#fff;font-size:20px;}
  .es-hero .es-ring small{color:rgba(255,255,255,.6);}
  .es-hring-t{display:flex;flex-direction:column;gap:2px;font-size:12px;color:rgba(255,255,255,.72);}
  .es-hring-t b{font-size:15px;color:#fff;} .es-hring-t .up{color:#4ADE80;font-weight:700;} .es-hring-t .dn{color:#F87171;font-weight:700;}
  .es-pace{display:inline-block;width:max-content;background:rgba(255,255,255,.1);border-radius:99px;padding:1px 9px;font-weight:700;color:#fff;margin-top:2px;}
  .es-kpis{flex:1;display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:10px;min-width:0;}
  .es-kpi{position:relative;display:flex;gap:10px;align-items:center;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:11px 12px 14px;backdrop-filter:blur(4px);}
  .es-kpi > i{flex:0 0 38px;height:38px;border-radius:11px;display:grid;place-items:center;font-style:normal;font-size:17px;background:color-mix(in srgb,var(--k) 22%,transparent);box-shadow:inset 0 0 0 1px color-mix(in srgb,var(--k) 40%,transparent);}
  .es-kpi .v{font-size:24px;font-weight:800;line-height:1.05;color:#fff;} .es-kpi .l{font-size:11.5px;color:rgba(255,255,255,.7);font-weight:600;}
  .es-kpi em{position:absolute;left:12px;right:12px;bottom:6px;height:3px;border-radius:99px;background:rgba(255,255,255,.1);overflow:hidden;} .es-kpi em s{display:block;height:100%;border-radius:99px;transition:width .4s;}
  /* ---------- Wing पट्टी ---------- */
  .es-wingbar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:var(--surface,#fff);border:1px solid var(--line);border-radius:14px;padding:10px 12px;margin-bottom:12px;}
  .es-wl{font-size:12.5px;font-weight:800;margin-right:4px;white-space:nowrap;flex:0 0 auto;} .es-wl small{font-weight:500;color:var(--text-dim);}
  .es-wchip{position:relative;font:inherit;font-size:12px;font-weight:700;padding:6px 11px 9px;border-radius:11px;background:var(--canvas);border:1px solid var(--line);cursor:pointer;display:inline-flex;gap:7px;align-items:center;color:inherit;transition:border-color .15s,transform .15s,box-shadow .15s;}
  .es-wchip:hover{transform:translateY(-1px);box-shadow:0 4px 12px -6px rgba(0,0,0,.2);}
  .es-wchip i{display:inline-block;width:8px;height:8px;border-radius:50%;}
  .es-wchip em{position:absolute;left:10px;right:10px;bottom:4px;height:3px;border-radius:99px;background:var(--line);overflow:hidden;} .es-wchip em s{display:block;height:100%;border-radius:99px;transition:width .4s;}
  .es-wchip.on{border-color:var(--rail);background:color-mix(in srgb,var(--rail) 10%,var(--surface,#fff));box-shadow:0 0 0 3px color-mix(in srgb,var(--rail) 14%,transparent);}
  /* ---------- स्मार्ट Cards / Tools — थोडं अधिक उठावदार ---------- */
  .es-card{box-shadow:0 1px 2px rgba(16,22,43,.04),0 8px 22px -16px rgba(16,22,43,.25);transition:box-shadow .2s;}
  .es-card:hover{box-shadow:0 1px 2px rgba(16,22,43,.05),0 12px 28px -14px rgba(16,22,43,.3);}
  .es-wrap{box-shadow:0 8px 26px -18px rgba(16,22,43,.35);}
  table.es tbody tr td{transition:background .15s;}
  @media (max-width:1100px){ .es-kpis{grid-template-columns:repeat(2,1fr);} .es-hring{border-right:0;padding-right:0;} }
  /* ---------- 📱 Mobile — तक्त्याऐवजी Cards ---------- */
  @media (max-width:767px){
    .es-hero{padding:16px;border-radius:18px;} .es-hero h1{font-size:18px;} .es-hero p{font-size:12px;}
    .es-actions{width:100%;} .es-actions form{flex:1 1 100%;} .es-hsel{width:100%;}
    .es-hbtn{flex:1 1 calc(25% - 6px);justify-content:center;padding:8px 6px;} .es-hbtn span{font-size:11.5px;} .es-hbtn.pri{flex-basis:100%;}
    .es-hero-body{gap:12px;} .es-hring{width:100%;justify-content:center;}
    .es-kpis{grid-template-columns:1fr 1fr;width:100%;gap:8px;} .es-kpi{padding:9px 10px 12px;} .es-kpi .v{font-size:20px;} .es-kpi > i{flex-basis:32px;height:32px;font-size:15px;}
    .es-wingbar{flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none;} .es-wingbar::-webkit-scrollbar{display:none;}
    .es-wl small{display:none;} .es-wchip{flex:0 0 auto;}
    .es-tools .form-control-custom{max-width:none;flex:1 1 100%;} .es-tools #esSort{flex:1 1 auto;max-width:none;}
    .es-wrap{overflow:visible;max-height:none;border:0;background:transparent;box-shadow:none;}
    table.es{min-width:0;display:block;} table.es thead{display:none;} table.es tbody{display:block;}
    table.es tbody tr{display:grid;grid-template-columns:1fr auto;grid-template-areas:"staff stat" "desig stat" "wing sec" "task task" "notes notes" "pct pct";
      background:var(--surface,#fff);border:1px solid var(--line);border-left:4px solid var(--wc,var(--rail));border-radius:14px;margin-bottom:10px;overflow:hidden;box-shadow:0 6px 18px -14px rgba(16,22,43,.35);}
    table.es tbody tr.es-hidden{display:none;}
    table.es td{display:block;border:0;padding:0 14px;background:transparent !important;}
    table.es td.sr{display:none;}
    table.es td.staff{grid-area:staff;padding-top:12px;font-size:14.5px;} table.es td.desig{grid-area:desig;font-size:11.5px;color:var(--text-dim);}
    table.es td.stat{grid-area:stat;padding-top:12px;align-self:start;}
    table.es td.wing{grid-area:wing;border-left:0;font-size:11.5px;padding-top:8px;color:var(--wc);} table.es td.wing.rep{color:var(--wc);font-weight:700;}
    table.es td.sec{grid-area:sec;font-size:11.5px;color:var(--text-dim);padding-top:8px;text-align:right;}
    table.es td.task{grid-area:task;max-width:none;min-width:0;margin:10px 14px 0;padding:9px 11px;background:var(--canvas) !important;border-radius:10px;font-size:12.5px;}
    table.es td.notes{grid-area:notes;padding-top:10px;} table.es td.pct{grid-area:pct;width:auto !important;min-width:0 !important;padding:8px 14px 12px;}
    table.es td[data-label]::before{content:attr(data-label);display:block;font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--text-dim);margin-bottom:3px;}
    table.es td.task::before{margin-bottom:2px;}
    table.es td.edit .es-note{border-color:var(--line);background:color-mix(in srgb,#FEF3C7 40%,var(--surface,#fff));}
    table.es td.pct .es-pct input{width:72px;font-size:16px;} table.es td.pct .es-pct button{width:38px;height:36px;font-size:16px;}
    table.es tr.mine td.staff b::after{content:' • मी';}
    table.es tfoot{display:block;} table.es tfoot tr{display:flex;justify-content:space-between;align-items:center;gap:8px;background:#191C3A;color:#fff;border-radius:14px;padding:10px 14px;}
    table.es tfoot td{position:static;display:block;background:transparent !important;color:#fff;border:0;padding:0;}
    table.es tfoot td:first-child{flex:1;text-align:left !important;font-size:12px;font-weight:600;color:rgba(255,255,255,.75);}
  }
  /* ---------- 🧠 स्मार्ट निष्कर्ष ---------- */
  .es-ai{margin-bottom:12px;}
  .es-tip{display:flex;gap:10px;align-items:center;border:1px solid color-mix(in srgb,var(--tc) 35%,var(--line));background:color-mix(in srgb,var(--tc) 8%,var(--surface,#fff));border-radius:12px;padding:9px 14px;font-size:13px;margin-bottom:10px;}
  .es-tip > span{font-size:20px;}
  .es-ai-grid{display:grid;grid-template-columns:1fr 1.3fr 1.3fr;gap:10px;}
  .es-card{background:var(--surface,#fff);border:1px solid var(--line);border-radius:14px;padding:12px 14px;min-width:0;}
  .es-card-h{font-size:12.5px;font-weight:800;margin-bottom:6px;}
  .es-big{font-size:30px;font-weight:800;line-height:1.1;}
  .es-sub{font-size:11.5px;color:var(--text-dim);margin-top:2px;}
  .es-spark{display:flex;gap:4px;align-items:flex-end;height:38px;margin-top:10px;} .es-spark i{flex:1;border-radius:4px 4px 0 0;min-height:3px;}
  .es-li{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:6px 0;border-bottom:1px dashed var(--line);font-size:12.5px;}
  .es-li:last-child{border-bottom:0;} .es-li small{display:block;color:var(--text-dim);font-size:11px;font-weight:500;}
  .es-up{color:#16A34A;font-weight:800;text-align:right;white-space:nowrap;} .es-up small{text-align:right;}
  .es-rbtn{border:1px solid var(--line);background:var(--canvas);border-radius:99px;font-size:11.5px;font-weight:700;padding:3px 10px;cursor:pointer;}
  .es-rbtn:hover{border-color:var(--rail);color:var(--rail);} .es-rbtn.sm{padding:2px 7px;}
  .es-flag{display:inline-block;font-size:10.5px;font-weight:700;border-radius:99px;padding:1px 7px;margin-top:3px;white-space:nowrap;}
  .es-flag.warn{background:#FEF3C7;color:#92400E;} .es-flag.bad{background:#FDECEC;color:#B42318;}
  @media (max-width:900px){ .es-ai-grid{grid-template-columns:1fr;} }
  details.es-charts > summary{cursor:pointer;font-weight:700;font-size:15px;padding:10px 0;list-style:none;} details.es-charts > summary::before{content:'▸ ';color:var(--rail);} details.es-charts[open] > summary::before{content:'▾ ';}
  .ec-chart{display:flex;gap:18px;align-items:center;flex-wrap:wrap;padding:14px 18px;} .ec-chart svg{max-width:100%;height:auto;}
  .ec-hbar{display:grid;grid-template-columns:minmax(120px,32%) 1fr 48px;gap:10px;align-items:center;font-size:13px;padding:6px 0;border-bottom:1px dashed var(--line);}
  .ec-hbar .b{height:12px;border-radius:99px;background:var(--canvas);overflow:hidden;} .ec-hbar .b i{display:block;height:100%;border-radius:99px;}
  @media print{ .es-tools,.no-print,details.es-charts{display:none !important;} .es-wrap{max-height:none;overflow:visible;border:0;} table.es th{position:static;} .es-note{border:0;resize:none;} }
</style>

<?php $tot = max(1, (int)$S['work']); $delta = ($rows && $S['overall'] !== null && ($prevS['overall'] ?? null) !== null) ? $S['overall'] - $prevS['overall'] : null; ?>
<!-- ---------- 🎓 Hero — शीर्षक, कृती, एकूण प्रगती व आकडे (तक्त्यातील बदलांबरोबर लगेच बदलतात) ---------- -->
<div class="es-hero">
  <div class="es-hero-top">
    <div class="es-hero-t">
      <div class="es-eyebrow">🎓 E-CAMPUS · <?= h(activeOrgName()) ?></div>
      <h1>MONTHLY E-CAMPUS REPORT <span>— <?= date('m/Y', strtotime($period . '-01')) ?></span></h1>
      <p><?= $isCurrent ? ($viewAll ? 'Progress Notes आणि % Complete थेट तक्त्यात भरा — आपोआप जतन होतं, Status आपोआप बदलतो.' : 'तुमच्या कामांची Progress Notes आणि % Complete इथे भरा — आपोआप जतन होतं.') : '📅 ' . ecampusPeriodLabel($period) . ' — मागचा महिना: त्या महिन्यात झालेलं काम पाहून % आणि नोंद भरा (आपोआप जतन).' ?></p>
    </div>
    <div class="es-actions no-print">
      <form method="GET"><select name="month" class="es-hsel" onchange="this.form.submit()" aria-label="महिना"><?php foreach ($months as $m): ?><option value="<?= $m ?>" <?= $m === $period ? 'selected' : '' ?>><?= h(ecampusPeriodLabel($m)) ?></option><?php endforeach; ?></select></form>
      <?php if ($viewAll): ?>
        <a href="report.php?month=<?= h($period) ?>&print=1" target="_blank" class="es-hbtn" title="Print"><i class="fa-solid fa-print"></i><span>Print</span></a>
        <a href="report.php?month=<?= h($period) ?>&pdf=1&v=<?= time() ?>" target="_blank" class="es-hbtn" title="PDF"><i class="fa-solid fa-file-pdf"></i><span>PDF</span></a>
        <a href="report.php?month=<?= h($period) ?>&pdf=dl&v=<?= time() ?>" class="es-hbtn" title="Download"><i class="fa-solid fa-download"></i><span>Download</span></a>
        <a href="report.php?month=<?= h($period) ?>&csv=1" class="es-hbtn" title="Excel"><i class="fa-solid fa-file-excel"></i><span>Excel</span></a>
      <?php endif; ?>
      <?php if ($isSA): ?><a href="manage.php" class="es-hbtn pri"><i class="fa-solid fa-user-gear"></i><span>कामांचं वाटप</span></a><?php endif; ?>
    </div>
  </div>
  <?php if ($rows): ?>
  <div class="es-hero-body" id="esStrip">
    <div class="es-hring">
      <div class="es-ring" id="esRing" style="--p:<?= (int)$S['overall'] ?>;--c:<?= $col[$S['level']] ?>;"><span><b id="esOverall"><?= (int)$S['overall'] ?>%</b><small>पूर्ण</small></span></div>
      <div class="es-hring-t">
        <b><span id="esLeft"><?= max(0, 100 - (int)$S['overall']) ?></span>% बाकी</b>
        <?php if ($isCurrent): ?><span>महिना <?= (int)$S['expected'] ?>% गेला</span><span class="es-pace" id="esPace"><?= ($S['overall'] ?? 0) >= $S['expected'] ? '🚀 वेळेच्या पुढे' : '⏳ वेळेच्या मागे' ?></span><?php endif; ?>
        <?php if ($delta !== null): ?><span class="<?= $delta >= 0 ? 'up' : 'dn' ?>"><?= $delta >= 0 ? '▲' : '▼' ?> <?= abs($delta) ?>% मागच्या महिन्यापेक्षा</span><?php endif; ?>
      </div>
    </div>
    <div class="es-kpis">
      <div class="es-kpi"><i style="--k:#A5B4FC;">📋</i><div><div class="v" id="esTotal"><?= (int)$S['work'] ?></div><div class="l">एकूण कामं · <?= (int)$S['staff'] ?> कर्मचारी</div></div></div>
      <div class="es-kpi"><i style="--k:#4ADE80;">✅</i><div><div class="v" id="esDone"><?= (int)$S['done'] ?></div><div class="l">पूर्ण</div></div><em><s id="esDoneBar" style="width:<?= round($S['done'] * 100 / $tot) ?>%;background:#4ADE80;"></s></em></div>
      <div class="es-kpi"><i style="--k:#FBBF24;">🔄</i><div><div class="v" id="esProg"><?= (int)$S['progress'] ?></div><div class="l">सुरू</div></div><em><s id="esProgBar" style="width:<?= round($S['progress'] * 100 / $tot) ?>%;background:#FBBF24;"></s></em></div>
      <div class="es-kpi"><i style="--k:#F87171;">⛔</i><div><div class="v" id="esPend"><?= (int)$S['pending'] ?></div><div class="l">सुरू नाही</div></div><em><s id="esPendBar" style="width:<?= round($S['pending'] * 100 / $tot) ?>%;background:#F87171;"></s></em></div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php if (!$rows): ?>
  <div class="panel" style="padding:40px;text-align:center;color:var(--text-dim);"><div style="font-size:38px;">🎓</div>या महिन्यासाठी E-Campus चं एकही काम नेमलेलं नाही.<?php if ($isSA): ?><div class="mt-3"><a href="manage.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;">कामं नेमा / Excel आयात करा</a></div><?php endif; ?></div>
<?php else: ?>

  <!-- ---------- Wing नुसार — Click करून फक्त तीच Wing ---------- -->
  <div class="es-wingbar no-print">
    <span class="es-wl">🏛️ Wing नुसार <small>— Click करून फक्त तेच पाहा</small></span>
    <?php foreach ($wings as $w): $g = $S['wings'][$w]; ?><button type="button" class="es-wchip" data-wing="<?= h($w) ?>"><i style="background:<?= $barCol($g['avg']) ?>;"></i><span class="wn"><?= h($w) ?></span><b data-wavg="<?= h($w) ?>"><?= (int)$g['avg'] ?>%</b><em><s style="width:<?= (int)$g['avg'] ?>%;background:<?= $barCol($g['avg']) ?>;"></s></em></button><?php endforeach; ?>
  </div>

  <?php if ($viewAll): /* ---------- 🧠 स्मार्ट निष्कर्ष ---------- */
    $ovr = (int)($S['overall'] ?? 0);
    if ($ovr >= 100) { $tip = ['🎉', 'सगळी कामं पूर्ण — उत्तम!', '#16A34A']; }
    elseif ($forecast && !$forecast['onTrack']) { $tip = ['⚠️', 'या वेगाने महिनाअखेर फक्त ' . $forecast['projected'] . '% — 100% साठी रोज सरासरी ' . $forecast['need'] . '% प्रगती हवी' . ($attention ? '; आधी "लक्ष द्या" मधल्या ' . count($attention) . ' कर्मचाऱ्यांशी बोला' : ''), '#B45309']; }
    elseif ($forecast) { $tip = ['🚀', 'वेळापत्रकाप्रमाणे — महिनाअखेर अंदाजे ' . $forecast['projected'] . '% पूर्ण होईल' . ($S['pending'] ? ' · पण ' . $S['pending'] . ' कामं अजून सुरू झाली नाहीत' : ''), $S['pending'] ? '#B45309' : '#16A34A']; }
    elseif ($S['pending']) { $tip = ['⛔', $S['pending'] . ' कामं सुरूच झाली नाहीत', '#DC2626']; }
    else { $tip = null; }
  ?>
  <div class="es-ai no-print">
    <?php if ($tip): ?><div class="es-tip" style="--tc:<?= $tip[2] ?>;"><span><?= $tip[0] ?></span><div><b>स्मार्ट निष्कर्ष:</b> <?= h($tip[1]) ?></div></div><?php endif; ?>
    <div class="es-ai-grid">
      <div class="es-card">
        <div class="es-card-h">🔮 महिनाअखेरचा अंदाज</div>
        <?php if ($forecast): ?>
          <div class="es-big" style="color:<?= $forecast['onTrack'] ? '#16A34A' : '#B45309' ?>;"><?= $forecast['projected'] ?>%</div>
          <div class="es-sub">सध्या <?= $ovr ?>% · वेग <b><?= $forecast['perDay'] ?>%/दिवस</b> · <?= $forecast['daysLeft'] ?> दिवस बाकी</div>
          <?php if ($forecast['daysLeft'] > 0 && $ovr < 100): ?><div class="es-sub">100% साठी रोज <b><?= $forecast['need'] ?>%</b> हवं</div><?php endif; ?>
        <?php else: ?>
          <div class="es-big"><?= $ovr ?>%</div><div class="es-sub"><?= h(ecampusPeriodLabel($period)) ?> — अंतिम</div>
        <?php endif; ?>
        <?php $hv = array_filter($history, fn($v) => $v !== null); if (count($hv) > 1): ?>
          <div class="es-spark" title="मागचे 6 महिने"><?php foreach ($history as $m => $v): ?><i style="height:<?= max(4, (int)$v) ?>%;background:<?= $v === null ? 'var(--line)' : $barCol((int)$v) ?>;" title="<?= h(ecampusPeriodLabel($m)) ?>: <?= $v === null ? '—' : (int)$v . '%' ?>"></i><?php endforeach; ?></div>
          <div class="es-sub">मागचे 6 महिने</div>
        <?php endif; ?>
      </div>
      <div class="es-card">
        <div class="es-card-h">📈 या आठवड्यातली प्रगती</div>
        <?php if ($movers): foreach ($movers as $mv): ?>
          <div class="es-li"><div><b><?= h($mv['row']['staff_name']) ?></b><small><?= h($mv['row']['section']) ?></small></div><span class="es-up">▲ <?= $mv['delta'] ?>%<small><?= $mv['from'] ?>→<?= $mv['to'] ?>%</small></span></div>
        <?php endforeach; else: ?><div class="es-sub" style="padding:8px 0;"><?= $isCurrent ? 'गेल्या 7 दिवसांत एकाही कामाची % वाढलेली नाही.' : 'फक्त चालू महिन्यासाठी.' ?></div><?php endif; ?>
      </div>
      <div class="es-card">
        <div class="es-card-h d-flex justify-content-between align-items-center">🎯 लक्ष द्या
          <?php if ($canRemind && $isCurrent && $attention): ?><form method="POST" onsubmit="return confirm('<?= count($attention) ?> कर्मचाऱ्यांना E-Campus प्रगती भरण्याची आठवण पाठवायची?');"><input type="hidden" name="action" value="remind"><button class="es-rbtn">🔔 सगळ्यांना आठवण</button></form><?php endif; ?></div>
        <?php if ($attention): foreach (array_slice($attention, 0, 6) as $b): ?>
          <div class="es-li"><div><b><?= h($b['name']) ?></b><small><?= $b['pending'] ? $b['pending'] . ' सुरू नाही' : '' ?><?= $b['pending'] && $b['stale'] ? ' · ' : '' ?><?= $b['stale'] ? 'नोंद: ' . h(ecampusAgo($b['last'])) : '' ?></small></div>
            <span style="display:flex;gap:6px;align-items:center;"><b style="color:<?= $barCol($b['avg']) ?>;"><?= $b['avg'] ?>%</b>
            <?php if ($canRemind && $isCurrent): ?><form method="POST"><input type="hidden" name="action" value="remind"><input type="hidden" name="user_id" value="<?= (int)$b['user_id'] ?>"><button class="es-rbtn sm" title="<?= h($b['name']) ?> यांना आठवण पाठवा">🔔</button></form><?php endif; ?></span></div>
        <?php endforeach; if (count($attention) > 6): ?><div class="es-sub">आणखी <?= count($attention) - 6 ?>…</div><?php endif; else: ?><div class="es-sub" style="padding:8px 0;color:#16A34A;font-weight:700;">✅ सगळ्यांच्या नोंदी अद्ययावत आहेत.</div><?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="es-tools no-print">
    <input type="search" id="esSearch" class="form-control-custom" placeholder="🔍 नाव / Section / काम शोधा…">
    <button type="button" class="sbtn on" data-st="">सर्व</button>
    <button type="button" class="sbtn" data-st="pending">⛔ सुरू नाही</button>
    <button type="button" class="sbtn" data-st="progress">🔄 सुरू</button>
    <button type="button" class="sbtn" data-st="done">✅ पूर्ण</button>
    <?php if ($viewAll && ecampusMyTaskCount($pdo, $me['id'])): ?><button type="button" class="sbtn" data-mine="1">👤 माझी कामं</button><?php endif; ?>
    <select id="esSort" class="form-select-custom" style="max-width:190px;" title="क्रम">
      <option value="">↕ मूळ क्रम</option><option value="low">कमी % आधी</option><option value="high">जास्त % आधी</option><option value="old">जुनी नोंद आधी</option>
    </select>
    <span id="esCount" style="font-size:12.5px;color:var(--text-dim);margin-left:auto;"></span>
  </div>

  <!-- ---------- Excel सारखा तक्ता ---------- -->
  <div class="es-wrap">
    <table class="es" id="esTable" data-period="<?= h($period) ?>">
      <thead><tr>
        <th>Sr. No</th><th>Wing / Category</th><th>Section / Department</th><th>Staff Name</th><th>Designation</th><th>Assigned Task</th>
        <th>Progress Notes<small>(editable)</small></th><th>% Complete<small>(editable)</small></th><th>Status<small>(auto)</small></th>
      </tr></thead>
      <tbody>
      <?php $prevWing = null; $wingColors = ['#D9530F', '#7B3F9E', '#2563EB', '#16A34A', '#0891B2', '#B45309', '#BE185D', '#4F46E5']; $wc = [];
      foreach ($rows as $i => $r): $wc[$r['wing']] ??= $wingColors[count($wc) % count($wingColors)]; $ed = $canEdit($r); $rep = $prevWing === $r['wing']; $prevWing = $r['wing']; ?>
        <tr data-id="<?= (int)$r['id'] ?>" data-wing="<?= h($r['wing']) ?>" data-status="<?= h($r['status']) ?>" data-access="<?= h($r['access_type']) ?>" data-pct="<?= $r['percent'] ?>" style="--wc:<?= $wc[$r['wing']] ?>;" data-mine="<?= (int)$r['user_id'] === (int)$me['id'] ? 1 : 0 ?>" data-sr="<?= $i ?>" data-upd="<?= $r['updated_at'] && !$r['carried'] ? strtotime($r['updated_at']) : 0 ?>" class="<?= (int)$r['user_id'] === (int)$me['id'] ? 'mine' : '' ?>">
          <td class="sr"><?= $i + 1 ?></td>
          <td class="wing <?= $rep ? 'rep' : '' ?>" style="--wc:<?= $wc[$r['wing']] ?>;"><?= h($r['wing']) ?></td>
          <td class="sec"><?= h($r['section']) ?></td>
          <td class="staff"><b><?= h($r['staff_name']) ?></b></td>
          <td class="desig"><?= h($r['designation_show']) ?></td>
          <td class="task" data-label="Assigned Task"><?= h($r['task_description']) ?></td>
          <td class="notes <?= $ed ? 'edit' : '' ?>" data-label="Progress Notes">
            <?php if ($ed): ?><textarea class="es-note" rows="2" maxlength="2000" placeholder="नोंद लिहा…"><?= h($r['carried'] ? '' : (string)$r['notes']) ?></textarea>
            <?php else: ?><?= nl2br(h((string)$r['notes'])) ?: '<span style="color:var(--text-dim);">—</span>' ?><?php endif; ?>
          </td>
          <td class="pct <?= $ed ? 'edit' : '' ?>" data-label="% Complete" style="min-width:118px;width:118px;">
            <?php if ($ed): ?>
              <div class="es-pct"><button type="button" data-step="-5" title="-5">−</button><input type="number" class="es-pval" min="0" max="100" step="1" value="<?= $r['percent'] ?>" inputmode="numeric"><button type="button" data-step="5" title="+5">+</button></div>
            <?php else: ?><div class="es-ro"><?= $r['percent'] ?>%</div><?php endif; ?>
            <div class="es-bar"><i style="width:<?= $r['percent'] ?>%;background:<?= $r['status_color'] ?>;"></i></div>
            <div class="es-saved"><?= $r['updated_at'] && !$r['carried'] ? h(ecampusAgo($r['updated_at'])) : '' ?></div>
            <?php if ($isCurrent && $r['access_type'] === 'work' && $r['percent'] < 100):
              $__u = $r['updated_at'] && !$r['carried'] ? strtotime($r['updated_at']) : 0; ?>
              <?php if (!$__u): ?><span class="es-flag bad">नोंद नाही</span><?php elseif ($__u < strtotime('-7 days')): ?><span class="es-flag warn">⚠️ 7+ दिवस जुनी</span><?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="stat"><span class="es-st" style="background:<?= $r['status_color'] ?>;"><?= h($r['status_label']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="7" style="text-align:right;">एकूण प्रगती (फक्त-Access वगळून)</td><td id="esFootPct"><?= (int)$S['overall'] ?>%</td><td id="esFootSt"><?= (int)$S['done'] ?>/<?= (int)$S['work'] ?> पूर्ण</td></tr></tfoot>
    </table>
  </div>

  <?php if ($viewAll): ?>
  <details class="es-charts mt-3" open>
    <summary>📊 Charts व विश्लेषण</summary>
    <div class="row g-3 mb-3">
      <div class="col-lg-5"><div class="panel h-100"><div class="panel-head"><div class="title">स्थिती</div></div>
        <div class="ec-chart"><?= drDonut([[(int)$S['done'], '#16A34A'], [(int)$S['progress'], '#F59E0B'], [(int)$S['pending'], '#DC2626']], ($S['overall'] ?? 0) . '%', 150) ?>
          <div style="font-size:13px;line-height:1.9;"><div>🟩 पूर्ण <b><?= (int)$S['done'] ?></b></div><div>🟧 सुरू <b><?= (int)$S['progress'] ?></b></div><div>🟥 सुरू नाही <b><?= (int)$S['pending'] ?></b></div><?php if ($S['viewOnly']): ?><div>🟦 फक्त Access <b><?= (int)$S['viewOnly'] ?></b></div><?php endif; ?></div></div></div></div>
      <div class="col-lg-7"><div class="panel h-100"><div class="panel-head"><div class="title">महिनाभरातली प्रगती</div><div class="sub">दिवसागणिक एकूण %</div></div>
        <div class="ec-chart" style="display:block;"><?php if ($trend): $keys = array_keys($trend); $vals = array_values($trend); $step = max(1, (int)ceil(count($vals) / 15)); $lbl = [];
          foreach ($keys as $i => $k) { $lbl[] = ($i % $step === 0 || $i === count($keys) - 1) ? date('j', strtotime($k)) : ''; } ?><?= drBars($vals, $lbl, '#D9530F', 640, 170) ?><?php else: ?><div style="color:var(--text-dim);font-size:13px;">अजून नोंदी नाहीत.</div><?php endif; ?></div></div></div>
    </div>
    <div class="row g-3">
      <div class="col-lg-6"><div class="panel h-100"><div class="panel-head"><div class="title">🏛️ Wing नुसार</div><div class="sub">कमी % आधी</div></div><div style="padding:8px 18px 14px;">
        <?php foreach ($S['wings'] as $w => $g): ?><div class="ec-hbar"><div><b><?= h($w) ?></b><br><small style="color:var(--text-dim);"><?= (int)$g['done'] ?>/<?= (int)$g['n'] ?> पूर्ण</small></div><div class="b"><i style="width:<?= (int)$g['avg'] ?>%;background:<?= $barCol($g['avg']) ?>;"></i></div><b style="text-align:right;"><?= (int)$g['avg'] ?>%</b></div><?php endforeach; ?></div></div></div>
      <div class="col-lg-6"><div class="panel h-100"><div class="panel-head"><div class="title">🗂️ Section नुसार</div><div class="sub">कमी % आधी</div></div><div style="padding:8px 18px 14px;max-height:420px;overflow:auto;">
        <?php foreach ($S['sections'] as $sec => $g): ?><div class="ec-hbar"><div><b><?= h($sec) ?></b><br><small style="color:var(--text-dim);"><?= h($g['wing']) ?></small></div><div class="b"><i style="width:<?= (int)$g['avg'] ?>%;background:<?= $barCol($g['avg']) ?>;"></i></div><b style="text-align:right;"><?= (int)$g['avg'] ?>%</b></div><?php endforeach; ?></div></div></div>
    </div>
    <div class="row g-3 mt-0">
      <div class="col-lg-7"><div class="panel h-100"><div class="panel-head"><div class="title">👥 कर्मचारी नुसार</div><div class="sub">कमी % आधी · शेवटची नोंद</div></div><div style="padding:8px 18px 14px;max-height:420px;overflow:auto;">
        <?php foreach ($board as $b): ?><div class="ec-hbar"><div><b><?= h($b['name']) ?></b><br><small style="color:var(--text-dim);"><?= (int)$b['done'] ?>/<?= (int)$b['n'] ?> पूर्ण · <?= h(ecampusAgo($b['last'])) ?></small></div><div class="b"><i style="width:<?= (int)$b['avg'] ?>%;background:<?= $barCol($b['avg']) ?>;"></i></div><b style="text-align:right;"><?= (int)$b['avg'] ?>%</b></div><?php endforeach; ?></div></div></div>
      <div class="col-lg-5"><div class="panel h-100"><div class="panel-head"><div class="title">📅 मागचे 6 महिने</div><div class="sub">महिन्याची एकूण प्रगती</div></div>
        <div class="ec-chart" style="display:block;"><?php $hData = array_filter($history, fn($v) => $v !== null); /* नोंदी नसलेले महिने वगळा — 0% दाखवणं दिशाभूल करणारं */ if ($hData): ?><?= drBars(array_map('intval', array_values($hData)), array_map(fn($m) => date('M Y', strtotime($m . '-01')), array_keys($hData)), '#7B3F9E', 420, 170) ?><?php if (count($hData) < count($history)): ?><div class="es-sub">E-Campus नोंदी असलेलेच महिने</div><?php endif; ?><?php else: ?><div style="color:var(--text-dim);font-size:13px;">अजून इतिहास नाही.</div><?php endif; ?></div></div></div>
    </div>
  </details>
  <?php endif; ?>
<?php endif; ?>

<script>
(function () {
  var table = document.getElementById('esTable'); if (!table) return;
  var period = table.dataset.period;
  var T = function (s) { return window.eowmsT ? eowmsT(s) : s; };
  var stOf = function (p) { return p >= 100 ? ['done', T('पूर्ण'), '#16A34A'] : (p > 0 ? ['progress', T('सुरू'), '#F59E0B'] : ['pending', T('सुरू नाही'), '#DC2626']); };
  var barColor = function (p) { return p >= 100 ? '#16A34A' : (p >= 75 ? '#65A30D' : (p >= 50 ? '#F59E0B' : '#DC2626')); };

  // ---- वरचा सारांश पुन्हा मोजा (तक्त्यातल्या मूल्यांवरून) ----
  function recalc() {
    var rows = [].slice.call(table.tBodies[0].rows).filter(function (r) { return r.dataset.access === 'work'; });
    var sum = 0, c = { done: 0, progress: 0, pending: 0 }, wing = {};
    rows.forEach(function (r) { var p = +r.dataset.pct; sum += p; c[r.dataset.status]++; var w = r.dataset.wing; wing[w] = wing[w] || [0, 0]; wing[w][0] += p; wing[w][1]++; });
    var o = rows.length ? Math.round(sum / rows.length) : 0;
    var ring = document.getElementById('esRing');
    ring.style.setProperty('--p', o); ring.style.setProperty('--c', o >= 85 ? '#16A34A' : (o >= 60 ? '#F59E0B' : '#DC2626'));
    document.getElementById('esOverall').textContent = o + '%';
    document.getElementById('esLeft').textContent = Math.max(0, 100 - o);
    document.getElementById('esDone').textContent = c.done; document.getElementById('esProg').textContent = c.progress; document.getElementById('esPend').textContent = c.pending;
    document.getElementById('esFootPct').textContent = o + '%';
    document.getElementById('esFootSt').textContent = c.done + '/' + rows.length + ' ' + T('पूर्ण');
    Object.keys(wing).forEach(function (w) { var el = document.querySelector('[data-wavg="' + CSS.escape(w) + '"]'); if (el) { var a = Math.round(wing[w][0] / wing[w][1]), ch = el.closest('.es-wchip'); el.textContent = a + '%'; ch.querySelector('i').style.background = barColor(a); var bar = ch.querySelector('em s'); if (bar) { bar.style.width = a + '%'; bar.style.background = barColor(a); } } });
    var tot = Math.max(1, rows.length);
    [['esDoneBar', c.done], ['esProgBar', c.progress], ['esPendBar', c.pending]].forEach(function (x) { var b = document.getElementById(x[0]); if (b) { b.style.width = Math.round(x[1] * 100 / tot) + '%'; } });
  }

  // ---- एका ओळीचं जतन (थोडं थांबून — टाइप करत असताना सतत नाही) ----
  function save(tr) {
    var inp = tr.querySelector('.es-pval'), note = tr.querySelector('.es-note'), msg = tr.querySelector('.es-saved');
    var p = Math.max(0, Math.min(100, parseInt(inp.value || '0', 10) || 0)); inp.value = p;
    msg.className = 'es-saved busy'; msg.textContent = T('जतन होत आहे…');
    fetch('save.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ task_id: +tr.dataset.id, percent: p, notes: note ? note.value : '', period: period }) })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) { throw new Error(j.msg || 'error'); }
        msg.className = 'es-saved ok'; msg.textContent = '✓ ' + T('जतन') + ' ' + j.time;
      })
      .catch(function (e) { msg.className = 'es-saved err'; msg.textContent = '✗ ' + T('जतन झालं नाही') + ' — ' + e.message; });
  }
  function apply(tr) { // % बदलताच रंग/Status/Bar लगेच
    var inp = tr.querySelector('.es-pval'); var p = Math.max(0, Math.min(100, parseInt(inp.value || '0', 10) || 0));
    var s = stOf(p); tr.dataset.pct = p; tr.dataset.status = s[0];
    var b = tr.querySelector('.es-bar i'); b.style.width = p + '%'; b.style.background = s[2];
    var st = tr.querySelector('.es-st'); st.textContent = s[1]; st.style.background = s[2];
    recalc();
  }
  var timers = new WeakMap();
  function later(tr, ms) { clearTimeout(timers.get(tr)); timers.set(tr, setTimeout(function () { save(tr); }, ms)); }
  table.addEventListener('input', function (e) {
    var tr = e.target.closest('tr'); if (!tr) return;
    if (e.target.classList.contains('es-pval')) { apply(tr); later(tr, 900); }
    if (e.target.classList.contains('es-note')) { e.target.style.height = 'auto'; e.target.style.height = e.target.scrollHeight + 'px'; later(tr, 1500); }
  });
  table.addEventListener('change', function (e) { var tr = e.target.closest('tr'); if (tr && (e.target.classList.contains('es-pval') || e.target.classList.contains('es-note'))) { clearTimeout(timers.get(tr)); save(tr); } });
  table.addEventListener('click', function (e) {
    var b = e.target.closest('[data-step]'); if (!b) return;
    var tr = b.closest('tr'), inp = tr.querySelector('.es-pval');
    inp.value = Math.max(0, Math.min(100, (parseInt(inp.value || '0', 10) || 0) + parseInt(b.dataset.step, 10))); apply(tr); later(tr, 700);
  });
  table.querySelectorAll('.es-note').forEach(function (n) { n.style.height = 'auto'; n.style.height = Math.max(38, n.scrollHeight) + 'px'; });

  // ---- शोध / स्थिती / Wing / माझी कामं — Filter ----
  var fSt = '', fWing = '', fMine = false, q = document.getElementById('esSearch');
  function filter() {
    var text = (q.value || '').toLowerCase(), shown = 0, all = table.tBodies[0].rows.length;
    [].forEach.call(table.tBodies[0].rows, function (r) {
      var ok = (!fSt || r.dataset.status === fSt) && (!fWing || r.dataset.wing === fWing) && (!fMine || r.dataset.mine === '1') && (!text || r.textContent.toLowerCase().indexOf(text) >= 0);
      r.classList.toggle('es-hidden', !ok); if (ok) shown++;
    });
    document.getElementById('esCount').textContent = shown + ' / ' + all + ' ' + T('ओळी');
  }
  q.addEventListener('input', filter);
  // ---- क्रम (Sort) ----
  var sortSel = document.getElementById('esSort');
  if (sortSel) sortSel.addEventListener('change', function () {
    var v = sortSel.value, tb = table.tBodies[0], rs = [].slice.call(tb.rows);
    rs.sort(function (a, b) {
      if (v === 'low') return (+a.dataset.pct - +b.dataset.pct) || (+a.dataset.sr - +b.dataset.sr);
      if (v === 'high') return (+b.dataset.pct - +a.dataset.pct) || (+a.dataset.sr - +b.dataset.sr);
      if (v === 'old') return (+a.dataset.upd - +b.dataset.upd) || (+a.dataset.sr - +b.dataset.sr);
      return +a.dataset.sr - +b.dataset.sr;
    });
    rs.forEach(function (r) { tb.appendChild(r); });
    tb.querySelectorAll('td.wing').forEach(function (td) { if (td.dataset.rep === undefined) { td.dataset.rep = td.classList.contains('rep') ? '1' : '0'; } td.classList.toggle('rep', !v && td.dataset.rep === '1'); });
  });
  document.querySelectorAll('.es-tools .sbtn[data-st]').forEach(function (b) { b.addEventListener('click', function () { document.querySelectorAll('.es-tools .sbtn[data-st]').forEach(function (x) { x.classList.toggle('on', x === b); }); fSt = b.dataset.st; filter(); }); });
  var mb = document.querySelector('.es-tools .sbtn[data-mine]'); if (mb) mb.addEventListener('click', function () { fMine = !fMine; mb.classList.toggle('on', fMine); filter(); });
  document.querySelectorAll('.es-wchip').forEach(function (c) { c.addEventListener('click', function () { fWing = fWing === c.dataset.wing ? '' : c.dataset.wing; document.querySelectorAll('.es-wchip').forEach(function (x) { x.classList.toggle('on', x.dataset.wing === fWing); }); filter(); }); });
  filter();
  window.addEventListener('beforeunload', function (e) { if (document.querySelector('.es-saved.busy')) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
