<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/history_processor.php';
require_once __DIR__ . '/../lib/predictor.php';

$config = getConfig();
$pdo = db();
runHistoryProcessing();

$stateTable = $config['state_table'];
$liveItems = getLiveCyclePredictions($pdo, $config, $pdo->query("SELECT * FROM {$stateTable} ORDER BY device_name, device_id")->fetchAll());
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Laufende Programme</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:20px;color:#24323f}
        .wrap{max-width:1100px;margin:0 auto}
        .card{background:#fff;border-radius:12px;padding:18px;margin-bottom:18px;box-shadow:0 2px 10px rgba(0,0,0,.06)}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}
        .btn{display:inline-block;padding:8px 12px;background:#1f6feb;color:#fff;text-decoration:none;border-radius:8px}
        .muted{color:#6b7785}
        .subcard{background:#f8fbff;border:1px solid #dbe6f2;border-radius:10px;padding:14px}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <a class="btn" href="index.php">← Übersicht</a>
        <h1>Laufende Programme</h1>
        <div class="muted">Prognosen basieren auf normierten 60-Punkte-Profilen früherer Vorgänge.</div>
    </div>

    <?php if (!$liveItems): ?>
        <div class="card">Aktuell ist kein laufendes Programm erkannt.</div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($liveItems as $item): ?>
                <div class="card">
                    <h2 style="margin-top:0"><?=h($item['device_name'])?></h2>
                    <div class="subcard">
                        <h3 style="margin-top:0">Prognose</h3>
                        <?php if ($item['prediction']): ?>
                            <p>Status: <?=h($item['prediction']['status'])?></p>
                            <p>Wahrscheinlichster Typ: <?=h($item['prediction']['profile_label'])?></p>
                            <p>Ähnlich zu <?= (int)$item['prediction']['matched_cycles'] ?> früheren Vorgängen</p>
                            <p>Geschätzte Gesamtdauer: <?= (int)round(((int)$item['prediction']['predicted_total_seconds']) / 60) ?> Minuten</p>
                            <p>Bereits vergangen: <?= (int)round(((int)$item['prediction']['elapsed_seconds']) / 60) ?> Minuten</p>
                            <p>Noch verbleibend: <?= (int)ceil(((int)$item['prediction']['remaining_seconds']) / 60) ?> Minuten</p>
                            <p>Sicherheit: <?=h($item['prediction']['confidence_label'])?></p>
                        <?php else: ?>
                            <p>Status: läuft</p>
                            <p>Geschätzte Gesamtdauer: noch keine belastbare Prognose</p>
                            <p>Bereits vergangen: <?= (int)round(((int)$item['elapsed_seconds']) / 60) ?> Minuten</p>
                            <p>Noch verbleibend: noch keine belastbare Prognose</p>
                            <p>Sicherheit: niedrig</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
