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

namespace local_jedomi\local;

/**
 * Tests for the anonymisation of the chat messages sent to the AI.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\local\anonymizer
 */
final class anonymizer_test extends \advanced_testcase {
    use \local_jedomi\tests\spanish_strings;

    /**
     * Create the site user whose personal data the messages carry.
     *
     * @return void
     */
    private function create_named_user(): void {
        $this->getDataGenerator()->create_user(['firstname' => 'Ximena', 'lastname' => 'Quintanilla',
            'email' => 'ximena.quintanilla@example.com', 'idnumber' => 'ID-778899']);
    }

    /**
     * MDL-CTR-011: the user and assistant messages lose the names and e-mails of site users; the system message and
     * the tool messages are kept as they are.
     *
     * @return void
     */
    public function test_user_and_assistant_messages_are_anonymised(): void {
        $this->resetAfterTest();
        $this->create_named_user();
        $anonymizer = new anonymizer(anonymizer::LEVEL_BASIC);
        $system = ['role' => 'system', 'content' => 'You help Ximena Quintanilla.'];
        $tool = ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '[person_9]'];

        $messages = $anonymizer->anonymize_messages([
            $system,
            ['role' => 'user', 'content' => 'Who is ximena.quintanilla@example.com?'],
            ['role' => 'assistant', 'content' => 'It is Ximena Quintanilla.'],
            $tool,
        ]);

        $this->assertSame($system, $messages[0], 'The system message is never anonymised');
        $this->assertSame($tool, $messages[3], 'Tool messages are anonymised when the tool answers');
        $this->assertSame('Who is [email_1]?', $messages[1]['content']);
        $this->assertSame('It is [person_1] [person_2].', $messages[2]['content']);
    }

    /**
     * MDL-CTR-011: the placeholders of the messages are the ones of the instance, so the tool results agree with them
     * and the answer of the AI gets the real values back.
     *
     * @return void
     */
    public function test_placeholders_are_shared_with_the_tool_results_and_restored(): void {
        $this->resetAfterTest();
        $this->create_named_user();
        $anonymizer = new anonymizer(anonymizer::LEVEL_BASIC);

        $messages = $anonymizer->anonymize_messages([['role' => 'user', 'content' => 'Grades of Ximena Quintanilla']]);
        $result = $anonymizer->anonymize_tool_result(json_encode(['firstname' => 'Ximena', 'lastname' => 'Quintanilla']));

        $this->assertSame('Grades of [person_1] [person_2]', $messages[0]['content']);
        $this->assertSame(['firstname' => '[person_1]', 'lastname' => '[person_2]'], json_decode($result, true));
        $this->assertSame('Ximena Quintanilla has 9.', $anonymizer->restore('[person_1] [person_2] has 9.'));
        $this->assertSame(['name' => 'Ximena'], $anonymizer->restore_value(['name' => '[person_1]']));
    }

    /**
     * MDL-CTR-011: in a message with several parts only the text parts are anonymised; image parts are kept.
     *
     * @return void
     */
    public function test_only_text_parts_are_anonymised(): void {
        $this->resetAfterTest();
        $this->create_named_user();
        $image = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,QUJD']];

        $messages = (new anonymizer(anonymizer::LEVEL_BASIC))->anonymize_messages([
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Is this Ximena?'], $image]],
        ]);

        $this->assertSame('Is this [person_1]?', $messages[0]['content'][0]['text']);
        $this->assertSame($image, $messages[0]['content'][1]);
    }

    /**
     * MDL-CTR-011: the Strict level also replaces codes mixing letters and digits; the Basic level keeps them.
     *
     * @return void
     */
    public function test_strict_level_also_replaces_codes(): void {
        $this->resetAfterTest();
        $this->create_named_user();
        $message = [['role' => 'user', 'content' => 'Student ID-778899 is Ximena.']];

        $basic = (new anonymizer(anonymizer::LEVEL_BASIC))->anonymize_messages($message);
        $strict = (new anonymizer(anonymizer::LEVEL_STRICT))->anonymize_messages($message);

        $this->assertSame('Student ID-778899 is [person_1].', $basic[0]['content']);
        $this->assertStringNotContainsString('778899', $strict[0]['content']);
        $this->assertStringNotContainsString('Ximena', $strict[0]['content']);
    }

    /**
     * Identifier columns and a sample value of each (Decision 6).
     *
     * @return array
     */
    public static function identifier_column_provider(): array {
        $columns = ['username' => 'xquintanilla', 'idnumber' => 'ID-778899', 'phone1' => '+57 300 111 2233',
            'phone2' => '604 555 0101', 'address' => 'Calle 10 # 20-30', 'lastip' => '192.168.10.20',
            'ip' => '10.0.0.7', 'skype' => 'ximena.q', 'icq' => '123456789', 'msn' => 'xq@msn.example',
            'aim' => 'xq_aim', 'yahoo' => 'xq_yahoo', 'url' => 'https://ximena.example.com/me'];
        $cases = [];
        foreach ([anonymizer::LEVEL_BASIC, anonymizer::LEVEL_STRICT, anonymizer::LEVEL_FEEDBACK] as $level) {
            foreach ($columns as $column => $value) {
                $cases["{$level} {$column}"] = [$level, $column, $value];
            }
        }
        return $cases;
    }

    /**
     * Decision 6: every identifier column of a tool result is replaced at every level, Basic included, and the answer
     * and the tool arguments written with its placeholder get the real value back.
     *
     * @dataProvider identifier_column_provider
     * @param string $level Anonymisation level.
     * @param string $column Identifier column.
     * @param string $value Real value.
     * @return void
     */
    public function test_identifier_columns_are_replaced_and_restored_at_every_level(
        string $level,
        string $column,
        string $value
    ): void {
        $this->resetAfterTest();
        $anonymizer = new anonymizer($level);

        $sent = $anonymizer->anonymize_tool_result(json_encode(['rows' => [['id' => 7, $column => $value]]]));

        $this->assertStringNotContainsString(json_encode($value), $sent, "The {$column} column must not reach the AI");
        $this->assertSame(['rows' => [['id' => 7, $column => '[id_1]']]], json_decode($sent, true));
        $this->assertSame("It is {$value}.", $anonymizer->restore('It is [id_1].'));
        $this->assertSame(
            ['sql' => "SELECT id FROM {user} WHERE {$column} = '{$value}'"],
            $anonymizer->restore_value(['sql' => "SELECT id FROM {user} WHERE {$column} = '[ID 1]'"]),
            'A tool call written with the placeholder runs with the real value'
        );
    }

    /**
     * Anonymiser that already replaced the given addresses, in order, by [id_1], [id_2]...
     *
     * @param string[] $addresses Real addresses.
     * @return anonymizer
     */
    private function anonymizer_with_addresses(array $addresses): anonymizer {
        $anonymizer = new anonymizer(anonymizer::LEVEL_BASIC);
        $anonymizer->anonymize_tool_result(json_encode(array_map(static fn(string $a): array => ['address' => $a], $addresses)));
        return $anonymizer;
    }

    /**
     * REL-004: a real value restored into the query of the SQL tool is escaped for the place where the AI wrote its
     * placeholder: doubled quotes inside a string, a quoted string outside one, backslashes doubled only where the
     * database reads them as escapes, comments left alone, and nothing restored when the quotes do not pair.
     *
     * @return void
     */
    public function test_restore_sql_escapes_the_real_values_for_the_query(): void {
        $this->resetAfterTest();
        $anonymizer = $this->anonymizer_with_addresses(["O'Hara St", 'C:\\dir', "x' OR '1'='1"]);
        $where = "SELECT id FROM {user} WHERE address = ";

        $this->assertSame($where . "'O''Hara St'", $anonymizer->restore_sql($where . "'[id_1]'", 'postgres'));
        $this->assertSame($where . "'O''Hara St'", $anonymizer->restore_sql($where . "'[id_1]'", 'mysql'));
        $this->assertSame($where . "'%O''Hara St%'", $anonymizer->restore_sql($where . "'%[id_1]%'", 'postgres'));
        $this->assertSame($where . "'C:\\dir'", $anonymizer->restore_sql($where . "'[id_2]'", 'postgres'));
        $this->assertSame($where . "'C:\\\\dir'", $anonymizer->restore_sql($where . "'[id_2]'", 'mysql'));
        $this->assertSame($where . "'x'' OR ''1''=''1'", $anonymizer->restore_sql($where . "'[id_3]'", 'mssql'));
        $this->assertSame($where . "\"O'Hara St\"", $anonymizer->restore_sql($where . '"[id_1]"', 'mysql'));
        $outside = $anonymizer->restore_sql($where . '[id_1] OR address = [id_2]', 'mysql');
        $this->assertSame($where . "'O''Hara St' OR address = 'C:\\\\dir'", $outside, 'Outside a string: a quoted string');
        $comment = $anonymizer->restore_sql($where . "[id_3] -- [id_1]\n", 'postgres');
        $this->assertSame($where . "'x'' OR ''1''=''1' -- [id_1]\n", $comment, 'Comments are left alone');
        $this->assert_not_restored($anonymizer, $where . "'[id_1] OR 1=1", 'postgres', 'Quotes that do not pair');
        $this->assertSame(
            $where . "'[id_9] OR 1=1",
            $anonymizer->restore_sql($where . "'[id_9] OR 1=1", 'postgres'),
            'Quotes that do not pair and only unknown tokens: the query is left as written'
        );
        $this->assertSame($where . "'[id_9]'", $anonymizer->restore_sql($where . "'[id_9]'", 'postgres'), 'Unknown tokens');
    }

    /**
     * REL-004: in a query only the exact placeholders of the anonymiser are restored, and on the MySQL family only when
     * the backslashes before them pair up, so a restored value never takes the second backslash of an escape pair and
     * can never close its string. Loose forms, kept for the answers, are left as written in a query.
     *
     * @return void
     */
    public function test_restore_sql_restores_only_exact_placeholders_and_never_splits_an_escape_pair(): void {
        $this->resetAfterTest();
        $anonymizer = $this->anonymizer_with_addresses(["' OR 1=1 -- ", "O'Hara St"]);
        $where = 'SELECT id FROM {user} WHERE address = ';
        $restored = [
            // Backslashes that pair up stay pairs; the value follows them escaped.
            "'\\\\[id_1]'" => "'\\\\'' OR 1=1 -- '",
            "'\\\\\\\\[id_1]'" => "'\\\\\\\\'' OR 1=1 -- '",
            "'a\\\\b[id_2]'" => "'a\\\\bO''Hara St'",
            '"\\\\[id_1]"' => "\"\\\\' OR 1=1 -- \"",
            // Backslashes are not escapes inside backquotes.
            "\x60\\[id_2]\x60" => "\x60\\O'Hara St\x60",
            // An escaped quote before the placeholder is not a backslash before it.
            "'\\'[id_1]'" => "'\\''' OR 1=1 -- '",
        ];
        $refused = [
            // An odd backslash escapes the bracket: the token cannot be restored, so the query is not run (RISK-001).
            "'\\[id_1]'", "'\\\\\\[id_1]'", '"\\[id_1]"',
            // Loose forms are restored in answers only; in a query they are refused, never run as text.
            "'[ id_1 ]'", "'[id-1]'", "'[id 1]'", "'[ID_1]'", "'\\[id\\_1\\]'", "'[id_01]'", '[ id_1 ]', '\\[id_1]',
        ];
        $quoted = static fn(string $sql): int => count(preg_split(sql_guard::quoted_parts_pattern('mysql'), $sql));
        foreach ($restored as $input => $expected) {
            $output = $anonymizer->restore_sql($where . $input, 'mysql');
            $this->assertSame($where . $expected, $output, $input);
            $this->assertSame($quoted($where . $input), $quoted($output), "{$input}: the value stays inside its string");
        }
        foreach ($refused as $input) {
            $this->assert_not_restored($anonymizer, $where . $input, 'mysql', $input);
        }
        $tokenvalue = anonymizer::from_map(['[id_1]' => '[id_2]', '[id_2]' => "x' OR '1'='1"], anonymizer::LEVEL_BASIC);
        $this->assertSame($where . "'[id_2]'", $tokenvalue->restore_sql($where . "'[id_1]'", 'mysql'), 'One pass');
        // Elsewhere a backslash is not an escape: the exact placeholder after it is restored.
        $this->assertSame($where . "'\\'' OR 1=1 -- '", $anonymizer->restore_sql($where . "'\\[id_1]'", 'postgres'));
        $this->assert_not_restored($anonymizer, $where . "'[id-1]'", 'postgres', 'Loose form elsewhere');
        $this->assertSame("' OR 1=1 -- ", $anonymizer->restore('\\[ID-1\\]'), 'Answers keep the loose restore');
    }

    /**
     * REL-004: the arguments of the SQL tool get the escaped values in its query, every other argument the raw value.
     *
     * @return void
     */
    public function test_restore_tool_args_escapes_only_the_query_of_the_sql_tool(): void {
        $this->resetAfterTest();
        $anonymizer = $this->anonymizer_with_addresses(["O'Hara St"]);
        $escaped = "WHERE address = 'O''Hara St'";

        $this->assertSame(
            ['sql' => $escaped, 'params' => ["O'Hara St"]],
            $anonymizer->restore_tool_args('execute_sql', ['sql' => "WHERE address = '[id_1]'", 'params' => ['[id_1]']])
        );
        $this->assertSame(
            ['query' => $escaped],
            $anonymizer->restore_tool_args('execute_sql', ['query' => "WHERE address = '[id_1]'"])
        );
        $this->assertSame(
            ['sql' => "WHERE address = 'O'Hara St'"],
            $anonymizer->restore_tool_args('describe_table', ['sql' => "WHERE address = '[id_1]'"]),
            'Other tools take structured values: raw restore'
        );
    }

    /**
     * Assert that a query is refused because a placeholder of the anonymiser in it cannot be restored.
     *
     * @param anonymizer $anonymizer Anonymiser.
     * @param string $sql Query.
     * @param string $family Database family.
     * @param string $message Assertion message.
     */
    private function assert_not_restored(anonymizer $anonymizer, string $sql, string $family, string $message): void {
        try {
            $restored = $anonymizer->restore_sql($sql, $family);
            $this->fail("{$message}: the query was returned to be run: {$restored}");
        } catch (unrestored_placeholder_exception $e) {
            $this->assertStringContainsString('[id_', $e->getMessage(), $message);
        }
    }

    /**
     * RISK-001: a placeholder of the anonymiser that the AI wrote in a form that cannot be restored in a query (another
     * case, spaces, Markdown escapes) stops the SQL tool with an error telling the AI the exact form to write, instead of
     * running the query against the text of the token; tokens that are not placeholders of the anonymiser stay text.
     *
     * @return void
     */
    public function test_sql_tool_arguments_with_a_placeholder_that_cannot_be_restored_are_refused(): void {
        $this->resetAfterTest();
        $anonymizer = $this->anonymizer_with_addresses(["O'Hara St"]);

        foreach (["address <> '[ID 1]'", "address = '[id 1]'", 'address = \\[id\\_1\\]'] as $where) {
            try {
                $anonymizer->restore_tool_args('execute_sql', ['sql' => "SELECT id FROM {user} WHERE {$where}"]);
                $this->fail("{$where}: the query would run against the text of the token");
            } catch (unrestored_placeholder_exception $e) {
                $result = json_decode($e->tool_result(), true);
                $this->assertSame('unrestored_placeholder', $result['error'], $where);
                $this->assertStringContainsString('[kind_N]', $result['detail'], $where);
                $this->assertStringContainsString('[id_1]', $result['detail'], $where);
                $this->assertStringNotContainsString("O'Hara", $result['detail'], 'The real value never reaches the AI');
            }
        }
        $this->assertSame(
            ['sql' => "SELECT id FROM {user} WHERE address <> '[ID 9]'"],
            $anonymizer->restore_tool_args('execute_sql', ['sql' => "SELECT id FROM {user} WHERE address <> '[ID 9]'"]),
            'A token that is not a placeholder of the anonymiser is text'
        );
        $this->assertSame(
            ['note' => "O'Hara St"],
            $anonymizer->restore_tool_args('describe_table', ['note' => '[ID 1]']),
            'Other tools keep the loose restore'
        );
    }

    /**
     * RISK-002: a value written outside a string next to another string or value would merge with it into one string,
     * so the restored query does not split into the expected strings and code (re-lex count fallback) and it is refused;
     * with code between them the same values are restored. Each value outside a string adds exactly one string and the
     * code after it.
     *
     * @return void
     */
    public function test_values_outside_strings_must_keep_the_strings_of_the_query(): void {
        $this->resetAfterTest();
        $anonymizer = $this->anonymizer_with_addresses(['a', 'b']);
        $select = 'SELECT id FROM {user} WHERE address = ';

        foreach (['postgres', 'mysql'] as $family) {
            foreach (['[id_1][id_2]', "'a'[id_1]", "[id_1]'b'", "'a'[id_1]'b'"] as $adjacent) {
                $this->assert_not_restored($anonymizer, $select . $adjacent, $family, "{$family}: {$adjacent}");
            }
            $this->assertSame($select . "'a' || 'b'", $anonymizer->restore_sql($select . '[id_1] || [id_2]', $family));
            $this->assertSame($select . "'a' || 'a'", $anonymizer->restore_sql($select . "'a' || [id_1]", $family));
            $this->assertSame($select . "'a' || 'b'", $anonymizer->restore_sql($select . "[id_1] || 'b'", $family));
            // One, two and three values outside strings, with strings around them: the expected count grows by two each.
            $this->assertSame($select . "'a'", $anonymizer->restore_sql($select . '[id_1]', $family));
            $this->assertSame(
                $select . "'a' AND city = 'x' AND address <> 'b' AND country = 'b'",
                $anonymizer->restore_sql($select . "[id_1] AND city = 'x' AND address <> [id_2] AND country = [id_2]", $family)
            );
        }
    }

    /**
     * REL-004: a query the AI writes with the placeholder of an address holding quotes or backslashes runs on the
     * database of the site and finds only the matching user; an address written as an SQL injection is just a value.
     *
     * @return void
     */
    public function test_restored_query_runs_and_cannot_be_injected_through_a_value(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $ohara = $generator->create_user(['address' => "O'Hara St"]);
        $slash = $generator->create_user(['address' => 'C:\\dir\\']);
        $generator->create_user(['address' => 'Other St']);
        $anonymizer = $this->anonymizer_with_addresses(["O'Hara St", 'C:\\dir\\', "x' OR '1'='1", "' OR 1=1 -- "]);
        $run = static function (string $sql) use ($anonymizer): array {
            $restored = $anonymizer->restore_tool_args('execute_sql', ['sql' => $sql])['sql'];
            return array_column(query_runner::run($restored, [], (int) get_admin()->id, 50)['rows'], 'id');
        };

        $this->assertEquals([$ohara->id], $run("SELECT id FROM {user} WHERE address = '[id_1]'"));
        $this->assertEquals([$ohara->id], $run('SELECT id FROM {user} WHERE address = [id_1]'));
        if ($DB->get_dbfamily() === 'mysql') {
            // Elsewhere a backslash in a query is refused by the guard before it reaches the database.
            $this->assertEquals([$slash->id], $run("SELECT id FROM {user} WHERE address = '[id_2]'"));
            // A placeholder after an escape pair or an escaped bracket never lets the value close its string.
            $this->assertSame([], $run("SELECT id FROM {user} WHERE address = '\\\\[id_4]'"), 'After an escape pair');
            try {
                $run("SELECT id FROM {user} WHERE address = '\\[id_4]'");
                $this->fail('A placeholder after an odd backslash cannot be restored and is not run');
            } catch (unrestored_placeholder_exception $e) {
                $this->assertStringContainsString('[id_4]', $e->getMessage());
            }
        }
        $this->assertSame([], $run("SELECT id FROM {user} WHERE address = '[id_3]'"), 'The injection is only a value');
        $this->assertSame([], $run('SELECT id FROM {user} WHERE address = [id_3]'));
    }

    /**
     * Decision 6: at the Basic level the identifier columns of a database row are replaced, the same value in two
     * columns gets one placeholder, empty, null and numeric values are kept, and free text is left to the Strict level.
     *
     * @return void
     */
    public function test_basic_level_replaces_identifier_columns_but_not_free_text(): void {
        $this->resetAfterTest();
        $text = 'The student asked for an extension of the deadline because of a family trip next week.';
        $row = ['id' => 7, 'username' => 'X-1001', 'idnumber' => 'X-1001', 'phone1' => '', 'phone2' => null,
            'icq' => 123456, 'description' => $text, 'city' => 'Bogotá'];

        $basic = (new anonymizer(anonymizer::LEVEL_BASIC))->anonymize_row($row);
        $strict = (new anonymizer(anonymizer::LEVEL_STRICT))->anonymize_row($row);

        $this->assertSame(['id' => 7, 'username' => '[id_1]', 'idnumber' => '[id_1]', 'phone1' => '', 'phone2' => null,
            'icq' => 123456, 'description' => $text, 'city' => 'Bogotá'], $basic);
        $this->assertSame('[id_1]', $strict['username']);
        $this->assertSame('[text_1]', $strict['description'], 'The Strict level still replaces free text');
    }

    /**
     * Decision 6: identifiers of a tool result wrapped in untrusted data markers are replaced and restored at the
     * Basic level, and the markers keep their random id.
     *
     * @return void
     */
    public function test_identifiers_inside_untrusted_markers_are_restored_at_the_basic_level(): void {
        $this->resetAfterTest();
        $anonymizer = new anonymizer(anonymizer::LEVEL_BASIC);
        $untrusted = new untrusted();

        $result = $anonymizer->anonymize_tool_result(json_encode(['username' => 'xq77']));
        $block = $untrusted->wrap('tool execute_sql', $result);
        $again = $anonymizer->anonymize_messages([['role' => 'user', 'content' => $block]])[0]['content'];

        $this->assertSame($block, $again, 'The markers and the placeholder are kept');
        $this->assertStringNotContainsString('xq77', $block);
        $this->assertSame('{"username":"xq77"}', $anonymizer->restore($untrusted->unwrap($block)));
    }

    /**
     * REL-001: at the Strict level the untrusted data markers of an attachment keep their random id (a code mixing
     * letters and digits), while the content between them is still anonymised and restored.
     *
     * @return void
     */
    public function test_untrusted_markers_are_kept_at_the_strict_level(): void {
        $this->resetAfterTest();
        $this->create_named_user();
        $open = '<<<UNTRUSTED_DATA id=Ab12Cd34Ef56Gh78 source="file">>>';
        $close = '<<<END_UNTRUSTED_DATA id=Ab12Cd34Ef56Gh78>>>';
        $message = [['role' => 'user', 'content' => "Summarise it\n\n{$open}\nStudent ID-778899 is Ximena.\n{$close}"]];
        $anonymizer = new anonymizer(anonymizer::LEVEL_STRICT);

        $sent = $anonymizer->anonymize_messages($message)[0]['content'];

        $this->assertStringContainsString("\n{$open}\n", $sent, 'The opening marker keeps its id');
        $this->assertStringEndsWith("\n{$close}", $sent, 'The closing marker keeps its id');
        $this->assertStringNotContainsString('778899', $sent);
        $this->assertStringNotContainsString('Ximena', $sent);
        $this->assertSame('Student ID-778899 is Ximena.', $anonymizer->restore('Student [id_1] is [person_1].'));
    }

    /**
     * MDL-CTR-011: the text of an attached file that already holds placeholder-shaped tokens (an export anonymised
     * before) reaches the AI with distinct tokens, so the answer gives back the original tokens and never turns them
     * into the real data of another placeholder; the real data of the file is still anonymised and restored.
     *
     * @return void
     */
    public function test_placeholders_already_in_an_attachment_are_not_restored_into_real_data(): void {
        $this->resetAfterTest();
        $this->create_named_user();
        $anonymizer = new anonymizer(anonymizer::LEVEL_BASIC);
        // The sidebar sends the extracted text of the file inside the user message.
        $attachment = "Summarise it\n\nAttached file export.txt — 1 page\n\n"
            . "Row 1: [person_1] wrote to [email_1].\nRow 2: ximena.quintanilla@example.com is Ximena.";

        $messages = $anonymizer->anonymize_messages([
            ['role' => 'user', 'content' => 'Hello Quintanilla'],
            ['role' => 'user', 'content' => $attachment],
        ]);

        $sent = $messages[1]['content'];
        $this->assertStringNotContainsString('ximena.quintanilla@example.com', $sent);
        $this->assertStringNotContainsString('Row 1: [person_1]', $sent, 'The token of the file is not a placeholder');
        $this->assertStringNotContainsString('[email_1].', $sent);
        preg_match('/Row 1: (\[literal_\d+\]) wrote to (\[literal_\d+\])/', $sent, $tokens);
        $this->assertCount(3, $tokens, $sent);

        // The AI quotes both rows: each token comes back as it was written.
        preg_match('/Row 2: (\[email_\d+\]) is (\[person_\d+\])/', $sent, $real);
        $answer = "Row 1: {$tokens[1]} wrote to {$tokens[2]}. Row 2: {$real[1]} is {$real[2]}.";
        $this->assertSame(
            'Row 1: [person_1] wrote to [email_1]. Row 2: ximena.quintanilla@example.com is Ximena.',
            $anonymizer->restore($answer)
        );
    }

    /**
     * MDL-CTR-011: data anonymised twice (the rows of a query and then the whole tool result) keeps its placeholders,
     * and a conversation continued with its placeholders keeps the ones of the AI, so the user always gets the real
     * values back.
     *
     * @return void
     */
    public function test_data_anonymised_twice_is_still_restored(): void {
        $this->resetAfterTest();
        $anonymizer = new anonymizer(anonymizer::LEVEL_BASIC);

        $row = $anonymizer->anonymize_row(['firstname' => 'Ximena', 'email' => 'ximena.quintanilla@example.com']);
        $twice = $anonymizer->anonymize_tool_result(json_encode($row));
        $this->assertSame(json_encode($row), $twice);
        $this->assertStringContainsString('ximena.quintanilla@example.com', $anonymizer->restore($twice));

        $continued = anonymizer::from_map($anonymizer->export_map(), anonymizer::LEVEL_BASIC);
        $assistant = $continued->anonymize_messages([['role' => 'assistant', 'content' => "I found {$row['firstname']}."]]);
        $this->assertSame('I found Ximena.', $continued->restore($assistant[0]['content']));
    }

    /**
     * MDL-CTR-011: the help of the anonymisation level explains what the AI receives with each level, and the user never
     * sees a placeholder the AI altered instead of the real value.
     *
     * @return void
     */
    public function test_help_explains_the_levels_and_altered_placeholders_are_restored(): void {
        $this->resetAfterTest();
        $manager = get_string_manager();
        $english = $manager->get_string('anonymization_level_help', 'local_jedomi', null, 'en');
        $spanish = self::plugin_spanish_string('anonymization_level_help');
        $this->assertStringNotContainsString('stored conversations', $english);
        $this->assertStringNotContainsString('conversaciones guardadas', $spanish);
        foreach (['Telegram', 'agent preview', 'Basic', 'Strict', 'Feedback only', 'real values'] as $part) {
            $this->assertStringContainsString($part, $english);
        }
        foreach (['Telegram', 'vista previa', 'Básico', 'Estricto', 'Solo comentarios', 'datos reales'] as $part) {
            $this->assertStringContainsString($part, $spanish);
        }
        // Decision 6: identifiers belong to the Basic level, free text to the Strict level.
        $this->assertMatchesRegularExpression('/Basic:[^<]*ID number[^<]*<br>Strict:[^<]*free texts/', $english);
        $this->assertMatchesRegularExpression(
            '/Básico:[^<]*número de identificación[^<]*<br>Estricto:[^<]*textos libres/u',
            $spanish
        );
        // REL-003: the labels of the levels in the Privacy tab say the same as the help.
        $label = static fn(string $key, string $lang): string => $lang === 'es'
            ? self::plugin_spanish_string($key)
            : $manager->get_string($key, 'local_jedomi', null, 'en');
        foreach (['names', 'emails', 'identifiers'] as $part) {
            $this->assertStringContainsString($part, $label('anon_basic', 'en'), "anon_basic names {$part}");
        }
        foreach (['nombres', 'correos', 'identificadores'] as $part) {
            $this->assertStringContainsString($part, $label('anon_basic', 'es'), "anon_basic (es) names {$part}");
        }
        foreach (['names', 'emails', 'user name', 'ID number', 'phones', 'address', 'IP', 'messaging', 'URL'] as $part) {
            $this->assertStringContainsString($part, $label('anon_basic_desc', 'en'), "anon_basic_desc names {$part}");
        }
        $parts = ['nombres', 'correos', 'nombre de usuario', 'número de identificación', 'teléfonos', 'dirección', 'IP',
            'mensajería', 'URL'];
        foreach ($parts as $part) {
            $this->assertStringContainsString($part, $label('anon_basic_desc', 'es'), "anon_basic_desc (es) names {$part}");
        }
        foreach (['anon_strict', 'anon_strict_desc'] as $key) {
            $this->assertMatchesRegularExpression('/identifiers.*free text.*codes/', $label($key, 'en'), $key);
            $this->assertMatchesRegularExpression('/identificadores.*texto libre.*códigos/u', $label($key, 'es'), $key);
            $this->assertStringNotContainsString('IDs', $label($key, 'en') . $label($key, 'es'), $key);
        }

        $anonymizer = new anonymizer(anonymizer::LEVEL_BASIC);
        $sent = $anonymizer->anonymize_messages([['role' => 'user', 'content' => 'Write to ana.diaz@example.com']]);
        $this->assertStringContainsString('[email_1]', $sent[0]['content']);
        foreach (['[email_1]', '[Email 1]', '[EMAIL_1]', '[email-1]', '[ email_1 ]', '\\[email\\_1\\]'] as $altered) {
            $this->assertSame(
                'Sent to ana.diaz@example.com.',
                $anonymizer->restore("Sent to {$altered}."),
                "The altered placeholder {$altered} gets the real value back"
            );
        }
        $this->assertSame('Unknown [email_7].', $anonymizer->restore('Unknown [email_7].'), 'No value is invented');

        // A loose token that came from the user text is not taken for a placeholder: it comes back as written.
        $other = new anonymizer(anonymizer::LEVEL_BASIC);
        $sent = $other->anonymize_messages([['role' => 'user', 'content' => 'Mail ana.diaz@example.com about [Email 1]']]);
        $this->assertStringNotContainsString('[Email 1]', $sent[0]['content']);
        $this->assertSame('Mail ana.diaz@example.com about [Email 1]', $other->restore($sent[0]['content']));
    }

    /**
     * JEDOMI-SEC-005: a web address of another site keeps its placeholders in the answer, so a link the AI was asked to
     * write by untrusted content carries no real value; the rest of the answer and the addresses of the site get them.
     */
    public function test_placeholders_in_external_addresses_are_not_restored(): void {
        global $CFG;
        $this->resetAfterTest();
        $map = ['[person_1]' => 'Ximena', '[email_1]' => 'xq@example.com'];
        $anonymizer = anonymizer::from_map($map, anonymizer::LEVEL_BASIC);
        $site = $CFG->wwwroot . '/user/profile.php?name=';
        $sitehost = parse_url($CFG->wwwroot, PHP_URL_HOST);

        $external = [
            'https://attacker.example/r?n=[person_1]',
            'http://attacker.example/[Person 1]x',
            '//attacker.example/r?n=[person_1]',
            'https:/attacker.example/r?n=[person_1]',
            '/\\attacker.example/r?n=[person_1]',
            'attacker.example/r?n=[person_1]',
            'https://[person_1].attacker.example/',
            'https://' . $sitehost . '@attacker.example/r?n=[person_1]',
            'https://attacker.example\\@' . $sitehost . '/r?n=[person_1]',
            'https://attacker.example/?n=\\[person\\_1\\]&e=[email_1]',
        ];
        foreach ($external as $url) {
            $answer = "See [the feedback]({$url}) of [person_1].";
            $this->assertSame(
                "See [the feedback]({$url}) of Ximena.",
                $anonymizer->restore($answer),
                "No real value in {$url}"
            );
        }

        $this->assertSame(
            "Profile of Ximena: [Ximena]({$site}Ximena) and <a href=\"https://attacker.example/?n=[email_1]\">Ximena</a>",
            $anonymizer->restore(
                "Profile of [person_1]: [[person_1]]({$site}[person_1]) and "
                    . '<a href="https://attacker.example/?n=[email_1]">[person_1]</a>'
            )
        );
        $this->assertSame(
            'Ximena wrote from /user/view.php?n=Ximena',
            $anonymizer->restore('[person_1] wrote from /user/view.php?n=[person_1]')
        );
        $this->assertSame('Mail xq@example.com today', $anonymizer->restore('Mail [email_1] today'));
    }

    /**
     * JEDOMI-SEC-005: tool arguments run on the site, so an address in them still gets the real values.
     */
    public function test_tool_arguments_keep_restoring_addresses(): void {
        $this->resetAfterTest();
        $anonymizer = anonymizer::from_map(['[person_1]' => 'Ximena'], anonymizer::LEVEL_BASIC);

        $this->assertSame(
            ['url' => 'https://example.org/Ximena'],
            $anonymizer->restore_value(['url' => 'https://example.org/[person_1]'])
        );
    }

    /**
     * Phase 23 unit 6: at the Basic level an identifier shown in a previous answer goes back to the AI as its placeholder
     * in a later turn; only values already in the map are replaced, as whole words.
     *
     * @return void
     */
    public function test_history_gets_the_placeholders_of_the_map(): void {
        $this->resetAfterTest();
        $anonymizer = new anonymizer(anonymizer::LEVEL_BASIC);
        $result = $anonymizer->anonymize_tool_result(json_encode(['idnumber' => 'ZX9-4471', 'phone1' => '555123']));
        $this->assertSame(['idnumber' => '[id_1]', 'phone1' => '[id_2]'], json_decode($result, true));
        $answer = $anonymizer->restore('The code of the student is [id_1], phone [id_2].');
        $continued = anonymizer::from_map($anonymizer->export_map(), anonymizer::LEVEL_BASIC);

        $messages = $continued->anonymize_messages([
            ['role' => 'user', 'content' => 'Show the code'],
            ['role' => 'assistant', 'content' => $answer],
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Is ZX9-4471 right? Not ZX9-44712 nor 5551234']]],
        ]);

        $this->assertSame('The code of the student is [id_1], phone [id_2].', $messages[1]['content']);
        $this->assertSame('Is [id_1] right? Not ZX9-44712 nor 5551234', $messages[2]['content'][0]['text']);
        $this->assertStringNotContainsString('ZX9-4471', $continued->anonymize_history_text('Code ZX9-4471'));
        $this->assertSame('The code of the student is ZX9-4471, phone 555123.', $continued->restore($messages[1]['content']));
    }

    /**
     * Phase 23 unit 6: short values of the map only replace whole words, and values under three characters are never
     * looked for in the history, so common text around them is kept.
     *
     * @return void
     */
    public function test_history_short_values_only_match_whole_words(): void {
        $this->resetAfterTest();
        $anonymizer = anonymizer::from_map(['[person_1]' => 'Ann', '[id_1]' => '12'], anonymizer::LEVEL_BASIC);

        $this->assertSame(
            'Annual report for [person_1]: 12 tasks, Anne and Joann agree.',
            $anonymizer->anonymize_history_text('Annual report for Ann: 12 tasks, Anne and Joann agree.')
        );
    }

    /**
     * Phase 23 unit 6: a real value with carriage returns restored in a query gets plain line breaks, so the guard does
     * not refuse a correct query.
     *
     * @return void
     */
    public function test_restore_sql_normalises_carriage_returns(): void {
        $this->resetAfterTest();
        $anonymizer = anonymizer::from_map(['[id_1]' => "Main St 1\r\nFloor 2\rBack"], anonymizer::LEVEL_BASIC);
        $where = 'SELECT id FROM {user} WHERE address = ';

        foreach (['postgres', 'mysql'] as $family) {
            $this->assertSame($where . "'Main St 1\nFloor 2\nBack'", $anonymizer->restore_sql($where . '[id_1]', $family));
            $this->assertSame($where . "'Main St 1\nFloor 2\nBack'", $anonymizer->restore_sql($where . "'[id_1]'", $family));
        }
        $this->assertSame(
            ['address' => "Main St 1\r\nFloor 2\rBack"],
            $anonymizer->restore_value(['address' => '[id_1]']),
            'Other arguments keep the value as it is'
        );
    }

    /**
     * JEDOMI-SEC-005: the whole destination of a Markdown link or an href is the address, across spaces, "<" and new
     * lines, and any scheme other than http or https is external; links of the site still get the real values.
     */
    public function test_whole_link_destination_and_other_schemes_keep_placeholders(): void {
        global $CFG;
        $this->resetAfterTest();
        $anonymizer = anonymizer::from_map(['[person_1]' => 'Ximena'], anonymizer::LEVEL_BASIC);

        $external = [
            'https://attacker.example/r?n= [person_1]',
            'https://attacker.example/r?n=<[person_1]',
            "https://attacker.example/r?n=\n[person_1]",
            "/\t/attacker.example/r?n=[person_1]",
            'mailto:a@attacker.example?body=[person_1]',
            'tg://msg?text=[person_1]',
        ];
        foreach ($external as $url) {
            $this->assertSame(
                "[x]({$url}) by Ximena",
                $anonymizer->restore("[x]({$url}) by [person_1]"),
                json_encode($url)
            );
            $this->assertSame(
                "<a href='{$url}'>Ximena</a>",
                $anonymizer->restore("<a href='{$url}'>[person_1]</a>"),
                json_encode($url)
            );
        }
        $site = $CFG->wwwroot . '/user/profile.php?name= ';
        $this->assertSame("[Ximena]({$site}Ximena)", $anonymizer->restore("[[person_1]]({$site}[person_1])"));
        $this->assertSame('[p](/user/view.php?n= Ximena)', $anonymizer->restore('[p](/user/view.php?n= [person_1])'));
        $text = "[see {$CFG->wwwroot}/x](https://attacker.example/?n=[person_1])";
        $this->assertSame($text, $anonymizer->restore($text), 'A site address in the link text');
    }

    /**
     * JEDOMI-SEC-005: a Markdown link is read as a whole unit, so an address at the end of the link text cannot run into
     * the destination and leave part of it with the real values; the text gets them unless it is an external address.
     */
    public function test_link_text_ending_in_an_address_does_not_split_the_destination(): void {
        global $CFG;
        $this->resetAfterTest();
        $anonymizer = anonymizer::from_map(['[person_1]' => 'Ximena'], anonymizer::LEVEL_BASIC);

        $kept = [
            'foreign address and a space' => '[docs.example/](https://evil.example/?q= [person_1])',
            'site address and "<"' => "[{$CFG->wwwroot}/](https://evil.example/?q=<[person_1]>)",
            'new line' => "[docs.example/](https://evil.example/?q=\n[person_1])",
            'address text of another host' => '[https://evil.example/[person_1]](/user/view.php)',
            'address glued before a link' => 'https://evil.example/?q=[person_1](/x)',
            'other address inside a site link' => '[x](/user/view.php https://evil.example/?q=[person_1])',
        ];
        foreach ($kept as $case => $link) {
            $this->assertSame("{$link} by Ximena", $anonymizer->restore("{$link} by [person_1]"), $case);
        }

        $unclosed = '[docs.example/](https://evil.example/(a [person_1] by [person_1]';
        $this->assertSame($unclosed, $anonymizer->restore($unclosed), 'An unclosed destination runs to the end');
        $this->assertSame(
            '[see Ximena at docs.example/](https://evil.example/?q= [person_1])',
            $anonymizer->restore('[see [person_1] at docs.example/](https://evil.example/?q= [person_1])'),
            'Nested brackets in the link text'
        );
        $this->assertSame(
            '[Ximena](https://evil.example/?q= [person_1])',
            $anonymizer->restore('[[person_1]](https://evil.example/?q= [person_1])')
        );
        $site = $CFG->wwwroot . '/user/profile.php?name= ';
        $this->assertSame(
            "[{$CFG->wwwroot}/]({$site}Ximena) and [p](/user/view.php?n=<Ximena>)",
            $anonymizer->restore("[{$CFG->wwwroot}/]({$site}[person_1]) and [p](/user/view.php?n=<[person_1]>)"),
            'Links of the site keep the real values'
        );
        $this->assertSame('Hello Ximena, see [x] today', $anonymizer->restore('Hello [person_1], see [x] today'));
    }

    /**
     * MDL-CTR-011: a placeholder the AI wrote without its brackets ("person_1", "Person_1") gets its real value when it is
     * a whole word and that placeholder exists in the anonymiser; any other token is kept as written.
     */
    public function test_restore_puts_back_bracketless_placeholders_of_the_map(): void {
        $this->resetAfterTest();
        $map = ['[person_1]' => 'Ximena', '[email_2]' => 'xq@example.com', '[literal_1]' => '[person_7]'];
        $anonymizer = anonymizer::from_map($map, anonymizer::LEVEL_BASIC);

        $this->assertSame('Hello Ximena.', $anonymizer->restore('Hello person_1.'));
        $this->assertSame('Hello Ximena (xq@example.com)', $anonymizer->restore('Hello Person_1 (EMAIL_2)'));
        $this->assertSame('Ximena and Ximena', $anonymizer->restore('[person_1] and person_1'));
        $this->assertSame('Hello person_9', $anonymizer->restore('Hello person_9'), 'Not in the map');
        $this->assertSame('Hello Person 1', $anonymizer->restore('Hello Person 1'), 'Bracketless with a space');
        $this->assertSame('literal_1 stays', $anonymizer->restore('literal_1 stays'), 'Literal placeholders');
        $this->assertSame('[person_7] is the token', $anonymizer->restore('[literal_1] is the token'));
    }

    /**
     * MDL-CTR-011: a bracketless token inside another word, followed by more digits or next to "_", "/", "[" or "]" is not
     * a placeholder.
     */
    public function test_restore_keeps_bracketless_tokens_that_are_not_whole_words(): void {
        $this->resetAfterTest();
        $anonymizer = anonymizer::from_map(['[person_1]' => 'Ximena'], anonymizer::LEVEL_BASIC);

        foreach (
            ['xperson_1', 'person_10', 'person_01', 'my_person_1', 'person_1_x', 'person_1a', 'a/person_1',
                'person_1/b', 'åperson_1', '1person_1'] as $token
        ) {
            $this->assertSame("See {$token} now", $anonymizer->restore("See {$token} now"), $token);
        }
    }

    /**
     * MDL-CTR-011 with JEDOMI-SEC-005: a bracketless placeholder in an address that leaves the site keeps its form, in a
     * Markdown link destination, an href and a bare address; addresses of the site get the real value.
     */
    public function test_bracketless_placeholders_in_external_addresses_are_not_restored(): void {
        global $CFG;
        $this->resetAfterTest();
        $anonymizer = anonymizer::from_map(['[person_1]' => 'Ximena'], anonymizer::LEVEL_BASIC);

        foreach (
            ['https://attacker.example/r?n=person_1', 'mailto:person_1@attacker.example',
                'https://attacker.example/r?n=Person_1 x'] as $url
        ) {
            $this->assertSame(
                "See [the feedback]({$url}) of Ximena.",
                $anonymizer->restore("See [the feedback]({$url}) of person_1."),
                "No real value in {$url}"
            );
        }
        $this->assertSame(
            '<a href="https://attacker.example/?n=person_1">Ximena</a> and https://attacker.example/?n=person_1',
            $anonymizer->restore('<a href="https://attacker.example/?n=person_1">person_1</a> and '
                . 'https://attacker.example/?n=person_1')
        );
        $this->assertSame(
            "[Ximena]({$CFG->wwwroot}/user/profile.php?name=Ximena)",
            $anonymizer->restore("[person_1]({$CFG->wwwroot}/user/profile.php?name=person_1)")
        );
    }
}
