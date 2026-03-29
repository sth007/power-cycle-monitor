-- Rohdaten-Tabelle ist bereits vorhanden.
-- Dieses SQL ergänzt die Verlaufstabellen und sinnvolle Indizes.

CREATE TABLE IF NOT EXISTS detected_cycles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_id VARCHAR(30) NOT NULL,
    device_name VARCHAR(100) NOT NULL,
    cycle_start DATETIME NOT NULL,
    cycle_end DATETIME NOT NULL,
    duration_seconds INT UNSIGNED NOT NULL,
    energy_wh DECIMAL(12,3) NOT NULL DEFAULT 0,
    avg_power DECIMAL(12,3) NOT NULL DEFAULT 0,
    peak_power DECIMAL(12,3) NOT NULL DEFAULT 0,
    sample_count INT UNSIGNED NOT NULL DEFAULT 0,
    is_closed TINYINT(1) NOT NULL DEFAULT 1,
    source_last_dt DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_cycle (device_id, cycle_start, cycle_end),
    KEY idx_cycle_start (cycle_start),
    KEY idx_device_start (device_id, cycle_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS history_processing_state (
    device_id VARCHAR(30) NOT NULL,
    device_name VARCHAR(100) NOT NULL,
    last_processed_dt DATETIME DEFAULT NULL,
    carry_active TINYINT(1) NOT NULL DEFAULT 0,
    carry_cycle_start DATETIME DEFAULT NULL,
    carry_last_ts DATETIME DEFAULT NULL,
    carry_last_active_ts DATETIME DEFAULT NULL,
    carry_prev_power DECIMAL(12,3) NOT NULL DEFAULT 0,
    carry_energy_wh DECIMAL(12,3) NOT NULL DEFAULT 0,
    carry_peak_power DECIMAL(12,3) NOT NULL DEFAULT 0,
    carry_sample_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cycle_patterns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cycle_id INT NOT NULL,
    device_id VARCHAR(30) NOT NULL,
    normalized_points JSON NOT NULL,
    phase_signature JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cycle (cycle_id),
    KEY idx_device_id (device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Für schnellere Rohdatenabfrage:
-- Den Tabellennamen power_log ggf. anpassen.
CREATE INDEX idx_powerlog_device_dtmod ON power_log (device_id, dtmod);
