<?php
// inc/monitor.php
// Fetch departure data from Wiener Linien API and return structured array

function monitor_get(mysqli $con, string $divaRaw, int $maxDepartures): array {
    $divaRaw = sanitizeDivaInput($divaRaw);
    if ($divaRaw === '') {
        throw new InvalidArgumentException('No valid DIVA numbers provided.');
    }

    $apiUrl = 'https://www.wienerlinien.at/ogd_realtime/monitor?diva='
        . str_replace(',', '&diva=', $divaRaw)
        . '&sender=' . APIKEY
        . '&activateTrafficInfo=stoerungkurz&activateTrafficInfo=stoerunglang';

    $raw = @file_get_contents($apiUrl);
    if ($raw === false) {
        throw new RuntimeException('Wiener Linien API request failed.');
    }

    $json = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Invalid JSON from API: ' . json_last_error_msg());
    }

    $monitors   = $json['data']['monitors'] ?? [];
    $serverTime = $json['message']['serverTime'] ?? date('c');

    if (count($monitors) === 0) {
        throw new RuntimeException('No monitors found for the given DIVA numbers.');
    }

    $result = [];
    $prevStationId = null;
    $trainCount = 0;
    $totalTrains = 0;
    $stationData = [];

    foreach ($monitors as $monitor) {
        $stationName = $monitor['locationStop']['properties']['title'];
        $stationId   = $monitor['locationStop']['properties']['name'];

        if ($stationId !== $prevStationId) {
            $prevStationId = $stationId;
            $trainCount    = 0;
            $stationData   = ['id' => $stationId, 'station_name' => $stationName];
        }

        foreach ($monitor['lines'] as $line) {
            $stationData['train_'     . $trainCount] = $line['name'] . ' -> ' . $line['towards'];
            $stationData['platform_'  . $trainCount] = $line['platform'];
            $stationData['departure_' . $trainCount] = '';

            $dCount = 1;
            foreach ($line['departures']['departure'] ?? [] as $dep) {
                if ($dCount > $maxDepartures) break;
                if ($stationData['departure_' . $trainCount] !== '') {
                    $stationData['departure_' . $trainCount] .= ', ';
                }
                $cd = $dep['departureTime']['countdown'];
                $stationData['departure_' . $trainCount] .= ($cd === 0 ? '*' : $cd);
                $dCount++;
            }
            $trainCount++;
            $totalTrains++;
        }

        $result[$stationId] = $stationData;
    }

    $result['trains']    = $totalTrains;
    $result['update_at'] = date_format(date_create($serverTime), 'H:i:s');
    $result['api_ping']  = strtotime($serverTime) - time();

    return $result;
}
