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

namespace local_jedomi\output;

/**
 * Tests for the WhatsApp profile page renderable.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\output\whatsapp_profile
 */
final class whatsapp_profile_test extends \advanced_testcase {
    /** @var string BSUID of the student. */
    private const BSUID = 'US.13491208655302741918';

    /**
     * Build the renderable.
     *
     * @param string $state One of the STATE_* constants.
     * @param \stdClass|null $link Link, if any.
     * @param string $code Generated link code.
     * @return whatsapp_profile
     */
    private function profile(string $state, ?\stdClass $link = null, string $code = ''): whatsapp_profile {
        return new whatsapp_profile(
            $state,
            '573001234567',
            $link,
            $code,
            $code !== '' ? 1800000600 : 0,
            new \moodle_url('/local/jedomi/whatsapp_profile.php', ['userid' => 2]),
            '<form>consent</form>'
        );
    }

    /**
     * A generated code exports the wa.me link with "JEDOMI <CODE>", the code and its expiry.
     */
    public function test_export_with_code_builds_the_wa_me_link(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/whatsapp_profile.php'));

        $profile = $this->profile(whatsapp_profile::STATE_NOT_LINKED, null, 'ABC234');
        $data = $profile->export_for_template($PAGE->get_renderer('core'));

        $this->assertTrue($data['notlinked']);
        $this->assertTrue($data['hascode']);
        $this->assertSame('ABC234', $data['code']);
        $this->assertSame('https://wa.me/573001234567?text=JEDOMI%20ABC234', $data['walink']);
        $this->assertSame('JEDOMI ABC234', $data['message']);
        $this->assertSame(1800000600, $data['codeexpiry']);
        $this->assertSame('', $data['consentformhtml'], 'The consent form is not shown with a code');
    }

    /**
     * Without a code the consent form is shown and no wa.me link is given.
     */
    public function test_export_without_code_shows_the_consent_form(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/whatsapp_profile.php'));

        $data = $this->profile(whatsapp_profile::STATE_NOT_LINKED)->export_for_template($PAGE->get_renderer('core'));

        $this->assertFalse($data['hascode']);
        $this->assertSame('', $data['walink']);
        $this->assertSame('<form>consent</form>', $data['consentformhtml']);
    }

    /**
     * A linked account exports the last digits of the phone and the opt-out state, never the BSUID or the full phone.
     */
    public function test_export_linked_shows_phone_end_and_opt_out(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/whatsapp_profile.php'));
        $link = (object) ['bsuid' => self::BSUID, 'waid' => '16505551234', 'optedout' => 1, 'timecreated' => 1700000000];

        $data = $this->profile(whatsapp_profile::STATE_LINKED, $link)->export_for_template($PAGE->get_renderer('core'));

        $this->assertTrue($data['linked']);
        $this->assertTrue($data['optedout']);
        $this->assertSame(1700000000, $data['linkedtime']);
        $this->assertSame(get_string('whatsapp_linked_phone', 'local_jedomi', '1234'), $data['linkedas']);
        $dump = json_encode($data);
        $this->assertStringNotContainsString(self::BSUID, $dump);
        $this->assertStringNotContainsString('16505551234', $dump);
    }

    /**
     * A link without phone says so.
     */
    public function test_export_linked_without_phone(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/whatsapp_profile.php'));
        $link = (object) ['bsuid' => self::BSUID, 'waid' => null, 'optedout' => 0, 'timecreated' => 1700000000];

        $data = $this->profile(whatsapp_profile::STATE_LINKED, $link)->export_for_template($PAGE->get_renderer('core'));

        $this->assertFalse($data['optedout']);
        $this->assertSame(get_string('whatsapp_linked_without_phone', 'local_jedomi'), $data['linkedas']);
    }

    /**
     * The template renders each state.
     */
    public function test_template_renders(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/whatsapp_profile.php'));
        $renderer = $PAGE->get_renderer('local_jedomi');
        $link = (object) ['bsuid' => self::BSUID, 'waid' => null, 'optedout' => 1, 'timecreated' => 1700000000];

        $withcode = $renderer->render($this->profile(whatsapp_profile::STATE_NOT_LINKED, null, 'ABC234'));
        $linked = $renderer->render($this->profile(whatsapp_profile::STATE_LINKED, $link));
        $blocked = $renderer->render($this->profile(whatsapp_profile::STATE_ROLE_BLOCKED));

        $this->assertStringContainsString('https://wa.me/573001234567?text=JEDOMI%20ABC234', $withcode);
        $this->assertStringContainsString(get_string('whatsapp_optedout_notice', 'local_jedomi'), $linked);
        $this->assertStringContainsString('name="action" value="unlink"', $linked);
        $this->assertStringContainsString(get_string('whatsapp_msg_role_blocked', 'local_jedomi'), $blocked);
        $this->assertStringNotContainsString('consent', $blocked);
    }
}
