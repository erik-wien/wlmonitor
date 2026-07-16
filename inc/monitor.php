<?php
/**
 * inc/monitor.php
 *
 * Fetches real-time departure data from the Wiener Linien OGD Realtime API
 * and returns it as a structured PHP array ready for JSON encoding.
 *
 * API endpoint
 * ────────────
 * GET https://www.wienerlinien.at/ogd_realtime/monitor
 *      ?diva=<DIVA>[&diva=<DIVA>…]
 *      &sender=<APIKEY>
 *      &activateTrafficInfo=stoerungkurz
 *      &activateTrafficInfo=stoerunglang
 *
 * DIVA numbers are 8-digit station identifiers from the ogd_haltestellen
 * table (field DIVA).  A single request can carry multiple DIVAs
 * (comma-separated in the input, repeated ?diva= parameters in the URL).
 *
 * Return structure
 * ────────────────
 * [
 *   '<stationId>' => [
 *     'id'           => string,   // e.g. 'STK60200103'
 *     'station_name' => string,   // e.g. 'Karlsplatz'
 *     'lines'        => [
 *       [
 *         'name'         => string, // e.g. 'U1'
 *         'towards'      => string, // e.g. 'Leopoldau'
 *         'type'         => string, // ptMetro | ptTram | ptBusCity | …
 *         'direction'    => string, // 'H' (outgoing) | 'R' (incoming)
 *         'platform'     => string,
 *         'barrier_free' => bool,   // line-level default
 *         'trafficjam'   => bool,
 *         'alert'        => bool,   // trafficjam OR referenced by an active trafficInfo
 *         'departures'   => [ ['t' => string, 'bf' => bool], … ], // 't' is countdown ('*' for 0)
 *       ],
 *       …
 *     ],
 *   ],
 *   …
 *   'alerts'    => [ ['title','description','priority','lines'=>[],'stops'=>[]], … ],
 *   'trains'    => int,    // total departure rows across all stations
 *   'update_at' => string, // server time formatted as 'H:i:s'
 *   'api_ping'  => int,    // server time minus local time in seconds
 * ]
 */

/**
 * Fetch and parse departure data for one or more DIVA numbers.
 *
 * @param mysqli $con          Active database connection (used by sanitizeDivaInput).
 * @param string $divaRaw      Raw DIVA input — comma-separated station IDs.
 *                             Non-numeric/non-comma characters are stripped.
 * @param int    $maxDepartures Maximum departure entries to include per line.
 *
 * @return array Structured departure data (see file docblock for shape).
 *
 * @throws InvalidArgumentException If $divaRaw contains no valid digits after sanitisation.
 * @throws RuntimeException         If the API request fails or returns invalid JSON,
 *                                  or if the API returns an empty monitors array.
 */
function monitor_get(mysqli $con, string $divaRaw, int $maxDepartures): array {
    $divaRaw = sanitizeDivaInput($divaRaw);
    if ($divaRaw === '') {
        throw new InvalidArgumentException('No valid DIVA numbers provided.');
    }

    // Build URL: each DIVA becomes a separate &diva= parameter.
    $apiUrl = 'https://www.wienerlinien.at/ogd_realtime/monitor?diva='
        . str_replace(',', '&diva=', $divaRaw)
        . '&sender=' . APIKEY
        . '&activateTrafficInfo=stoerungkurz&activateTrafficInfo=stoerunglang';

    $ctx = stream_context_create(['http' => ['timeout' => 10]]);
    $raw = file_get_contents($apiUrl, false, $ctx);
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

    // Build a lookup of active trafficInfos by name, plus a set of line names
    // each info affects (for the per-line ⚠️ flag).
    $activeInfos = [];
    $infosByLine = [];
    foreach ($json['data']['trafficInfos'] ?? [] as $info) {
        if (($info['status'] ?? 'active') !== 'active') continue;
        $name = $info['name'] ?? '';
        if ($name === '' || isset($activeInfos[$name])) continue;
        $activeInfos[$name] = [
            'title'           => $info['title']           ?? '',
            'description'     => $info['description']     ?? '',
            'descriptionHTML' => $info['descriptionHTML'] ?? '',
            'priority'        => $info['priority']        ?? '',
            'lines'           => $info['relatedLines']    ?? [],
            'stops'           => $info['relatedStops']    ?? [],
        ];
        foreach ($info['relatedLines'] ?? [] as $ln) {
            $infosByLine[$ln] = true;
        }
    }

    $result      = [];
    $totalTrains = 0;

    foreach ($monitors as $monitor) {
        $stationName = $monitor['locationStop']['properties']['title'];
        $stationId   = $monitor['locationStop']['properties']['name'];
        // Extract numeric DIVA from station code (e.g. "STK60200103" → "60200103")
        $stationDiva = $monitor['locationStop']['properties']['diva']['statId']
            ?? preg_replace('/\D/', '', $stationId);

        // The WL API returns one monitor entry per line, not per station — entries
        // for the same station are interleaved with entries for other stations.
        // Initialise on first encounter; subsequent entries append their lines.
        if (!isset($result[$stationId])) {
            $result[$stationId] = [
                'id'           => $stationId,
                'diva'         => $stationDiva,
                'station_name' => $stationName,
                'lines'        => [],
            ];
        }

        foreach ($monitor['lines'] as $line) {
            $lineBf       = (bool) ($line['barrierFree'] ?? false);
            $lineName     = $line['name'];
            $lineTowards  = $line['towards'];
            $deps         = [];
            $dCount       = 1;
            foreach ($line['departures']['departure'] ?? [] as $dep) {
                if ($dCount > $maxDepartures) break;
                $cd = $dep['departureTime']['countdown'];
                // vehicle.* fields override line defaults for a single run.
                $vehicle = $dep['vehicle'] ?? null;
                $depBf   = ($vehicle !== null && array_key_exists('barrierFree', $vehicle))
                    ? (bool) $vehicle['barrierFree']
                    : $lineBf;
                $depJam  = isset($vehicle['trafficjam']) ? (bool) $vehicle['trafficjam'] : false;
                $vName   = $vehicle['name']    ?? null;
                $vTow    = $vehicle['towards'] ?? null;
                $deps[]  = [
                    't'                => ($cd === 0 ? '*' : (string) $cd),
                    'bf'               => $depBf,
                    'jam'              => $depJam,
                    'name_override'    => ($vName !== null && $vName !== $lineName)    ? $vName : null,
                    'towards_override' => ($vTow  !== null && $vTow  !== $lineTowards) ? $vTow  : null,
                ];
                $dCount++;
            }

            $trafficjam = (bool) ($line['trafficjam'] ?? false);
            $hasAlert   = $trafficjam || isset($infosByLine[$lineName]);

            $result[$stationId]['lines'][] = [
                'name'               => $lineName,
                'towards'            => $lineTowards,
                'type'               => $line['type']      ?? '',
                'direction'          => $line['direction'] ?? '',
                'platform'           => $line['platform'],
                'barrier_free'       => $lineBf,
                'realtime_supported' => (bool) ($line['realtimeSupported'] ?? true),
                'trafficjam'         => $trafficjam,
                'alert'              => $hasAlert,
                'departures'         => $deps,
            ];

            $totalTrains++;
        }
    }

    $result['alerts']    = array_values($activeInfos);
    $result['trains']    = $totalTrains;
    $result['update_at'] = date_format(date_create($serverTime), 'H:i:s');
    $result['api_ping']  = strtotime($serverTime) - time();

    return $result;
}
