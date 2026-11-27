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

namespace local_jedomi\whatsapp;

/**
 * Recipient of a message sent through the WhatsApp Cloud API.
 *
 * Meta accepts a phone (wa_id) in "to" or a business-scoped user ID (BSUID) in "recipient"; when both are sent, "to"
 * wins. Only one field is ever sent: the phone when the link has one, otherwise the BSUID, kept as it is (with its
 * country prefix).
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class recipient_ref {
    /** @var string Field of a phone (wa_id). */
    public const FIELD_TO = 'to';

    /** @var string Field of a BSUID. */
    public const FIELD_RECIPIENT = 'recipient';

    /** @var string Field of the request body. */
    private string $field;

    /** @var string Phone or BSUID. */
    private string $value;

    /**
     * Recipient with its field and value.
     *
     * @param string $field self::FIELD_TO or self::FIELD_RECIPIENT.
     * @param string $value Phone or BSUID, never empty.
     */
    private function __construct(string $field, string $value) {
        $this->field = $field;
        $this->value = $value;
    }

    /**
     * Recipient of a link: its phone when it has one, otherwise its BSUID.
     *
     * @param string|null $waid Phone (wa_id) of the link, if Meta shared it.
     * @param string|null $bsuid BSUID of the link.
     * @return self
     * @throws \invalid_parameter_exception When the link has neither.
     */
    public static function from_link(?string $waid, ?string $bsuid): self {
        $waid = trim((string) $waid);
        if ($waid !== '') {
            return new self(self::FIELD_TO, $waid);
        }
        $bsuid = trim((string) $bsuid);
        if ($bsuid !== '') {
            return new self(self::FIELD_RECIPIENT, $bsuid);
        }
        throw new \invalid_parameter_exception('A WhatsApp recipient needs a phone (wa_id) or a BSUID');
    }

    /**
     * Recipient addressed by phone.
     *
     * @param string $waid Phone (wa_id).
     * @return self
     * @throws \invalid_parameter_exception When it is empty.
     */
    public static function phone(string $waid): self {
        if (trim($waid) === '') {
            throw new \invalid_parameter_exception('The phone of a WhatsApp recipient is empty');
        }
        return self::from_link($waid, null);
    }

    /**
     * Recipient addressed by BSUID.
     *
     * @param string $bsuid BSUID.
     * @return self
     * @throws \invalid_parameter_exception When it is empty.
     */
    public static function bsuid(string $bsuid): self {
        if (trim($bsuid) === '') {
            throw new \invalid_parameter_exception('The BSUID of a WhatsApp recipient is empty');
        }
        return self::from_link(null, $bsuid);
    }

    /**
     * Field of the request body: "to" or "recipient".
     *
     * @return string
     */
    public function field(): string {
        return $this->field;
    }

    /**
     * Phone or BSUID.
     *
     * @return string
     */
    public function value(): string {
        return $this->value;
    }

    /**
     * Fields of the request body that address the recipient.
     *
     * @return array<string, string>
     */
    public function to_payload(): array {
        return [$this->field => $this->value];
    }
}
