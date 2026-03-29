<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function runHistoryProcessing(): array
{
	$pdo = db();
	$config = getConfig();
	$sourceTable = $config['source_table'];
	$cyclesTable = $config['cycles_table'];
	$stateTable = $config['state_table'];

	$devices = $pdo->query("SELECT DISTINCT device_id, MAX(device_name) AS device_name FROM {$sourceTable} GROUP BY device_id ORDER BY device_id")->fetchAll();

	$processedDevices = 0;
	$newCycles = 0;

	foreach ($devices as $device) {
		$deviceId = (string)$device['device_id'];
		$deviceName = (string)($device['device_name'] ?? $deviceId);

		$stateStmt = $pdo->prepare("SELECT * FROM {$stateTable} WHERE device_id = :device_id");
		$stateStmt->execute(['device_id' => $deviceId]);
		$state = $stateStmt->fetch();

		$startDt = $state['last_processed_dt'] ?? null;
		if ($startDt === null) {
			$minStmt = $pdo->prepare("SELECT MIN(dtmod) FROM {$sourceTable} WHERE device_id = :device_id");
			$minStmt->execute(['device_id' => $deviceId]);
			$startDt = $minStmt->fetchColumn();
		}

		if (!$startDt) {
			continue;
		}

		$sql = $state
			? "SELECT dtmod, cur_power, device_id, device_name FROM {$sourceTable} WHERE device_id = :device_id AND dtmod > :start_dt ORDER BY dtmod ASC"
			: "SELECT dtmod, cur_power, device_id, device_name FROM {$sourceTable} WHERE device_id = :device_id AND dtmod >= :start_dt ORDER BY dtmod ASC";

		$stmt = $pdo->prepare($sql);
		$stmt->execute([
			'device_id' => $deviceId,
			'start_dt' => $startDt,
		]);

		$thresholds = resolveDeviceThresholds($config, $deviceId, $deviceName);
		$powerThreshold = (float)$thresholds['power_threshold_w'];
		$maxIdleGapSeconds = (int)$thresholds['max_idle_gap_minutes'] * 60;
		$minCycleSeconds = (int)$thresholds['min_cycle_minutes'] * 60;

		$current = restoreCarryState($state);
		$lastProcessedDt = $state['last_processed_dt'] ?? null;
		$hasRows = false;

		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$hasRows = true;

			$dt = (string)$row['dtmod'];
			$power = (float)$row['cur_power'];
			$deviceName = (string)($row['device_name'] ?: $deviceName);
			$isActive = $power >= $powerThreshold;

			if ($current === null) {
				if ($isActive) {
					$current = [
						'cycle_start' => $dt,
						'last_ts' => $dt,
						'last_active_ts' => $dt,
						'prev_power' => $power,
						'energy_wh' => 0.0,
						'peak_power' => $power,
						'sample_count' => 1,
						'device_name' => $deviceName,
					];
				}
				$lastProcessedDt = $dt;
				continue;
			}

			$deltaSeconds = max(0, strtotime($dt) - strtotime($current['last_ts']));
			$current['energy_wh'] += ($current['prev_power'] * $deltaSeconds) / 3600.0;
			$current['last_ts'] = $dt;
			$current['prev_power'] = $power;
			$current['sample_count']++;
			$current['peak_power'] = max($current['peak_power'], $power);
			$current['device_name'] = $deviceName;

			if ($isActive) {
				$current['last_active_ts'] = $dt;
			}

			$idleSeconds = max(0, strtotime($dt) - strtotime($current['last_active_ts']));
			if ($idleSeconds > $maxIdleGapSeconds) {
				$cycleStartTs = strtotime($current['cycle_start']);
				$cycleEndTs = strtotime($current['last_active_ts']);
				$durationSeconds = max(0, $cycleEndTs - $cycleStartTs);

				if ($durationSeconds >= $minCycleSeconds) {
					upsertCycle($pdo, $cyclesTable, $deviceId, $current, $durationSeconds, true, $current['last_active_ts']);
					$newCycles++;
				}

				$current = null;

				if ($isActive) {
					$current = [
						'cycle_start' => $dt,
						'last_ts' => $dt,
						'last_active_ts' => $dt,
						'prev_power' => $power,
						'energy_wh' => 0.0,
						'peak_power' => $power,
						'sample_count' => 1,
						'device_name' => $deviceName,
					];
				}
			}

			$lastProcessedDt = $dt;
		}

		$stmt->closeCursor();

		if (!$hasRows) {
			continue;
		}

		saveProcessingState($pdo, $stateTable, $deviceId, $deviceName, $lastProcessedDt, $current);
		$processedDevices++;
	}

	return [
		'processed_devices' => $processedDevices,
		'new_cycles_written' => $newCycles,
	];
}

function restoreCarryState($state): ?array
{
	if (!$state || (int)$state['carry_active'] !== 1) {
		return null;
	}

	return [
		'cycle_start' => $state['carry_cycle_start'],
		'last_ts' => $state['carry_last_ts'],
		'last_active_ts' => $state['carry_last_active_ts'],
		'prev_power' => (float)$state['carry_prev_power'],
		'energy_wh' => (float)$state['carry_energy_wh'],
		'peak_power' => (float)$state['carry_peak_power'],
		'sample_count' => (int)$state['carry_sample_count'],
		'device_name' => (string)$state['device_name'],
	];
}

function upsertCycle(PDO $pdo, string $cyclesTable, string $deviceId, array $current, int $durationSeconds, bool $isClosed, string $sourceLastDt): void
{
	$avgPower = $durationSeconds > 0 ? ($current['energy_wh'] / ($durationSeconds / 3600.0)) : 0.0;

	$sql = "
	INSERT INTO {$cyclesTable}
	    (device_id, device_name, cycle_start, cycle_end, duration_seconds, energy_wh, avg_power, peak_power, sample_count, is_closed, source_last_dt)
	VALUES
	    (:device_id, :device_name, :cycle_start, :cycle_end, :duration_seconds, :energy_wh, :avg_power, :peak_power, :sample_count, :is_closed, :source_last_dt)
	ON DUPLICATE KEY UPDATE
	    device_name = VALUES(device_name),
	    duration_seconds = VALUES(duration_seconds),
	    energy_wh = VALUES(energy_wh),
	    avg_power = VALUES(avg_power),
	    peak_power = VALUES(peak_power),
	    sample_count = VALUES(sample_count),
	    is_closed = VALUES(is_closed),
	    source_last_dt = VALUES(source_last_dt),
	    updated_at = CURRENT_TIMESTAMP
    ";

	$stmt = $pdo->prepare($sql);
	$stmt->execute([
		'device_id' => $deviceId,
		'device_name' => $current['device_name'],
		'cycle_start' => $current['cycle_start'],
		'cycle_end' => $current['last_active_ts'],
		'duration_seconds' => $durationSeconds,
		'energy_wh' => round($current['energy_wh'], 3),
		'avg_power' => round($avgPower, 3),
		'peak_power' => round($current['peak_power'], 3),
		'sample_count' => $current['sample_count'],
		'is_closed' => $isClosed ? 1 : 0,
		'source_last_dt' => $sourceLastDt,
	]);
}

function saveProcessingState(PDO $pdo, string $stateTable, string $deviceId, string $deviceName, ?string $lastProcessedDt, ?array $current): void
{
	$sql = "
	INSERT INTO {$stateTable}
	    (device_id, device_name, last_processed_dt, carry_active, carry_cycle_start, carry_last_ts, carry_last_active_ts, carry_prev_power, carry_energy_wh, carry_peak_power, carry_sample_count)
	VALUES
	    (:device_id, :device_name, :last_processed_dt, :carry_active, :carry_cycle_start, :carry_last_ts, :carry_last_active_ts, :carry_prev_power, :carry_energy_wh, :carry_peak_power, :carry_sample_count)
	ON DUPLICATE KEY UPDATE
	    device_name = VALUES(device_name),
	    last_processed_dt = VALUES(last_processed_dt),
	    carry_active = VALUES(carry_active),
	    carry_cycle_start = VALUES(carry_cycle_start),
	    carry_last_ts = VALUES(carry_last_ts),
	    carry_last_active_ts = VALUES(carry_last_active_ts),
	    carry_prev_power = VALUES(carry_prev_power),
	    carry_energy_wh = VALUES(carry_energy_wh),
	    carry_peak_power = VALUES(carry_peak_power),
	    carry_sample_count = VALUES(carry_sample_count),
	    updated_at = CURRENT_TIMESTAMP
    ";

	$stmt = $pdo->prepare($sql);
	$stmt->execute([
		'device_id' => $deviceId,
		'device_name' => $deviceName,
		'last_processed_dt' => $lastProcessedDt,
		'carry_active' => $current ? 1 : 0,
		'carry_cycle_start' => $current['cycle_start'] ?? null,
		'carry_last_ts' => $current['last_ts'] ?? null,
		'carry_last_active_ts' => $current['last_active_ts'] ?? null,
		'carry_prev_power' => $current['prev_power'] ?? 0,
		'carry_energy_wh' => $current['energy_wh'] ?? 0,
		'carry_peak_power' => $current['peak_power'] ?? 0,
		'carry_sample_count' => $current['sample_count'] ?? 0,
	]);
}
