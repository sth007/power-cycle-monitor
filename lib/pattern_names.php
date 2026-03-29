<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function loadPatternNames(PDO $pdo, string $deviceId): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT pattern_key, display_name
            FROM pattern_names
            WHERE device_id = :device_id
        ");
        $stmt->execute(['device_id' => $deviceId]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }

    $names = [];
    foreach ($rows as $row) {
        $names[(string)$row['pattern_key']] = (string)$row['display_name'];
    }

    return $names;
}

function savePatternName(PDO $pdo, string $deviceId, string $patternKey, string $displayName): void
{
    $displayName = trim($displayName);

    if ($displayName === '') {
        $stmt = $pdo->prepare("
            DELETE FROM pattern_names
            WHERE device_id = :device_id
              AND pattern_key = :pattern_key
        ");
        $stmt->execute([
            'device_id' => $deviceId,
            'pattern_key' => $patternKey,
        ]);
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO pattern_names (device_id, pattern_key, display_name)
        VALUES (:device_id, :pattern_key, :display_name)
        ON DUPLICATE KEY UPDATE
            display_name = VALUES(display_name),
            updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([
        'device_id' => $deviceId,
        'pattern_key' => $patternKey,
        'display_name' => $displayName,
    ]);
}
