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

namespace local_jedomi\telegram;

use local_jedomi\ai\client;
use local_jedomi\ai\fake_ai_client;
use local_jedomi\messaging\fake_plugin_state;
use local_jedomi\messaging\plugin_state;
use local_jedomi\provider_absence_probe;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_ai_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_telegram_api_client.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/fake_plugin_state.php');
require_once($CFG->dirroot . '/local/jedomi/tests/fixtures/provider_absence_probe.php');

/**
 * Order of the steps a Telegram message goes through: help, message limit, private chat, link, link commands, access,
 * PIN or confirmation word, new conversation, media and conversation. Each test sends a message two steps disagree on
 * and checks which one answers.
 *
 * @package    local_jedomi
 * @category   test
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_jedomi\telegram\webhook_handler
 * @covers     \local_jedomi\messaging\router
 */
final class webhook_routing_test extends \advanced_testcase {
    /** @var fake_ai_client AI double. */
    private fake_ai_client $ai;

    /** @var fake_telegram_api_client Telegram double. */
    private fake_telegram_api_client $telegram;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        // Jedomi is enabled through the plugin state seam: the bot never asks the Datacurso AI provider, which the CI
        // does not install.
        provider_absence_probe::start();
        \core\di::set(plugin_state::class, fake_plugin_state::enabled());
        set_config('telegram_enabled', 1, 'local_jedomi');
        $this->ai = new fake_ai_client();
        client::set_instance_for_testing($this->ai);
        $this->telegram = new fake_telegram_api_client();
        $this->telegram->files = ['photo-big' => 'jpeg bytes'];
        api_client::set_instance_for_testing($this->telegram);
    }

    #[\Override]
    protected function assertPostConditions(): void {
        parent::assertPostConditions();
        $this->assertSame([], provider_absence_probe::touched(), 'The test reached the Datacurso AI provider.');
    }

    #[\Override]
    protected function tearDown(): void {
        client::set_instance_for_testing(null);
        api_client::set_instance_for_testing(null);
        parent::tearDown();
    }

    /**
     * Link a chat to a user.
     *
     * @param int $userid User.
     * @param string $chatid Chat id.
     * @param int|null $lastused Last use of the link; now when null.
     * @return void
     */
    private function link(int $userid, string $chatid, ?int $lastused = null): void {
        global $DB;
        $time = $lastused ?? time();
        $DB->insert_record('local_jedomi_telegram_links', (object) ['userid' => $userid, 'chatid' => $chatid,
            'username' => 'linked', 'confirmed' => 1, 'timecreated' => $time, 'lastused' => $time,
            'lastproactivesent' => 0]);
    }

    /**
     * Send a message to the bot, restoring the PHP time limit the AI loop raises.
     *
     * @param string $chatid Chat id.
     * @param array $fields Message fields besides the chat.
     * @param string $type Chat type.
     * @return void
     */
    private function receive(string $chatid, array $fields, string $type = 'private'): void {
        $limit = (int) ini_get('max_execution_time');
        webhook_handler::handle(['message' => array_merge(['chat' => ['id' => $chatid, 'type' => $type]], $fields)]);
        set_time_limit($limit);
    }

    /**
     * Texts the bot sent.
     *
     * @return string[]
     */
    private function texts(): array {
        return array_column($this->telegram->sent, 'text');
    }

    /**
     * Whether a chat is linked to a user.
     *
     * @param string $chatid Chat id.
     * @param int $userid User.
     * @return bool
     */
    private function linked(string $chatid, int $userid): bool {
        global $DB;
        return $DB->record_exists('local_jedomi_telegram_links', ['chatid' => $chatid, 'userid' => $userid]);
    }

    /**
     * Help comes before the private chat check: a group gets the help.
     */
    public function test_help_is_answered_in_a_group(): void {
        $this->receive('-1009100001', ['text' => '/help'], 'group');

        $this->assertCount(1, $this->texts());
        $this->assertStringContainsString(get_string('telegram_help_heading', 'local_jedomi'), $this->texts()[0]);
    }

    /**
     * The message limit comes before the private chat check: a group over the limit gets the limit notice.
     */
    public function test_message_limit_comes_before_the_private_chat_check(): void {
        set_config('telegram_rate_limit', 1, 'local_jedomi');
        set_config('telegram_cooldown', 5, 'local_jedomi');

        $this->receive('-1009100002', ['text' => 'hello'], 'group');
        $this->receive('-1009100002', ['text' => 'hello'], 'group');

        $this->assertSame([
            get_string('telegram_msg_private_only', 'local_jedomi'),
            get_string('telegram_msg_rate_limited', 'local_jedomi', ['minutes' => 5]),
        ], $this->texts());
    }

    /**
     * The private chat check comes before the link: a link code sent from a group links nothing.
     */
    public function test_link_code_sent_from_a_group_links_nothing(): void {
        $user = $this->getDataGenerator()->create_user();
        $code = manager::generate_link_code((int) $user->id);

        $this->receive('-1009100003', ['text' => $code], 'group');

        $this->assertSame([get_string('telegram_msg_private_only', 'local_jedomi')], $this->texts());
        $this->assertFalse($this->linked('-1009100003', (int) $user->id));
    }

    /**
     * Help comes before the link: the help of a chat whose link expired keeps the link.
     */
    public function test_help_keeps_an_expired_link(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->link((int) $user->id, '791000004', time() - 40 * DAYSECS);

        $this->receive('791000004', ['text' => '/help']);

        $this->assertStringContainsString(get_string('telegram_help_heading', 'local_jedomi'), $this->texts()[0]);
        $this->assertTrue($this->linked('791000004', (int) $user->id));
    }

    /**
     * Unlinking comes before the access check: a suspended account can still unlink its chat.
     */
    public function test_suspended_account_can_unlink(): void {
        $user = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $this->link((int) $user->id, '791000005');

        $this->receive('791000005', ['text' => '/unlink']);

        $this->assertSame([get_string('telegram_msg_unlinked', 'local_jedomi')], $this->texts());
        $this->assertFalse($this->linked('791000005', (int) $user->id));
    }

    /**
     * A link code comes before the access check: the chat of a suspended account moves to another account.
     */
    public function test_link_code_moves_the_chat_of_a_suspended_account(): void {
        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $other = $this->getDataGenerator()->create_user();
        $this->link((int) $suspended->id, '791000006');
        $code = manager::generate_link_code((int) $other->id);

        $this->receive('791000006', ['text' => '/start ' . $code]);

        $this->assertTrue($this->linked('791000006', (int) $other->id));
        $this->assertFalse($this->linked('791000006', (int) $suspended->id));
        $this->assertStringContainsString($other->username, end($this->telegram->sent)['text']);
    }

    /**
     * The access check comes before a new conversation: a suspended account gets the notice, not a greeting.
     */
    public function test_suspended_account_cannot_start_a_new_conversation(): void {
        $user = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $this->link((int) $user->id, '791000007');

        $this->receive('791000007', ['text' => '/new']);

        $this->assertSame([get_string('telegram_msg_account_inactive', 'local_jedomi')], $this->texts());
        $this->assertSame([], $this->ai->calls);
    }

    /**
     * The access check comes before the media: the photo of a suspended account is not downloaded.
     */
    public function test_photo_of_a_suspended_account_is_not_downloaded(): void {
        $user = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $this->link((int) $user->id, '791000008');

        $this->receive('791000008', ['photo' => [['file_id' => 'photo-big']], 'caption' => 'What is this?']);

        $this->assertSame([get_string('telegram_msg_account_inactive', 'local_jedomi')], $this->texts());
        $this->assertSame([], $this->telegram->downloads);
    }

    /**
     * The new conversation command comes before the media: a sticker with no text asks for text, "/new" starts over.
     */
    public function test_new_conversation_reaches_the_ai_as_a_greeting(): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Valentina']);
        $this->link((int) $user->id, '791000009');
        $this->ai->enqueue_text('Hello again.');

        $this->receive('791000009', ['sticker' => ['file_id' => 'sticker-1']]);
        $this->receive('791000009', ['text' => '/new']);

        $this->assertSame(get_string('telegram_msg_text_only', 'local_jedomi'), $this->texts()[0]);
        $this->assertCount(1, $this->ai->calls);
    }
}
