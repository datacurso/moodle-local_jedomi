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

namespace local_jedomi;

use local_jedomi\proxy\usage;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Token usage read from the streamed AI responses and added up per run.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(usage::class)]
final class proxy_usage_test extends \advanced_testcase {
    /**
     * A stream as the OpenAI-compatible endpoint sends it with include_usage: text chunks, then a last chunk with no
     * choices and the usage.
     *
     * @param array|null $usage Usage of the last chunk, or null for a stream without it.
     * @return string Raw SSE.
     */
    private static function stream(?array $usage): string {
        $chunks = [
            ['id' => 'c1', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hel']]]],
            ['id' => 'c1', 'choices' => [['index' => 0, 'delta' => ['content' => 'lo'], 'finish_reason' => 'stop']]],
        ];
        if ($usage !== null) {
            $chunks[] = ['id' => 'c1', 'choices' => [], 'usage' => $usage];
        }
        $sse = '';
        foreach ($chunks as $chunk) {
            $sse .= 'data: ' . json_encode($chunk) . "\n\n";
        }
        return $sse . "data: [DONE]\n\n";
    }

    /**
     * The usage of the last chunk is read as integer token counts; a stream without it reports none.
     */
    public function test_usage_from_the_stream(): void {
        $this->resetAfterTest(true);
        $usage = ['prompt_tokens' => 1200, 'completion_tokens' => 85, 'total_tokens' => 1285,
            'prompt_tokens_details' => []];
        $this->assertSame(
            ['prompt_tokens' => 1200, 'completion_tokens' => 85, 'total_tokens' => 1285],
            usage::from_sse(self::stream($usage))
        );
        $this->assertNull(usage::from_sse(self::stream(null)));
        $this->assertNull(usage::from_sse("data: {not json}\n\n"));
    }

    /**
     * A run adds up its requests, and counts apart the ones that reported no usage.
     */
    public function test_run_total(): void {
        $this->resetAfterTest(true);
        $total = usage::add([], ['prompt_tokens' => 1000, 'completion_tokens' => 20, 'total_tokens' => 1020]);
        $total = usage::add($total, ['prompt_tokens' => 1300, 'completion_tokens' => 90, 'total_tokens' => 1390]);
        $total = usage::add($total, null);
        $this->assertSame(['requests' => 3, 'prompt_tokens' => 2300, 'completion_tokens' => 110, 'total_tokens' => 2410,
            'without_usage' => 1], $total);
    }
}
