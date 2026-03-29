<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

$config = getConfig();
$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Ungültige ID');
}

$cyclesTable = $config['cycles_table'];
$stmt = $pdo->prepare("SELECT * FROM {$cyclesTable} WHERE id = :id LIMIT 1");
$stmt->execute(['id' => $id]);
$cycle = $stmt->fetch();
if (!$cycle) {
    http_response_code(404);
    exit('Vorgang nicht gefunden');
}
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Vorgang #<?= (int)$cycle['id'] ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:20px;color:#24323f}
        .wrap{max-width:1200px;margin:0 auto}.card{background:#fff;border-radius:12px;padding:18px;margin-bottom:18px;box-shadow:0 2px 10px rgba(0,0,0,.06)}
        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.stat{background:#f9fbfc;border:1px solid #e0e8ef;border-radius:10px;padding:12px}
        canvas{width:100%;height:420px !important}.btn{display:inline-block;padding:8px 12px;background:#1f6feb;color:#fff;text-decoration:none;border-radius:8px}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <a class="btn" href="day.php?date=<?=h(substr($cycle['cycle_start'], 0, 10))?>">← zurück</a>
        <h1 style="margin-bottom:6px"><?=h($cycle['device_name'])?></h1>
        <div>Vorgang #<?= (int)$cycle['id'] ?></div>
    </div>

    <div class="card">
        <div class="stats">
            <div class="stat"><strong>Start</strong><br><?=h(dt($cycle['cycle_start']))?></div>
            <div class="stat"><strong>Ende</strong><br><?=h(dt($cycle['cycle_end']))?></div>
            <div class="stat"><strong>Dauer</strong><br><?=h(secondsToHuman((int)$cycle['duration_seconds']))?></div>
            <div class="stat"><strong>Energie</strong><br><?=number_format((float)$cycle['energy_wh'], 1, ',', '.')?> Wh</div>
            <div class="stat"><strong>Peak</strong><br><?=number_format((float)$cycle['peak_power'], 1, ',', '.')?> W</div>
            <div class="stat"><strong>Mittelwert</strong><br><?=number_format((float)$cycle['avg_power'], 1, ',', '.')?> W</div>
        </div>
    </div>

    <div class="card">
        <h2 style="margin-top:0">Leistungsverlauf</h2>
        <canvas id="powerChart"></canvas>
    </div>
</div>
<script>
fetch('chart_data.php?id=<?= (int)$cycle['id'] ?>')
    .then(r => r.json())
    .then(data => {
        new Chart(document.getElementById('powerChart'), {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Leistung (W)',
                    data: data.values,
                    borderWidth: 2,
                    pointRadius: 0,
                    tension: 0.15
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    tooltip: {
                        callbacks: {
                            afterBody: (items) => {
                                const idx = items[0].dataIndex;
                                return data.markers[idx] ? ['innerhalb erkannter Vorgang'] : ['Padding / Randbereich'];
                            }
                        }
                    }
                },
                scales: {
                    x: { ticks: { maxTicksLimit: 16 } },
                    y: { title: { display: true, text: 'Watt' } }
                }
            }
        });
    });
</script>
</body>
</html>
