<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/pattern_builder.php';
require_once __DIR__ . '/pattern_names.php';

function getPatternDeviceSummaries(PDO $pdo, array $config): array
{
    $cyclesTable = $config['cycles_table'];

    try {
        $stmt = $pdo->query("
            SELECT
                dc.device_id,
                MAX(dc.device_name) AS device_name,
                COUNT(*) AS cycle_count,
                COUNT(cp.id) AS profiled_cycles
            FROM {$cyclesTable} dc
            LEFT JOIN cycle_patterns cp ON cp.cycle_id = dc.id
            WHERE dc.is_closed = 1
            GROUP BY dc.device_id
            ORDER BY device_name, device_id
        ");
        $devices = $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }

    foreach ($devices as &$device) {
        $catalog = getDevicePatternCatalog($pdo, $config, (string)$device['device_id']);
        $device['pattern_count'] = count($catalog['patterns']);
    }
    unset($device);

    return $devices;
}

function getDevicePatternCatalog(PDO $pdo, array $config, string $deviceId): array
{
    $cyclesTable = $config['cycles_table'];
    $customNames = loadPatternNames($pdo, $deviceId);

    try {
        $stmt = $pdo->prepare("
            SELECT
                dc.id,
                dc.device_id,
                dc.device_name,
                dc.cycle_start,
                dc.cycle_end,
                dc.duration_seconds,
                dc.energy_wh,
                dc.avg_power,
                dc.peak_power,
                cp.normalized_points,
                cp.phase_signature
            FROM cycle_patterns cp
            INNER JOIN {$cyclesTable} dc ON dc.id = cp.cycle_id
            WHERE dc.device_id = :device_id
              AND dc.is_closed = 1
            ORDER BY dc.cycle_start DESC
        ");
        $stmt->execute(['device_id' => $deviceId]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        return [
            'device_id' => $deviceId,
            'device_name' => $deviceId,
            'patterns' => [],
            'cycle_count' => 0,
        ];
    }

    if (!$rows) {
        return [
            'device_id' => $deviceId,
            'device_name' => $deviceId,
            'patterns' => [],
            'cycle_count' => 0,
        ];
    }

    $clusters = [];
    foreach ($rows as $row) {
        $points = json_decode((string)$row['normalized_points'], true);
        if (!is_array($points) || count($points) !== 60) {
            continue;
        }

        $phaseSignature = json_decode((string)$row['phase_signature'], true);
        $candidate = [
            'cycle_id' => (int)$row['id'],
            'cycle_start' => (string)$row['cycle_start'],
            'cycle_end' => (string)$row['cycle_end'],
            'duration_seconds' => (int)$row['duration_seconds'],
            'energy_wh' => (float)$row['energy_wh'],
            'avg_power' => (float)$row['avg_power'],
            'peak_power' => (float)$row['peak_power'],
            'points' => array_map('floatval', $points),
            'phase_signature' => is_array($phaseSignature) ? $phaseSignature : [],
        ];

        $bestIndex = null;
        $bestDistance = null;
        foreach ($clusters as $index => $cluster) {
            $distance = patternClusterDistance($candidate, $cluster);
            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $bestIndex = $index;
            }
        }

        if ($bestIndex !== null && $bestDistance !== null && $bestDistance <= 0.34) {
            $clusters[$bestIndex]['items'][] = $candidate;
            recalculatePatternCluster($clusters[$bestIndex]);
            continue;
        }

        $cluster = [
            'items' => [$candidate],
            'centroid_points' => $candidate['points'],
            'avg_duration_seconds' => $candidate['duration_seconds'],
            'avg_energy_wh' => $candidate['energy_wh'],
            'avg_peak_power' => $candidate['peak_power'],
            'duration_bucket' => (string)($candidate['phase_signature']['duration_bucket'] ?? durationBucket((int)round($candidate['duration_seconds'] / 60))),
            'heat_level' => (string)($candidate['phase_signature']['heat_level'] ?? heatLevel($candidate['peak_power'])),
        ];
        recalculatePatternCluster($cluster);
        $clusters[] = $cluster;
    }

    usort($clusters, static function (array $a, array $b): int {
        return count($b['items']) <=> count($a['items']);
    });

    $patterns = [];
    foreach ($clusters as $index => $cluster) {
        $patterns[] = finalizePatternCluster($cluster, $index, $customNames);
    }

    return [
        'device_id' => $deviceId,
        'device_name' => (string)$rows[0]['device_name'],
        'patterns' => $patterns,
        'cycle_count' => count($rows),
    ];
}

function patternClusterDistance(array $candidate, array $cluster): float
{
    $shape = comparePointSeries($candidate['points'], $cluster['centroid_points']);
    $duration = catalogRelativeDiff((float)$candidate['duration_seconds'], (float)$cluster['avg_duration_seconds'], 300.0);
    $energy = catalogRelativeDiff((float)$candidate['energy_wh'], (float)$cluster['avg_energy_wh'], 50.0);
    $peak = catalogRelativeDiff((float)$candidate['peak_power'], (float)$cluster['avg_peak_power'], 50.0);

    return ($shape * 0.60) + ($duration * 0.20) + ($energy * 0.12) + ($peak * 0.08);
}

function comparePointSeries(array $a, array $b): float
{
    $count = min(count($a), count($b));
    if ($count < 2) {
        return 1.0;
    }

    $error = 0.0;
    for ($i = 0; $i < $count; $i++) {
        $error += catalogRelativeDiff((float)$a[$i], (float)$b[$i], 25.0);
    }

    return $error / $count;
}

function recalculatePatternCluster(array &$cluster): void
{
    $count = count($cluster['items']);
    $centroid = array_fill(0, 60, 0.0);
    $durationSum = 0.0;
    $energySum = 0.0;
    $peakSum = 0.0;
    $durationBuckets = [];
    $heatLevels = [];

    foreach ($cluster['items'] as $item) {
        for ($i = 0; $i < 60; $i++) {
            $centroid[$i] += (float)$item['points'][$i];
        }
        $durationSum += (float)$item['duration_seconds'];
        $energySum += (float)$item['energy_wh'];
        $peakSum += (float)$item['peak_power'];

        $durationBucket = (string)($item['phase_signature']['duration_bucket'] ?? durationBucket((int)round($item['duration_seconds'] / 60)));
        $heatLevel = (string)($item['phase_signature']['heat_level'] ?? heatLevel((float)$item['peak_power']));
        $durationBuckets[$durationBucket] = ($durationBuckets[$durationBucket] ?? 0) + 1;
        $heatLevels[$heatLevel] = ($heatLevels[$heatLevel] ?? 0) + 1;
    }

    for ($i = 0; $i < 60; $i++) {
        $centroid[$i] = round($centroid[$i] / max(1, $count), 3);
    }

    $cluster['centroid_points'] = $centroid;
    $cluster['avg_duration_seconds'] = $durationSum / max(1, $count);
    $cluster['avg_energy_wh'] = $energySum / max(1, $count);
    $cluster['avg_peak_power'] = $peakSum / max(1, $count);
    arsort($durationBuckets);
    arsort($heatLevels);
    $cluster['duration_bucket'] = (string)array_key_first($durationBuckets);
    $cluster['heat_level'] = (string)array_key_first($heatLevels);
}

function finalizePatternCluster(array $cluster, int $index, array $customNames): array
{
    usort($cluster['items'], static function (array $a, array $b): int {
        return strcmp($b['cycle_start'], $a['cycle_start']);
    });

    $patternKey = buildPatternKey($cluster);
    $defaultLabel = 'Programm ' . chr(65 + $index);

    return [
        'pattern_key' => $patternKey,
        'label' => $customNames[$patternKey] ?? $defaultLabel,
        'default_label' => $defaultLabel,
        'profile_label' => sprintf('%s / %s', germanDurationBucket((string)$cluster['duration_bucket']), germanHeatLevel((string)$cluster['heat_level'])),
        'count' => count($cluster['items']),
        'avg_duration_seconds' => (int)round($cluster['avg_duration_seconds']),
        'avg_energy_wh' => round((float)$cluster['avg_energy_wh'], 1),
        'avg_peak_power' => round((float)$cluster['avg_peak_power'], 1),
        'centroid_points' => $cluster['centroid_points'],
        'recent_cycles' => array_slice(array_map(static function (array $item): array {
            return [
                'cycle_id' => $item['cycle_id'],
                'cycle_start' => $item['cycle_start'],
                'duration_seconds' => $item['duration_seconds'],
                'energy_wh' => $item['energy_wh'],
            ];
        }, $cluster['items']), 0, 5),
    ];
}

function buildPatternKey(array $cluster): string
{
    $signature = [
        'duration_bucket' => (string)$cluster['duration_bucket'],
        'heat_level' => (string)$cluster['heat_level'],
        'duration_minutes' => (int)round(((float)$cluster['avg_duration_seconds']) / 60),
        'energy_wh' => (int)round((float)$cluster['avg_energy_wh']),
        'peak_power' => (int)round((float)$cluster['avg_peak_power'] / 25),
        'shape' => array_values(array_map(
            static fn(float $value): int => (int)round($value / 25),
            array_intersect_key($cluster['centroid_points'], array_flip([0, 6, 12, 18, 24, 30, 36, 42, 48, 54, 59]))
        )),
    ];

    return sha1(json_encode($signature));
}

function germanDurationBucket(string $bucket): string
{
    return match ($bucket) {
        'short' => 'kurz',
        'medium' => 'mittel',
        'long' => 'lang',
        default => $bucket,
    };
}

function germanHeatLevel(string $level): string
{
    return match ($level) {
        'low' => 'schwaches Heizen',
        'medium' => 'mittleres Heizen',
        'strong' => 'starkes Heizen',
        default => $level,
    };
}

function catalogRelativeDiff(float $a, float $b, float $floor): float
{
    return abs($a - $b) / max($floor, abs($a), abs($b));
}
