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
        $this->resetAfterTest();
    }

    /**
     * Build a temporary tree with a root, a prefix-colliding sibling and a
     * symlink escaping to it.
     *
     * Layout:
     *   <tmp>/moodle/lib/ok.php          — legitimate file inside the root
     *   <tmp>/moodle-backup/secret.txt   — sibling whose name shares the
     *                                      root's prefix (the old check's gap)
     *   <tmp>/moodle/evil -> ../moodle-backup — symlink escaping via realpath
     *
     * @return string Absolute path of the fake root (<tmp>/moodle)
     */
    private function make_fake_root(): string {
        $base = make_request_directory();
        $root = $base . '/moodle';
        mkdir($root . '/lib', 0777, true);
        file_put_contents($root . '/lib/ok.php', "<?php // ok\n");
        mkdir($base . '/moodle-backup', 0777, true);
        file_put_contents($base . '/moodle-backup/secret.txt', "secret\n");
        symlink($base . '/moodle-backup', $root . '/evil');
        return $root;
    }

    /**
     * Data provider for resolve_inside_root.
     *
     * @return array Each case: [relative path, whether it must resolve]
     */
    public static function boundary_path_provider(): array {
        return [
            'legitimate file'              => ['lib/ok.php', true],
            'simple traversal'             => ['../moodle-backup/secret.txt', false],
            'nested traversal'             => ['....//moodle-backup/secret.txt', false],
            'nested traversal under lib'   => ['lib/....//....//secret.txt', false],
            'traversal through lib'        => ['lib/../../moodle-backup/secret.txt', false],
            'colliding sibling via symlink' => ['evil/secret.txt', false],
            'traversal staying inside'     => ['lib/../lib/ok.php', false],
        ];
    }

    /**
     * Test resolve_inside_root rejects traversal and prefix-colliding siblings.
     *
     * The symlink case is the key one: realpath() lands on the sibling
     * moodle-backup, whose absolute path starts with the root's path — the
     * old prefix check without a trailing separator accepted it.
     *
     * @dataProvider boundary_path_provider
     * @param string $relpath Relative path to resolve against the fake root
     * @param bool $mustresolve Whether the path must resolve to a real file
     */
    public function test_resolve_inside_root(string $relpath, bool $mustresolve): void {
        $root = $this->make_fake_root();
        $result = read_file::resolve_inside_root($relpath, $root);

        if ($mustresolve) {
            $this->assertIsString($result, "Path {$relpath} should resolve inside the root");
            $this->assertFileExists($result);
        } else {
            $this->assertNull($result, "Path {$relpath} must be rejected");
        }
    }

    /**
     * Test a legitimate read inside dirroot still works through the full flow.
     */
    public function test_execute_legitimate_read(): void {
        $result = $this->execute_signed('lib/moodlelib.php');

        $this->assertTrue($result['exists'], 'A legitimate file inside dirroot should be readable');
        $this->assertNotEmpty($result['content']);
        $this->assertStringContainsString('1:', $result['content'], 'Content should carry line numbers');
        $this->assertGreaterThan(0, $result['total_lines']);
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
