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
 *         'name'       => string, // e.g. 'U1'
 *         'towards'    => string, // e.g. 'Leopoldau'
 *         'type'       => string, // ptMetro | ptTram | ptBusCity | …
 *         'direction'  => string, // 'H' (outgoing) | 'R' (incoming)
 *         'platform'   => string,
 *         'departures' => string, // e.g. '3, 8' or '*, 5' (0 → *)
 *       ],
 *       …
 *     ],
 *   ],
 *   …
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

    $result      = [];
    $prevStation = null;
    $totalTrains = 0;
    $stationData = [];

    foreach ($monitors as $monitor) {
        $stationName = $monitor['locationStop']['properties']['title'];
        $stationId   = $monitor['locationStop']['properties']['name'];

        // Start a new station block when the station changes.
        if ($stationId !== $prevStation) {
            $prevStation = $stationId;
            $stationData = [
                'id'           => $stationId,
                'station_name' => $stationName,
                'lines'        => [],
            ];
        }

        foreach ($monitor['lines'] as $line) {
            $depStr = '';
            $dCount = 1;
            foreach ($line['departures']['departure'] ?? [] as $dep) {
                if ($dCount > $maxDepartures) break;
                if ($depStr !== '') $depStr .= ', ';
                $cd = $dep['departureTime']['countdown'];
                // Countdown 0 means the vehicle is at the platform now.
                $depStr .= ($cd === 0 ? '*' : $cd);
                $dCount++;
            }

            $stationData['lines'][] = [
                'name'       => $line['name'],
                'towards'    => $line['towards'],
                'type'       => $line['type']      ?? '',
                'direction'  => $line['direction'] ?? '',
                'platform'   => $line['platform'],
                'departures' => $depStr,
            ];

            $totalTrains++;
        }

        $result[$stationId] = $stationData;
    }

    $result['trains']    = $totalTrains;
    $result['update_at'] = date_format(date_create($serverTime), 'H:i:s');
    $result['api_ping']  = strtotime($serverTime) - time();

    return $result;
}
