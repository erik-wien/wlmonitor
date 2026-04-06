<?php
// tests/Unit/SanitizeTest.php

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SanitizeTest extends TestCase
{
    // --- sanitizeDivaInput ---------------------------------------------------

    public function test_empty_string_returns_empty(): void
    {
        $this->assertSame('', sanitizeDivaInput(''));
    }

    public function test_single_valid_diva_passes_through(): void
    {
        $this->assertSame('60200103', sanitizeDivaInput('60200103'));
    }

    public function test_comma_separated_divas_pass_through(): void
    {
        $this->assertSame('60200103,60200104', sanitizeDivaInput('60200103,60200104'));
    }

    public function test_strips_letters(): void
    {
        $this->assertSame('123', sanitizeDivaInput('abc123'));
    }

    public function test_strips_html_tags(): void
    {
        $this->assertSame('1,2', sanitizeDivaInput('<script>1,2</script>'));
    }

    public function test_strips_spaces(): void
    {
        $this->assertSame('1,2', sanitizeDivaInput('1, 2'));
    }

    public function test_strips_special_characters(): void
    {
        $this->assertSame('123456', sanitizeDivaInput('123;456'));
    }

    public function test_preserves_multiple_commas(): void
    {
        $this->assertSame('1,2,3,4', sanitizeDivaInput('1,2,3,4'));
    }

    public function test_all_letters_returns_empty(): void
    {
        $this->assertSame('', sanitizeDivaInput('abcdef'));
    }

    // --- sanitizeRblInput alias ----------------------------------------------

    public function test_sanitize_rbl_is_alias(): void
    {
        $this->assertSame(
            sanitizeDivaInput('60200103,abc'),
            sanitizeRblInput('60200103,abc')
        );
    }
}
