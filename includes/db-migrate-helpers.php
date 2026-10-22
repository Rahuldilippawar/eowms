<?php
/**
 * database/RUN-ALL-MIGRATIONS.sql सुरक्षितपणे (कितीही वेळा) चालवणं.
 *
 * ती File phpMyAdmin मध्ये थेट चालवली तर — आधीच झालेल्या Migration वर
 * ("Table already exists" / "Duplicate column") पहिल्याच Error ला थांबते,
 * आणि पुढच्या (अजून न झालेल्या) Migrations कधीच चालत नाहीत. म्हणून इथे
 * प्रत्येक Statement स्वतंत्रपणे, आधी तपासून चालवतो:
 *  - CREATE TABLE → IF NOT EXISTS
 *  - ALTER TABLE  → प्रत्येक Clause वेगळा; आधीच असलेले Column/Index/Foreign Key वगळतो
 *  - permissions / role_permissions Seed → INSERT IGNORE
 *  - इतर Seed INSERT → फक्त त्या Migration ने नवीन Table बनवली असेल तरच
 *    (नाहीतर Help-Articles वगैरे Data दुप्पट होईल)
 */

/** SQL मजकूर Statements मध्ये तोडतो — Quotes/Comments मधले ';' दुर्लक्षित */
function dbmSplitStatements($sql) {
    $stmts = [];
    $cur = '';
    $len = strlen($sql);
    $quote = null;
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($quote !== null) {
            $cur .= $c;
            if ($c === '\\' && $quote !== '`' && $i + 1 < $len) { $cur .= $sql[++$i]; continue; }
            if ($c === $quote) {
                if ($i + 1 < $len && $sql[$i + 1] === $quote) { $cur .= $sql[++$i]; continue; }
                $quote = null;
            }
            continue;
        }
        if ($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
            $nl = strpos($sql, "\n", $i);
            $i = $nl === false ? $len : $nl;
            $cur .= "\n";
            continue;
        }
        if ($c === '#') {
            $nl = strpos($sql, "\n", $i);
            $i = $nl === false ? $len : $nl;
            continue;
        }
        if ($c === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $len : $end + 1;
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; $cur .= $c; continue; }
        if ($c === ';') {
            if (trim($cur) !== '') { $stmts[] = trim($cur); }
            $cur = '';
            continue;
        }
        $cur .= $c;
    }
    if (trim($cur) !== '') { $stmts[] = trim($cur); }
    return $stmts;
}

/** Comma ने वेगळे Clauses — Brackets/Quotes मधले Comma दुर्लक्षित */
function dbmSplitClauses($text) {
    $parts = [];
    $cur = '';
    $depth = 0;
    $quote = null;
    $len = strlen($text);
    for ($i = 0; $i < $len; $i++) {
        $c = $text[$i];
        if ($quote !== null) {
            $cur .= $c;
            if ($c === $quote) { $quote = null; }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; $cur .= $c; continue; }
        if ($c === '(') { $depth++; }
        if ($c === ')') { $depth--; }
        if ($c === ',' && $depth === 0) { $parts[] = trim($cur); $cur = ''; continue; }
        $cur .= $c;
    }
    if (trim($cur) !== '') { $parts[] = trim($cur); }
    return $parts;
}

function dbmIdent($s) {
    return strtolower(trim($s, " `\t\r\n"));
}

function dbmTableExists($pdo, $table) {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

function dbmColumnExists($pdo, $table, $column) {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
}

function dbmIndexExists($pdo, $table, $indexName) {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $st->execute([$table, $indexName]);
    return (bool)$st->fetchColumn();
}

/** दिलेल्या Columns वर (त्याच क्रमाने सुरू होणारा) Index आधीच आहे का */
function dbmIndexOnColumnsExists($pdo, $table, array $cols) {
    $st = $pdo->prepare("SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? GROUP BY INDEX_NAME");
    $st->execute([$table]);
    $want = implode(',', $cols);
    foreach ($st->fetchAll() as $r) {
        if (strtolower($r['cols']) === $want) { return true; }
    }
    return false;
}

function dbmForeignKeyExists($pdo, $table, $column) {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL");
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
}

function dbmConstraintExists($pdo, $table, $name) {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?");
    $st->execute([$table, $name]);
    return (bool)$st->fetchColumn();
}

function dbmColsFromParens($s) {
    return array_map(fn($c) => dbmIdent(preg_replace('/\(\d+\)$/', '', trim($c))), explode(',', $s));
}

/**
 * ALTER TABLE चा एक Clause आधीच लागू आहे का (म्हणजे वगळायचा) — true = वगळा
 */
function dbmAlterClauseAlreadyApplied($pdo, $table, $clause) {
    $c = trim($clause);
    if (preg_match('/^ADD\s+(?:COLUMN\s+)?(?!INDEX\b|KEY\b|UNIQUE\b|CONSTRAINT\b|FOREIGN\b|PRIMARY\b|FULLTEXT\b|SPATIAL\b)`?(\w+)`?\s/i', $c, $m)) {
        return dbmColumnExists($pdo, $table, $m[1]);
    }
    if (preg_match('/^ADD\s+CONSTRAINT\s+`?(\w+)`?/i', $c, $m)) {
        if (dbmConstraintExists($pdo, $table, $m[1])) { return true; }
        if (preg_match('/FOREIGN\s+KEY\s*\(\s*`?(\w+)`?\s*\)/i', $c, $fk)) { return dbmForeignKeyExists($pdo, $table, $fk[1]); }
        if (preg_match('/UNIQUE\s*(?:KEY|INDEX)?\s*`?\w*`?\s*\(([^)]*)\)/i', $c, $u)) { return dbmIndexOnColumnsExists($pdo, $table, dbmColsFromParens($u[1])); }
        return false;
    }
    if (preg_match('/^ADD\s+FOREIGN\s+KEY\s*(?:`?\w+`?\s*)?\(\s*`?(\w+)`?\s*\)/i', $c, $m)) {
        return dbmForeignKeyExists($pdo, $table, $m[1]);
    }
    if (preg_match('/^ADD\s+(?:UNIQUE\s+)?(?:INDEX|KEY)\s+`?(\w+)`?\s*\(([^)]*)\)/i', $c, $m)
        || preg_match('/^ADD\s+UNIQUE\s+`?(\w+)`?\s*\(([^)]*)\)/i', $c, $m)) {
        return dbmIndexExists($pdo, $table, $m[1]) || dbmIndexOnColumnsExists($pdo, $table, dbmColsFromParens($m[2]));
    }
    if (preg_match('/^ADD\s+(?:UNIQUE\s+)?(?:INDEX|KEY)?\s*\(([^)]*)\)/i', $c, $m)) {
        return dbmIndexOnColumnsExists($pdo, $table, dbmColsFromParens($m[1]));
    }
    if (preg_match('/^ADD\s+PRIMARY\s+KEY/i', $c)) {
        return dbmIndexExists($pdo, $table, 'PRIMARY');
    }
    if (preg_match('/^DROP\s+(?:COLUMN\s+)?(?!INDEX\b|KEY\b|FOREIGN\b|PRIMARY\b)`?(\w+)`?\s*$/i', $c, $m)) {
        return !dbmColumnExists($pdo, $table, $m[1]);
    }
    if (preg_match('/^DROP\s+(?:INDEX|KEY)\s+`?(\w+)`?/i', $c, $m)) {
        return !dbmIndexExists($pdo, $table, $m[1]);
    }
    if (preg_match('/^DROP\s+FOREIGN\s+KEY\s+`?(\w+)`?/i', $c, $m)) {
        return !dbmConstraintExists($pdo, $table, $m[1]);
    }
    if (preg_match('/^CHANGE\s+(?:COLUMN\s+)?`?(\w+)`?\s+`?(\w+)`?\s/i', $c, $m)) {
        // जुना Column नाही आणि नवीन आधीच आहे — म्हणजे आधीच बदल झालेला आहे
        return !dbmColumnExists($pdo, $table, $m[1]) && dbmColumnExists($pdo, $table, $m[2]);
    }
    if (preg_match('/^MODIFY\s+(?:COLUMN\s+)?`?(\w+)`?\s/i', $c, $m)) {
        return !dbmColumnExists($pdo, $table, $m[1]); // Column च नसेल तर (त्याचा ADD नंतर येईल) — वगळा
    }
    return false;
}

/**
 * संपूर्ण Migration File चालवतो. परत: ['ran'=>n, 'skipped'=>n, 'errors'=>[[section, sql, message], ...], 'created'=>[tables]]
 */
function dbmRunMigrationFile($pdo, $file) {
    $sql = file_get_contents($file);
    $sections = preg_split('/^-- FROM: (\S+)\s*$/m', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
    $result = ['ran' => 0, 'skipped' => 0, 'errors' => [], 'created' => []];

    // [0] = पहिल्या "FROM:" आधीचा मजकूर; मग [नाव, मजकूर] जोड्या
    $pairs = [['(header)', $sections[0]]];
    for ($i = 1; $i < count($sections); $i += 2) {
        $pairs[] = [$sections[$i], $sections[$i + 1] ?? ''];
    }

    // एकाच Column वर अनेक MODIFY/CHANGE असतील तर फक्त शेवटचाच चालवायचा — जुना
    // (अरुंद) ENUM पुन्हा लावला तर नंतर जोडलेली Values (उदा. 'Trust HQ') पुसली जाऊ शकतात
    $lastColumnChange = [];
    $clauseCounter = 0;
    foreach ($pairs as [$sectionName, $sectionSql]) {
        foreach (dbmSplitStatements($sectionSql) as $stmt) {
            if (!preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+(.*)$/is', $stmt, $m)) { continue; }
            foreach (dbmSplitClauses($m[2]) as $clause) {
                $clauseCounter++;
                if (preg_match('/^(?:MODIFY|CHANGE)\s+(?:COLUMN\s+)?`?(\w+)`?/i', $clause, $cm)) {
                    $lastColumnChange[strtolower($m[1] . '.' . $cm[1])] = $clauseCounter;
                }
            }
        }
    }
    $clauseCounter = 0;

    foreach ($pairs as [$sectionName, $sectionSql]) {
        $sectionCreatedTable = false;
        foreach (dbmSplitStatements($sectionSql) as $stmt) {
            $exec = function ($q) use ($pdo, &$result, $sectionName) {
                try {
                    $pdo->exec($q);
                    $result['ran']++;
                    return true;
                } catch (PDOException $e) {
                    $code = (int)($e->errorInfo[1] ?? 0);
                    // आधीच अस्तित्वात — Duplicate Column/Key/Entry/Table, FK name — वगळा
                    if (in_array($code, [1050, 1060, 1061, 1062, 1068, 1091, 1826], true)
                        || ($code === 1005 && strpos($e->getMessage(), 'errno: 121') !== false)) {
                        $result['skipped']++;
                        return false;
                    }
                    $result['errors'][] = [$sectionName, mb_substr(preg_replace('/\s+/', ' ', $q), 0, 300), $e->getMessage()];
                    return false;
                }
            };

            if (preg_match('/^USE\s/i', $stmt)) { continue; }

            if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $stmt, $m)) {
                $table = $m[1];
                if (dbmTableExists($pdo, $table)) { $result['skipped']++; continue; }
                if ($exec($stmt)) { $sectionCreatedTable = true; $result['created'][] = $table; }
                continue;
            }

            if (preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+(.*)$/is', $stmt, $m)) {
                $table = $m[1];
                if (!dbmTableExists($pdo, $table)) {
                    // काढून टाकलेल्या जुन्या Module ची Table (उदा. purchase_pending_register) — वगळा
                    $clauseCounter += count(dbmSplitClauses($m[2]));
                    $result['skipped']++;
                    continue;
                }
                foreach (dbmSplitClauses($m[2]) as $clause) {
                    $clauseCounter++;
                    if (preg_match('/^(?:MODIFY|CHANGE)\s+(?:COLUMN\s+)?`?(\w+)`?/i', $clause, $cm)
                        && ($lastColumnChange[strtolower($table . '.' . $cm[1])] ?? 0) !== $clauseCounter) {
                        $result['skipped']++;
                        continue;
                    }
                    if (dbmAlterClauseAlreadyApplied($pdo, $table, $clause)) { $result['skipped']++; continue; }
                    $exec("ALTER TABLE `$table` $clause");
                }
                continue;
            }

            if (preg_match('/^INSERT\s+INTO\s+`?(permissions|role_permissions)`?\b/i', $stmt)) {
                $exec(preg_replace('/^INSERT\s+INTO/i', 'INSERT IGNORE INTO', $stmt));
                continue;
            }

            if (preg_match('/^INSERT\s+INTO\b/i', $stmt) && stripos($stmt, 'NOT EXISTS') === false && !$sectionCreatedTable) {
                // Seed Data — हा Migration आधीच झालेला आहे, पुन्हा घातल्यास Data दुप्पट होईल
                $result['skipped']++;
                continue;
            }

            $exec($stmt);
        }
    }
    return $result;
}
