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

/**
 * Tests for the recipient of a WhatsApp message: a phone (to) or a BSUID (recipient).
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\whatsapp\recipient_ref
 */
final class recipient_ref_test extends \basic_testcase {
    /** @var string BSUID of Meta's example. */
    private const BSUID = 'US.13491208655302741918';

    public function test_phone_only_is_sent_in_to(): void {
        $ref = recipient_ref::from_link('16505551234', null);

        $this->assertSame('to', $ref->field());
        $this->assertSame('16505551234', $ref->value());
        $this->assertSame(['to' => '16505551234'], $ref->to_payload());
    }

    public function test_bsuid_only_is_sent_in_recipient(): void {
        $ref = recipient_ref::from_link(null, self::BSUID);

        $this->assertSame('recipient', $ref->field());
        $this->assertSame(['recipient' => self::BSUID], $ref->to_payload());
    }

    public function test_phone_wins_over_bsuid(): void {
        $ref = recipient_ref::from_link('16505551234', self::BSUID);

        $this->assertSame(['to' => '16505551234'], $ref->to_payload());
    }

    public function test_empty_phone_falls_back_to_bsuid(): void {
        $ref = recipient_ref::from_link('  ', self::BSUID);

        $this->assertSame(['recipient' => self::BSUID], $ref->to_payload());
    }

    public function test_values_are_trimmed_and_bsuid_kept_as_it_is(): void {
        $this->assertSame(['to' => '16505551234'], recipient_ref::from_link(' 16505551234 ', null)->to_payload());
        $this->assertSame(
            ['recipient' => 'US.ENT.abc123'],
            recipient_ref::from_link('', ' US.ENT.abc123 ')->to_payload()
        );
    }

    public function test_named_constructors(): void {
        $this->assertSame(['to' => '573001234567'], recipient_ref::phone('573001234567')->to_payload());
        $this->assertSame(['recipient' => self::BSUID], recipient_ref::bsuid(self::BSUID)->to_payload());
    }

    /**
     * Links without any identity to send to.
     *
     * @return array
     */
    public static function no_identity_provider(): array {
        return [
            'both null' => [null, null],
            'both empty' => ['', ''],
            'both blank' => [' ', "\t"],
        ];
    }

    /**
     * Checks one case of the data provider.
     *
     * @dataProvider no_identity_provider
     * @param string|null $waid Phone.
     * @param string|null $bsuid BSUID.
     */
    public function test_link_without_identity_is_rejected(?string $waid, ?string $bsuid): void {
        $this->expectException(\invalid_parameter_exception::class);
        recipient_ref::from_link($waid, $bsuid);
    }

    public function test_empty_named_values_are_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        recipient_ref::phone(' ');
    }

    public function test_empty_bsuid_is_rejected(): void {
        $this->expectException(\invalid_parameter_exception::class);
        recipient_ref::bsuid('');
    }
}
