<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/history_processor.php';

$config = getConfig();
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Laufende Programme</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:20px;color:#24323f}
        .wrap{max-width:1100px;margin:0 auto}
        .card{background:#fff;border-radius:12px;padding:18px;margin-bottom:18px;box-shadow:0 2px 10px rgba(0,0,0,.06)}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px}
        .btn{display:inline-block;padding:8px 12px;background:#1f6feb;color:#fff;text-decoration:none;border-radius:8px}
        .muted{color:#6b7785}
        .subcard{background:#f8fbff;border:1px solid #dbe6f2;border-radius:10px;padding:14px}
        .chart-wrap{height:240px;margin-top:14px}
        .stamp{font-size:13px;color:#6b7785}
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <a class="btn" href="index.php">← Übersicht</a>
        <h1>Laufende Programme</h1>
        <div class="muted">Prognosen basieren auf normierten 60-Punkte-Profilen früherer Vorgänge.</div>
        <div class="stamp" id="generatedAt">lade Live-Daten...</div>
    </div>

    <div id="liveContainer" class="grid"></div>
</div>
<script>
const liveContainer = document.getElementById('liveContainer');
const generatedAt = document.getElementById('generatedAt');
const charts = new Map();

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function renderCard(item) {
    const prediction = item.prediction;
    const predictionHtml = prediction ? `
        <p>Status: ${escapeHtml(prediction.status)}</p>
        <p>Wahrscheinlichster Typ: ${escapeHtml(prediction.profile_label)}</p>
        <p>Aktueller Wert: ${item.current_power.toFixed(1)} W</p>
        <p>Ähnlich zu ${prediction.matched_cycles} früheren Vorgängen</p>
        <p>Geschätzte Gesamtdauer: ${prediction.predicted_total_minutes} Minuten</p>
        <p>Bereits vergangen: ${item.elapsed_minutes} Minuten</p>
        <p>Noch verbleibend: ${prediction.remaining_minutes} Minuten</p>
        <p>Sicherheit: ${escapeHtml(prediction.confidence_label)}</p>
    ` : `
        <p>Status: läuft</p>
        <p>Aktueller Wert: ${item.current_power.toFixed(1)} W</p>
        <p>Geschätzte Gesamtdauer: noch keine belastbare Prognose</p>
        <p>Bereits vergangen: ${item.elapsed_minutes} Minuten</p>
        <p>Noch verbleibend: noch keine belastbare Prognose</p>
        <p>Sicherheit: niedrig</p>
    `;

    return `
        <div class="card" data-device-id="${escapeHtml(item.device_id)}">
            <h2 style="margin-top:0">${escapeHtml(item.device_name)}</h2>
            <div class="muted">Start: ${escapeHtml(item.cycle_start)}</div>
            <div class="subcard">
                <h3 style="margin-top:0">Prognose</h3>
                ${predictionHtml}
            </div>
            <div class="chart-wrap">
                <canvas id="chart-${escapeHtml(item.device_id)}"></canvas>
            </div>
        </div>
    `;
}

function upsertChart(item) {
    const canvas = document.getElementById(`chart-${item.device_id}`);
    if (!canvas) {
        return;
    }

    const data = {
        labels: item.chart.labels,
        datasets: [
            {
                label: 'Ist-Leistung',
                data: item.chart.actual,
                borderWidth: 2,
                borderColor: '#1f6feb',
                pointRadius: 0,
                tension: 0.15,
                spanGaps: true
            },
            {
                label: 'Prognose',
                data: item.chart.projection,
                borderWidth: 2,
                borderColor: '#d97706',
                borderDash: [6, 4],
                pointRadius: 0,
                tension: 0.15,
                spanGaps: true
            }
        ]
    };

    charts.set(item.device_id, new Chart(canvas, {
        type: 'line',
        data,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: true } },
            scales: {
                x: { ticks: { maxTicksLimit: 8 } },
                y: { title: { display: true, text: 'Watt' } }
            }
        }
    }));
}

async function refreshLiveView() {
    const response = await fetch('live_data.php', { cache: 'no-store' });
    const payload = await response.json();
    generatedAt.textContent = `zuletzt aktualisiert: ${new Date(payload.generated_at).toLocaleString('de-DE')}`;

    charts.forEach(chart => chart.destroy());
    charts.clear();

    if (!payload.items.length) {
        liveContainer.innerHTML = '<div class="card">Aktuell ist kein laufendes Programm erkannt.</div>';
        return;
    }

    liveContainer.innerHTML = payload.items.map(renderCard).join('');
    payload.items.forEach(upsertChart);
}

refreshLiveView();
setInterval(refreshLiveView, 15000);
</script>
</body>
</html>
