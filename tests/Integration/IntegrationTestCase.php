<?php
// tests/Integration/IntegrationTestCase.php

namespace WLMonitor\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Base class for all integration tests.
 *
 * Each test runs inside a transaction that is rolled back in tearDown,
 * so no test data ever persists to the database.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected \mysqli $con;

    protected function setUp(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->con = new \mysqli(DATABASE_HOST, DATABASE_USER, DATABASE_PASS, DATABASE_NAME);
        $this->con->set_charset('utf8');
        $this->con->begin_transaction();

        // Auth and logging functions read these from session / server globals
        $_SESSION['id']         = 0;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    protected function tearDown(): void
    {
        $this->con->rollback();
    }

    /**
     * Insert a test user and return its ID.
     * All fields default to a sane activated, enabled User account.
     */
    protected function createUser(array $overrides = []): int
    {
        $d = array_merge([
            'username'        => 'testuser_' . uniqid(),
            'email'           => 'test_' . uniqid() . '@example.com',
            'password'        => password_hash('TestPass123!', PASSWORD_BCRYPT, ['cost' => 4]),
            'activation_code' => 'activated',
            'disabled'        => '0',
            'rights'          => 'User',
            'departures'      => 2,
            'debug'           => '0',
            'img'             => 'user-md-grey.svg',
            'img_type'        => '',
            'img_size'        => 0,
            'newMail'         => '',
            'lastLogin'       => date('Y-m-d H:i:s'),
            'invalidLogins'   => 0,
        ], $overrides);

        $stmt = $this->con->prepare(
            'INSERT INTO auth_accounts
                (username, email, password, activation_code, disabled, rights,
                 departures, debug, img, img_type, img_size, newMail, lastLogin, invalidLogins)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->bind_param(
            'ssssssisssiisi',
            $d['username'], $d['email'], $d['password'], $d['activation_code'],
            $d['disabled'], $d['rights'], $d['departures'], $d['debug'],
            $d['img'], $d['img_type'], $d['img_size'], $d['newMail'],
            $d['lastLogin'], $d['invalidLogins']
        );
        $stmt->execute();
        $id = (int) $this->con->insert_id;
        $stmt->close();
        return $id;
    }
}
