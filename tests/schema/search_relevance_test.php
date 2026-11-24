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

namespace local_jedomi\schema;

/**
 * Tests for the relevance of the web service and administration operation searches offered to the AI.
 *
 * The functions are chosen from the real catalogue of the site so that each one matches the searched term in a
 * single field (name, description, parameters or result).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\schema\ws_indexer
 * @covers     \local_jedomi\schema\cli_registry
 */
final class search_relevance_test extends \advanced_testcase {
    /** @var string[] Terms tried when looking for functions that match in a single field. */
    private const TERMS = ['course', 'user', 'grade', 'message', 'group', 'forum', 'calendar', 'badge', 'enrol'];

    /**
     * Find, for one term, a function matching it in each field: name, description only, parameters only, result only,
     * and one that does not match at all.
     *
     * @return array{0: string, 1: array<string, string>} Term and function name per field.
     */
    private function functions_by_field(): array {
        global $DB;
        $names = $DB->get_fieldset_sql("SELECT name FROM {external_functions} WHERE component = 'moodle' ORDER BY name");
        $infos = [];
        foreach ($names as $name) {
            $info = ws_indexer::describe($name);
            if (!isset($info['error'])) {
                $infos[$name] = array_map('strtolower', [$name, (string) $info['description'], (string) $info['params'],
                    (string) $info['returns']]);
            }
        }
        $this->resetDebugging();
        foreach (self::TERMS as $term) {
            $found = [];
            foreach ($infos as $name => [$lname, $desc, $params, $returns]) {
                $inname = str_contains($lname, $term);
                $indesc = str_contains($desc, $term);
                $inparams = str_contains($params, $term);
                $inreturns = str_contains($returns, $term);
                if ($inname && !isset($found['name'])) {
                    $found['name'] = $name;
                } else if (!$inname && $indesc && !isset($found['description'])) {
                    $found['description'] = $name;
                } else if (!$inname && !$indesc && $inparams && !isset($found['params'])) {
                    $found['params'] = $name;
                } else if (!$inname && !$indesc && !$inparams && $inreturns && !isset($found['returns'])) {
                    $found['returns'] = $name;
                } else if (!$inname && !$indesc && !$inparams && !$inreturns && !isset($found['none'])) {
                    $found['none'] = $name;
                }
            }
            if (count($found) === 5) {
                return [$term, $found];
            }
        }
        $this->markTestSkipped('The site catalogue has no term matching a function in each single field.');
    }

    /**
     * Search the web services.
     *
     * The first search indexes every function of the site; functions of other plugins may raise developer debugging
     * messages while they are described, which are not part of the behaviour under test.
     *
     * @param string $query Query.
     * @param string[] $set Functions to consider.
     * @param int $limit Maximum number of results.
     * @return array
     */
    private function search(string $query, array $set, int $limit): array {
        $results = ws_indexer::search($query, $set, $limit);
        $this->resetDebugging();
        return $results;
    }

    /**
     * MDL-UNIT-101: a match in the name weighs more than one in the description, which weighs more than one in the
     * parameters or in the result; results are sorted from most to least relevant.
     *
     * @return void
     */
    public function test_web_service_matches_are_ranked_by_field(): void {
        $this->resetAfterTest();
        [$term, $found] = $this->functions_by_field();
        $set = [$found['returns'], $found['none'], $found['params'], $found['description'], $found['name']];

        $results = array_column($this->search($term, $set, 10), 'name');

        $this->assertSame([$found['name'], $found['description'], $found['params'], $found['returns']], $results);
    }

    /**
     * MDL-UNIT-101: the web service results respect the requested limit, keeping the most relevant ones.
     *
     * @return void
     */
    public function test_web_service_results_respect_the_limit(): void {
        $this->resetAfterTest();
        [$term, $found] = $this->functions_by_field();
        $set = [$found['returns'], $found['params'], $found['description'], $found['name']];

        $this->assertSame([$found['name'], $found['description']], array_column($this->search($term, $set, 2), 'name'));
        $this->assertSame([$found['name']], array_column($this->search($term, $set, 1), 'name'));
    }

    /**
     * MDL-UNIT-101: functions without any match are not returned and only the received set of functions is considered.
     *
     * @return void
     */
    public function test_web_service_search_only_returns_matches_from_the_given_set(): void {
        $this->resetAfterTest();
        [$term, $found] = $this->functions_by_field();

        $this->assertSame([], $this->search($term, [$found['none']], 10));
        $results = array_column($this->search($term, [$found['description'], $found['none']], 10), 'name');
        $this->assertSame([$found['description']], $results);
        $this->assertNotContains($found['name'], $results);
    }

    /**
     * MDL-UNIT-101: terms that are too short are ignored and an empty web service search returns nothing.
     *
     * @return void
     */
    public function test_short_terms_are_ignored_and_empty_search_returns_nothing(): void {
        $this->resetAfterTest();
        [$term, $found] = $this->functions_by_field();
        $set = array_values($found);

        $this->assertSame([], $this->search('', $set, 10));
        $this->assertSame([], $this->search('a b c', $set, 10));
        $this->assertSame(
            array_column($this->search($term, $set, 10), 'name'),
            array_column($this->search('x ' . $term . ' y', $set, 10), 'name')
        );
    }

    /**
     * MDL-UNIT-101: in the administration operations a match in the name weighs more than one in the description,
     * operations without a match are not returned and the limit is respected.
     *
     * @return void
     */
    public function test_administration_operations_are_ranked_and_limited(): void {
        $operations = cli_registry::get_all();
        $chosen = null;
        foreach ($operations as $candidate) {
            foreach (preg_split('/[_\s]+/', strtolower($candidate['name'])) as $word) {
                if (strlen($word) < 4) {
                    continue;
                }
                $indesc = array_filter($operations, static fn(array $op): bool =>
                    !str_contains(strtolower($op['name']), $word) && str_contains(strtolower($op['description']), $word));
                if ($indesc) {
                    $chosen = [$word, $candidate['name'], reset($indesc)['name']];
                    break 2;
                }
            }
        }
        if ($chosen === null) {
            $this->markTestSkipped('No operation term matches one name and another description only.');
        }
        [$word, $byname, $bydesc] = $chosen;

        $results = cli_registry::search($word, 50);
        $names = array_column($results, 'name');
        $scores = array_column($results, 'score');

        $this->assertLessThan(array_search($bydesc, $names), array_search($byname, $names));
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores);
        foreach ($results as $result) {
            $this->assertTrue(str_contains(strtolower($result['name'] . ' ' . $result['description']), $word));
        }
        $this->assertCount(1, cli_registry::search($word, 1));
        $this->assertSame([], cli_registry::search('zzqqxxunmatchable', 10));
    }
}
