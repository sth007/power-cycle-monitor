<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

header('Content-Type: application/json; charset=utf-8');

$config = getConfig();
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültige ID']);
    exit;
}

$cyclesTable = $config['cycles_table'];
$sourceTable = $config['source_table'];
$stmt = $pdo->prepare("SELECT * FROM {$cyclesTable} WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $id]);
$cycle = $stmt->fetch();
if (!$cycle) {
    http_response_code(404);
    echo json_encode(['error' => 'Vorgang nicht gefunden']);
    exit;
}

$padBefore = (int)$config['ui']['chart_padding_before_minutes'];
$padAfter = (int)$config['ui']['chart_padding_after_minutes'];
$from = date('Y-m-d H:i:s', strtotime($cycle['cycle_start'] . " -{$padBefore} minutes"));
$to   = date('Y-m-d H:i:s', strtotime($cycle['cycle_end'] . " +{$padAfter} minutes"));

$sql = "SELECT dtmod, cur_power FROM {$sourceTable} WHERE device_id = :device_id AND dtmod BETWEEN :from_dt AND :to_dt ORDER BY dtmod ASC";
$rowsStmt = $pdo->prepare($sql);
$rowsStmt->execute([
    'device_id' => $cycle['device_id'],
    'from_dt' => $from,
    'to_dt' => $to,
]);
$rows = downsampleRows($rowsStmt->fetchAll(), (int)$config['ui']['chart_max_points']);

$labels = [];
$values = [];
$markers = [];
$cycleStartTs = strtotime($cycle['cycle_start']);
$cycleEndTs = strtotime($cycle['cycle_end']);

foreach ($rows as $row) {
    $ts = strtotime($row['dtmod']);
    $labels[] = date('d.m.Y H:i:s', $ts);
    $values[] = (float)$row['cur_power'];
    $markers[] = ($ts >= $cycleStartTs && $ts <= $cycleEndTs) ? 1 : 0;
}

echo json_encode([
    'labels' => $labels,
    'values' => $values,
    'markers' => $markers,
], JSON_UNESCAPED_UNICODE);
