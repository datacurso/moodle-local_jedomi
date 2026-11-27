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

namespace local_jedomi\whatsapp;

use local_jedomi\telegram\link_code_test_clock;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/link_code_test_clock.php');

/**
 * Tests for the one-time WhatsApp link codes and the consent they carry.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\link_codes
 * @covers     \local_jedomi\whatsapp\consent
 */
final class link_codes_test extends \advanced_testcase {
    /** @var int Frozen time of the consent. */
    private const NOW = 1800000000;

    /**
     * Restore the cache clock after each test.
     */
    protected function tearDown(): void {
        link_code_test_clock::reset();
        parent::tearDown();
    }

    /**
     * Consent to both categories.
     *
     * @return consent
     */
    private static function consent(): consent {
        return new consent(consent::VERSION, [consent::CHAT, consent::GRADES], self::NOW);
    }

    /**
     * A code has six characters of the code alphabet with a digit, and two codes of the same user differ.
     */
    public function test_code_has_the_format_of_a_link_code(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $first = link_codes::generate((int) $user->id, self::consent());
        $second = link_codes::generate((int) $user->id, self::consent());

        foreach ([$first, $second] as $code) {
            $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZ2-9]{6}$/', $code);
            $this->assertMatchesRegularExpression('/[2-9]/', $code);
        }
        $this->assertNotSame($first, $second);
    }

    /**
     * A code resolves to its user with the consent recorded when it was generated, in any letter case.
     */
    public function test_code_resolves_to_its_user_and_consent(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $code = link_codes::generate((int) $user->id, new consent(consent::VERSION, [consent::CHAT], self::NOW));

        $grant = link_codes::resolve(strtolower($code));

        $this->assertNotNull($grant);
        $this->assertSame((int) $user->id, $grant['userid']);
        $this->assertSame(consent::VERSION, $grant['consent']->version);
        $this->assertSame('chat', $grant['consent']->categories_field());
        $this->assertSame(self::NOW, $grant['consent']->acceptedat);
    }

    /**
     * Only the newest code of a user is valid: an earlier one fails the check in both directions.
     */
    public function test_an_earlier_code_of_the_user_no_longer_resolves(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $old = link_codes::generate((int) $user->id, self::consent());
        $new = link_codes::generate((int) $user->id, self::consent());

        $this->assertNull(link_codes::resolve($old));
        $this->assertSame((int) $user->id, link_codes::resolve($new)['userid']);
    }

    /**
     * An unknown code, a malformed one and a Telegram code of the same user never resolve.
     */
    public function test_unknown_malformed_and_telegram_codes_do_not_resolve(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $telegram = \local_jedomi\telegram\manager::generate_link_code((int) $user->id);

        $this->assertNull(link_codes::resolve('ABC234'));
        $this->assertNull(link_codes::resolve(''));
        $this->assertNull(link_codes::resolve('ABC 23'));
        $this->assertNull(link_codes::resolve('../../x'));
        $this->assertNull(link_codes::resolve($telegram));
    }

    /**
     * A used code is removed and resolves no more.
     */
    public function test_invalidated_code_does_not_resolve(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $code = link_codes::generate((int) $user->id, self::consent());

        link_codes::invalidate((int) $user->id, $code);

        $this->assertNull(link_codes::resolve($code));
    }

    /**
     * A code is valid for ten minutes.
     */
    public function test_code_expires_after_ten_minutes(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $start = (float) time();
        link_code_test_clock::set_now($start);
        $code = link_codes::generate((int) $user->id, self::consent());

        link_code_test_clock::set_now($start + link_codes::TTL - 1);
        $this->assertNotNull(link_codes::resolve($code));
        link_code_test_clock::set_now($start + link_codes::TTL + 1);
        $this->assertNull(link_codes::resolve($code));
    }

    /**
     * Texts that carry a link code, with or without the JEDOMI prefix, in any letter case.
     *
     * @return array
     */
    public static function code_texts(): array {
        return [
            'bare code' => ['ABC234', 'ABC234'],
            'lower case' => ['abc234', 'ABC234'],
            'prefix' => ['JEDOMI ABC234', 'ABC234'],
            'prefix in lower case' => ['jedomi abc234', 'ABC234'],
            'prefix with spaces' => ['  Jedomi   ABC234 ', 'ABC234'],
            'prefix with a slash' => ['/jedomi ABC234', 'ABC234'],
        ];
    }

    /**
     * The code of a text that carries one.
     *
     * @dataProvider code_texts
     * @param string $text Text of the message.
     * @param string $expected Code.
     */
    public function test_parse_finds_the_code(string $text, string $expected): void {
        $this->assertSame($expected, link_codes::parse($text));
    }

    /**
     * Texts that are not a link code.
     *
     * @return array
     */
    public static function other_texts(): array {
        return [
            'empty' => [''],
            'word without a digit' => ['ABCDEF'],
            'letter outside the alphabet' => ['ABO234'],
            'too short' => ['AB234'],
            'too long' => ['ABC2345'],
            'sentence' => ['my code is ABC234'],
            'prefix alone' => ['JEDOMI'],
            'other prefix' => ['START ABC234'],
            'command' => ['BAJA'],
        ];
    }

    /**
     * A text that is not a link code gives null.
     *
     * @dataProvider other_texts
     * @param string $text Text of the message.
     */
    public function test_parse_ignores_other_texts(string $text): void {
        $this->assertNull(link_codes::parse($text));
    }

    /**
     * Consent needs a version and at least one known category; categories are kept in a fixed order without repeats.
     */
    public function test_consent_validates_its_categories(): void {
        $consent = new consent('1', [consent::GRADES, consent::CHAT, consent::GRADES], self::NOW);
        $this->assertSame('chat,grades', $consent->categories_field());

        foreach ([[[], 'no category'], [['marketing'], 'unknown category']] as [$categories, $why]) {
            try {
                new consent('1', $categories, self::NOW);
                $this->fail('Accepted ' . $why);
            } catch (\invalid_parameter_exception $e) {
                $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
            }
        }
        $this->expectException(\invalid_parameter_exception::class);
        new consent('', [consent::CHAT], self::NOW);
    }
}
