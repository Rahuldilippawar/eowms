<?php
// 📡 Live Dashboard साठी JSON — दर 20 सेकंदांनी: नवीन Punches + आकडे
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/_common.php';
header('Content-Type: application/json; charset=utf-8');
if ((!hasRole('super_admin') && !isManagementOrAbove()) || !bioReady($pdo)) { echo json_encode(['ok' => false]); exit; }
$L = bioLiveData($pdo, activeOrgId(), (int)($_GET['after'] ?? 0));
$rows = array_map(fn($p) => [
    'id' => (int)$p['id'], 'time' => date('h:i:s A', strtotime($p['punch_time'])), 'name' => $p['name'] ?: ('PIN ' . $p['pin'] . ' (न जोडलेला)'),
    'mapped' => (bool)$p['name'], 'state' => $p['punch_state'] !== null ? bioStateLabel($p['punch_state']) : '', 'verify' => bioVerifyLabel($p['verify_mode']),
    'device' => $p['device_name'] . ($p['location'] ? ' · ' . $p['location'] : ''),
], array_reverse($L['feed']));
echo json_encode(['ok' => true, 'maxId' => $L['maxId'], 'rows' => $rows, 'tiles' => [
    'present' => count($L['present']), 'inside' => count($L['inside']), 'late' => count($L['late']), 'notArrived' => count($L['notArrived']), 'online' => $L['online'] . '/' . count($L['devices']),
], 'time' => date('h:i:s A')], JSON_UNESCAPED_UNICODE);
