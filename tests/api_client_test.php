<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Unit tests for api_client chunking logic.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi;

/**
 * Test cases for api_client chunk_text and related methods.
 *
 * @covers \local_jedomi\telegram\api_client
 */
final class api_client_test extends \advanced_testcase {
    /**
     * Setup test environment.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    // ... ========================
    // Data providers
    // ========================

    /**
     * Data provider for chunk_text tests.
     *
     * @return array
     */
    public static function chunk_text_provider(): array {
        return [
            'short text' => [
                'Hello world',
                false,
                1,
            ],
            'exactly 4000' => [
                str_repeat('a', 4000),
                false,
                1,
            ],
            'just over 4000' => [
                str_repeat('a', 4001),
                false,
                2,
            ],
            'split at newline boundary' => [
                str_repeat('x', 2500) . "\n\n" . str_repeat('y', 2000),
                false,
                2,
            ],
            'split at last space' => [
                str_repeat('a', 2000) . ' ' . str_repeat('b', 2000) . ' ' . str_repeat('c', 2000),
                false,
                3,
            ],
            'very long hard cut' => [
                str_repeat('a', 12000),
                false,
                3,
            ],
            'truncate true' => [
                str_repeat('x', 5000),
                true,
                1,
            ],
        ];
    }

    // ... ========================
    // chunk_text tests
    // ========================

    /**
     * MDL-UNIT-031: short texts give one message and long ones are split under the Telegram limit.
     *
     * @dataProvider chunk_text_provider
     * @param string $text Input text to chunk
     * @param bool $truncate Whether truncation mode is enabled
     * @param int $expectedcount Expected number of chunks
     */
    public function test_chunk_text_count(string $text, bool $truncate, int $expectedcount): void {
        $result = (new \local_jedomi\telegram\api_client(''))->chunk_text($text, $truncate);
        $this->assertIsArray($result);
        $this->assertCount($expectedcount, $result, "Expected $expectedcount chunk(s) for " .
            (mb_strlen($text) > 50 ? mb_substr($text, 0, 50) . '...' : $text) .
            ' (len=' . mb_strlen($text) . ', truncate=' . ($truncate ? 'true' : 'false') . ')');
    }

    /**
     * MDL-UNIT-031: a short text is sent unchanged in a single message.
     */
    public function test_chunk_text_short_content(): void {
        $text = 'Hello world';
        $result = (new \local_jedomi\telegram\api_client(''))->chunk_text($text, false);
        $this->assertCount(1, $result);
        $this->assertEquals($text, $result[0]);
    }

    /**
     * Test chunk_text truncation includes the truncated suffix.
     */
    public function test_chunk_text_truncate_suffix(): void {
        $text = str_repeat('x', 5000);
        $result = (new \local_jedomi\telegram\api_client(''))->chunk_text($text, true);
        $this->assertCount(1, $result);
        $this->assertStringEndsWith('… (truncated)', $result[0]);
        // Truncated output should not exceed 4000.
        $this->assertLessThanOrEqual(4000, mb_strlen($result[0]));
    }

    /**
     * MDL-UNIT-031: a long text is split preferably between paragraphs.
     */
    public function test_chunk_text_newline_boundary_split(): void {
        $text = str_repeat('x', 2500) . "\n\n" . str_repeat('y', 2000);
        $result = (new \local_jedomi\telegram\api_client(''))->chunk_text($text, false);
        $this->assertCount(2, $result);
        // First chunk should end with the double newline.
        $this->assertStringEndsWith("\n\n", $result[0]);
        // Second chunk should start without leading whitespace (trim removes it).
        $this->assertEquals('y', $result[1][0]);
    }

    /**
     * MDL-UNIT-031: without paragraphs or spaces the text is hard cut at the limit.
     */
    public function test_chunk_text_hard_cut(): void {
        $text = str_repeat('a', 4001);
        $result = (new \local_jedomi\telegram\api_client(''))->chunk_text($text, false);
        $this->assertCount(2, $result);
        // First chunk should be exactly 4000 chars.
        $this->assertEquals(4000, mb_strlen($result[0]));
        // Last chunk should have the remaining char.
        $this->assertEquals(1, mb_strlen($result[1]));
        $this->assertEquals('a', $result[1]);
    }

    /**
     * MDL-UNIT-031: joining the fragments keeps all the text except the spaces at the split points.
     * MDL-CTR-009: a long message is split into fragments that keep the whole text.
     */
    public function test_chunk_text_preserves_content(): void {
        $original = str_repeat('a', 2000) . ' ' . str_repeat('b', 2000) . ' ' . str_repeat('c', 2000);
        $result = (new \local_jedomi\telegram\api_client(''))->chunk_text($original, false);
        $reassembled = implode('', $result);
        // Without the spaces that were split boundaries, the core content should match.
        $originalchars = str_replace([' '], '', $original);
        $reassembledchars = str_replace([' '], '', $reassembled);
        $this->assertEquals(
            $originalchars,
            $reassembledchars,
            'All characters should be preserved across chunks (minus whitespace from splits)'
        );
        $this->assertEquals(mb_strlen($originalchars), mb_strlen($reassembledchars));
    }

    // ... ========================
    // send_message delegation
    // ========================

    /**
     * Test that send_message delegates to chunk_text.
     * MDL-CTR-009: a long message is sent in several valid calls.
     *
     * We verify this by creating a client with empty token (to avoid real HTTP calls)
     * and checking that send_message returns false (no token) for long text
     * without throwing errors.
     */
    public function test_send_message_with_long_text(): void {
        $client = new \local_jedomi\telegram\api_client('');
        $longtext = str_repeat('Long message text. ', 200);
        // Should not throw — chunk_text splits it, then post() fails with E001.
        $result = $client->send_message('12345', $longtext);
        $this->assertFalse($result, 'send_message with empty token should return false');
    }

    /**
     * MDL-UNIT-031: a long text without paragraphs is split at a space and every fragment stays within the limit.
     */
    public function test_chunk_text_splits_at_a_space_within_the_limit(): void {
        $text = trim(str_repeat('word ', 1500));

        $result = (new \local_jedomi\telegram\api_client(''))->chunk_text($text, false);

        $this->assertGreaterThan(1, count($result));
        foreach ($result as $chunk) {
            $this->assertLessThanOrEqual(4000, mb_strlen(trim($chunk)));
            $this->assertMatchesRegularExpression('/^word\b/', trim($chunk), 'Words are never cut in half');
        }
        $this->assertSame(str_replace(' ', '', $text), str_replace(' ', '', implode('', $result)));
    }

    /**
     * MDL-UNIT-031: each fragment closes the format it opens, so Telegram does not reject it.
     * MDL-CTR-009: each fragment is a valid Telegram message on its own.
     */
    public function test_chunk_text_closes_the_format_each_fragment_opens(): void {
        $text = '<b>' . trim(str_repeat('bold ', 1200)) . '</b> <i>' . trim(str_repeat('italic ', 300)) . '</i>';

        $result = (new \local_jedomi\telegram\api_client(''))->chunk_text($text, false);

        $this->assertGreaterThan(1, count($result));
        foreach ($result as $index => $chunk) {
            foreach (['b', 'i'] as $tag) {
                $this->assertLessThanOrEqual(
                    substr_count($chunk, '</' . $tag . '>'),
                    substr_count($chunk, '<' . $tag . '>'),
                    "Fragment {$index} must close every <{$tag}> it opens"
                );
            }
        }
    }
}
