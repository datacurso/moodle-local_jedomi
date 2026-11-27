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

namespace local_jedomi\form;

use local_jedomi\whatsapp\consent;

/**
 * Tests for the consent form of the WhatsApp profile page.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\form\whatsapp_consent_form
 */
final class whatsapp_consent_form_test extends \advanced_testcase {
    /**
     * Submit the form.
     *
     * @param array $data Submitted values.
     * @return whatsapp_consent_form
     */
    private function submit(array $data): whatsapp_consent_form {
        whatsapp_consent_form::mock_submit($data);
        return new whatsapp_consent_form(new \moodle_url('/local/jedomi/whatsapp_profile.php'));
    }

    /**
     * The consent text is shown with its categories checked by default.
     */
    public function test_form_shows_the_consent_text(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $html = (new whatsapp_consent_form(new \moodle_url('/local/jedomi/whatsapp_profile.php')))->render();

        $this->assertStringContainsString(get_string('whatsapp_consent_text', 'local_jedomi'), $html);
        $this->assertStringContainsString('name="consent"', $html);
        $this->assertStringContainsString('name="chat"', $html);
        $this->assertStringContainsString('name="grades"', $html);
    }

    /**
     * Without the consent box the form gives no data, so no code is generated.
     */
    public function test_without_consent_there_is_no_data(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $form = $this->submit(['consent' => 0, 'chat' => 1, 'grades' => 1]);

        $this->assertNull($form->get_data());
        $this->assertNull($form->get_consent(1800000000));
    }

    /**
     * Consent without any category gives no data.
     */
    public function test_consent_needs_a_category(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->assertNull($this->submit(['consent' => 1, 'chat' => 0, 'grades' => 0])->get_consent(1800000000));
    }

    /**
     * Accepted consent gives the current version, the categories chosen and the time.
     */
    public function test_accepted_consent_records_version_and_categories(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $consent = $this->submit(['consent' => 1, 'chat' => 1, 'grades' => 0])->get_consent(1800000000);

        $this->assertNotNull($consent);
        $this->assertSame(consent::VERSION, $consent->version);
        $this->assertSame('chat', $consent->categories_field());
        $this->assertSame(1800000000, $consent->acceptedat);
    }
}
