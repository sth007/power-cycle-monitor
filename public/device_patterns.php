<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/history_processor.php';
require_once __DIR__ . '/../lib/pattern_catalog.php';

$config = getConfig();
$pdo = db();
runHistoryProcessing();

$deviceId = trim((string)($_GET['device_id'] ?? ''));
if ($deviceId === '') {
    http_response_code(400);
    exit('Device ID fehlt');
}

$catalog = getDevicePatternCatalog($pdo, $config, $deviceId);
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Programme für <?=h($catalog['device_name'])?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:20px;color:#24323f}
        .wrap{max-width:1200px;margin:0 auto}
        .card{background:#fff;border-radius:12px;padding:18px;margin-bottom:18px;box-shadow:0 2px 10px rgba(0,0,0,.06)}
        .btn{display:inline-block;padding:8px 12px;background:#1f6feb;color:#fff;text-decoration:none;border-radius:8px}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px}
        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin:12px 0}
        .stat{background:#f8fbff;border:1px solid #dbe6f2;border-radius:10px;padding:10px}
        canvas{width:100%;height:220px !important}
        table{width:100%;border-collapse:collapse}
        th,td{padding:10px;border-bottom:1px solid #e8edf2;text-align:left}
        .muted{color:#6b7785}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <a class="btn" href="patterns.php">← Geräte</a>
        <h1 style="margin-bottom:6px"><?=h($catalog['device_name'])?></h1>
        <div class="muted"><?=h($catalog['device_id'])?>, <?= (int)$catalog['cycle_count'] ?> profilierte historische Vorgänge</div>
    </div>

    <?php if (!$catalog['patterns']): ?>
        <div class="card">Für dieses Gerät konnten noch keine Muster erkannt werden.</div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($catalog['patterns'] as $index => $pattern): ?>
                <div class="card">
                    <h2 style="margin-top:0"><?=h($pattern['label'])?></h2>
                    <div class="muted"><?=h($pattern['profile_label'])?></div>
                    <div class="stats">
                        <div class="stat"><strong>Anzahl</strong><br><?= (int)$pattern['count'] ?></div>
                        <div class="stat"><strong>Dauer</strong><br><?=h(secondsToHuman((int)$pattern['avg_duration_seconds']))?></div>
                        <div class="stat"><strong>Energie</strong><br><?=number_format((float)$pattern['avg_energy_wh'], 1, ',', '.')?> Wh</div>
                        <div class="stat"><strong>Peak</strong><br><?=number_format((float)$pattern['avg_peak_power'], 1, ',', '.')?> W</div>
                    </div>
                    <canvas id="patternChart<?= $index ?>"></canvas>
                    <h3>Letzte passende Vorgänge</h3>
                    <table>
                        <thead>
                        <tr>
                            <th>Start</th>
                            <th>Dauer</th>
                            <th>Energie</th>
                            <th>Aktion</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pattern['recent_cycles'] as $cycle): ?>
                            <tr>
                                <td><?=h(dt($cycle['cycle_start']))?></td>
                                <td><?=h(secondsToHuman((int)$cycle['duration_seconds']))?></td>
                                <td><?=number_format((float)$cycle['energy_wh'], 1, ',', '.')?> Wh</td>
                                <td><a class="btn" href="cycle.php?id=<?= (int)$cycle['cycle_id'] ?>">Grafik</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<script>
const patternCharts = <?= json_encode(array_map(static function (array $pattern): array {
    return [
        'label' => $pattern['label'],
        'points' => $pattern['centroid_points'],
    ];
}, $catalog['patterns']), JSON_UNESCAPED_UNICODE) ?>;

patternCharts.forEach((pattern, index) => {
    new Chart(document.getElementById(`patternChart${index}`), {
        type: 'line',
        data: {
            labels: pattern.points.map((_, idx) => idx + 1),
            datasets: [{
                label: `${pattern.label} mittlere Leistung`,
                data: pattern.points,
                borderWidth: 2,
                pointRadius: 0,
                tension: 0.2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: true } },
            scales: {
                x: { title: { display: true, text: 'Normierter Abschnitt (1-60)' } },
                y: { title: { display: true, text: 'Watt' } }
            }
        }
    });
});
</script>
</body>
</html>
