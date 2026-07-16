<?php
function log_cycle($data) {
    $file = __DIR__ . '/autonomous-cycle-log.json';
    if (!file_exists($file)) {
        file_put_contents($file, json_encode(['cycles'=>[]], JSON_PRETTY_PRINT));
    }
    $json = json_decode(file_get_contents($file), true);
    if (!isset($json['cycles'])) $json['cycles'] = [];

    $last = end($json['cycles']);
    if ($last && $last['task'] === $data['task'] && $last['result'] === $data['result']) {
        return; // evitar duplicidade
    }

    $json['cycles'][] = $data;

    file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT));
}
