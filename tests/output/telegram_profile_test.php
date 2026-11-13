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
 * Tests for the Telegram profile page renderable.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\output\telegram_profile
 */
final class telegram_profile_test extends \advanced_testcase {
    /**
     * Build the renderable for a state.
     *
     * @param string $state One of the telegram_profile::STATE_* constants.
     * @param \stdClass|null $link Confirmed link, if any.
     * @param string $code Generated link code.
     * @return telegram_profile
     */
    private function profile(string $state, ?\stdClass $link = null, string $code = ''): telegram_profile {
        return new telegram_profile(
            $state,
            'jedomibot',
            'Jedomi',
            $link,
            $code,
            $code ? 1700000600 : 0,
            new \moodle_url('/local/jedomi/telegram_profile.php', ['userid' => 2])
        );
    }

    /**
     * MDL-UNIT-057: a generated code exports the code, the countdown expiry and the direct link to the bot.
     * A generated code exports the deep link and the expiry.
     *
     * @return void
     */
    public function test_export_with_code(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/telegram_profile.php'));

        $data = $this->profile(telegram_profile::STATE_NOT_LINKED, null, 'ABC234')
            ->export_for_template($PAGE->get_renderer('local_jedomi'));

        $this->assertTrue($data['notlinked']);
        $this->assertTrue($data['hascode']);
        $this->assertSame('https://t.me/jedomibot?start=ABC234', $data['deeplink']);
        $this->assertSame('https://t.me/jedomibot', $data['botlink']);
        $this->assertSame(1700000600, $data['codeexpiry']);
        $this->assertFalse($data['linked']);
    }

    /**
     * MDL-UNIT-057: the linked state exports the Telegram user and the link date.
     * A linked account shows the Telegram username, or the chat id when there is none.
     *
     * @return void
     */
    public function test_export_linked(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/telegram_profile.php'));
        $renderer = $PAGE->get_renderer('local_jedomi');

        $named = $this->profile(telegram_profile::STATE_LINKED, (object) ['username' => 'ada', 'chatid' => '100',
            'timecreated' => 1700000000])->export_for_template($renderer);
        $unnamed = $this->profile(telegram_profile::STATE_LINKED, (object) ['username' => '', 'chatid' => '100',
            'timecreated' => 1700000000])->export_for_template($renderer);

        $this->assertTrue($named['linked']);
        $this->assertSame('@ada', $named['linkedas']);
        $this->assertSame(get_string('telegram_linked_chatid', 'local_jedomi', '100'), $unnamed['linkedas']);
        $this->assertSame(1700000000, $named['linkedtime']);
    }

    /**
     * MDL-UNIT-057: each state (not configured with settings link, role blocked, code, linked) renders its own block.
     * Each state renders its own block.
     *
     * @return void
     */
    public function test_render_states(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url(new \moodle_url('/local/jedomi/telegram_profile.php'));
        $renderer = $PAGE->get_renderer('local_jedomi');

        $notconfigured = $renderer->render($this->profile(telegram_profile::STATE_NOT_CONFIGURED));
        $blocked = $renderer->render($this->profile(telegram_profile::STATE_ROLE_BLOCKED));
        $code = $renderer->render($this->profile(telegram_profile::STATE_NOT_LINKED, null, 'ABC234'));
        $linked = $renderer->render($this->profile(telegram_profile::STATE_LINKED, (object) ['username' => 'ada',
            'chatid' => '1', 'timecreated' => 1700000000]));

        $this->assertStringContainsString('admin_settings.php?tab=integrations', $notconfigured);
        $this->assertStringContainsString(get_string('telegram_msg_role_blocked', 'local_jedomi'), $blocked);
        $this->assertStringContainsString('data-region="local_jedomi/telegram_profile/countdown"', $code);
        $this->assertStringContainsString('/start ABC234', $code);
        $this->assertStringContainsString('name="action" value="generate"', $code);
        $this->assertStringContainsString('data-form="local_jedomi/telegram_profile/unlink"', $linked);
        $this->assertStringContainsString('@ada', $linked);
    }

    /**
     * MDL-UNIT-057: the not linked state without a code offers to generate one and shows no countdown.
     *
     * @return void
     */
    public function test_not_linked_without_code_offers_to_generate_one(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/telegram_profile.php'));

        $html = $PAGE->get_renderer('local_jedomi')->render($this->profile(telegram_profile::STATE_NOT_LINKED));

        $this->assertStringContainsString('name="action" value="generate"', $html);
        $this->assertStringNotContainsString('data-region="local_jedomi/telegram_profile/countdown"', $html);
        $this->assertStringNotContainsString('data-form="local_jedomi/telegram_profile/unlink"', $html);
    }

    /**
     * MDL-UNIT-057: the linked state shows the Telegram user, the link date and the unlink button, and no code.
     *
     * @return void
     */
    public function test_linked_state_shows_user_date_and_unlink(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/telegram_profile.php'));
        $time = 1700000000;

        $html = $PAGE->get_renderer('local_jedomi')->render($this->profile(
            telegram_profile::STATE_LINKED,
            (object) ['username' => 'ada', 'chatid' => '1', 'timecreated' => $time]
        ));

        $date = userdate($time, get_string('strftimedatetime', 'core_langconfig'));
        $this->assertStringContainsString('@ada', $html);
        $this->assertStringContainsString(get_string('telegram_linked_on', 'local_jedomi', $date), $html);
        $this->assertStringContainsString('data-form="local_jedomi/telegram_profile/unlink"', $html);
        $this->assertStringNotContainsString('name="action" value="generate"', $html);
    }

    /**
     * MDL-UNIT-057: the not configured and role blocked states show neither a code nor the unlink button.
     *
     * @return void
     */
    public function test_not_configured_and_blocked_states_offer_no_link_actions(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $PAGE->set_url(new \moodle_url('/local/jedomi/telegram_profile.php'));
        $renderer = $PAGE->get_renderer('local_jedomi');

        foreach ([telegram_profile::STATE_NOT_CONFIGURED, telegram_profile::STATE_ROLE_BLOCKED] as $state) {
            $data = $this->profile($state)->export_for_template($renderer);
            $html = $renderer->render($this->profile($state));
            $this->assertFalse($data['linked']);
            $this->assertFalse($data['notlinked']);
            $this->assertStringNotContainsString('name="action" value="generate"', $html);
            $this->assertStringNotContainsString('data-form="local_jedomi/telegram_profile/unlink"', $html);
        }
        $blocked = $renderer->render($this->profile(telegram_profile::STATE_ROLE_BLOCKED));
        $this->assertStringNotContainsString('admin_settings.php?tab=integrations', $blocked);
    }

    /**
     * MDL-UNIT-110: the subtitle of the Telegram link page is addressed to the user, explains what linking the account
     * is for and uses neutral Spanish with usted.
     *
     * @return void
     */
    public function test_link_page_subtitle_is_addressed_to_the_user(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_url(new \moodle_url('/local/jedomi/telegram_profile.php'));
        $renderer = $PAGE->get_renderer('local_jedomi');

        $html = $renderer->render($this->profile(telegram_profile::STATE_NOT_LINKED, null, 'ABC123'));
        $this->assertStringContainsString(get_string('telegram_link_subtitle', 'local_jedomi'), $html);
        $this->assertStringNotContainsString(get_string('telegram_heading_help', 'local_jedomi'), $html);

        $manager = get_string_manager();
        $spanish = $manager->get_string('telegram_link_subtitle', 'local_jedomi', null, 'es');
        $this->assertStringContainsString('Vincule su cuenta', $spanish);
        $this->assertDoesNotMatchRegularExpression('/\b(tu|tus|vincula|vinculá|puedes|podés)\b/iu', $spanish);
        $this->assertStringNotContainsString('bot de Telegram para que los usuarios', $spanish, 'Not the administrator text');
    }
}
