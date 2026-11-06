<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Unit tests for the jedomi_read_file external function path boundary.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

use local_jedomi\external\read_file;

/**
 * Test cases for read_file dirroot boundary enforcement.
 *
 * @covers \local_jedomi\external\read_file
 */
final class read_file_test extends \advanced_testcase {
    /**
     * Setup test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists(\aiprovider_datacurso\httpclient\ai_services_api::class)) {
            $this->markTestSkipped('aiprovider_datacurso is not installed; the web service checks the plugin state through it.');
        }
        $this->resetAfterTest();
    }

    /**
     * Test a legitimate read inside dirroot still works through the full flow.
     *
     * NOTE: the boundary check (resolve_inside_root) is private — it only
     * enforces traversal/root-containment, while BLOCKED_PATHS/ALLOWED_PREFIXES
     * are enforced by execute(). All boundary coverage below therefore goes
     * through execute() (the only public entry point) so no gate can be
     * bypassed by a direct call.
     */
    public function test_execute_legitimate_read(): void {
        $result = $this->execute_signed('lib/moodlelib.php');

        $this->assertTrue($result['exists'], 'A legitimate file inside dirroot should be readable');
        $this->assertNotEmpty($result['content']);
        $this->assertStringContainsString('1:', $result['content'], 'Content should carry line numbers');
        $this->assertGreaterThan(0, $result['total_lines']);
    }

    /**
     * Test execute() blocks a sensitive path via the BLOCKED_PATHS list,
     * even though it never contains a traversal segment and is a real file.
     */
    public function test_execute_blocks_sensitive_path(): void {
        $this->expectException(\moodle_exception::class);
        $this->execute_signed('config.php');
    }

    /**
     * Test execute() blocks a real, non-traversal path that is simply
     * outside the ALLOWED_PREFIXES whitelist.
     */
    public function test_execute_blocks_path_outside_allowlist(): void {
        $this->expectException(\moodle_exception::class);
        $this->execute_signed('README.md');
    }

    /**
     * Test a nested traversal path is rejected end to end.
     *
     * PARAM_PATH validation rejects it at the external API layer before it
     * reaches the boundary check — resolve_inside_root remains the second
     * layer should that validation ever change.
     */
    public function test_execute_rejects_traversal_path(): void {
        $this->expectException(\core\exception\invalid_parameter_exception::class);
        $this->execute_signed('lib/....//....//etc/passwd');
    }

    /**
     * Test execute() blocks a symlink escaping dirroot (real on-disk fixture).
     *
     * A symlink inside the whitelisted local/ tree pointing outside dirroot
     * is the realistic escape without ".." segments: realpath() resolves it
     * and the boundary check must reject the result.
     */
    public function test_execute_blocks_symlink_escape(): void {
        global $CFG;

        $fixturedir = $CFG->dirroot . '/local/jedomi/tests/fixtures';
        if (!is_writable($CFG->dirroot . '/local/jedomi/tests')) {
            $this->markTestSkipped('dirroot tests directory not writable, cannot create symlink fixture');
        }

        $outside = make_request_directory();
        file_put_contents($outside . '/secret.txt', "outside dirroot\n");
        @mkdir($fixturedir);
        $linkpath = $fixturedir . '/escape_link';
        @unlink($linkpath);
        symlink($outside, $linkpath);

        try {
            $result = $this->execute_signed('local/jedomi/tests/fixtures/escape_link/secret.txt');
            $this->assertFalse($result['exists'], 'A symlink escaping dirroot must not be readable');
            $this->assertSame('', $result['content']);
        } finally {
            @unlink($linkpath);
            @rmdir($fixturedir);
        }
    }

    /**
     * Test execute() blocks a sibling directory with a colliding name prefix.
     *
     * The fixture is a real sibling of dirroot (e.g. /var/www/html-jedomitest
     * next to /var/www/html) reached via a symlink: its realpath STARTS WITH
     * the dirroot string, so the old separator-less prefix check accepted it.
     */
    public function test_execute_blocks_prefix_colliding_sibling(): void {
        global $CFG;

        $parent = dirname($CFG->dirroot);
        if (!is_writable($parent) || !is_writable($CFG->dirroot . '/local/jedomi/tests')) {
            $this->markTestSkipped('dirroot parent not writable, cannot create colliding sibling fixture');
        }

        $sibling = $CFG->dirroot . '-jedomitest';
        $fixturedir = $CFG->dirroot . '/local/jedomi/tests/fixtures';
        @mkdir($sibling);
        file_put_contents($sibling . '/secret.txt', "colliding sibling\n");
        @mkdir($fixturedir);
        $linkpath = $fixturedir . '/collide_link';
        @unlink($linkpath);
        symlink($sibling, $linkpath);

        try {
            $result = $this->execute_signed('local/jedomi/tests/fixtures/collide_link/secret.txt');
            $this->assertFalse(
                $result['exists'],
                'A sibling whose name shares the dirroot prefix must not be readable'
            );
            $this->assertSame('', $result['content']);
        } finally {
            @unlink($linkpath);
            @rmdir($fixturedir);
            @unlink($sibling . '/secret.txt');
            @rmdir($sibling);
        }
    }

    /**
     * Call read_file::execute with a valid HMAC signature as admin.
     *
     * Mirrors companion_hmac::verify_hmac: the signed message is
     * "{function}:{timestamp}:{payload hash}" with the shared secret.
     *
     * @param string $path Relative path to request
     * @return array The external function response
     */
    private function execute_signed(string $path): array {
        $this->setAdminUser();

        $secret = bin2hex(random_bytes(32));
        set_config('hmac_secret', $secret, 'local_jedomi');

        $timestamp = time();
        $payloadhash = hash('sha256', 'test-payload');
        $signature = hash_hmac('sha256', "jedomi_read_file:{$timestamp}:{$payloadhash}", $secret);

        return read_file::execute($path, 1, 5, $signature, $timestamp, $payloadhash);
    }
}
