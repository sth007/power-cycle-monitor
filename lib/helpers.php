<?php
declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function dt(?string $value, string $format = 'd.m.Y H:i:s'): string
{
    if (!$value) {
        return '-';
    }
    $ts = strtotime($value);
    return $ts ? date($format, $ts) : $value;
}

function secondsToHuman(int $seconds): string
{
    $seconds = max(0, $seconds);
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;

    if ($h > 0) {
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
    return sprintf('%02d:%02d', $m, $s);
}

function monthName(int $month): string
{
    $names = [1=>'Januar',2=>'Februar',3=>'März',4=>'April',5=>'Mai',6=>'Juni',7=>'Juli',8=>'August',9=>'September',10=>'Oktober',11=>'November',12=>'Dezember'];
    return $names[$month] ?? (string)$month;
}

function resolveDeviceThresholds(array $config, string $deviceId, string $deviceName): array
{
    $base = $config['thresholds']['default'];

    if (!empty($config['thresholds']['by_device_id'][$deviceId])) {
        return array_merge($base, $config['thresholds']['by_device_id'][$deviceId]);
    }

    $deviceNameLc = mb_strtolower($deviceName);
    foreach ($config['thresholds']['by_device_name_contains'] as $needle => $override) {
        if (mb_strpos($deviceNameLc, mb_strtolower($needle)) !== false) {
            return array_merge($base, $override);
        }
    }

    return $base;
}

function downsampleRows(array $rows, int $maxPoints): array
{
    $count = count($rows);
    if ($count <= $maxPoints || $maxPoints < 2) {
        return $rows;
    }

    $step = (int)ceil($count / $maxPoints);
    $out = [];
    for ($i = 0; $i < $count; $i += $step) {
        $out[] = $rows[$i];
    }
    if (end($out) !== end($rows)) {
        $out[] = end($rows);
    }
    return $out;
}
