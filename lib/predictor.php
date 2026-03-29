<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/pattern_builder.php';

function getLiveCyclePredictions(PDO $pdo, array $config, array $stateRows): array
{
    $items = [];

    foreach ($stateRows as $row) {
        if ((int)($row['carry_active'] ?? 0) !== 1) {
            continue;
        }

        $currentCycle = buildCurrentCycleSnapshot($pdo, $config, $row);
        if ($currentCycle === null) {
            continue;
        }

        $items[] = $currentCycle;
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp($a['cycle_start'], $b['cycle_start']);
    });

    return $items;
}

function buildCurrentCycleSnapshot(PDO $pdo, array $config, array $stateRow): ?array
{
    $cycleStart = (string)($stateRow['carry_cycle_start'] ?? '');
    $lastTs = (string)($stateRow['carry_last_ts'] ?? '');
    if ($cycleStart === '' || $lastTs === '') {
        return null;
    }

    $startTs = strtotime($cycleStart);
    $lastTsTs = strtotime($lastTs);
    if ($startTs === false || $lastTsTs === false || $lastTsTs <= $startTs) {
        return null;
    }

    $rows = fetchCyclePowerRows($pdo, $config['source_table'], (string)$stateRow['device_id'], $cycleStart, $lastTs);
    if (count($rows) < 2) {
        return null;
    }

    $normalized = buildNormalizedPattern($rows, 60);
    $elapsedSeconds = max(0, $lastTsTs - $startTs);
    $energyWh = (float)($stateRow['carry_energy_wh'] ?? 0);
    $peakPower = (float)($stateRow['carry_peak_power'] ?? 0);
    $prediction = predictRemainingRuntime($pdo, $config, (string)$stateRow['device_id'], $elapsedSeconds, $energyWh, $peakPower, $normalized);

    return [
        'device_id' => (string)$stateRow['device_id'],
        'device_name' => (string)$stateRow['device_name'],
        'status' => 'laeuft',
        'cycle_start' => $cycleStart,
        'last_ts' => $lastTs,
        'elapsed_seconds' => $elapsedSeconds,
        'energy_wh' => $energyWh,
        'peak_power' => $peakPower,
        'normalized_points' => $normalized,
        'prediction' => $prediction,
    ];
}

function predictRemainingRuntime(PDO $pdo, array $config, string $deviceId, int $elapsedSeconds, float $energyWh, float $peakPower, array $normalizedPoints): ?array
{
    $predictionConfig = $config['prediction'] ?? [];
    $minPredictAfterMinutes = max(0, (int)($predictionConfig['min_predict_after_minutes'] ?? 3));
    $minHistory = max(1, (int)($predictionConfig['min_history_cycles'] ?? 3));
    $historyLimit = max($minHistory, (int)($predictionConfig['history_limit'] ?? 30));

    if ($elapsedSeconds < ($minPredictAfterMinutes * 60)) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT
                dc.id,
                dc.duration_seconds,
                dc.energy_wh,
                dc.peak_power,
                cp.normalized_points,
                cp.phase_signature
            FROM cycle_patterns cp
            INNER JOIN {$config['cycles_table']} dc ON dc.id = cp.cycle_id
            WHERE cp.device_id = :device_id
              AND dc.is_closed = 1
            ORDER BY dc.cycle_start DESC
            LIMIT {$historyLimit}
        ");
        $stmt->execute(['device_id' => $deviceId]);
        $history = $stmt->fetchAll();
    } catch (PDOException $e) {
        return null;
    }

    if (count($history) < $minHistory) {
        return null;
    }

    $candidates = [];

    foreach ($history as $row) {
        $historicalPoints = json_decode((string)$row['normalized_points'], true);
        if (!is_array($historicalPoints) || count($historicalPoints) < 10) {
            continue;
        }

        $historicalProgressBins = max(2, min(60, (int)floor(($elapsedSeconds / max(1, (int)$row['duration_seconds'])) * 60)));
        $historicalPrefix = array_slice($historicalPoints, 0, $historicalProgressBins);
        if (count($historicalPrefix) < 2) {
            continue;
        }

        $currentComparable = resamplePoints($normalizedPoints, count($historicalPrefix));
        $shapeScore = compareNormalizedPrefixes($currentComparable, $historicalPrefix);
        $energyScore = relativeDiff($energyWh, (float)$row['energy_wh'], 20.0);
        $peakScore = relativeDiff($peakPower, (float)$row['peak_power'], 40.0);
        $durationScore = abs($elapsedSeconds - min($elapsedSeconds, (int)$row['duration_seconds'])) / max(60, (int)$row['duration_seconds']);
        $totalScore = ($shapeScore * 0.55) + ($energyScore * 0.20) + ($peakScore * 0.15) + ($durationScore * 0.10);

        $phaseSignature = json_decode((string)$row['phase_signature'], true);
        $candidates[] = [
            'cycle_id' => (int)$row['id'],
            'duration_seconds' => (int)$row['duration_seconds'],
            'score' => $totalScore,
            'weight' => 1 / max(0.05, $totalScore),
            'profile_label' => buildProfileLabel(is_array($phaseSignature) ? $phaseSignature : []),
        ];
    }

    if (count($candidates) < $minHistory) {
        return null;
    }

    usort($candidates, static function (array $a, array $b): int {
        return $a['score'] <=> $b['score'];
    });
    $matches = array_slice($candidates, 0, min(5, count($candidates)));
    $weightSum = array_sum(array_column($matches, 'weight'));
    if ($weightSum <= 0) {
        return null;
    }

    $predictedTotalSeconds = 0.0;
    foreach ($matches as $match) {
        $predictedTotalSeconds += $match['duration_seconds'] * ($match['weight'] / $weightSum);
    }

    $predictedTotalSeconds = (int)round(max($elapsedSeconds, $predictedTotalSeconds));
    $remainingSeconds = max(0, $predictedTotalSeconds - $elapsedSeconds);

    return [
        'status' => 'läuft',
        'predicted_total_seconds' => $predictedTotalSeconds,
        'elapsed_seconds' => $elapsedSeconds,
        'remaining_seconds' => $remainingSeconds,
        'confidence_label' => predictionConfidenceLabel((float)$matches[0]['score'], count($matches)),
        'matched_cycles' => count($matches),
        'profile_label' => $matches[0]['profile_label'],
    ];
}

function compareNormalizedPrefixes(array $a, array $b): float
{
    $count = min(count($a), count($b));
    if ($count < 2) {
        return 1.0;
    }

    $error = 0.0;
    for ($i = 0; $i < $count; $i++) {
        $error += relativeDiff((float)$a[$i], (float)$b[$i], 25.0);
    }

    return $error / $count;
}

function resamplePoints(array $points, int $targetCount): array
{
    $sourceCount = count($points);
    if ($targetCount <= 0 || $sourceCount === 0) {
        return [];
    }
    if ($sourceCount === $targetCount) {
        return array_values($points);
    }

    $out = [];
    for ($i = 0; $i < $targetCount; $i++) {
        $sourceIndex = (int)floor(($i / max(1, $targetCount - 1)) * max(0, $sourceCount - 1));
        $out[] = (float)$points[$sourceIndex];
    }

    return $out;
}

function buildProfileLabel(array $phaseSignature): string
{
    $durationBucket = (string)($phaseSignature['duration_bucket'] ?? 'unknown');
    $heatLevel = (string)($phaseSignature['heat_level'] ?? 'unknown');

    return sprintf('Profil %s / %s', $durationBucket, $heatLevel);
}

function predictionConfidenceLabel(float $bestScore, int $matches): string
{
    if ($matches >= 4 && $bestScore < 0.18) {
        return 'hoch';
    }
    if ($matches >= 3 && $bestScore < 0.32) {
        return 'mittel';
    }
    return 'niedrig';
}

function relativeDiff(float $a, float $b, float $floor): float
{
    return abs($a - $b) / max($floor, abs($a), abs($b));
}
