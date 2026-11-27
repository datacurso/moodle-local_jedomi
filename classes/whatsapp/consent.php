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
 * Consent a user gives on the WhatsApp profile page before a link code is generated.
 *
 * It records the version of the consent text and the categories of messages accepted: chat (the conversation) and
 * grades (the grade notifications).
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso <developer@datacurso.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class consent {
    /** @var string Version of the current consent text (whatsapp_consent_text). */
    public const VERSION = '1';

    /** @var string Category of the conversation. */
    public const CHAT = 'chat';

    /** @var string Category of the grade notifications. */
    public const GRADES = 'grades';

    /** @var string[] Known categories, in the order they are stored. */
    public const CATEGORIES = [self::CHAT, self::GRADES];

    /** @var int Maximum length of the version (consentversion field). */
    private const VERSION_MAXLENGTH = 20;

    /** @var string[] Categories accepted, without repeats, in the order of CATEGORIES. */
    public readonly array $categories;

    /**
     * Constructor.
     *
     * @param string $version Version of the consent text.
     * @param string[] $categories Categories accepted; at least one, all of them known.
     * @param int $acceptedat When the user accepted it.
     * @throws \invalid_parameter_exception When the version or the categories are not valid.
     */
    public function __construct(
        /** @var string Version of the consent text. */
        public readonly string $version,
        array $categories,
        /** @var int When the user accepted it. */
        public readonly int $acceptedat,
    ) {
        if ($version === '' || \core_text::strlen($version) > self::VERSION_MAXLENGTH) {
            throw new \invalid_parameter_exception('Invalid consent version');
        }
        $categories = array_values(array_unique(array_map('strval', $categories)));
        if ($categories === [] || array_diff($categories, self::CATEGORIES) !== []) {
            throw new \invalid_parameter_exception('Invalid consent categories');
        }
        $this->categories = array_values(array_intersect(self::CATEGORIES, $categories));
    }

    /**
     * Categories as stored in the consentcategories field: chat,grades.
     *
     * @return string
     */
    public function categories_field(): string {
        return implode(',', $this->categories);
    }

    /**
     * Encode the consent for the cache of the link codes.
     *
     * @return string
     */
    public function to_json(): string {
        return json_encode(['version' => $this->version, 'categories' => $this->categories, 'at' => $this->acceptedat]);
    }

    /**
     * Decode a consent encoded by to_json(); null when it is not valid.
     *
     * @param string $json Encoded consent.
     * @return self|null
     */
    public static function from_json(string $json): ?self {
        $data = json_decode($json, true);
        if (
            !is_array($data) || !is_string($data['version'] ?? null) || !is_array($data['categories'] ?? null)
                || !is_int($data['at'] ?? null)
        ) {
            return null;
        }
        try {
            return new self($data['version'], $data['categories'], $data['at']);
        } catch (\invalid_parameter_exception $e) {
            return null;
        }
    }
}
