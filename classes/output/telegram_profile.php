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
 * Telegram profile page: link status, one-time link code and instructions.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class telegram_profile implements \core\output\named_templatable, \renderable, \templatable {
    /** @var string The bot is not configured; only administrators see the page. */
    public const STATE_NOT_CONFIGURED = 'notconfigured';
    /** @var string The role of the user cannot use the bot. */
    public const STATE_ROLE_BLOCKED = 'roleblocked';
    /** @var string The account is linked. */
    public const STATE_LINKED = 'linked';
    /** @var string The account is not linked. */
    public const STATE_NOT_LINKED = 'notlinked';

    /** @var string One of the STATE_* constants. */
    protected string $state;
    /** @var string Bot username, without the @. */
    protected string $botusername;
    /** @var string Bot display name. */
    protected string $botfirstname;
    /** @var \stdClass|null Confirmed link record. */
    protected ?\stdClass $link;
    /** @var string One-time link code just generated; empty for none. */
    protected string $code;
    /** @var int Time the code expires; 0 for none. */
    protected int $codeexpiry;
    /** @var \moodle_url URL the page forms post to. */
    protected \moodle_url $pageurl;
    /** @var bool Whether the user confirms Telegram writes with a PIN (Decision 7). */
    protected bool $pinrequired;
    /** @var bool Whether the user has set the PIN. */
    protected bool $pinset;

    /**
     * Store the page state.
     *
     * @param string $state One of the STATE_* constants.
     * @param string $botusername Bot username, without the @.
     * @param string $botfirstname Bot display name.
     * @param \stdClass|null $link Confirmed link record, when linked.
     * @param string $code One-time link code just generated; empty for none.
     * @param int $codeexpiry Time the code expires; 0 for none.
     * @param \moodle_url $pageurl URL the page forms post to.
     * @param bool $pinrequired Whether the user confirms Telegram writes with a PIN.
     * @param bool $pinset Whether the user has set the PIN.
     */
    public function __construct(
        string $state,
        string $botusername,
        string $botfirstname,
        ?\stdClass $link,
        string $code,
        int $codeexpiry,
        \moodle_url $pageurl,
        bool $pinrequired = false,
        bool $pinset = false
    ) {
        $this->state = $state;
        $this->botusername = $botusername;
        $this->botfirstname = $botfirstname;
        $this->link = $link;
        $this->code = $code;
        $this->codeexpiry = $codeexpiry;
        $this->pageurl = $pageurl;
        $this->pinrequired = $pinrequired;
        $this->pinset = $pinset;
    }

    /**
     * Export the state, the bot links and the code for the template.
     *
     * @param \renderer_base $output Renderer used to render the page.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $botlink = 'https://t.me/' . rawurlencode($this->botusername);
        $linkedas = '';
        if ($this->link) {
            $linkedas = !empty($this->link->username)
                ? '@' . $this->link->username
                : get_string('telegram_linked_chatid', 'local_jedomi', (string) $this->link->chatid);
        }
        return [
            'notconfigured' => $this->state === self::STATE_NOT_CONFIGURED,
            'roleblocked' => $this->state === self::STATE_ROLE_BLOCKED,
            'linked' => $this->state === self::STATE_LINKED,
            'notlinked' => $this->state === self::STATE_NOT_LINKED,
            'settingsurl' => (new \moodle_url('/local/jedomi/admin_settings.php', ['tab' => 'integrations']))->out(false),
            'actionurl' => $this->pageurl->out(false),
            'sesskey' => sesskey(),
            'botusername' => $this->botusername,
            'botfirstname' => $this->botfirstname,
            'botlink' => $botlink,
            'linkedas' => $linkedas,
            'linkedtime' => $this->link ? (int) $this->link->timecreated : 0,
            'hascode' => $this->code !== '',
            'code' => $this->code,
            'deeplink' => $botlink . '?start=' . rawurlencode($this->code),
            'codeexpiry' => $this->codeexpiry,
            'pinrequired' => $this->pinrequired,
            'pinset' => $this->pinset,
            'pinminlength' => \local_jedomi\local\service\telegram_pin_service::MIN_LENGTH,
            'pinmaxlength' => \local_jedomi\local\service\telegram_pin_service::MAX_LENGTH,
            'pinpattern' => '[0-9]{' . \local_jedomi\local\service\telegram_pin_service::MIN_LENGTH . ','
                . \local_jedomi\local\service\telegram_pin_service::MAX_LENGTH . '}',
        ];
    }

    /**
     * Template of the page.
     *
     * @param \renderer_base $renderer Renderer used to render the page.
     * @return string
     */
    public function get_template_name(\renderer_base $renderer): string {
        return 'local_jedomi/telegram_profile';
    }
}
