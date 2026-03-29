<?php
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'dbname' => 'YOUR_DATABASE',
        'user' => 'YOUR_USER',
        'pass' => 'YOUR_PASSWORD',
        'charset' => 'utf8mb4',
    ],

    // Rohdaten-Tabelle mit dtmod, cur_power, device_id, device_name
    'source_table' => 'power_log',

    // Erzeugte Verlaufstabellen
    'cycles_table' => 'detected_cycles',
    'state_table'  => 'history_processing_state',

    // Standardwerte für die Zykluserkennung
    'thresholds' => [
        'default' => [
            'power_threshold_w' => 10.0,
            'max_idle_gap_minutes' => 10,
            'min_cycle_minutes' => 5,
        ],

        // Optionale gerätespezifische Überschreibungen nach device_id
        'by_device_id' => [
            // '1424480070039f2ed0a3' => [
            //     'power_threshold_w' => 20.0,
            //     'max_idle_gap_minutes' => 8,
            //     'min_cycle_minutes' => 5,
            // ],
        ],

        // Optionale Regeln nach Name (Teilstring, case-insensitive)
        'by_device_name_contains' => [
            'waschmaschine' => [
                'power_threshold_w' => 8.0,
                'max_idle_gap_minutes' => 12,
                'min_cycle_minutes' => 8,
            ],
            'trockner' => [
                'power_threshold_w' => 20.0,
                'max_idle_gap_minutes' => 10,
                'min_cycle_minutes' => 8,
            ],
            'geschirrspüler' => [
                'power_threshold_w' => 8.0,
                'max_idle_gap_minutes' => 15,
                'min_cycle_minutes' => 10,
            ],
        ],
    ],

    'ui' => [
        'chart_padding_before_minutes' => 2,
        'chart_padding_after_minutes' => 2,
        'chart_max_points' => 1200,
    ],
];
