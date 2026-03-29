<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function getOpenCyclePredictions(PDO $pdo, array $config, array $stateRows): array
{
    $predictions = [];

    foreach ($stateRows as $row) {
        if ((int)($row['carry_active'] ?? 0) !== 1) {
            continue;
        }

        $currentCycle = buildCurrentCycleFromState($row);
        if ($currentCycle === null) {
            continue;
        }

        $prediction = estimateRemainingRuntime($pdo, $config, (string)$row['device_id'], $currentCycle);

        $predictions[] = array_merge($currentCycle, [
            'device_id' => (string)$row['device_id'],
            'device_name' => (string)$row['device_name'],
            'prediction' => $prediction,
        ]);
    }

    usort($predictions, static function (array $a, array $b): int {
        return strcmp($a['cycle_start'], $b['cycle_start']);
    });

    return $predictions;
}

function buildCurrentCycleFromState(array $state): ?array
{
    $cycleStart = $state['carry_cycle_start'] ?? null;
    $lastTs = $state['carry_last_ts'] ?? null;
    $lastActiveTs = $state['carry_last_active_ts'] ?? null;
    if (!$cycleStart || !$lastTs || !$lastActiveTs) {
        return null;
    }

    $cycleStartTs = strtotime((string)$cycleStart);
    $lastTsTs = strtotime((string)$lastTs);
    if ($cycleStartTs === false || $lastTsTs === false || $lastTsTs < $cycleStartTs) {
        return null;
    }

    $durationSeconds = max(0, $lastTsTs - $cycleStartTs);
    $energyWh = (float)($state['carry_energy_wh'] ?? 0);
    $avgPower = $durationSeconds > 0 ? ($energyWh / ($durationSeconds / 3600.0)) : 0.0;

    return [
        'cycle_start' => (string)$cycleStart,
        'last_ts' => (string)$lastTs,
        'last_active_ts' => (string)$lastActiveTs,
        'duration_seconds' => $durationSeconds,
        'energy_wh' => $energyWh,
        'avg_power' => $avgPower,
        'peak_power' => (float)($state['carry_peak_power'] ?? 0),
        'sample_count' => (int)($state['carry_sample_count'] ?? 0),
    ];
}

function estimateRemainingRuntime(PDO $pdo, array $config, string $deviceId, array $currentCycle): ?array
{
    $elapsedSeconds = (int)$currentCycle['duration_seconds'];
    if ($elapsedSeconds < 60) {
        return null;
    }

    $sourceTable = $config['source_table'];
    $cyclesTable = $config['cycles_table'];
    $predictionConfig = $config['prediction'] ?? [];
    $minHistory = max(1, (int)($predictionConfig['min_history_cycles'] ?? 3));
    $historyLimit = max($minHistory, (int)($predictionConfig['history_limit'] ?? 12));
    $minPredictAfterMinutes = max(0, (int)($predictionConfig['min_predict_after_minutes'] ?? 3));
    if ($elapsedSeconds < ($minPredictAfterMinutes * 60)) {
        return null;
    }

    $historyStmt = $pdo->prepare("
        SELECT id, cycle_start, cycle_end, duration_seconds, energy_wh, avg_power, peak_power
        FROM {$cyclesTable}
        WHERE device_id = :device_id
          AND is_closed = 1
          AND duration_seconds > :elapsed_seconds
        ORDER BY cycle_start DESC
        LIMIT {$historyLimit}
    ");
    $historyStmt->execute([
        'device_id' => $deviceId,
        'elapsed_seconds' => $elapsedSeconds,
    ]);
    $history = $historyStmt->fetchAll();

    if (count($history) < $minHistory) {
        return null;
    }

    $currentSignature = [
        'avg_power' => (float)$currentCycle['avg_power'],
        'peak_power' => (float)$currentCycle['peak_power'],
        'energy_wh' => (float)$currentCycle['energy_wh'],
    ];

    $candidates = [];
    foreach ($history as $row) {
        $prefixMetrics = computeHistoricalPrefixMetrics(
            $pdo,
            $sourceTable,
            $deviceId,
            (string)$row['cycle_start'],
            min($elapsedSeconds, (int)$row['duration_seconds'])
        );
        if ($prefixMetrics === null) {
            continue;
        }

        $score = calculatePredictionScore($currentSignature, $prefixMetrics, (int)$row['duration_seconds'], $elapsedSeconds);
        $weight = 1 / max(0.08, $score);
        $candidates[] = [
            'duration_seconds' => (int)$row['duration_seconds'],
            'score' => $score,
            'weight' => $weight,
        ];
    }

    if (count($candidates) < $minHistory) {
        return null;
    }

    usort($candidates, static function (array $a, array $b): int {
        return $a['score'] <=> $b['score'];
    });
    $candidates = array_slice($candidates, 0, min(5, count($candidates)));

    $weightSum = array_sum(array_column($candidates, 'weight'));
    if ($weightSum <= 0) {
        return null;
    }

    $predictedTotalSeconds = 0.0;
    foreach ($candidates as $candidate) {
        $predictedTotalSeconds += $candidate['duration_seconds'] * ($candidate['weight'] / $weightSum);
    }

    $predictedTotalSeconds = (int)round(max($elapsedSeconds, $predictedTotalSeconds));
    $remainingSeconds = max(0, $predictedTotalSeconds - $elapsedSeconds);
    $bestScore = (float)$candidates[0]['score'];

    return [
        'predicted_total_seconds' => $predictedTotalSeconds,
        'remaining_seconds' => $remainingSeconds,
        'matched_cycles' => count($candidates),
        'confidence_label' => predictionConfidenceLabel($bestScore, count($candidates)),
    ];
}

function computeHistoricalPrefixMetrics(PDO $pdo, string $sourceTable, string $deviceId, string $cycleStart, int $elapsedSeconds): ?array
{
    $fromTs = strtotime($cycleStart);
    if ($fromTs === false || $elapsedSeconds <= 0) {
        return null;
    }

    $to = date('Y-m-d H:i:s', $fromTs + $elapsedSeconds);
    $stmt = $pdo->prepare("
        SELECT dtmod, cur_power
        FROM {$sourceTable}
        WHERE device_id = :device_id
          AND dtmod BETWEEN :from_dt AND :to_dt
        ORDER BY dtmod ASC
    ");
    $stmt->execute([
        'device_id' => $deviceId,
        'from_dt' => $cycleStart,
        'to_dt' => $to,
    ]);
    $rows = $stmt->fetchAll();

    return computeMetricsFromRows($rows);
}

function computeMetricsFromRows(array $rows): ?array
{
    if (count($rows) < 2) {
        return null;
    }

    $firstTs = strtotime((string)$rows[0]['dtmod']);
    $lastTs = $firstTs;
    if ($firstTs === false) {
        return null;
    }

    $energyWh = 0.0;
    $peakPower = 0.0;
    $prevPower = (float)$rows[0]['cur_power'];

    foreach ($rows as $index => $row) {
        $ts = strtotime((string)$row['dtmod']);
        if ($ts === false) {
            continue;
        }

        $power = (float)$row['cur_power'];
        $peakPower = max($peakPower, $power);
        if ($index > 0) {
            $deltaSeconds = max(0, $ts - $lastTs);
            $energyWh += ($prevPower * $deltaSeconds) / 3600.0;
        }

        $lastTs = $ts;
        $prevPower = $power;
    }

    $durationSeconds = max(0, $lastTs - $firstTs);
    if ($durationSeconds < 60) {
        return null;
    }

    return [
        'energy_wh' => $energyWh,
        'avg_power' => $durationSeconds > 0 ? ($energyWh / ($durationSeconds / 3600.0)) : 0.0,
        'peak_power' => $peakPower,
    ];
}

function calculatePredictionScore(array $currentSignature, array $historicalSignature, int $historicalDurationSeconds, int $elapsedSeconds): float
{
    $avgDiff = relativeDiff($currentSignature['avg_power'], $historicalSignature['avg_power'], 20.0);
    $peakDiff = relativeDiff($currentSignature['peak_power'], $historicalSignature['peak_power'], 40.0);
    $energyDiff = relativeDiff($currentSignature['energy_wh'], $historicalSignature['energy_wh'], 15.0);
    $durationRatio = $historicalDurationSeconds > 0 ? min(1.0, $elapsedSeconds / $historicalDurationSeconds) : 1.0;

    return ($avgDiff * 0.45) + ($peakDiff * 0.20) + ($energyDiff * 0.35) + ((1 - $durationRatio) * 0.10);
}

function relativeDiff(float $a, float $b, float $floor): float
{
    return abs($a - $b) / max($floor, abs($a), abs($b));
}

function predictionConfidenceLabel(float $bestScore, int $matches): string
{
    if ($matches >= 4 && $bestScore < 0.18) {
        return 'hoch';
    }

    if ($matches >= 3 && $bestScore < 0.35) {
        return 'mittel';
    }

    return 'niedrig';
}
