<?php
// 🧠 Smart Command Center — दृश्य (HTML). Data: includes/dashboard-smart.php
?>
<?php if (($smartPart ?? 'main') === 'alerts'): ?>
<!-- ============ आज लक्ष द्या ============ -->
<div class="sm-attn mb-3">
  <div class="sm-attn-title"><i class="fa-solid fa-bell"></i> आज लक्ष द्या</div>
  <?php if (empty($smart['alerts'])): ?>
    <div class="sm-attn-ok"><i class="fa-solid fa-circle-check"></i> सर्व काही नियंत्रणात — कोणतीही तातडीची बाब नाही</div>
  <?php else: ?>
    <div class="sm-attn-list">
      <?php foreach ($smart['alerts'] as $a): ?>
        <a class="sm-attn-item <?= $a['sev'] ?>" href="<?= h($a['href']) ?>">
          <i class="<?= h($a['icon']) ?>"></i>
          <b><?= $a['cnt'] ?></b>
          <span><?= h($a['text']) ?></span>
          <i class="fa-solid fa-chevron-right sm-go"></i>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php return; endif; ?>

<?php if ($quickAssign): ?>
<!-- ============ झटपट Task द्या (Modal) ============ -->
<div class="modal fade" id="smQuickAssign" tabindex="-1" aria-labelledby="smQaTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="POST" action="tasks/save.php">
      <input type="hidden" name="return_to" value="dashboard">
      <div class="modal-header">
        <h5 class="modal-title" id="smQaTitle" style="font-size:16px;font-weight:700;"><i class="fa-solid fa-bolt me-1" style="color:var(--amber);"></i> झटपट Task द्या</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="बंद करा"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label-custom">Task चं शीर्षक <span class="req">*</span></label>
            <input type="text" name="title" class="form-control-custom" maxlength="255" required placeholder="उदा. मुख्य इमारतीतील दिवे तपासा">
          </div>
          <div class="col-sm-6">
            <label class="form-label-custom">विभाग <span class="req">*</span></label>
            <select name="department_id" id="smQaDept" class="form-select-custom" required>
              <?php if (count($quickAssign['depts']) > 1): ?><option value="">-- निवडा --</option><?php endif; ?>
              <?php foreach ($quickAssign['depts'] as $qd): ?>
                <option value="<?= (int)$qd['id'] ?>"><?= h($qd['name_local'] ?: $qd['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label-custom">कोणाला <span class="req">*</span></label>
            <select name="assigned_to" id="smQaUser" class="form-select-custom" required>
              <option value="">-- आधी विभाग निवडा --</option>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label-custom">मुदत <span class="req">*</span></label>
            <input type="date" name="target_date" id="smQaDate" class="form-control-custom" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+2 days')) ?>" required>
            <div class="sm-qa-chips">
              <button type="button" data-days="0">आज</button><button type="button" data-days="1">उद्या</button><button type="button" data-days="7">१ आठवडा</button>
            </div>
          </div>
          <div class="col-sm-6">
            <label class="form-label-custom">प्राधान्य</label>
            <select name="priority" class="form-select-custom">
              <option value="normal">सर्वसाधारण</option><option value="high">जास्त</option><option value="urgent">तातडीचं</option><option value="low">कमी</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label-custom">वर्णन</label>
            <textarea name="description" class="form-control-custom" rows="2" placeholder="(ऐच्छिक)"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <a href="tasks/assign.php" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;padding:7px 14px;">पूर्ण Form उघडा</a>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:8px;padding:7px 18px;font-weight:600;"><i class="fa-solid fa-paper-plane"></i> Task द्या</button>
      </div>
    </form>
  </div>
</div>
<script type="application/json" id="smQaStaff"><?= json_encode($quickAssign['staff'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>


<div class="row g-3 mb-3">
  <?php if ($smart['requests']): $rq = $smart['requests']; $rc = $rq['counts']; ?>
  <!-- ============ कामाच्या विनंत्या ============ -->
  <div class="<?= $smart['tasks'] ? 'col-xl-6' : 'col-12' ?>">
    <div class="panel h-100 sm-panel" data-sm-tabs>
      <div class="panel-head d-flex justify-content-between align-items-center gap-2">
        <div><div class="title"><i class="fa-solid fa-inbox me-1" style="color:#3B6FC9;"></i> कामाच्या विनंत्या</div>
          <div class="sub">आज नवीन <?= (int)$rc['new_today'] ?> · आज पूर्ण <?= (int)$rc['done_today'] ?><?php if ($rc['avg_rating']): ?> · ⭐ <?= number_format((float)$rc['avg_rating'], 1) ?>/5 समाधान<?php endif; ?></div></div>
        <a href="requests/index.php" class="sm-link">सर्व पहा →</a>
      </div>
      <div class="sm-stats">
        <div><span>उघड्या</span><b><?= (int)$rc['open_cnt'] ?></b></div>
        <div><span>प्रलंबित</span><b class="c-amber"><?= (int)$rc['pending_cnt'] ?></b></div>
        <div><span>सुरू आहे</span><b class="c-blue"><?= (int)$rc['progress_cnt'] ?></b></div>
        <div><span>SLA बाहेर</span><b class="c-rose"><?= (int)$rc['overdue_cnt'] ?></b></div>
      </div>
      <?php if ($rc['open_cnt'] > 0): ?>
        <div class="sm-stack" title="प्रलंबित / सुरू आहे / SLA बाहेर">
          <i style="flex:<?= max(0, (int)$rc['pending_cnt']) ?>;background:var(--amber);"></i>
          <i style="flex:<?= max(0, (int)$rc['progress_cnt']) ?>;background:#3B6FC9;"></i>
        </div>
      <?php endif; ?>
      <div class="sm-tabs" role="tablist">
        <button type="button" class="active" data-tab="open">उघड्या</button>
        <button type="button" data-tab="overdue">SLA बाहेर <em><?= count($rq['tabs']['overdue']) ?></em></button>
        <button type="button" data-tab="urgent">तातडीच्या</button>
        <button type="button" data-tab="mine">माझ्याकडे</button>
      </div>
      <?php foreach ($rq['tabs'] as $tab => $rows): ?>
        <div class="sm-list" data-pane="<?= $tab ?>"<?= $tab === 'open' ? '' : ' hidden' ?>>
          <?php if (!$rows): ?><div class="sm-empty">✅ इथे कोणतीही विनंती नाही</div><?php endif; ?>
          <?php foreach ($rows as $r): $sla = ticketSlaBadge($r); ?>
            <a class="sm-row" href="requests/view.php?id=<?= (int)$r['id'] ?>">
              <span class="sm-dot" style="background:<?= $prioDot[$r['priority']] ?? '#999' ?>;" title="प्राधान्य: <?= h($prioLabel[$r['priority']] ?? $r['priority']) ?>"></span>
              <div class="sm-main">
                <div class="sm-t"><?= h($r['title'] ?: (TICKET_CATEGORIES[$r['category']] ?? 'विनंती')) ?></div>
                <div class="sm-s"><span class="font-mono"><?= h($r['request_code']) ?></span> · <?= h($r['dept_name'] ?: ($r['category'] === 'it' ? 'IT' : '—')) ?> · <?= h($r['requester_name']) ?> · <?= h(ticketAgeLabel($r['created_at'])) ?> जुनी</div>
              </div>
              <div class="sm-side"><?= $sla['html'] ?: '<span class="status-chip ' . (TICKET_STATUS_CHIPS[$r['status']] ?? 'pending') . '" style="font-size:10px;">' . h(TICKET_STATUS_LABELS[$r['status']] ?? $r['status']) . '</span>' ?></div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($smart['tasks']): $tk = $smart['tasks']; $tc = $tk['counts'];
    $onTimePct = $tc['done_month'] > 0 ? (int)round($tc['ontime_month'] * 100 / $tc['done_month']) : null; ?>
  <!-- ============ Tasks ============ -->
  <div class="<?= $smart['requests'] ? 'col-xl-6' : 'col-12' ?>">
    <div class="panel h-100 sm-panel" data-sm-tabs>
      <div class="panel-head d-flex justify-content-between align-items-center gap-2">
        <div><div class="title"><i class="fa-solid fa-list-check me-1" style="color:var(--rail);"></i> <?= $tk['orgWide'] ? 'Tasks — संपूर्ण संस्था' : 'माझे Tasks' ?></div>
          <div class="sub">या महिन्यात पूर्ण <?= $tc['done_month'] ?><?php if ($onTimePct !== null): ?> · वेळेत <?= $onTimePct ?>%<?php endif; ?><?php if ($tc['to_verify']): ?> · पडताळणी बाकी <?= $tc['to_verify'] ?><?php endif; ?></div></div>
        <div class="d-flex gap-2 align-items-center">
          <?php if ($quickAssign): ?>
            <button type="button" class="btn btn-sm sm-qa-btn" data-bs-toggle="modal" data-bs-target="#smQuickAssign"><i class="fa-solid fa-bolt"></i> झटपट Task द्या</button>
          <?php endif; ?>
          <a href="<?= $tk['orgWide'] ? 'tasks/index.php' : 'tasks/my-tasks.php' ?>" class="sm-link">सर्व →</a>
        </div>
      </div>
      <div class="sm-stats">
        <div><span>उघडे</span><b><?= $tc['open_cnt'] ?></b></div>
        <div><span>मुदत संपली</span><b class="c-rose"><?= $tc['overdue_cnt'] ?></b></div>
        <div><span>आज मुदत</span><b class="c-amber"><?= $tc['today_cnt'] ?></b></div>
        <div><span>७ दिवसांत</span><b class="c-blue"><?= $tc['week_cnt'] ?></b></div>
      </div>
      <?php if ($onTimePct !== null): ?>
        <div class="sm-meter" title="या महिन्यात वेळेत पूर्ण झालेले Tasks"><i style="width:<?= $onTimePct ?>%;"></i></div>
      <?php endif; ?>
      <div class="sm-tabs" role="tablist">
        <button type="button" class="active" data-tab="overdue">मुदत संपलेले <em><?= count($tk['tabs']['overdue']) ?></em></button>
        <button type="button" data-tab="week">या आठवड्यात</button>
        <button type="button" data-tab="mine">मला दिलेले <em><?= $tc['mine_open'] ?></em></button>
        <?php if (hasPermission('assign_tasks')): ?><button type="button" data-tab="given">मी दिलेले</button><?php endif; ?>
      </div>
      <?php foreach ($tk['tabs'] as $tab => $rows): if ($tab === 'given' && !hasPermission('assign_tasks')) continue; ?>
        <div class="sm-list" data-pane="<?= $tab ?>"<?= $tab === 'overdue' ? '' : ' hidden' ?>>
          <?php if (!$rows): ?><div class="sm-empty">✅ इथे कोणताही Task नाही</div><?php endif; ?>
          <?php foreach ($rows as $t): [$rel, $relColor] = dashRelDay($t['target_date']);
            $href = (int)$t['assigned_to'] === (int)$user['id'] ? 'tasks/my-tasks.php' : 'tasks/index.php'; ?>
            <a class="sm-row" href="<?= $href ?>">
              <span class="sm-av" title="<?= h($t['assignee_name']) ?>"><?= h(dashInitials($t['assignee_name'])) ?></span>
              <div class="sm-main">
                <div class="sm-t"><span class="sm-dot sm-dot-inline" style="background:<?= $prioDot[$t['priority']] ?? '#999' ?>;"></span><?= h($t['title']) ?></div>
                <div class="sm-s"><?= h($t['assignee_name']) ?> · <?= h($t['dept_name']) ?><?= $t['status'] === 'in_progress' ? ' · 🔧 सुरू आहे' : '' ?></div>
              </div>
              <div class="sm-side"><span class="sm-when <?= $relColor ?>"><?= h($rel) ?></span><div class="sm-date font-mono"><?= date('d M', strtotime($t['target_date'])) ?></div></div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="row g-3">
  <!-- ============ विभाग-आरोग्य ============ -->
  <div class="col-lg-8">
    <div class="panel" id="smDepts">
      <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div><div class="title"><i class="fa-solid fa-heart-pulse me-1" style="color:var(--rose);"></i> <?= $isMgmtView ? 'विभाग — आरोग्य व कामगिरी' : 'माझा विभाग' ?></div>
          <div class="sub">मागील ३० दिवसांची पूर्णता, मुदतबाह्य Tasks, SLA व नूतनीकरण यांवरून गुण</div></div>
        <?php if (count($smart['health']) > 1): $groups = array_unique(array_column($smart['health'], 'group')); ?>
          <div class="sm-dept-tools">
            <input type="search" id="smDeptSearch" placeholder="विभाग शोधा…" aria-label="विभाग शोधा">
            <?php if (count($groups) > 1): ?>
              <select id="smDeptGroup" aria-label="College/School">
                <option value="">सर्व Campus</option>
                <?php foreach ($groups as $g): ?><option value="<?= h($g) ?>"><?= h($g) ?></option><?php endforeach; ?>
              </select>
            <?php endif; ?>
            <select id="smDeptSort" aria-label="क्रम">
              <option value="attn">लक्ष आवश्यक आधी</option>
              <option value="best">उत्तम कामगिरी आधी</option>
              <option value="name">नावानुसार</option>
            </select>
          </div>
        <?php endif; ?>
      </div>
      <?php if (count($smart['health']) > 1):
        $lv = array_count_values(array_column($smart['health'], 'level')); ?>
        <div class="sm-health-sum">
          <button type="button" class="active" data-level="">सर्व <b><?= count($smart['health']) ?></b></button>
          <button type="button" data-level="good"><span class="sm-lv good"></span>उत्तम <b><?= $lv['good'] ?? 0 ?></b></button>
          <button type="button" data-level="warn"><span class="sm-lv warn"></span>लक्ष द्या <b><?= $lv['warn'] ?? 0 ?></b></button>
          <button type="button" data-level="bad"><span class="sm-lv bad"></span>गंभीर <b><?= $lv['bad'] ?? 0 ?></b></button>
        </div>
      <?php endif; ?>
      <div class="sm-dept-grid" id="smDeptGrid">
        <?php if (!$smart['health']): ?><div class="sm-empty">विभाग सापडले नाहीत</div><?php endif; ?>
        <?php if (count($smart['health']) > 1 && hasPermission('view_org_wide_dashboards')): $hs = $smart['healthSum']; ?>
          <a class="sm-dept sm-dept-all <?= $hs['level'] ?>" href="departments/all.php" title="सर्व विभागांचा Showroom उघडा">
            <div class="sm-all-ring" style="--p:<?= $hs['score'] ?>;"><span><b data-count="<?= $hs['score'] ?>"><?= $hs['score'] ?></b><small>आरोग्य</small></span></div>
            <div class="sm-all-body">
              <div class="sm-all-head">
                <div><div class="sm-t"><i class="fa-solid fa-layer-group"></i> सर्व विभाग</div>
                  <div class="sm-s"><?= $hs['count'] ?> विभागांचं एकत्रित आरोग्य</div></div>
                <span class="sm-all-cta">Showroom उघडा <i class="fa-solid fa-arrow-right"></i></span>
              </div>
              <div class="sm-all-stack" title="उत्तम / लक्ष द्या / गंभीर">
                <i class="good" style="flex:<?= $hs['good'] ?>"></i><i class="warn" style="flex:<?= $hs['warn'] ?>"></i><i class="bad" style="flex:<?= $hs['bad'] ?>"></i>
              </div>
              <div class="sm-all-legend">
                <span><span class="sm-lv good"></span>उत्तम <b><?= $hs['good'] ?></b></span>
                <span><span class="sm-lv warn"></span>लक्ष द्या <b><?= $hs['warn'] ?></b></span>
                <span><span class="sm-lv bad"></span>गंभीर <b><?= $hs['bad'] ?></b></span>
              </div>
              <div class="sm-dept-meta">
                <span title="आज नोंदवलेली कामं"><i class="fa-regular fa-clipboard"></i> आज <?= $hs['today'] ?></span>
                <span title="३० दिवसांतील पूर्णता"><i class="fa-solid fa-chart-simple"></i> <?= $hs['rate'] === null ? '—' : $hs['rate'] . '%' ?></span>
                <span title="उघडे Tasks"><i class="fa-solid fa-list-check"></i> <?= $hs['openTasks'] ?><?php if ($hs['overdueTasks']): ?> <em><?= $hs['overdueTasks'] ?> उशीर</em><?php endif; ?></span>
                <span title="उघड्या विनंत्या"><i class="fa-solid fa-inbox"></i> <?= $hs['openReq'] ?></span>
                <?php if ($hs['renDue'] || $hs['expired']): ?><span title="नूतनीकरण"><i class="fa-solid fa-shield-halved"></i> <?= $hs['renDue'] + $hs['expired'] ?></span><?php endif; ?>
              </div>
            </div>
          </a>
        <?php endif; ?>
        <?php foreach ($smart['health'] as $hd): ?>
          <a class="sm-dept <?= $hd['level'] ?>" href="departments/dashboard.php?id=<?= $hd['id'] ?>"
             data-name="<?= h(mb_strtolower($hd['name'] . ' ' . $hd['hod'])) ?>" data-group="<?= h($hd['group']) ?>"
             data-score="<?= $hd['score'] ?>" data-level="<?= $hd['level'] ?>">
            <div class="sm-dept-top">
              <span class="sm-dept-ic"><i class="<?= h($hd['icon']) ?>"></i></span>
              <div class="sm-main">
                <div class="sm-t"><?= h($hd['name']) ?></div>
                <div class="sm-s"><?= $hd['hod'] ? 'HOD: ' . h($hd['hod']) : 'HOD नेमलेले नाहीत' ?></div>
              </div>
              <div class="sm-score" style="--p:<?= $hd['score'] ?>;" title="आरोग्य गुण"><span><?= $hd['score'] ?></span></div>
            </div>
            <div class="sm-dept-bar" title="३० दिवसांतील कामांची पूर्णता"><i style="width:<?= $hd['rate'] ?? 0 ?>%;"></i></div>
            <div class="sm-dept-meta">
              <span title="आज नोंदवलेली कामं"><i class="fa-regular fa-clipboard"></i> आज <?= $hd['today'] ?></span>
              <span title="३० दिवसांतील पूर्णता"><i class="fa-solid fa-chart-simple"></i> <?= $hd['rate'] === null ? '—' : $hd['rate'] . '%' ?></span>
              <span title="उघडे Tasks"><i class="fa-solid fa-list-check"></i> <?= $hd['openTasks'] ?></span>
              <span title="उघड्या विनंत्या"><i class="fa-solid fa-inbox"></i> <?= $hd['openReq'] ?></span>
              <?php if ($hd['renDue']): ?><span title="३० दिवसांत नूतनीकरण"><i class="fa-solid fa-shield-halved"></i> <?= $hd['renDue'] ?></span><?php endif; ?>
            </div>
            <?php if ($hd['issues']): ?><div class="sm-issues"><?php foreach ($hd['issues'] as $is): ?><span><?= h($is) ?></span><?php endforeach; ?></div><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="sm-empty" id="smDeptNone" hidden>शोधाशी जुळणारा विभाग नाही</div>
    </div>
  </div>

  <div class="col-lg-4">
    <?php if ($smart['renewals']): $rn = $smart['renewals']['counts']; ?>
    <!-- ============ नूतनीकरण ============ -->
    <div class="panel sm-panel">
      <div class="panel-head d-flex justify-content-between align-items-center">
        <div><div class="title"><i class="fa-solid fa-shield-halved me-1" style="color:var(--amber);"></i> नूतनीकरण</div>
          <div class="sub">पुढील ९० दिवस<?php if ((float)$rn['cost30'] > 0): ?> · ३० दिवसांत खर्च ₹<?= number_format((float)$rn['cost30']) ?><?php endif; ?></div></div>
        <a href="renewals/calendar.php" class="sm-link"><i class="fa-regular fa-calendar"></i> Calendar</a>
      </div>
      <div class="sm-ren-buckets">
        <a href="renewals/index.php?filter=expired" class="b-rose"><b><?= (int)$rn['expired'] ?></b><span>संपलेली</span></a>
        <a href="renewals/index.php?filter=due_soon" class="b-amber"><b><?= (int)$rn['d7'] ?></b><span>७ दिवसांत</span></a>
        <a href="renewals/index.php?filter=due_soon"><b><?= (int)$rn['d30'] ?></b><span>३० दिवसांत</span></a>
        <a href="renewals/index.php"><b><?= (int)$rn['d90'] ?></b><span>९० दिवसांत</span></a>
      </div>
      <?php $bucketSum = (int)$rn['expired'] + (int)$rn['d7'] + (int)$rn['d30'] + (int)$rn['d90']; if ($bucketSum > 0): ?>
        <div class="sm-stack">
          <i style="flex:<?= (int)$rn['expired'] ?>;background:var(--rose);"></i>
          <i style="flex:<?= (int)$rn['d7'] ?>;background:var(--amber);"></i>
          <i style="flex:<?= (int)$rn['d30'] ?>;background:#D4A017;"></i>
          <i style="flex:<?= (int)$rn['d90'] ?>;background:var(--rail);"></i>
        </div>
      <?php endif; ?>
      <div class="sm-list">
        <?php if (!$smart['renewals']['items']): ?><div class="sm-empty">✅ पुढील ९० दिवसांत कोणतंही नूतनीकरण नाही</div><?php endif; ?>
        <?php foreach ($smart['renewals']['items'] as $it):
          $dl = (int)$it['days_left'];
          $col = $dl < 0 ? 'rose' : ($dl <= 7 ? 'rose' : ($dl <= 30 ? 'amber' : 'rail'));
          $pct = $dl < 0 ? 100 : max(4, 100 - (int)round($dl * 100 / 90)); ?>
          <a class="sm-row" href="renewals/index.php?item=<?= (int)$it['id'] ?>">
            <div class="sm-ring <?= $col ?>" style="--p:<?= $pct ?>;"><span><?= $dl < 0 ? '!' : $dl ?></span></div>
            <div class="sm-main">
              <div class="sm-t"><?= h($it['item_name']) ?></div>
              <div class="sm-s"><?= h($it['dept_name']) ?> · <?= date('d M Y', strtotime($it['expiry_date'])) ?><?= $it['renewal_cost'] ? ' · ₹' . number_format((float)$it['renewal_cost']) : '' ?></div>
            </div>
            <div class="sm-side"><span class="sm-when <?= $col ?>"><?= $dl < 0 ? abs($dl) . ' दिवसांपूर्वी संपलं' : ($dl === 0 ? 'आज संपतं' : $dl . ' दिवस बाकी') ?></span></div>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
