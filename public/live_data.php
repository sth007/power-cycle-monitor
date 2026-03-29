<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/history_processor.php';
require_once __DIR__ . '/../lib/predictor.php';

header('Content-Type: application/json; charset=utf-8');

$config = getConfig();
$pdo = db();
runHistoryProcessing();

$stateTable = $config['state_table'];
$liveItems = getLiveCyclePredictions($pdo, $config, $pdo->query("SELECT * FROM {$stateTable} ORDER BY device_name, device_id")->fetchAll());

$payload = array_map(static function (array $item) use ($pdo, $config): array {
    return [
        'device_id' => $item['device_id'],
        'device_name' => $item['device_name'],
        'status' => $item['status'],
        'cycle_start' => dt($item['cycle_start']),
        'elapsed_minutes' => (int)round(((int)$item['elapsed_seconds']) / 60),
        'energy_wh' => round((float)$item['energy_wh'], 1),
        'current_power' => round((float)$item['current_power'], 1),
        'prediction' => $item['prediction'] ? [
            'status' => $item['prediction']['status'],
            'profile_label' => $item['prediction']['profile_label'],
            'matched_cycles' => (int)$item['prediction']['matched_cycles'],
            'predicted_total_minutes' => (int)round(((int)$item['prediction']['predicted_total_seconds']) / 60),
            'remaining_minutes' => (int)ceil(((int)$item['prediction']['remaining_seconds']) / 60),
            'confidence_label' => $item['prediction']['confidence_label'],
        ] : null,
        'chart' => buildLiveChartData($pdo, $config, $item),
    ];
}, $liveItems);

echo json_encode([
    'generated_at' => date('c'),
    'items' => $payload,
], JSON_UNESCAPED_UNICODE);
