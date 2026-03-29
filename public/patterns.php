<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/history_processor.php';
require_once __DIR__ . '/../lib/pattern_catalog.php';

$config = getConfig();
$pdo = db();
runHistoryProcessing();

$devices = getPatternDeviceSummaries($pdo, $config);
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Programm-Muster</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:20px;color:#24323f}
        .wrap{max-width:1100px;margin:0 auto}
        .card{background:#fff;border-radius:12px;padding:18px;margin-bottom:18px;box-shadow:0 2px 10px rgba(0,0,0,.06)}
        .btn{display:inline-block;padding:8px 12px;background:#1f6feb;color:#fff;text-decoration:none;border-radius:8px}
        table{width:100%;border-collapse:collapse}
        th,td{padding:10px;border-bottom:1px solid #e8edf2;text-align:left}
        .muted{color:#6b7785}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <a class="btn" href="index.php">← Übersicht</a>
        <h1>Erkannte Programme pro Gerät</h1>
        <div class="muted">Historische Vorgänge werden je Gerät in ähnliche Muster gruppiert.</div>
    </div>

    <div class="card">
        <?php if (!$devices): ?>
            Keine Pattern-Daten gefunden. Prüfe, ob `cycle_patterns` angelegt ist und bereits abgeschlossene Vorgänge vorliegen.
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Gerät</th>
                    <th>Device ID</th>
                    <th>Abgeschlossene Vorgänge</th>
                    <th>Profilierte Vorgänge</th>
                    <th>Erkannte Programme</th>
                    <th>Aktion</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($devices as $device): ?>
                    <tr>
                        <td><?=h($device['device_name'])?></td>
                        <td><?=h($device['device_id'])?></td>
                        <td><?= (int)$device['cycle_count'] ?></td>
                        <td><?= (int)$device['profiled_cycles'] ?></td>
                        <td><?= (int)$device['pattern_count'] ?></td>
                        <td><a class="btn" href="device_patterns.php?device_id=<?=h($device['device_id'])?>">Details</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
