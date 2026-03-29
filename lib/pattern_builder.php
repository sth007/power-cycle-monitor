<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function saveCyclePattern(PDO $pdo, array $config, int $cycleId, string $deviceId, string $cycleStart, string $cycleEnd, float $energyWh, float $peakPower): void
{
    if ($cycleId <= 0) {
        return;
    }

    $rows = fetchCyclePowerRows($pdo, $config['source_table'], $deviceId, $cycleStart, $cycleEnd);
    if (count($rows) < 2) {
        return;
    }

    $normalized = buildNormalizedPattern($rows, 60);
    if ($normalized === []) {
        return;
    }

    $phaseSignature = buildPhaseSignature($cycleStart, $cycleEnd, $energyWh, $peakPower, $normalized);

    $stmt = $pdo->prepare("
        INSERT INTO cycle_patterns (cycle_id, device_id, normalized_points, phase_signature)
        VALUES (:cycle_id, :device_id, :normalized_points, :phase_signature)
        ON DUPLICATE KEY UPDATE
            device_id = VALUES(device_id),
            normalized_points = VALUES(normalized_points),
            phase_signature = VALUES(phase_signature),
            created_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        'cycle_id' => $cycleId,
        'device_id' => $deviceId,
        'normalized_points' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
        'phase_signature' => json_encode($phaseSignature, JSON_UNESCAPED_UNICODE),
    ]);
}

function syncMissingCyclePatterns(PDO $pdo, array $config, int $limit = 25): int
{
    $limit = max(1, $limit);

    $stmt = $pdo->prepare("
        SELECT dc.id, dc.device_id, dc.cycle_start, dc.cycle_end, dc.energy_wh, dc.peak_power
        FROM {$config['cycles_table']} dc
        LEFT JOIN cycle_patterns cp ON cp.cycle_id = dc.id
        WHERE dc.is_closed = 1
          AND cp.id IS NULL
        ORDER BY dc.cycle_start DESC
        LIMIT {$limit}
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $count = 0;
    foreach ($rows as $row) {
        saveCyclePattern(
            $pdo,
            $config,
            (int)$row['id'],
            (string)$row['device_id'],
            (string)$row['cycle_start'],
            (string)$row['cycle_end'],
            (float)$row['energy_wh'],
            (float)$row['peak_power']
        );
        $count++;
    }

    return $count;
}

function fetchCyclePowerRows(PDO $pdo, string $sourceTable, string $deviceId, string $from, string $to): array
{
    $stmt = $pdo->prepare("
        SELECT dtmod, cur_power
        FROM {$sourceTable}
        WHERE device_id = :device_id
          AND dtmod BETWEEN :from_dt AND :to_dt
        ORDER BY dtmod ASC
    ");
    $stmt->execute([
        'device_id' => $deviceId,
        'from_dt' => $from,
        'to_dt' => $to,
    ]);

    return $stmt->fetchAll();
}

function buildNormalizedPattern(array $rows, int $segments = 60): array
{
    if (count($rows) < 2 || $segments < 2) {
        return [];
    }

    $startTs = strtotime((string)$rows[0]['dtmod']);
    $endTs = strtotime((string)$rows[count($rows) - 1]['dtmod']);
    if ($startTs === false || $endTs === false || $endTs <= $startTs) {
        return [];
    }

    $duration = $endTs - $startTs;
    $binSums = array_fill(0, $segments, 0.0);
    $binCounts = array_fill(0, $segments, 0);

    foreach ($rows as $row) {
        $ts = strtotime((string)$row['dtmod']);
        if ($ts === false) {
            continue;
        }

        $offset = min($duration, max(0, $ts - $startTs));
        $index = min($segments - 1, (int)floor(($offset / max(1, $duration)) * $segments));
        $binSums[$index] += (float)$row['cur_power'];
        $binCounts[$index]++;
    }

    $points = [];
    $lastValue = 0.0;
    for ($i = 0; $i < $segments; $i++) {
        if ($binCounts[$i] > 0) {
            $lastValue = $binSums[$i] / $binCounts[$i];
        }
        $points[] = round($lastValue, 3);
    }

    return $points;
}

function buildPhaseSignature(string $cycleStart, string $cycleEnd, float $energyWh, float $peakPower, array $normalizedPoints): array
{
    $startTs = strtotime($cycleStart);
    $endTs = strtotime($cycleEnd);
    $durationMinutes = ($startTs !== false && $endTs !== false && $endTs > $startTs)
        ? (int)round(($endTs - $startTs) / 60)
        : 0;

    return [
        'duration_bucket' => durationBucket($durationMinutes),
        'heat_level' => heatLevel($peakPower),
        'energy_wh' => round($energyWh, 3),
        'peak_power' => round($peakPower, 3),
        'avg_power' => round(array_sum($normalizedPoints) / max(1, count($normalizedPoints)), 3),
    ];
}

function durationBucket(int $minutes): string
{
    if ($minutes < 45) {
        return 'short';
    }
    if ($minutes < 90) {
        return 'medium';
    }
    return 'long';
}

function heatLevel(float $peakPower): string
{
    if ($peakPower >= 1800) {
        return 'strong';
    }
    if ($peakPower >= 900) {
        return 'medium';
    }
    return 'low';
}
