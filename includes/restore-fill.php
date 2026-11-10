<?php
/**
 * ♻️ Restore — "हरवलेले Columns" भरणारा Post-Pass
 *
 * backup/restore.php मधली प्रत्येक INSERT फक्त ठराविक Columns घालते; नंतर Table मध्ये नवीन Columns
 * आले (उदा. users चे पगार/बँक, work_requests चं शीर्षक/प्राधान्य, created_at) तर ते Restore मध्ये हरवत.
 * हा Post-Pass Backup मधल्या प्रत्येक नोंदीचे — INSERT ने न घातलेले — Columns नव्या नोंदीत भरतो:
 *  - फक्त याच Restore मध्ये नवीन तयार झालेल्या नोंदी (आधीच्या/जुळलेल्या नोंदींना हात लावत नाही)
 *  - Foreign Key Columns नव्या IDs मध्ये बदलतो (Map नसेल तर तो Column वगळतो — चुकीच्या नोंदीकडे Link नको)
 *  - organization_id / id कधीच बदलत नाही
 */

/** [table => [oldId => newId]] — restoreTrack() इथे नोंदवतो */
$GLOBALS['restoreTracked'] = [];

/** INSERT नंतर लगेच: जुना ID → नवा ID नोंदवा (INSERT IGNORE वगळलं गेलं तर lastInsertId = 0 — काही नोंदवत नाही) */
function restoreTrack($table, $oldId, PDO $pdo) {
    $newId = (int)$pdo->lastInsertId();
    if ($oldId === null || $oldId === '' || $newId <= 0) { return; }
    $GLOBALS['restoreTracked'][$table][(string)$oldId] = $newId;
}

/** Restore सुरू होण्याआधी — प्रत्येक Table चा सध्याचा सर्वात मोठा id (यानंतरचे = नवीन) */
function restoreMaxIds(PDO $pdo) {
    $out = [];
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'id' AND EXTRA LIKE '%auto_increment%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        try { $out[$t] = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM `$t`")->fetchColumn(); } catch (PDOException $e) {}
    }
    return $out;
}

/** restore.php मध्ये प्रत्येक Table साठी INSERT मध्ये आधीच घातलेले Columns (Source वाचून — नवीन INSERT आपोआप लक्षात) */
function restoreInsertedColumns($sourceFile) {
    $cols = [];
    preg_match_all('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?\s*\(([^)]*)\)/i', (string)file_get_contents($sourceFile), $m, PREG_SET_ORDER);
    foreach ($m as $x) {
        foreach (explode(',', $x[2]) as $c) { $cols[$x[1]][trim($c, " `\r\n\t")] = true; }
    }
    return $cols;
}

/**
 * @param array $fixedMaps ['users' => $userMap, 'departments' => $deptMap, ...] — restore.php मधले आधीचे Maps
 * @return int किती नोंदी पूर्ण केल्या
 */
function restoreFillMissingColumns(PDO $pdo, array $data, array $maxBefore, array $fixedMaps, $sourceFile) {
    $tracked = $GLOBALS['restoreTracked'];
    foreach ($fixedMaps as $t => $map) {
        foreach ((array)$map as $old => $new) { if (!isset($tracked[$t][(string)$old])) { $tracked[$t][(string)$old] = (int)$new; } }
    }
    $inserted = restoreInsertedColumns($sourceFile);

    // Foreign Keys: table.column → referenced table
    $fk = [];
    foreach ($pdo->query("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll() as $r) {
        $fk[$r['TABLE_NAME']][$r['COLUMN_NAME']] = $r['REFERENCED_TABLE_NAME'];
    }
    // FK Constraint नसलेले पण ID असलेले Columns
    $implicit = ['hod_id' => 'users', 'current_holder_id' => 'users', 'returned_to' => 'users', 'mother_cattle_id' => 'gaushala_cattle',
        'parent_event_id' => 'events', 'vehicle_id' => 'vehicles', 'category_id' => 'letter_categories', 'item_id' => 'asset_items'];

    $colsOf = [];
    $filled = 0;
    foreach ($tracked as $table => $map) {
        if (!empty($GLOBALS['restoreGeneric'][$table])) { continue; }   // restoreInsertRows ने सगळे Columns आधीच घातले
        if (empty($data[$table]) || !is_array($data[$table]) || !preg_match('/^\w+$/', $table)) { continue; }
        if (!isset($colsOf[$table])) {
            $st = $pdo->prepare("SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $st->execute([$table]);
            $colsOf[$table] = array_column($st->fetchAll(), 'IS_NULLABLE', 'COLUMN_NAME');
        }
        $tableCols = $colsOf[$table];
        // 2-Step Login चे Secrets दुसऱ्या Server च्या Key ने Encrypt असू शकतात, आणि Lock/प्रयत्न — नव्याने सुरू व्हावेत
        $never = ['id', 'organization_id', 'tfa_method', 'tfa_secret', 'tfa_recovery', 'tfa_last_step', 'tfa_enabled_at',
            'failed_login_attempts', 'locked_until', 'password', 'must_change_password'];
        $extras = array_diff(array_keys($tableCols), array_keys($inserted[$table] ?? []), $never);
        if (!$extras) { continue; }
        $floor = $maxBefore[$table] ?? PHP_INT_MAX;   // Snapshot नसेल तर काहीच बदलू नका

        foreach ($data[$table] as $row) {
            if (!is_array($row) || !isset($row['id'])) { continue; }
            $newId = $map[(string)$row['id']] ?? null;
            if (!$newId || $newId <= $floor) { continue; }   // आधीची / जुळलेली नोंद — जशीच्या तशी
            $set = []; $vals = [];
            foreach ($extras as $c) {
                if (!array_key_exists($c, $row)) { continue; }
                $v = $row[$c];
                $ref = $fk[$table][$c] ?? (preg_match('/_by$/', $c) ? 'users' : ($implicit[$c] ?? null));
                if ($ref === null && preg_match('/_id$/', $c)) { continue; }   // अज्ञात Link (उदा. legacy_*, import_batch_id)
                if ($v !== null && $ref !== null) {
                    if ($ref === $table && $c !== 'id') { $refMap = $map; } else { $refMap = $tracked[$ref] ?? null; }
                    if ($refMap === null) { continue; }
                    $v = $refMap[(string)$v] ?? null;
                    if ($v === null && ($tableCols[$c] ?? 'YES') === 'NO') { continue; }
                }
                $set[] = "`$c` = ?"; $vals[] = $v;
            }
            if (!$set) { continue; }
            $vals[] = $newId;
            try {
                $pdo->prepare("UPDATE `$table` SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
                $filled++;
            } catch (PDOException $e) { /* एखादा Column जुळला नाही — बाकी Restore चालू ठेवा */ }
        }
    }
    return $filled;
}

/** आधीच्या Restore भागातले Maps (आधीपासून असलेल्या, नावाने जुळलेल्या नोंदींसह) Tracking मध्ये जोडतो */
function restoreRegisterMaps(array $maps) {
    foreach ($maps as $t => $map) {
        foreach ((array)$map as $old => $new) {
            if ($new && !isset($GLOBALS['restoreTracked'][$t][(string)$old])) { $GLOBALS['restoreTracked'][$t][(string)$old] = (int)$new; }
        }
    }
}

/**
 * नवीन (साध्या) Tables साठी सामान्य Restore — सगळे Columns घेतो, organization_id = हीच संस्था,
 * Links (FK, *_by, …) नव्या IDs मध्ये बदलतो; आवश्यक Link सापडला नाही तर ती नोंद वगळतो.
 * @param array $dedupe या Columns ची (बदललेली) किंमत सारखीच असलेली नोंद आधीच असेल तर वगळा
 * @param array $custom [column => fn(array $row): mixed|false] — false = नोंद वगळा
 * @return int किती नोंदी घातल्या
 */
function restoreInsertRows(PDO $pdo, $rows, $table, $orgId, array $dedupe = [], array $custom = []) {
    if (empty($rows) || !is_array($rows) || !preg_match('/^\w+$/', $table)) { return 0; }
    $GLOBALS['restoreGeneric'][$table] = true;
    try {
        $st = $pdo->prepare("SELECT COLUMN_NAME, IS_NULLABLE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
        $st->execute([$table]);
        $cols = $st->fetchAll();
        if (!$cols) { return 0; }   // Table अजून तयार नाही (Database Update बाकी)
        $fk = [];
        $f = $pdo->prepare("SELECT COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL");
        $f->execute([$table]);
        foreach ($f->fetchAll() as $r) { $fk[$r['COLUMN_NAME']] = $r['REFERENCED_TABLE_NAME']; }
    } catch (PDOException $e) { return 0; }
    $implicit = ['hod_id' => 'users', 'current_holder_id' => 'users', 'returned_to' => 'users', 'vehicle_id' => 'vehicles', 'item_id' => null];
    $n = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) { continue; }
        $names = []; $vals = []; $skip = false;
        foreach ($cols as $c) {
            $name = $c['COLUMN_NAME'];
            if (stripos($c['EXTRA'], 'auto_increment') !== false) { continue; }
            if ($name === 'organization_id') { $names[] = $name; $vals[] = (int)$orgId; continue; }
            if (isset($custom[$name])) {
                $v = $custom[$name]($row);
                if ($v === false) { $skip = true; break; }
                $names[] = $name; $vals[] = $v; continue;
            }
            if (!array_key_exists($name, $row)) { continue; }
            $v = $row[$name];
            $ref = $fk[$name] ?? (preg_match('/_by$/', $name) ? 'users' : ($implicit[$name] ?? null));
            if ($ref === null && preg_match('/_id$/', $name) && !isset($fk[$name])) {
                $v = null;   // अज्ञात Link (legacy_* इ.) — रिकामा
            } elseif ($v !== null && $ref !== null) {
                $v = $GLOBALS['restoreTracked'][$ref][(string)$v] ?? null;
            }
            if ($v === null && $c['IS_NULLABLE'] === 'NO' && array_key_exists($name, $row) && $row[$name] !== null) { $skip = true; break; }
            $names[] = $name; $vals[] = $v;
        }
        if ($skip || !$names) { continue; }
        try {
            if ($dedupe) {
                $w = []; $wv = [];
                foreach ($dedupe as $d) {
                    $i = array_search($d, $names, true);
                    if ($i === false || $vals[$i] === null) { $w[] = "`$d` IS NULL"; } else { $w[] = "`$d` = ?"; $wv[] = $vals[$i]; }
                }
                $chk = $pdo->prepare("SELECT 1 FROM `$table` WHERE " . implode(' AND ', $w) . " LIMIT 1");
                $chk->execute($wv);
                if ($chk->fetchColumn()) {
                    continue;
                }
            }
            $pdo->prepare("INSERT IGNORE INTO `$table` (`" . implode('`, `', $names) . "`) VALUES (" . implode(', ', array_fill(0, count($names), '?')) . ")")->execute($vals);
            if (isset($row['id'])) { restoreTrack($table, $row['id'], $pdo); }
            $n++;
        } catch (PDOException $e) { /* एखादी नोंद जुळली नाही — बाकी Restore चालू */ }
    }
    return $n;
}
