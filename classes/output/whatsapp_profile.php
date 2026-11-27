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
 * WhatsApp profile page: consent, one-time link code with its wa.me link, link state and opt-out state.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class whatsapp_profile implements \core\output\named_templatable, \renderable, \templatable {
    /** @var string The channel is not configured; only administrators see the page. */
    public const STATE_NOT_CONFIGURED = 'notconfigured';
    /** @var string The role of the user cannot use the channel. */
    public const STATE_ROLE_BLOCKED = 'roleblocked';
    /** @var string The account is linked. */
    public const STATE_LINKED = 'linked';
    /** @var string The account is not linked. */
    public const STATE_NOT_LINKED = 'notlinked';

    /** @var int Digits of the phone shown on a linked account. */
    private const PHONE_DIGITS_SHOWN = 4;

    /**
     * Store the page state.
     *
     * @param string $state One of the STATE_* constants.
     * @param string $displaynumber Number of the institution, digits only; empty when not saved.
     * @param \stdClass|null $link Link of the account, when linked.
     * @param string $code One-time link code just generated; empty for none.
     * @param int $codeexpiry Time the code expires; 0 for none.
     * @param \moodle_url $pageurl URL the page forms post to.
     * @param string $consentformhtml Rendered consent form, shown while no code is generated.
     */
    public function __construct(
        /** @var string One of the STATE_* constants. */
        protected string $state,
        /** @var string Number of the institution. */
        protected string $displaynumber,
        /** @var \stdClass|null Link of the account. */
        protected ?\stdClass $link,
        /** @var string One-time link code. */
        protected string $code,
        /** @var int Time the code expires. */
        protected int $codeexpiry,
        /** @var \moodle_url URL the page forms post to. */
        protected \moodle_url $pageurl,
        /** @var string Rendered consent form. */
        protected string $consentformhtml = '',
    ) {
    }

    /**
     * Export the state, the code and its wa.me link for the template; never the BSUID or the full phone.
     *
     * @param \renderer_base $output Renderer used to render the page.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $hascode = $this->code !== '' && $this->state === self::STATE_NOT_LINKED;
        $message = $hascode ? \local_jedomi\whatsapp\link_codes::PREFIX . ' ' . $this->code : '';
        $walink = $hascode && $this->displaynumber !== ''
            ? 'https://wa.me/' . rawurlencode($this->displaynumber) . '?text=' . rawurlencode($message)
            : '';
        $linkedas = '';
        if ($this->link) {
            $waid = (string) ($this->link->waid ?? '');
            $linkedas = $waid !== ''
                ? get_string('whatsapp_linked_phone', 'local_jedomi', substr($waid, -self::PHONE_DIGITS_SHOWN))
                : get_string('whatsapp_linked_without_phone', 'local_jedomi');
        }
        return [
            'notconfigured' => $this->state === self::STATE_NOT_CONFIGURED,
            'roleblocked' => $this->state === self::STATE_ROLE_BLOCKED,
            'linked' => $this->state === self::STATE_LINKED,
            'notlinked' => $this->state === self::STATE_NOT_LINKED,
            'settingsurl' => (new \moodle_url('/local/jedomi/admin_settings.php', ['tab' => 'integrations']))->out(false),
            'actionurl' => $this->pageurl->out(false),
            'sesskey' => sesskey(),
            'displaynumber' => $this->displaynumber,
            'linkedas' => $linkedas,
            'linkedtime' => $this->link ? (int) $this->link->timecreated : 0,
            'optedout' => $this->link !== null && !empty($this->link->optedout),
            'hascode' => $hascode,
            'code' => $hascode ? $this->code : '',
            'message' => $message,
            'walink' => $walink,
            'codeexpiry' => $hascode ? $this->codeexpiry : 0,
            'consentformhtml' => $this->state === self::STATE_NOT_LINKED && !$hascode ? $this->consentformhtml : '',
        ];
    }

    /**
     * Template of the page.
     *
     * @param \renderer_base $renderer Renderer used to render the page.
     * @return string
     */
    public function get_template_name(\renderer_base $renderer): string {
        return 'local_jedomi/whatsapp_profile';
    }
}
