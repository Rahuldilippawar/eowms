<?php
/**
 * रहिवासी Advance Import — सामायिक Logic (Preview आणि प्रत्यक्ष Import
 * दोन्हीसाठी हेच वापरतो, जेणेकरून Preview मध्ये जे दाखवलं तेच प्रत्यक्षात होईल).
 */

function residencyImportParseCsv($filePath) {
    $handle = fopen($filePath, 'r');
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") { rewind($handle); }

    $header = array_map(fn($h) => strtolower(trim($h)), fgetcsv($handle));
    $required = ['building_name', 'room_name', 'occupant_type', 'name'];
    foreach ($required as $col) {
        if (!in_array($col, $header, true)) {
            fclose($handle);
            return ['error' => "CSV मध्ये \"$col\" हा column असणे आवश्यक आहे."];
        }
    }
    $colIndex = array_flip($header);

    $rows = [];
    $rowNum = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $rowNum++;
        if (count($row) === 1 && trim($row[0]) === '') continue;
        $get = fn($col) => isset($colIndex[$col]) && isset($row[$colIndex[$col]]) ? trim($row[$colIndex[$col]]) : null;

        $rows[] = [
            'row_num' => $rowNum,
            'building_name' => $get('building_name'),
            'room_name' => $get('room_name'),
            'occupant_type' => strtolower($get('occupant_type') ?: ''),
            'name' => $get('name'),
            'staff_email' => $get('staff_email'),
            'relation' => $get('relation'),
            'moved_in_date' => $get('moved_in_date'),
        ];
    }
    fclose($handle);
    return ['rows' => $rows];
}

/**
 * Preview साठी — काय-काय Valid आहे, काय Skip होईल, किती नवीन Building/Room/
 * Occupant तयार होतील (Duplicate/Database तपासून) — याचा अंदाज बनवतो.
 */
function residencyImportPreview($pdo, $orgId, $rows) {
    $campusStmt = $pdo->prepare("SELECT id FROM campuses WHERE organization_id = ? ORDER BY id LIMIT 1");
    $campusStmt->execute([$orgId]);
    $campusId = $campusStmt->fetch()['id'] ?? null;

    $buildingStmt = $pdo->prepare("SELECT name FROM buildings WHERE organization_id = ?");
    $buildingStmt->execute([$orgId]);
    $existingBuildings = array_map(fn($b) => mb_strtolower(trim($b['name'])), $buildingStmt->fetchAll());

    $roomStmt = $pdo->prepare("SELECT b.name AS building_name, ml.name AS room_name FROM maintenance_locations ml LEFT JOIN buildings b ON b.id = ml.building_id WHERE ml.organization_id = ?");
    $roomStmt->execute([$orgId]);
    $existingRooms = [];
    foreach ($roomStmt->fetchAll() as $r) { $existingRooms[mb_strtolower(trim($r['building_name'] ?? '')) . '|' . mb_strtolower(trim($r['room_name']))] = true; }

    $newBuildings = [];
    $newRooms = [];
    $validRows = [];
    $errors = [];

    foreach ($rows as $r) {
        if (!$r['building_name'] || !$r['room_name'] || !$r['name']) {
            $errors[] = "ओळ {$r['row_num']}: आवश्यक माहिती रिकामी आहे — वगळलं जाईल";
            continue;
        }
        if (!in_array($r['occupant_type'], ['staff', 'family'], true)) {
            $errors[] = "ओळ {$r['row_num']}: occupant_type \"staff\" किंवा \"family\" असायला हवा — वगळलं जाईल";
            continue;
        }
        if (!$campusId) {
            $errors[] = "ओळ {$r['row_num']}: आधी किमान 1 Campus तयार असणं आवश्यक आहे";
            continue;
        }

        $bKey = mb_strtolower(trim($r['building_name']));
        if (!in_array($bKey, $existingBuildings, true) && !isset($newBuildings[$bKey])) {
            $newBuildings[$bKey] = $r['building_name'];
        }

        $rKey = $bKey . '|' . mb_strtolower(trim($r['room_name']));
        if (!isset($existingRooms[$rKey]) && !isset($newRooms[$rKey])) {
            $newRooms[$rKey] = ['building' => $r['building_name'], 'room' => $r['room_name']];
        }

        $validRows[] = $r;
    }

    return [
        'campus_id' => $campusId,
        'valid_rows' => $validRows,
        'errors' => $errors,
        'new_buildings' => array_values($newBuildings),
        'new_rooms' => array_values($newRooms),
        'total_occupants' => count($validRows),
    ];
}

/**
 * प्रत्यक्ष Import — Preview मध्ये दाखवलेल्याच Logic ने, पण आता खरोखर
 * Database मध्ये Insert करतो. सगळं एका Batch खाली Tag केलं जातं
 * (Import History + Rollback साठी).
 */
function residencyImportExecute($pdo, $orgId, $userId, $rows, $filename) {
    $preview = residencyImportPreview($pdo, $orgId, $rows);
    $campusId = $preview['campus_id'];
    if (!$campusId) {
        return ['error' => 'आधी किमान 1 Campus तयार असणं आवश्यक आहे.'];
    }

    $pdo->beginTransaction();
    try {
        $batchStmt = $pdo->prepare("INSERT INTO residency_import_batches (organization_id, filename, imported_by) VALUES (?, ?, ?)");
        $batchStmt->execute([$orgId, $filename, $userId]);
        $batchId = $pdo->lastInsertId();

        $buildingByName = [];
        $bStmt = $pdo->prepare("SELECT id, name FROM buildings WHERE organization_id = ?");
        $bStmt->execute([$orgId]);
        foreach ($bStmt->fetchAll() as $b) { $buildingByName[mb_strtolower(trim($b['name']))] = $b['id']; }

        $roomByKey = [];
        $rStmt = $pdo->prepare("SELECT id, building_id, name FROM maintenance_locations WHERE organization_id = ?");
        $rStmt->execute([$orgId]);
        foreach ($rStmt->fetchAll() as $r) { $roomByKey[$r['building_id'] . '|' . mb_strtolower(trim($r['name']))] = $r['id']; }

        $staffByEmail = [];
        $sStmt = $pdo->prepare("SELECT id, email FROM users WHERE organization_id = ?");
        $sStmt->execute([$orgId]);
        foreach ($sStmt->fetchAll() as $s) { if ($s['email']) { $staffByEmail[mb_strtolower(trim($s['email']))] = $s['id']; } }

        $buildingInsert = $pdo->prepare("INSERT INTO buildings (organization_id, campus_id, import_batch_id, name, created_by) VALUES (?, ?, ?, ?, ?)");
        $roomInsert = $pdo->prepare("INSERT INTO maintenance_locations (organization_id, campus_id, building_id, import_batch_id, floor, name, location_code, is_active, created_by) VALUES (?, ?, ?, ?, 0, ?, ?, 1, ?)");
        $occInsert = $pdo->prepare("INSERT INTO residency_occupants (organization_id, location_id, import_batch_id, occupant_type, user_id, name, relation, moved_in_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)");

        $buildingsCreated = 0; $roomsCreated = 0; $occupantsCreated = 0;

        foreach ($preview['valid_rows'] as $r) {
            $bKey = mb_strtolower(trim($r['building_name']));
            if (!isset($buildingByName[$bKey])) {
                $buildingInsert->execute([$orgId, $campusId, $batchId, $r['building_name'], $userId]);
                $buildingByName[$bKey] = $pdo->lastInsertId();
                $buildingsCreated++;
            }
            $buildingId = $buildingByName[$bKey];

            $rKey = $buildingId . '|' . mb_strtolower(trim($r['room_name']));
            if (!isset($roomByKey[$rKey])) {
                $locationCode = 'RES-' . strtoupper(substr(uniqid(), -6));
                $roomInsert->execute([$orgId, $campusId, $buildingId, $batchId, $r['room_name'], $locationCode, $userId]);
                $roomByKey[$rKey] = $pdo->lastInsertId();
                $roomsCreated++;
            }
            $locationId = $roomByKey[$rKey];

            $userId2 = null;
            if ($r['occupant_type'] === 'staff' && $r['staff_email']) {
                $userId2 = $staffByEmail[mb_strtolower(trim($r['staff_email']))] ?? null;
            }
            $movedInDate = ($r['moved_in_date'] && strtotime($r['moved_in_date'])) ? date('Y-m-d', strtotime($r['moved_in_date'])) : date('Y-m-d');

            $occInsert->execute([$orgId, $locationId, $batchId, $r['occupant_type'], $userId2, $r['name'], $r['relation'], $movedInDate, $userId]);
            $occupantsCreated++;
        }

        $pdo->prepare("UPDATE residency_import_batches SET buildings_created = ?, rooms_created = ?, occupants_created = ? WHERE id = ?")
            ->execute([$buildingsCreated, $roomsCreated, $occupantsCreated, $batchId]);

        $pdo->commit();
        return ['batch_id' => $batchId, 'buildings_created' => $buildingsCreated, 'rooms_created' => $roomsCreated, 'occupants_created' => $occupantsCreated, 'errors' => $preview['errors']];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['error' => 'Import करताना त्रुटी आली — काहीही Save झालेलं नाही: ' . $e->getMessage()];
    }
}
