<?php
// tests/Unit/CsrfTest.php

namespace WLMonitor\Tests\Unit;

use PHPUnit\Framework\TestCase;

class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        // Start fresh: remove any token from a previous test
        unset($_SESSION['csrf_token'], $_POST['csrf_token'], $_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    // --- csrf_token() --------------------------------------------------------

    public function test_token_is_64_hex_chars(): void
    {
        $token = csrf_token();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function test_token_is_stored_in_session(): void
    {
        $token = csrf_token();
        $this->assertSame($token, $_SESSION['csrf_token']);
    }

    public function test_token_is_idempotent(): void
    {
        $first  = csrf_token();
        $second = csrf_token();
        $this->assertSame($first, $second);
    }

    public function test_tokens_differ_across_sessions(): void
    {
        $first = csrf_token();
        unset($_SESSION['csrf_token']);
        $second = csrf_token();
        $this->assertNotSame($first, $second);
    }

    // --- csrf_verify() -------------------------------------------------------

    public function test_verify_returns_false_with_no_session_token(): void
    {
        unset($_SESSION['csrf_token']);
        $_POST['csrf_token'] = 'anything';
        $this->assertFalse(csrf_verify());
    }

    public function test_verify_returns_false_with_wrong_post_token(): void
    {
        csrf_token(); // populate session
        $_POST['csrf_token'] = 'wrongtoken';
        $this->assertFalse(csrf_verify());
    }

    public function test_verify_returns_true_with_correct_post_token(): void
    {
        $token = csrf_token();
        $_POST['csrf_token'] = $token;
        $this->assertTrue(csrf_verify());
    }

    public function test_verify_returns_true_with_correct_header_token(): void
    {
        $token = csrf_token();
        unset($_POST['csrf_token']);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        $this->assertTrue(csrf_verify());
    }

    public function test_verify_returns_false_with_empty_post_token(): void
    {
        csrf_token();
        $_POST['csrf_token'] = '';
        $this->assertFalse(csrf_verify());
    }

    public function test_verify_post_takes_precedence_over_header(): void
    {
        $token = csrf_token();
        $_POST['csrf_token']          = $token;
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'wrongheader';
        $this->assertTrue(csrf_verify());
    }

    // --- csrf_input() --------------------------------------------------------

    public function test_input_renders_hidden_field(): void
    {
        $token = csrf_token();
        $html  = csrf_input();
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertStringContainsString('value="' . $token . '"', $html);
    }

    public function test_input_escapes_token(): void
    {
        // Force a token that contains HTML-special chars (won't happen in practice,
        // but the escaping path must be covered)
        $_SESSION['csrf_token'] = 'abc"def';
        $html = csrf_input();
        $this->assertStringNotContainsString('"def', $html);
        $this->assertStringContainsString('&quot;', $html);
    }
}
