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

/**
 * Channel-neutral access guard: who and when a messaging channel may serve.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_jedomi\messaging;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Access checks shared by the messaging channels (Telegram, WhatsApp).
 *
 * The channel checks are, in this order: Jedomi enabled in the Datacurso AI provider (the plugin_state from the
 * container) and the channel integration enabled (the {channel}_enabled setting). The user checks are an active
 * account and a role allowed by the channel ({channel}_hidden_roles, see local_jedomi_channel_role_allowed_for()).
 * The guard only decides; the channel chooses the message to send and when to check.
 */
class access_guard {
    /** @var string Denial code when the channel integration is disabled. */
    public const INTEGRATION_DISABLED = 'integrationdisabled';

    /**
     * Constructor.
     *
     * @param string $channel Channel name, the prefix of its settings, e.g. telegram.
     */
    public function __construct(
        /** @var string Channel name. */
        private readonly string $channel,
    ) {
    }

    /**
     * Why the channel may not serve anything now, or null when it may.
     *
     * @return array|null The plugin_state error (['code' => 'plugindisabled', 'message' => ...]) when Jedomi is
     *     disabled; ['code' => self::INTEGRATION_DISABLED, 'message' => ''] when the channel integration is disabled.
     */
    public function channel_denial(): ?array {
        $disabled = \core\di::get(plugin_state::class)->disabled_error();
        if ($disabled !== null) {
            return $disabled;
        }
        if (!get_config('local_jedomi', $this->channel . '_enabled')) {
            return ['code' => self::INTEGRATION_DISABLED, 'message' => ''];
        }
        return null;
    }

    /**
     * The account of a user when it can still be served: it exists, is not suspended or deleted, can log in and is
     * not the guest user.
     *
     * @param int $userid Moodle user id.
     * @return \stdClass|null The user record, or null when the account can no longer be served.
     */
    public function active_user(int $userid): ?\stdClass {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        if (!$user || !empty($user->suspended) || $user->auth === 'nologin' || isguestuser($user)) {
            return null;
        }
        return $user;
    }

    /**
     * Whether the channel role rule lets the user use the channel.
     *
     * @param int $userid Moodle user id.
     * @return bool
     */
    public function role_allowed(int $userid): bool {
        return \local_jedomi_channel_role_allowed_for($this->channel, $userid);
    }

    /**
     * The account of a user who may be served now: active and with a role allowed by the channel.
     *
     * @param int $userid Moodle user id.
     * @return \stdClass|null The user record, or null when the user may not be served.
     */
    public function served_user(int $userid): ?\stdClass {
        $user = $this->active_user($userid);
        if ($user === null || !$this->role_allowed((int) $user->id)) {
            return null;
        }
        return $user;
    }
}
