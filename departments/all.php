<?php
$pageTitle = 'सर्व विभाग — Showroom';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/dept-health.php';

// फक्त संपूर्ण संस्था पाहू शकणाऱ्यांसाठी; बाकीच्यांना स्वतःचा विभाग
if (!isManagementOrAbove() || !hasPermission('view_org_wide_dashboards')) {
    $own = (int)($user['department_id'] ?? 0);
    header('Location: ' . ($own ? 'dashboard.php?id=' . $own : '../index.php'));
    exit;
}

$orgId = activeOrgId();
$st = $pdo->prepare("SELECT d.*, u.name AS hod_name, c.name AS campus_name, c.college_type
    FROM departments d LEFT JOIN users u ON u.id = d.hod_id LEFT JOIN campuses c ON c.id = d.campus_id
    WHERE d.organization_id = ? AND d.is_active = 1
    ORDER BY (c.college_type = 'Trust HQ') DESC, d.campus_id IS NULL DESC, c.name, d.name_local");
$st->execute([$orgId]);
$departments = $st->fetchAll();

$rows = deptHealthRows($pdo, $orgId, $departments);
$sum = deptHealthSummary($rows);
$trend = deptHealthTrend($pdo, $orgId, array_column($rows, 'id'), 14);
$staffCount = [];
try {
    $q = $pdo->prepare("SELECT department_id, COUNT(*) c FROM users WHERE organization_id = ? AND is_active = 1 AND department_id IS NOT NULL GROUP BY department_id");
    $q->execute([$orgId]);
    foreach ($q->fetchAll() as $r) $staffCount[(int)$r['department_id']] = (int)$r['c'];
} catch (PDOException $e) {}
foreach ($rows as &$r) { $r['staff'] = $staffCount[$r['id']] ?? 0; $r['trend'] = $trend[$r['id']] ?? []; }
unset($r);
$groups = array_values(array_unique(array_column($rows, 'group')));
$levelName = ['good' => 'उत्तम', 'warn' => 'लक्ष द्या', 'bad' => 'गंभीर'];

/** 14 दिवसांची छोटी रेषा */
function showroomSpark(array $s, $w = 132, $h = 34) {
    if (!$s) return '';
    $max = max(1, max($s));
    $n = count($s);
    $pts = [];
    foreach ($s as $i => $v) $pts[] = round($i * $w / max(1, $n - 1), 1) . ',' . round($h - 3 - ($v / $max) * ($h - 8), 1);
    $line = implode(' ', $pts);
    return '<svg class="sr-spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
        . '<polygon points="0,' . $h . ' ' . $line . ' ' . $w . ',' . $h . '" class="a"/>'
        . '<polyline points="' . $line . '" class="l"/></svg>';
}
$C = 2 * M_PI * 42; // ring परिघ (r = 42)
?>
<style>
  .sr-hero{position:relative;overflow:hidden;border-radius:20px;padding:26px;margin-bottom:18px;color:#EAF3F1;
    background:radial-gradient(ellipse 90% 120% at 0% 0%,#1B3A4A 0%,#121A30 55%,#0C1120 100%);box-shadow:0 18px 40px rgba(10,14,26,.25);}
  .sr-hero::before,.sr-hero::after{content:'';position:absolute;border-radius:50%;filter:blur(60px);opacity:.35;pointer-events:none;animation:srDrift 14s ease-in-out infinite alternate;}
  .sr-hero::before{width:340px;height:340px;background:#1B8A83;top:-140px;right:10%;}
  .sr-hero::after{width:300px;height:300px;background:#C97F1D;bottom:-160px;left:20%;animation-delay:-7s;}
  @keyframes srDrift{to{transform:translate(40px,20px) scale(1.1);}}
  .sr-hero-in{position:relative;z-index:1;display:grid;grid-template-columns:auto 1fr auto;gap:26px;align-items:center;}
  .sr-gauge{width:170px;height:170px;position:relative;}
  .sr-gauge svg{width:100%;height:100%;transform:rotate(-90deg);}
  .sr-gauge .bg{stroke:rgba(255,255,255,.1);} .sr-gauge .fg{stroke:url(#srGrad);stroke-linecap:round;transition:stroke-dashoffset 1.6s cubic-bezier(.2,.8,.2,1);}
  .sr-gauge-c{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;}
  .sr-gauge-c b{font-family:'JetBrains Mono',monospace;font-size:46px;line-height:1;}
  .sr-gauge-c small{font-size:12px;opacity:.75;margin-top:4px;}
  .sr-lvl{display:inline-block;margin-top:6px;font-size:11.5px;font-weight:700;padding:2px 10px;border-radius:20px;}
  .sr-lvl.good{background:rgba(47,179,168,.2);color:#5FD3C7;} .sr-lvl.warn{background:rgba(224,153,74,.2);color:#F0B35A;} .sr-lvl.bad{background:rgba(224,96,112,.2);color:#F08A97;}
  .sr-hero h1{font-size:24px;font-weight:700;margin:0 0 4px;color:#fff;}
  .sr-hero .sub{font-size:13px;color:#A9B1CB;margin-bottom:14px;}
  .sr-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(104px,1fr));gap:10px;}
  .sr-kpi{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:10px 12px;backdrop-filter:blur(6px);}
  .sr-kpi .n{font-family:'JetBrains Mono',monospace;font-size:22px;font-weight:700;color:#fff;}
  .sr-kpi .l{font-size:11.5px;color:#A9B1CB;} .sr-kpi .x{font-size:11px;color:#F08A97;font-weight:600;}
  .sr-side{display:flex;flex-direction:column;gap:10px;min-width:190px;}
  .sr-dist{display:flex;height:10px;border-radius:10px;overflow:hidden;gap:2px;background:rgba(255,255,255,.1);}
  .sr-dist i{display:block;transform-origin:left;animation:srGrow 1.2s ease-out both;}
  .sr-dist .good{background:#2FB3A8;} .sr-dist .warn{background:#E0994A;animation-delay:.15s;} .sr-dist .bad{background:#E06070;animation-delay:.3s;}
  @keyframes srGrow{from{transform:scaleX(0);}}
  .sr-dist-l{display:flex;justify-content:space-between;font-size:11.5px;color:#A9B1CB;} .sr-dist-l b{color:#fff;font-family:'JetBrains Mono',monospace;}
  .sr-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:12px;padding:10px 14px;font-weight:700;font-size:13px;cursor:pointer;font-family:inherit;text-decoration:none;}
  .sr-btn.play{background:linear-gradient(135deg,#2FB3A8,#1B8A83);color:#fff;box-shadow:0 8px 20px rgba(27,138,131,.4);}
  .sr-btn.play:hover{filter:brightness(1.08);transform:translateY(-1px);}
  .sr-btn.ghost{background:rgba(255,255,255,.08);color:#DCEFEC;border:1px solid rgba(255,255,255,.14);}

  .sr-insights{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:18px;}
  .sr-ins{display:flex;gap:12px;align-items:center;background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:12px 14px;text-decoration:none;color:inherit;transition:transform .15s,box-shadow .15s;}
  .sr-ins:hover{transform:translateY(-2px);box-shadow:var(--shadow-md);color:inherit;}
  .sr-ins .ic{width:38px;height:38px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
  .sr-ins .t{font-size:11.5px;color:var(--text-dim);} .sr-ins .v{font-size:14px;font-weight:700;}

  .sr-tools{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px;}
  .sr-tools input,.sr-tools select{border:1px solid var(--line);border-radius:10px;padding:8px 12px;font-size:13px;background:var(--surface);color:var(--text);font-family:inherit;}
  .sr-tools input{min-width:200px;flex:1;max-width:280px;}
  .sr-chip{border:1px solid var(--line);background:var(--surface);color:var(--text-dim);border-radius:20px;padding:6px 12px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;display:inline-flex;gap:6px;align-items:center;}
  .sr-chip.on{border-color:var(--rail);color:var(--rail);background:var(--rail-soft);}
  .sr-chip b{font-family:'JetBrains Mono',monospace;}
  .sr-view{margin-left:auto;display:inline-flex;border:1px solid var(--line);border-radius:10px;overflow:hidden;}
  .sr-view button{border:0;background:var(--surface);color:var(--text-dim);padding:7px 12px;cursor:pointer;font-size:13px;}
  .sr-view button.on{background:var(--rail-soft);color:var(--rail);}

  .sr-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:16px;perspective:1200px;}
  .sr-card{--lc:var(--rail);position:relative;display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--line);border-radius:18px;padding:16px;text-decoration:none;color:inherit;
    transform-style:preserve-3d;transition:transform .25s ease,box-shadow .25s ease,opacity .5s ease;opacity:0;transform:translateY(26px) scale(.97);}
  .sr-card.in{opacity:1;transform:none;}
  .sr-card.warn{--lc:var(--amber);} .sr-card.bad{--lc:var(--rose);}
  .sr-card::before{content:'';position:absolute;inset:0 0 auto 0;height:4px;border-radius:18px 18px 0 0;background:var(--lc);}
  .sr-card:hover{box-shadow:0 18px 40px color-mix(in srgb,var(--lc) 22%,transparent);color:inherit;}
  .sr-card-top{display:flex;gap:12px;align-items:center;}
  .sr-ic{width:44px;height:44px;border-radius:13px;background:color-mix(in srgb,var(--lc) 14%,var(--surface));color:var(--lc);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;transform:translateZ(30px);}
  .sr-name{font-weight:700;font-size:15px;line-height:1.3;}
  .sr-grp{font-size:11.5px;color:var(--text-dim);}
  .sr-ring{width:64px;height:64px;margin-left:auto;position:relative;flex-shrink:0;transform:translateZ(40px);}
  .sr-ring svg{width:100%;height:100%;transform:rotate(-90deg);}
  .sr-ring .bg{stroke:var(--line);} .sr-ring .fg{stroke:var(--lc);stroke-linecap:round;transition:stroke-dashoffset 1.4s cubic-bezier(.2,.8,.2,1);}
  .sr-ring span{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-family:'JetBrains Mono',monospace;font-weight:700;font-size:16px;color:var(--lc);}
  .sr-hod{font-size:12px;color:var(--text-dim);margin:10px 0 6px;}
  .sr-spark{width:100%;height:38px;display:block;}
  .sr-spark .a{fill:color-mix(in srgb,var(--lc) 14%,transparent);} .sr-spark .l{fill:none;stroke:var(--lc);stroke-width:2;vector-effect:non-scaling-stroke;stroke-dasharray:3000;stroke-dashoffset:3000;transition:stroke-dashoffset 1.6s ease .3s;}
  .sr-card.in .sr-spark .l{stroke-dashoffset:0;}
  .sr-spark-l{font-size:10.5px;color:var(--text-dim);display:flex;justify-content:space-between;margin-top:2px;}
  .sr-meta{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:10px;}
  .sr-meta div{background:var(--canvas);border-radius:10px;padding:7px 8px;text-align:center;}
  .sr-meta b{display:block;font-family:'JetBrains Mono',monospace;font-size:15px;}
  .sr-meta span{font-size:10.5px;color:var(--text-dim);}
  .sr-meta .hot b{color:var(--rose);}
  .sr-issues{display:flex;flex-wrap:wrap;gap:4px;margin-top:10px;}
  .sr-issues span{font-size:10.5px;font-weight:600;background:var(--rose-soft);color:var(--rose);border-radius:6px;padding:2px 7px;}
  .sr-go{margin-top:auto;padding-top:12px;font-size:12.5px;font-weight:700;color:var(--lc);display:flex;justify-content:space-between;align-items:center;}
  .sr-go i{transition:transform .2s;} .sr-card:hover .sr-go i{transform:translateX(5px);}

  .sr-rank{display:none;background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:8px 16px;}
  .sr-rank.on{display:block;} .sr-grid.off{display:none;}
  .sr-rrow{display:grid;grid-template-columns:28px minmax(120px,1.4fr) 3fr 46px;gap:12px;align-items:center;padding:9px 0;border-bottom:1px solid var(--line);text-decoration:none;color:inherit;}
  .sr-rrow:last-child{border-bottom:0;}
  .sr-rrow .pos{font-family:'JetBrains Mono',monospace;color:var(--text-dim);font-weight:700;}
  .sr-rrow .nm{font-weight:600;font-size:13.5px;} .sr-rrow .nm small{display:block;font-weight:400;color:var(--text-dim);font-size:11px;}
  .sr-rbar{height:12px;border-radius:12px;background:var(--line);overflow:hidden;}
  .sr-rbar i{display:block;height:100%;width:0;border-radius:12px;background:var(--lc);transition:width 1.2s cubic-bezier(.2,.8,.2,1);}
  .sr-rrow.warn{--lc:var(--amber);} .sr-rrow.bad{--lc:var(--rose);} .sr-rrow{--lc:var(--rail);}
  .sr-rrow .sc{font-family:'JetBrains Mono',monospace;font-weight:700;color:var(--lc);text-align:right;}
  .sr-none{text-align:center;color:var(--text-dim);padding:30px;}

  /* ── Showroom Mode ── */
  .sr-stage{position:fixed;inset:0;z-index:2000;display:none;flex-direction:column;color:#EAF3F1;
    background:radial-gradient(ellipse 100% 80% at 50% 0%,#1B2340,#0A0E1A 70%);}
  .sr-stage.open{display:flex;}
  .sr-stage-top{display:flex;justify-content:space-between;align-items:center;padding:18px 26px;}
  .sr-stage-top .org{font-size:13px;color:#A9B1CB;} .sr-stage-top .clock{font-family:'JetBrains Mono',monospace;font-size:14px;color:#DCEFEC;}
  .sr-slide{flex:1;display:flex;align-items:center;justify-content:center;padding:10px 40px;}
  .sr-slide-in{display:grid;grid-template-columns:auto 1fr;gap:56px;align-items:center;max-width:1100px;width:100%;animation:srSlideIn .8s cubic-bezier(.2,.8,.2,1) both;}
  @keyframes srSlideIn{from{opacity:0;transform:translateX(60px) scale(.97);}}
  .sr-big{width:min(34vw,320px);height:min(34vw,320px);position:relative;}
  .sr-big svg{width:100%;height:100%;transform:rotate(-90deg);}
  .sr-big .bg{stroke:rgba(255,255,255,.08);} .sr-big .fg{stroke-linecap:round;transition:stroke-dashoffset 1.6s cubic-bezier(.2,.8,.2,1);}
  .sr-big-c{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;}
  .sr-big-c b{font-family:'JetBrains Mono',monospace;font-size:clamp(48px,8vw,92px);line-height:1;}
  .sr-big-c small{font-size:14px;color:#A9B1CB;margin-top:6px;}
  .sr-s-ic{width:64px;height:64px;border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:28px;margin-bottom:14px;}
  .sr-s-name{font-size:clamp(28px,4vw,46px);font-weight:700;color:#fff;line-height:1.15;}
  .sr-s-sub{font-size:15px;color:#A9B1CB;margin:6px 0 22px;}
  .sr-s-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;max-width:620px;}
  .sr-s-kpis div{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:14px;}
  .sr-s-kpis b{display:block;font-family:'JetBrains Mono',monospace;font-size:clamp(22px,2.6vw,32px);color:#fff;}
  .sr-s-kpis span{font-size:12.5px;color:#A9B1CB;}
  .sr-s-kpis .hot b{color:#F08A97;}
  .sr-s-spark{max-width:620px;margin-top:18px;} .sr-s-spark .sr-spark{height:70px;} .sr-s-spark .sr-spark .l{stroke-dasharray:none;stroke-width:3;}
  .sr-s-issues{display:flex;gap:6px;flex-wrap:wrap;margin-top:14px;} .sr-s-issues span{background:rgba(224,96,112,.18);color:#F08A97;border-radius:8px;padding:4px 10px;font-size:13px;font-weight:600;}
  .sr-stage-bot{padding:14px 26px 22px;}
  .sr-prog{height:4px;background:rgba(255,255,255,.1);border-radius:4px;overflow:hidden;margin-bottom:14px;}
  .sr-prog i{display:block;height:100%;width:0;background:linear-gradient(90deg,#2FB3A8,#F0B35A);}
  .sr-ctrl{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;}
  .sr-ctrl .btns{display:flex;gap:8px;}
  .sr-ctrl button{width:42px;height:42px;border-radius:50%;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.06);color:#fff;cursor:pointer;font-size:15px;}
  .sr-ctrl button:hover{background:rgba(255,255,255,.14);}
  .sr-dots{display:flex;gap:6px;flex-wrap:wrap;justify-content:center;flex:1;}
  .sr-dots i{width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,.2);cursor:pointer;transition:.3s;}
  .sr-dots i.on{width:24px;border-radius:8px;background:#2FB3A8;}
  .sr-ctrl .count{font-family:'JetBrains Mono',monospace;color:#A9B1CB;font-size:13px;min-width:60px;text-align:right;}

  @media (max-width:991px){.sr-hero-in{grid-template-columns:1fr;justify-items:center;text-align:center;}.sr-side{width:100%;}.sr-slide-in{grid-template-columns:1fr;justify-items:center;text-align:center;gap:24px;}.sr-s-kpis{margin:0 auto;}.sr-s-spark{margin:18px auto 0;}.sr-s-issues{justify-content:center;}}
  @media (max-width:575px){.sr-hero{padding:18px;}.sr-gauge{width:140px;height:140px;}.sr-gauge-c b{font-size:38px;}.sr-tools input{max-width:none;}.sr-view{margin-left:0;}.sr-rrow{grid-template-columns:24px 1fr 46px;}.sr-rrow .sr-rbar{grid-column:2 / 4;}.sr-slide{padding:10px 18px;}.sr-s-kpis{grid-template-columns:repeat(2,minmax(0,1fr));}}
  @media (prefers-reduced-motion:reduce){.sr-hero::before,.sr-hero::after,.sr-dist i,.sr-slide-in{animation:none;}.sr-card{opacity:1;transform:none;transition:none;}.sr-spark .l{stroke-dashoffset:0;transition:none;}}
  @media print{.sr-tools,.sr-btn,.sr-stage,.sr-view{display:none !important;}.sr-card{opacity:1;transform:none;break-inside:avoid;}.sr-hero{box-shadow:none;}}
</style>

<div class="sr-hero">
  <div class="sr-hero-in">
    <div class="sr-gauge" title="सर्व विभागांचे सरासरी आरोग्य गुण">
      <svg viewBox="0 0 100 100" aria-hidden="true">
        <defs><linearGradient id="srGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#2FB3A8"/><stop offset="1" stop-color="#F0B35A"/></linearGradient></defs>
        <circle class="bg" cx="50" cy="50" r="42" fill="none" stroke-width="9"/>
        <circle class="fg" id="srGaugeFg" cx="50" cy="50" r="42" fill="none" stroke-width="9" stroke-dasharray="<?= round($C, 2) ?>" stroke-dashoffset="<?= round($C, 2) ?>" data-p="<?= $sum['score'] ?>"/>
      </svg>
      <div class="sr-gauge-c"><b data-count="<?= $sum['score'] ?>"><?= $sum['score'] ?></b><small>एकत्रित आरोग्य</small><span class="sr-lvl <?= $sum['level'] ?>"><?= $levelName[$sum['level']] ?></span></div>
    </div>
    <div>
      <h1><i class="fa-solid fa-layer-group"></i> सर्व विभाग</h1>
      <div class="sub"><?= $sum['count'] ?> विभाग · <?= count($groups) ?> Campus · मागील 30 दिवसांची पूर्णता, मुदतबाह्य Tasks, SLA व नूतनीकरण यांवरून गुण</div>
      <div class="sr-kpis">
        <div class="sr-kpi"><div class="n" data-count="<?= $sum['today'] ?>"><?= $sum['today'] ?></div><div class="l">आज नोंदवलेली कामं</div><?php if ($sum['idleToday']): ?><div class="x"><?= $sum['idleToday'] ?> विभाग शांत</div><?php endif; ?></div>
        <div class="sr-kpi"><div class="n"><?= $sum['rate'] === null ? '—' : '<span data-count="' . $sum['rate'] . '">' . $sum['rate'] . '</span>%' ?></div><div class="l">पूर्णता (30 दिवस)</div></div>
        <div class="sr-kpi"><div class="n" data-count="<?= $sum['openTasks'] ?>"><?= $sum['openTasks'] ?></div><div class="l">उघडे Tasks</div><?php if ($sum['overdueTasks']): ?><div class="x"><?= $sum['overdueTasks'] ?> मुदतबाह्य</div><?php endif; ?></div>
        <div class="sr-kpi"><div class="n" data-count="<?= $sum['openReq'] ?>"><?= $sum['openReq'] ?></div><div class="l">उघड्या विनंत्या</div><?php if ($sum['overdueReq']): ?><div class="x"><?= $sum['overdueReq'] ?> SLA बाहेर</div><?php endif; ?></div>
        <div class="sr-kpi"><div class="n" data-count="<?= $sum['renDue'] ?>"><?= $sum['renDue'] ?></div><div class="l">नूतनीकरण (30 दिवस)</div><?php if ($sum['expired']): ?><div class="x"><?= $sum['expired'] ?> संपलेली</div><?php endif; ?></div>
      </div>
    </div>
    <div class="sr-side">
      <div class="sr-dist"><i class="good" style="flex:<?= $sum['good'] ?>"></i><i class="warn" style="flex:<?= $sum['warn'] ?>"></i><i class="bad" style="flex:<?= $sum['bad'] ?>"></i></div>
      <div class="sr-dist-l"><span>उत्तम <b><?= $sum['good'] ?></b></span><span>लक्ष द्या <b><?= $sum['warn'] ?></b></span><span>गंभीर <b><?= $sum['bad'] ?></b></span></div>
      <?php if ($rows): ?><button type="button" class="sr-btn play" id="srPlay"><i class="fa-solid fa-play"></i> Showroom Mode</button><?php endif; ?>
      <a href="../index.php" class="sr-btn ghost"><i class="fa-solid fa-arrow-left"></i> Dashboard</a>
    </div>
  </div>
</div>

<?php if ($rows): ?>
<div class="sr-insights">
  <?php if ($sum['best']): ?>
    <a class="sr-ins" href="dashboard.php?id=<?= $sum['best']['id'] ?>"><span class="ic" style="background:var(--rail-soft);color:var(--rail);"><i class="fa-solid fa-trophy"></i></span>
      <div><div class="t">सर्वोत्तम कामगिरी</div><div class="v"><?= h($sum['best']['name']) ?> · <?= $sum['best']['score'] ?></div></div></a>
  <?php endif; ?>
  <?php if ($sum['worst'] && $sum['worst']['score'] < 75): ?>
    <a class="sr-ins" href="dashboard.php?id=<?= $sum['worst']['id'] ?>"><span class="ic" style="background:var(--rose-soft);color:var(--rose);"><i class="fa-solid fa-triangle-exclamation"></i></span>
      <div><div class="t">सर्वात जास्त लक्ष आवश्यक</div><div class="v"><?= h($sum['worst']['name']) ?> · <?= $sum['worst']['score'] ?></div></div></a>
  <?php endif; ?>
  <div class="sr-ins"><span class="ic" style="background:var(--amber-soft);color:var(--amber);"><i class="fa-solid fa-moon"></i></span>
    <div><div class="t">आज अजून काम न नोंदवलेले</div><div class="v"><?= $sum['idleToday'] ?> / <?= $sum['count'] ?> विभाग</div></div></div>
  <div class="sr-ins"><span class="ic" style="background:var(--rail-soft);color:var(--rail);"><i class="fa-solid fa-clipboard-check"></i></span>
    <div><div class="t">पूर्ण कामं (30 दिवस)</div><div class="v"><?= number_format($sum['done30']) ?> / <?= number_format($sum['total30']) ?></div></div></div>
</div>

<div class="sr-tools">
  <input type="search" id="srSearch" placeholder="विभाग / HOD शोधा…" aria-label="विभाग शोधा">
  <button type="button" class="sr-chip on" data-level="">सर्व <b><?= $sum['count'] ?></b></button>
  <button type="button" class="sr-chip" data-level="good"><span class="sm-lv good"></span>उत्तम <b><?= $sum['good'] ?></b></button>
  <button type="button" class="sr-chip" data-level="warn"><span class="sm-lv warn"></span>लक्ष द्या <b><?= $sum['warn'] ?></b></button>
  <button type="button" class="sr-chip" data-level="bad"><span class="sm-lv bad"></span>गंभीर <b><?= $sum['bad'] ?></b></button>
  <?php if (count($groups) > 1): ?>
    <select id="srGroup" aria-label="Campus"><option value="">सर्व Campus</option><?php foreach ($groups as $g): ?><option value="<?= h($g) ?>"><?= h($g) ?></option><?php endforeach; ?></select>
  <?php endif; ?>
  <select id="srSort" aria-label="क्रम">
    <option value="attn">लक्ष आवश्यक आधी</option>
    <option value="best">उत्तम कामगिरी आधी</option>
    <option value="today">आजची कामं जास्त आधी</option>
    <option value="name">नावानुसार</option>
  </select>
  <div class="sr-view" role="group" aria-label="दृश्य">
    <button type="button" class="on" data-view="grid" title="Cards"><i class="fa-solid fa-grip"></i></button>
    <button type="button" data-view="rank" title="क्रमवारी"><i class="fa-solid fa-ranking-star"></i></button>
  </div>
</div>

<div class="sr-grid" id="srGrid">
  <?php foreach ($rows as $r): $off = round($C * (1 - $r['score'] / 100), 2); $mx = $r['trend'] ? max($r['trend']) : 0; ?>
    <a class="sr-card <?= $r['level'] ?>" href="dashboard.php?id=<?= $r['id'] ?>" data-id="<?= $r['id'] ?>"
       data-name="<?= h(mb_strtolower($r['name'] . ' ' . $r['hod'])) ?>" data-group="<?= h($r['group']) ?>" data-level="<?= $r['level'] ?>"
       data-score="<?= $r['score'] ?>" data-today="<?= $r['today'] ?>">
      <div class="sr-card-top">
        <span class="sr-ic"><i class="<?= h($r['icon']) ?>"></i></span>
        <div style="min-width:0;"><div class="sr-name"><?= h($r['name']) ?></div><div class="sr-grp"><?= h($r['group']) ?></div></div>
        <div class="sr-ring" title="आरोग्य गुण">
          <svg viewBox="0 0 100 100" aria-hidden="true"><circle class="bg" cx="50" cy="50" r="42" fill="none" stroke-width="10"/>
            <circle class="fg" cx="50" cy="50" r="42" fill="none" stroke-width="10" stroke-dasharray="<?= round($C, 2) ?>" stroke-dashoffset="<?= round($C, 2) ?>" data-off="<?= $off ?>"/></svg>
          <span><?= $r['score'] ?></span>
        </div>
      </div>
      <div class="sr-hod"><i class="fa-solid fa-user-tie"></i> <?= $r['hod'] ? h($r['hod']) : 'HOD नेमलेले नाहीत' ?> · <i class="fa-solid fa-users"></i> <?= $r['staff'] ?></div>
      <?= showroomSpark($r['trend']) ?>
      <div class="sr-spark-l"><span>14 दिवस</span><span>कमाल <?= $mx ?>/दिवस</span></div>
      <div class="sr-meta">
        <div><b><?= $r['today'] ?></b><span>आज</span></div>
        <div><b><?= $r['rate'] === null ? '—' : $r['rate'] . '%' ?></b><span>पूर्णता</span></div>
        <div class="<?= $r['overdueTasks'] ? 'hot' : '' ?>"><b><?= $r['openTasks'] ?></b><span>Tasks</span></div>
        <div class="<?= $r['overdueReq'] ? 'hot' : '' ?>"><b><?= $r['openReq'] ?></b><span>विनंत्या</span></div>
        <div class="<?= $r['expired'] ? 'hot' : '' ?>"><b><?= $r['renDue'] + $r['expired'] ?></b><span>नूतनीकरण</span></div>
        <div><b><?= $r['total30'] ?></b><span>30 दिवस</span></div>
      </div>
      <?php if ($r['issues']): ?><div class="sr-issues"><?php foreach ($r['issues'] as $is): ?><span><?= h($is) ?></span><?php endforeach; ?></div><?php endif; ?>
      <div class="sr-go"><span><?= $levelName[$r['level']] ?></span><span>विभाग Dashboard उघडा <i class="fa-solid fa-arrow-right"></i></span></div>
    </a>
  <?php endforeach; ?>
</div>

<div class="sr-rank" id="srRank">
  <?php $ranked = $rows; usort($ranked, fn($a, $b) => $b['score'] <=> $a['score']); foreach ($ranked as $i => $r): ?>
    <a class="sr-rrow <?= $r['level'] ?>" href="dashboard.php?id=<?= $r['id'] ?>" data-id="<?= $r['id'] ?>">
      <span class="pos"><?= $i + 1 ?></span>
      <span class="nm"><?= h($r['name']) ?><small><?= h($r['group']) ?></small></span>
      <span class="sr-rbar"><i data-w="<?= $r['score'] ?>"></i></span>
      <span class="sc"><?= $r['score'] ?></span>
    </a>
  <?php endforeach; ?>
</div>
<div class="sr-none" id="srNone" hidden>शोधाशी जुळणारा विभाग नाही</div>

<!-- Showroom Mode -->
<div class="sr-stage" id="srStage" role="dialog" aria-modal="true" aria-label="Showroom Mode">
  <div class="sr-stage-top"><span class="org"><i class="fa-solid fa-layer-group"></i> <?= h(activeOrgName()) ?> — सर्व विभाग</span><span class="clock" id="srClock"></span></div>
  <div class="sr-slide" id="srSlide"></div>
  <div class="sr-stage-bot">
    <div class="sr-prog"><i id="srProg"></i></div>
    <div class="sr-ctrl">
      <div class="btns">
        <button type="button" id="srPrev" aria-label="मागे"><i class="fa-solid fa-backward-step"></i></button>
        <button type="button" id="srPause" aria-label="थांबवा"><i class="fa-solid fa-pause"></i></button>
        <button type="button" id="srNext" aria-label="पुढे"><i class="fa-solid fa-forward-step"></i></button>
      </div>
      <div class="sr-dots" id="srDots"></div>
      <div class="btns"><span class="count" id="srCount"></span><button type="button" id="srClose" aria-label="बंद करा"><i class="fa-solid fa-xmark"></i></button></div>
    </div>
  </div>
</div>
<script type="application/json" id="srData"><?= json_encode([
    'sum' => ['score' => $sum['score'], 'level' => $sum['level'], 'count' => $sum['count'], 'today' => $sum['today'], 'rate' => $sum['rate'],
              'openTasks' => $sum['openTasks'], 'overdueTasks' => $sum['overdueTasks'], 'openReq' => $sum['openReq'], 'good' => $sum['good'], 'warn' => $sum['warn'], 'bad' => $sum['bad']],
    'rows' => array_map(fn($r) => array_intersect_key($r, array_flip(['id', 'name', 'icon', 'hod', 'group', 'today', 'rate', 'openTasks', 'overdueTasks', 'openReq', 'overdueReq', 'renDue', 'expired', 'score', 'level', 'issues', 'staff', 'trend'])), $rows),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php else: ?>
  <div class="panel"><div class="sr-none">अजून एकही सक्रिय विभाग नाही.</div></div>
<?php endif; ?>

<script>
(function () {
  'use strict';
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var C = <?= json_encode(round($C, 2)) ?>;
  var fmt = function (n) { return new Intl.NumberFormat('en-IN').format(n); };
  function countUp(el) {
    var end = +el.getAttribute('data-count'), t0 = null;
    if (reduce || !end) { el.textContent = fmt(end); return; }
    (function step(t) { if (!t0) t0 = t; var p = Math.min((t - t0) / 1300, 1); el.textContent = fmt(Math.round(end * (1 - Math.pow(1 - p, 3)))); if (p < 1) requestAnimationFrame(step); })(performance.now());
  }

  // Hero gauge + counters
  var g = document.getElementById('srGaugeFg');
  if (g) requestAnimationFrame(function () { g.style.strokeDashoffset = C * (1 - (+g.getAttribute('data-p')) / 100); });
  document.querySelectorAll('.sr-hero [data-count]').forEach(countUp);

  var grid = document.getElementById('srGrid');
  if (!grid) return;
  var cards = Array.prototype.slice.call(grid.querySelectorAll('.sr-card'));

  // Fly-in + ring animation जसे दिसतील तसे
  function show(c, i) {
    setTimeout(function () {
      c.classList.add('in');
      var fg = c.querySelector('.sr-ring .fg'); if (fg) fg.style.strokeDashoffset = fg.getAttribute('data-off');
    }, reduce ? 0 : (i % 8) * 70);
  }
  if ('IntersectionObserver' in window && !reduce) {
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.isIntersecting) { show(e.target, cards.indexOf(e.target)); io.unobserve(e.target); } });
    }, { threshold: 0.1 });
    cards.forEach(function (c) { io.observe(c); });
  } else cards.forEach(show);

  // 3D Tilt
  if (!reduce && matchMedia('(hover: hover)').matches) cards.forEach(function (c) {
    c.addEventListener('mousemove', function (e) {
      var b = c.getBoundingClientRect(), x = (e.clientX - b.left) / b.width - .5, y = (e.clientY - b.top) / b.height - .5;
      c.style.transform = 'rotateY(' + (x * 8) + 'deg) rotateX(' + (-y * 8) + 'deg) translateY(-4px)';
    });
    c.addEventListener('mouseleave', function () { c.style.transform = ''; });
  });

  // Filter / Sort / View
  var search = document.getElementById('srSearch'), group = document.getElementById('srGroup'), sort = document.getElementById('srSort');
  var chips = document.querySelectorAll('.sr-chip'), level = '', none = document.getElementById('srNone');
  var rank = document.getElementById('srRank'), rrows = rank.querySelectorAll('.sr-rrow');
  function visible(c) {
    var q = search.value.trim().toLowerCase();
    return (!q || c.getAttribute('data-name').indexOf(q) > -1) && (!group || !group.value || c.getAttribute('data-group') === group.value) && (!level || c.getAttribute('data-level') === level);
  }
  function apply() {
    var shownIds = {}, n = 0;
    cards.forEach(function (c) { var ok = visible(c); c.style.display = ok ? '' : 'none'; if (ok) { shownIds[c.getAttribute('data-id')] = 1; n++; show(c, 0); } });
    rrows.forEach(function (r) { r.style.display = shownIds[r.getAttribute('data-id')] ? '' : 'none'; });
    none.hidden = n > 0;
  }
  function order() {
    var m = sort.value;
    cards.sort(function (a, b) {
      if (m === 'name') return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
      if (m === 'today') return (+b.getAttribute('data-today')) - (+a.getAttribute('data-today'));
      var d = (+a.getAttribute('data-score')) - (+b.getAttribute('data-score'));
      return m === 'best' ? -d : d;
    });
    cards.forEach(function (c) { grid.appendChild(c); });
    try { localStorage.setItem('eowmsShowroomSort', m); } catch (e) {}
  }
  search.addEventListener('input', apply);
  if (group) group.addEventListener('change', apply);
  try { var sv = localStorage.getItem('eowmsShowroomSort'); if (sv) sort.value = sv; } catch (e) {}
  sort.addEventListener('change', order);
  chips.forEach(function (b) { b.addEventListener('click', function () { level = b.getAttribute('data-level'); chips.forEach(function (o) { o.classList.toggle('on', o === b); }); apply(); }); });
  document.querySelectorAll('.sr-view button').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = b.getAttribute('data-view');
      document.querySelectorAll('.sr-view button').forEach(function (o) { o.classList.toggle('on', o === b); });
      grid.classList.toggle('off', v === 'rank'); rank.classList.toggle('on', v === 'rank');
      if (v === 'rank') rrows.forEach(function (r, i) { var bar = r.querySelector('.sr-rbar i'); setTimeout(function () { bar.style.width = bar.getAttribute('data-w') + '%'; }, reduce ? 0 : i * 40); });
    });
  });
  order();

  // ── Showroom Mode — एकेक विभाग मोठ्या पडद्यावर, आपोआप पुढे ──
  var data = {}; try { data = JSON.parse(document.getElementById('srData').textContent); } catch (e) {}
  var stage = document.getElementById('srStage'), slide = document.getElementById('srSlide'), prog = document.getElementById('srProg');
  var dotsEl = document.getElementById('srDots'), countEl = document.getElementById('srCount'), pauseBtn = document.getElementById('srPause');
  var colors = { good: '#2FB3A8', warn: '#E0994A', bad: '#E06070' }, names = { good: 'उत्तम', warn: 'लक्ष द्या', bad: 'गंभीर' };
  var DUR = 7000, idx = 0, playing = true, t0 = 0, raf = null, list = [];
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function spark(s) {
    if (!s || !s.length) return '';
    var w = 620, h = 70, mx = Math.max.apply(null, s.concat([1])), pts = s.map(function (v, i) { return (i * w / (s.length - 1)).toFixed(1) + ',' + (h - 4 - v / mx * (h - 10)).toFixed(1); }).join(' ');
    return '<svg class="sr-spark" viewBox="0 0 ' + w + ' ' + h + '" preserveAspectRatio="none" style="--lc:currentColor"><polygon class="a" points="0,' + h + ' ' + pts + ' ' + w + ',' + h + '"/><polyline class="l" points="' + pts + '"/></svg>';
  }
  function ring(score, color) {
    return '<div class="sr-big"><svg viewBox="0 0 100 100"><circle class="bg" cx="50" cy="50" r="42" fill="none" stroke-width="8"/><circle class="fg" cx="50" cy="50" r="42" fill="none" stroke-width="8" stroke="' + color + '" stroke-dasharray="' + C + '" stroke-dashoffset="' + C + '" data-off="' + (C * (1 - score / 100)) + '"/></svg><div class="sr-big-c"><b data-count="' + score + '">0</b><small>आरोग्य गुण</small></div></div>';
  }
  function slideAll() {
    var s = data.sum, c = colors[s.level];
    return '<div class="sr-slide-in">' + ring(s.score, c) + '<div><div class="sr-s-ic" style="background:rgba(47,179,168,.18);color:#5FD3C7"><i class="fa-solid fa-layer-group"></i></div>'
      + '<div class="sr-s-name">सर्व विभाग</div><div class="sr-s-sub">' + s.count + ' विभागांचं एकत्रित आरोग्य · ' + names[s.level] + '</div>'
      + '<div class="sr-s-kpis"><div><b data-count="' + s.today + '">0</b><span>आज नोंदवलेली कामं</span></div><div><b>' + (s.rate == null ? '—' : s.rate + '%') + '</b><span>पूर्णता (30 दिवस)</span></div>'
      + '<div class="' + (s.overdueTasks ? 'hot' : '') + '"><b data-count="' + s.openTasks + '">0</b><span>उघडे Tasks</span></div>'
      + '<div><b style="color:#5FD3C7">' + s.good + '</b><span>उत्तम</span></div><div><b style="color:#F0B35A">' + s.warn + '</b><span>लक्ष द्या</span></div><div><b style="color:#F08A97">' + s.bad + '</b><span>गंभीर</span></div></div></div></div>';
  }
  function slideDept(r) {
    var c = colors[r.level];
    return '<div class="sr-slide-in">' + ring(r.score, c) + '<div><div class="sr-s-ic" style="background:' + c + '22;color:' + c + '"><i class="' + esc(r.icon) + '"></i></div>'
      + '<div class="sr-s-name">' + esc(r.name) + '</div><div class="sr-s-sub">' + esc(r.group) + ' · HOD: ' + (r.hod ? esc(r.hod) : '—') + ' · ' + r.staff + ' कर्मचारी · ' + names[r.level] + '</div>'
      + '<div class="sr-s-kpis"><div><b data-count="' + r.today + '">0</b><span>आज</span></div><div><b>' + (r.rate == null ? '—' : r.rate + '%') + '</b><span>पूर्णता (30 दिवस)</span></div>'
      + '<div class="' + (r.overdueTasks ? 'hot' : '') + '"><b data-count="' + r.openTasks + '">0</b><span>उघडे Tasks</span></div>'
      + '<div class="' + (r.overdueReq ? 'hot' : '') + '"><b data-count="' + r.openReq + '">0</b><span>उघड्या विनंत्या</span></div>'
      + '<div class="' + (r.expired ? 'hot' : '') + '"><b data-count="' + (r.renDue + r.expired) + '">0</b><span>नूतनीकरण</span></div>'
      + '<div><b data-count="' + r.overdueTasks + '">0</b><span>मुदतबाह्य Tasks</span></div></div>'
      + '<div class="sr-s-spark" style="color:' + c + '">' + spark(r.trend) + '<div class="sr-spark-l" style="color:#A9B1CB"><span>मागील 14 दिवसांची कामं</span></div></div>'
      + (r.issues && r.issues.length ? '<div class="sr-s-issues">' + r.issues.map(function (i) { return '<span>' + esc(i) + '</span>'; }).join('') + '</div>' : '')
      + '</div></div>';
  }
  function render() {
    var item = list[idx];
    slide.innerHTML = item === 'all' ? slideAll() : slideDept(item);
    if (window.eowmsT) slide.querySelectorAll('.sr-s-sub, .sr-s-kpis span, .sr-big-c small, .sr-spark-l span, .sr-s-name').forEach(function (e) { e.textContent = eowmsT(e.textContent); });
    requestAnimationFrame(function () {
      var fg = slide.querySelector('.sr-big .fg'); if (fg) fg.style.strokeDashoffset = fg.getAttribute('data-off');
      slide.querySelectorAll('[data-count]').forEach(countUp);
    });
    dotsEl.querySelectorAll('i').forEach(function (d, i) { d.classList.toggle('on', i === idx); });
    countEl.textContent = (idx + 1) + ' / ' + list.length;
    t0 = performance.now();
  }
  function go(n) { idx = (n + list.length) % list.length; render(); }
  function tick(t) {
    if (!stage.classList.contains('open')) return;
    if (playing) { var p = Math.min((t - t0) / DUR, 1); prog.style.width = (p * 100) + '%'; if (p >= 1) go(idx + 1); }
    else t0 = t - (parseFloat(prog.style.width) || 0) / 100 * DUR;
    document.getElementById('srClock').textContent = new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    raf = requestAnimationFrame(tick);
  }
  function open() {
    var visibleIds = {}; cards.forEach(function (c) { if (c.style.display !== 'none') visibleIds[c.getAttribute('data-id')] = 1; });
    var order = cards.map(function (c) { return +c.getAttribute('data-id'); });
    list = ['all'].concat((data.rows || []).filter(function (r) { return visibleIds[r.id]; }).sort(function (a, b) { return order.indexOf(a.id) - order.indexOf(b.id); }));
    dotsEl.innerHTML = list.map(function (_, i) { return '<i data-i="' + i + '"></i>'; }).join('');
    stage.classList.add('open'); document.body.style.overflow = 'hidden';
    if (stage.requestFullscreen) stage.requestFullscreen().catch(function () {});
    playing = true; pauseBtn.innerHTML = '<i class="fa-solid fa-pause"></i>'; idx = 0; render(); raf = requestAnimationFrame(tick);
  }
  function close() {
    stage.classList.remove('open'); document.body.style.overflow = ''; cancelAnimationFrame(raf);
    if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(function () {});
  }
  document.getElementById('srPlay').addEventListener('click', open);
  document.getElementById('srClose').addEventListener('click', close);
  document.getElementById('srNext').addEventListener('click', function () { go(idx + 1); });
  document.getElementById('srPrev').addEventListener('click', function () { go(idx - 1); });
  pauseBtn.addEventListener('click', function () { playing = !playing; pauseBtn.innerHTML = '<i class="fa-solid fa-' + (playing ? 'pause' : 'play') + '"></i>'; });
  dotsEl.addEventListener('click', function (e) { var i = e.target.getAttribute('data-i'); if (i !== null) go(+i); });
  document.addEventListener('keydown', function (e) {
    if (!stage.classList.contains('open')) return;
    if (e.key === 'Escape') close(); else if (e.key === 'ArrowRight') go(idx + 1); else if (e.key === 'ArrowLeft') go(idx - 1);
    else if (e.key === ' ') { e.preventDefault(); pauseBtn.click(); }
  });
  document.addEventListener('fullscreenchange', function () { if (!document.fullscreenElement && stage.classList.contains('open')) close(); });
  if (location.hash === '#play') open();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
