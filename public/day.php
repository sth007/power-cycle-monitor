<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

$config = getConfig();
$pdo = db();
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    exit('Ungültiges Datum');
}

$cyclesTable = $config['cycles_table'];
$stmt = $pdo->prepare("SELECT * FROM {$cyclesTable} WHERE is_closed = 1 AND DATE(cycle_start) = :date ORDER BY cycle_start ASC");
$stmt->execute(['date' => $date]);
$cycles = $stmt->fetchAll();
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Vorgänge am <?=h($date)?></title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:20px;color:#24323f}
        .wrap{max-width:1100px;margin:0 auto}.card{background:#fff;border-radius:12px;padding:18px;margin-bottom:18px;box-shadow:0 2px 10px rgba(0,0,0,.06)}
        table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e8edf2;text-align:left}
        a.btn{display:inline-block;padding:8px 12px;background:#1f6feb;color:#fff;text-decoration:none;border-radius:8px}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <a class="btn" href="index.php">← Übersicht</a>
        <h1>Vorgänge am <?=h(dt($date, 'd.m.Y'))?></h1>
    </div>

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>Gerät</th>
                <th>Start</th>
                <th>Ende</th>
                <th>Dauer</th>
                <th>Energie</th>
                <th>Peak</th>
                <th>Aktion</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$cycles): ?>
                <tr><td colspan="7">Keine abgeschlossenen Vorgänge gefunden.</td></tr>
            <?php else: foreach ($cycles as $cycle): ?>
                <tr>
                    <td><?=h($cycle['device_name'])?></td>
                    <td><?=h(dt($cycle['cycle_start']))?></td>
                    <td><?=h(dt($cycle['cycle_end']))?></td>
                    <td><?=h(secondsToHuman((int)$cycle['duration_seconds']))?></td>
                    <td><?=number_format((float)$cycle['energy_wh'], 1, ',', '.')?> Wh</td>
                    <td><?=number_format((float)$cycle['peak_power'], 1, ',', '.')?> W</td>
                    <td><a class="btn" href="cycle.php?id=<?= (int)$cycle['id'] ?>">Grafik</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
