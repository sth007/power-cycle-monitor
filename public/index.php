<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/history_processor.php';
require_once __DIR__ . '/../lib/prediction.php';

$config = getConfig();
$pdo = db();
$processInfo = runHistoryProcessing();

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

[$year, $monthNum] = array_map('intval', explode('-', $month));
$firstDay = sprintf('%04d-%02d-01', $year, $monthNum);
$daysInMonth = (int)date('t', strtotime($firstDay));
$firstWeekday = (int)date('N', strtotime($firstDay));
$prevMonth = date('Y-m', strtotime($firstDay . ' -1 month'));
$nextMonth = date('Y-m', strtotime($firstDay . ' +1 month'));

$cyclesTable = $config['cycles_table'];
$stmt = $pdo->prepare("SELECT DATE(cycle_start) AS day_key, COUNT(*) AS cycle_count, SUM(energy_wh) AS total_energy FROM {$cyclesTable} WHERE is_closed = 1 AND cycle_start >= :start AND cycle_start < DATE_ADD(:start, INTERVAL 1 MONTH) GROUP BY DATE(cycle_start)");
$stmt->execute(['start' => $firstDay]);
$calendarData = [];
foreach ($stmt->fetchAll() as $row) {
    $calendarData[$row['day_key']] = $row;
}

$stateTable = $config['state_table'];
$stateRows = $pdo->query("SELECT device_id, device_name, last_processed_dt, carry_active, carry_cycle_start FROM {$stateTable} ORDER BY device_name, device_id")->fetchAll();
$openPredictions = getOpenCyclePredictions($pdo, $config, $pdo->query("SELECT * FROM {$stateTable} ORDER BY device_name, device_id")->fetchAll());
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Waschvorgang-Historie</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:20px;color:#24323f}
        .wrap{max-width:1200px;margin:0 auto}
        .card{background:#fff;border-radius:12px;padding:18px;margin-bottom:18px;box-shadow:0 2px 10px rgba(0,0,0,.06)}
        .topbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
        .calendar{display:grid;grid-template-columns:repeat(7,1fr);gap:8px}
        .weekday,.day{padding:10px;border-radius:10px}
        .weekday{background:#e9eef3;font-weight:bold;text-align:center}
        .day{background:#f9fbfc;min-height:96px;border:1px solid #dde5ec}
        .day.empty{background:transparent;border:none}
        .day a{text-decoration:none;color:inherit;display:block;height:100%}
        .count{display:inline-block;margin-top:8px;background:#1f6feb;color:#fff;padding:4px 8px;border-radius:999px;font-size:12px}
        .muted{color:#6b7785}
        table{width:100%;border-collapse:collapse}
        th,td{padding:10px;border-bottom:1px solid #e8edf2;text-align:left}
        .btn{display:inline-block;padding:8px 12px;background:#1f6feb;color:#fff;text-decoration:none;border-radius:8px}
        .small{font-size:13px}
        .running-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}
        .running{border:1px solid #d9e7d1;background:#f8fff4}
        .eta{display:inline-block;margin-top:10px;background:#17803d;color:#fff;padding:6px 10px;border-radius:999px;font-weight:bold}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="topbar">
            <div>
                <h1 style="margin:0 0 6px 0">Waschvorgang-Historie</h1>
                <div class="muted small">Historie wird bei jedem Aufruf nur ab dem letzten verarbeiteten Zeitpunkt fortgeschrieben.</div>
            </div>
            <div>
                <a class="btn" href="?month=<?=h($prevMonth)?>">◀</a>
                <strong style="margin:0 10px"><?=h(monthName($monthNum) . ' ' . $year)?></strong>
                <a class="btn" href="?month=<?=h($nextMonth)?>">▶</a>
            </div>
        </div>
    </div>

    <div class="card">
        <strong>Aktueller Lauf:</strong>
        <?= (int)$processInfo['processed_devices'] ?> Geräte geprüft,
        <?= (int)$processInfo['new_cycles_written'] ?> abgeschlossene Vorgänge neu/aktualisiert gespeichert.
    </div>

    <?php if ($openPredictions): ?>
    <div class="card">
        <h2 style="margin-top:0">Laufende Vorgänge mit Restzeit</h2>
        <div class="running-grid">
            <?php foreach ($openPredictions as $item): ?>
                <div class="card running" style="margin:0">
                    <strong><?=h($item['device_name'])?></strong>
                    <div class="small muted" style="margin-top:8px">Start: <?=h(dt($item['cycle_start']))?></div>
                    <div class="small">Bisher: <?=h(secondsToHuman((int)$item['duration_seconds']))?></div>
                    <div class="small">Verbrauch bisher: <?=number_format((float)$item['energy_wh'], 1, ',', '.')?> Wh</div>
                    <?php if ($item['prediction']): ?>
                        <div class="eta">noch ca. <?= (int)ceil(((int)$item['prediction']['remaining_seconds']) / 60) ?> min</div>
                        <div class="small muted" style="margin-top:8px">
                            Gesamt geschätzt: <?=h(secondsToHuman((int)$item['prediction']['predicted_total_seconds']))?>,
                            Prognose <?=h($item['prediction']['confidence_label'])?>,
                            Musterbasis <?= (int)$item['prediction']['matched_cycles'] ?> Zyklen
                        </div>
                    <?php else: ?>
                        <div class="small muted" style="margin-top:10px">Noch keine belastbare Prognose. Es fehlen entweder genügend Historienläufe oder ein paar Minuten aktuelle Laufzeit.</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="calendar">
            <?php foreach (['Mo','Di','Mi','Do','Fr','Sa','So'] as $wd): ?>
                <div class="weekday"><?=h($wd)?></div>
            <?php endforeach; ?>

            <?php for ($i = 1; $i < $firstWeekday; $i++): ?>
                <div class="day empty"></div>
            <?php endfor; ?>

            <?php for ($day = 1; $day <= $daysInMonth; $day++):
                $date = sprintf('%04d-%02d-%02d', $year, $monthNum, $day);
                $info = $calendarData[$date] ?? null;
            ?>
                <div class="day">
                    <a href="day.php?date=<?=h($date)?>">
                        <strong><?= $day ?></strong>
                        <?php if ($info): ?>
                            <div class="count"><?= (int)$info['cycle_count'] ?> Vorgänge</div>
                            <div class="small" style="margin-top:8px"><?= number_format((float)$info['total_energy'], 1, ',', '.') ?> Wh</div>
                        <?php else: ?>
                            <div class="muted small" style="margin-top:12px">keine Vorgänge</div>
                        <?php endif; ?>
                    </a>
                </div>
            <?php endfor; ?>
        </div>
    </div>

    <div class="card">
        <h2 style="margin-top:0">Verarbeitungsstatus pro Gerät</h2>
        <table>
            <thead>
                <tr>
                    <th>Gerät</th>
                    <th>Device ID</th>
                    <th>Letzter gelesener Zeitpunkt</th>
                    <th>Offener Vorgang</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($stateRows as $row): ?>
                <tr>
                    <td><?=h($row['device_name'])?></td>
                    <td><?=h($row['device_id'])?></td>
                    <td><?=h($row['last_processed_dt'])?></td>
                    <td><?= (int)$row['carry_active'] === 1 ? 'ja, seit ' . h($row['carry_cycle_start']) : 'nein' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
