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

namespace local_jedomi\local\service;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/jedomi/lib.php');

/**
 * Telegram PIN of administrators and managers (Decision 7, SEC-JD-049).
 *
 * With the site setting "telegram_require_pin" on, the Telegram writes of an administrator or manager (the roles Jedomi
 * computes outside a course) are confirmed with a PIN of 6 to 8 digits instead of the confirm button or word. The PIN
 * is kept only as a password_hash() in the Telegram link of the user, so it is removed with the link. Three wrong PINs
 * in a row lock the Telegram writes of the user for 15 minutes; a right PIN lets the writes of the next 15 minutes run
 * without asking it again. The PIN is never logged.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class telegram_pin_service {
    /** @var int Fewest digits of a PIN. */
    public const MIN_LENGTH = 6;
    /** @var int Most digits of a PIN. */
    public const MAX_LENGTH = 8;
    /** @var int Wrong PINs in a row that lock the writes. */
    public const MAX_ATTEMPTS = 3;
    /** @var int Seconds the writes stay locked after too many wrong PINs. */
    public const LOCK_TIME = 15 * MINSECS;
    /** @var int Seconds after a right PIN during which it is not asked again (the writes are still confirmed). */
    public const WINDOW = 15 * MINSECS;

    /** @var string The PIN is right. */
    public const RESULT_OK = 'ok';
    /** @var string The PIN is wrong; attempts remain. */
    public const RESULT_WRONG = 'wrong';
    /** @var string The writes are locked, now or by this wrong PIN. */
    public const RESULT_LOCKED = 'locked';
    /** @var string The user has no PIN (or no link). */
    public const RESULT_NO_PIN = 'nopin';

    /** @var string[] Roles that need a PIN when the setting is on. */
    private const ROLES = ['admin', 'manager'];

    /**
     * Whether the site setting asks administrators and managers for a PIN.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool) get_config('local_jedomi', 'telegram_require_pin');
    }

    /**
     * Whether the Telegram writes of a user need the PIN: the setting is on and the user is an administrator or manager.
     *
     * @param int $userid Moodle user id.
     * @return bool
     */
    public static function is_required(int $userid): bool {
        return $userid > 0 && self::is_enabled() && in_array(\local_jedomi_get_user_role_for_user($userid), self::ROLES, true);
    }

    /**
     * Whether a text has the format of a PIN: only 6 to 8 ASCII digits.
     *
     * @param string $pin Text to check.
     * @return bool
     */
    public static function is_valid_format(string $pin): bool {
        return (bool) preg_match('/^[0-9]{' . self::MIN_LENGTH . ',' . self::MAX_LENGTH . '}$/D', $pin);
    }

    /**
     * Set the PIN of a linked user; the failures, the lock and the window start again.
     *
     * @param int $userid Moodle user id.
     * @param string $pin New PIN.
     * @return bool False when the format is wrong or the user has no Telegram link.
     */
    public static function set_pin(int $userid, string $pin): bool {
        global $DB;
        $link = self::link($userid);
        if (!$link || !self::is_valid_format($pin)) {
            return false;
        }
        $DB->update_record('local_jedomi_telegram_links', (object) [
            'id' => $link->id,
            'pinhash' => password_hash($pin, PASSWORD_DEFAULT),
            'pinfailures' => 0,
            'pinlockeduntil' => 0,
            'pinverifiedat' => 0,
        ]);
        return true;
    }

    /**
     * Whether the user has set a PIN.
     *
     * @param int $userid Moodle user id.
     * @return bool
     */
    public static function has_pin(int $userid): bool {
        $link = self::link($userid);
        return $link !== null && (string) $link->pinhash !== '';
    }

    /**
     * Time until which the Telegram writes of the user are locked.
     *
     * @param int $userid Moodle user id.
     * @return int 0 when they are not locked.
     */
    public static function locked_until(int $userid): int {
        $link = self::link($userid);
        $until = $link ? (int) $link->pinlockeduntil : 0;
        return $until > time() ? $until : 0;
    }

    /**
     * Whether a right PIN was given in the last 15 minutes, so it is not asked again (writes are still confirmed).
     *
     * @param int $userid Moodle user id.
     * @return bool
     */
    public static function in_window(int $userid): bool {
        $link = self::link($userid);
        return $link !== null && (int) $link->pinverifiedat > 0 && (int) $link->pinverifiedat >= time() - self::WINDOW
            && self::locked_until($userid) === 0;
    }

    /**
     * Check a PIN typed in Telegram. A right PIN opens the window and clears the failures; the third wrong PIN in a row
     * locks the writes for LOCK_TIME.
     *
     * @param int $userid Moodle user id.
     * @param string $pin Typed PIN.
     * @return array{result: string, remaining: int} One of the RESULT_* constants and the attempts left before the lock.
     */
    public static function verify(int $userid, string $pin): array {
        global $DB;
        $link = self::link($userid);
        if (!$link || (string) $link->pinhash === '') {
            return ['result' => self::RESULT_NO_PIN, 'remaining' => 0];
        }
        $now = time();
        if ((int) $link->pinlockeduntil > $now) {
            return ['result' => self::RESULT_LOCKED, 'remaining' => 0];
        }
        if (password_verify($pin, (string) $link->pinhash)) {
            $DB->update_record('local_jedomi_telegram_links', (object) ['id' => $link->id, 'pinfailures' => 0,
                'pinlockeduntil' => 0, 'pinverifiedat' => $now]);
            return ['result' => self::RESULT_OK, 'remaining' => self::MAX_ATTEMPTS];
        }
        return self::record_failure((int) $link->id);
    }

    /**
     * Count a wrong PIN of a link. The count is increased by the database itself and read back, so wrong PINs checked
     * at the same time by other requests are never lost (REL-005); reaching MAX_ATTEMPTS locks the writes.
     *
     * @param int $linkid Telegram link id.
     * @return array{result: string, remaining: int} RESULT_WRONG with the attempts left, or RESULT_LOCKED.
     */
    public static function record_failure(int $linkid): array {
        global $DB;
        $DB->execute('UPDATE {local_jedomi_telegram_links} SET pinfailures = pinfailures + 1 WHERE id = ?', [$linkid]);
        $failures = (int) $DB->get_field('local_jedomi_telegram_links', 'pinfailures', ['id' => $linkid]);
        if ($failures >= self::MAX_ATTEMPTS) {
            $DB->update_record('local_jedomi_telegram_links', (object) ['id' => $linkid, 'pinfailures' => 0,
                'pinlockeduntil' => time() + self::LOCK_TIME, 'pinverifiedat' => 0]);
            return ['result' => self::RESULT_LOCKED, 'remaining' => 0];
        }
        return ['result' => self::RESULT_WRONG, 'remaining' => self::MAX_ATTEMPTS - $failures];
    }

    /**
     * Confirmed Telegram link of a user.
     *
     * @param int $userid Moodle user id.
     * @return \stdClass|null
     */
    private static function link(int $userid): ?\stdClass {
        return $userid > 0 ? telegram_link_service::get_confirmed_link($userid) : null;
    }
}
