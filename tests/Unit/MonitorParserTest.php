<?php
// tests/Unit/MonitorParserTest.php
//
// Tests for monitor_get() that do not require a live API connection.
// Live-API tests are skipped when the Wiener Linien endpoint is unreachable.

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class MonitorParserTest extends TestCase
{
    private \mysqli $con;

    protected function setUp(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->con = new \mysqli(DATABASE_HOST, DATABASE_USER, DATABASE_PASS, DATABASE_NAME);
        $this->con->set_charset('utf8');
    }

    protected function tearDown(): void
    {
        $this->con->close();
    }

    // --- Input validation ----------------------------------------------------

    public function test_empty_diva_throws_invalid_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        monitor_get($this->con, '', 2);
    }

    public function test_letters_only_diva_throws_invalid_argument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        monitor_get($this->con, 'abcdef', 2);
    }

    // --- URL sanitization (via the public sanitizeDivaInput contract) --------

    public function test_diva_sanitization_strips_injection_before_url(): void
    {
        // sanitizeDivaInput is used by monitor_get before building the URL.
        // Confirm the function applied to a crafted string strips non-numeric/comma.
        $this->assertSame('123,456', sanitizeDivaInput('123;DROP TABLE ogd_stations;--,456'));
    }

    // --- Response parsing ----------------------------------------------------

    /**
     * Build a minimal API response array and verify monitor_get parses it correctly.
     * We swap out file_get_contents with a stream wrapper so no network call is made.
     */
    public function test_parses_single_station_single_line(): void
    {
        $fixture = json_encode([
            'message' => ['serverTime' => '2024-01-15T10:30:00+01:00'],
            'data'    => [
                'monitors' => [[
                    'locationStop' => [
                        'properties' => ['title' => 'Karlsplatz', 'name' => 'STK60200103'],
                    ],
                    'lines' => [[
                        'name'      => 'U1',
                        'towards'   => 'Leopoldau',
                        'type'      => 'ptMetro',
                        'direction' => 'H',
                        'platform'  => '1',
                        'departures' => [
                            'departure' => [
                                ['departureTime' => ['countdown' => 3]],
                                ['departureTime' => ['countdown' => 8]],
                            ],
                        ],
                    ]],
                ]],
            ],
        ]);

        MockHttpWrapper::setResponse($fixture);
        stream_wrapper_unregister('https');
        stream_wrapper_register('https', MockHttpWrapper::class);

        try {
            $result = monitor_get($this->con, '60200103', 2);
        } finally {
            stream_wrapper_restore('https');
        }

        $station = $result['STK60200103'];
        $line0   = $station['lines'][0];
        $this->assertSame('Karlsplatz', $station['station_name']);
        $this->assertSame('U1',        $line0['name']);
        $this->assertSame('Leopoldau', $line0['towards']);
        $this->assertSame('ptMetro',   $line0['type']);
        $this->assertSame('H',         $line0['direction']);
        $this->assertSame('1',         $line0['platform']);
        $this->assertSame('3, 8',      $line0['departures']);
        $this->assertSame(1,           $result['trains']);
        $this->assertSame('10:30:00',  $result['update_at']);
    }

    public function test_countdown_zero_renders_as_asterisk(): void
    {
        $fixture = json_encode([
            'message' => ['serverTime' => '2024-01-15T10:30:00+01:00'],
            'data'    => [
                'monitors' => [[
                    'locationStop' => [
                        'properties' => ['title' => 'Schwedenplatz', 'name' => 'STS123'],
                    ],
                    'lines' => [[
                        'name'     => 'U4',
                        'towards'  => 'Hütteldorf',
                        'platform' => '2',
                        'departures' => [
                            'departure' => [
                                ['departureTime' => ['countdown' => 0]],
                                ['departureTime' => ['countdown' => 5]],
                            ],
                        ],
                    ]],
                ]],
            ],
        ]);

        MockHttpWrapper::setResponse($fixture);
        stream_wrapper_unregister('https');
        stream_wrapper_register('https', MockHttpWrapper::class);

        try {
            $result = monitor_get($this->con, '60200105', 2);
        } finally {
            stream_wrapper_restore('https');
        }

        $this->assertSame('*, 5', $result['STS123']['lines'][0]['departures']);
    }

    public function test_max_departures_limits_departure_count(): void
    {
        $fixture = json_encode([
            'message' => ['serverTime' => '2024-01-15T10:30:00+01:00'],
            'data'    => [
                'monitors' => [[
                    'locationStop' => [
                        'properties' => ['title' => 'Test', 'name' => 'TST1'],
                    ],
                    'lines' => [[
                        'name'     => 'U2',
                        'towards'  => 'Seestadt',
                        'platform' => '1',
                        'departures' => [
                            'departure' => [
                                ['departureTime' => ['countdown' => 1]],
                                ['departureTime' => ['countdown' => 5]],
                                ['departureTime' => ['countdown' => 10]],
                            ],
                        ],
                    ]],
                ]],
            ],
        ]);

        MockHttpWrapper::setResponse($fixture);
        stream_wrapper_unregister('https');
        stream_wrapper_register('https', MockHttpWrapper::class);

        try {
            $result = monitor_get($this->con, '99999', 1);
        } finally {
            stream_wrapper_restore('https');
        }

        // Only 1 departure allowed; the third should be cut off
        $this->assertSame('1', $result['TST1']['lines'][0]['departures']);
    }

    public function test_throws_on_empty_monitors_array(): void
    {
        $fixture = json_encode([
            'message' => ['serverTime' => '2024-01-15T10:30:00+01:00'],
            'data'    => ['monitors' => []],
        ]);

        MockHttpWrapper::setResponse($fixture);
        stream_wrapper_unregister('https');
        stream_wrapper_register('https', MockHttpWrapper::class);

        $this->expectException(\RuntimeException::class);

        try {
            monitor_get($this->con, '60200103', 2);
        } finally {
            stream_wrapper_restore('https');
        }
    }

    public function test_throws_on_invalid_json(): void
    {
        MockHttpWrapper::setResponse('NOT JSON {{{');
        stream_wrapper_unregister('https');
        stream_wrapper_register('https', MockHttpWrapper::class);

        $this->expectException(\RuntimeException::class);

        try {
            monitor_get($this->con, '60200103', 2);
        } finally {
            stream_wrapper_restore('https');
        }
    }
}

/**
 * Minimal stream wrapper that returns a preset string for any https:// URL.
 * Only implements what file_get_contents() needs.
 */
class MockHttpWrapper
{
    private static string $response = '';
    private int $pos = 0;

    public static function setResponse(string $body): void
    {
        self::$response = $body;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $this->pos = 0;
        return true;
    }

    public function stream_read(int $count): string|false
    {
        $chunk = substr(self::$response, $this->pos, $count);
        $this->pos += strlen($chunk);
        return $chunk === '' ? false : $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen(self::$response);
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function url_stat(string $path, int $flags): array|false
    {
        return false;
    }
}
