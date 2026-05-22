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

/**
 * Bundled inside local_jedomi. Auto-installed by local_jedomi/db/install.php.
 * Configuration is read from local_jedomi — no separate setup needed.
 *
 * @package   aiprovider_jedomi
 * @copyright 2026 Jedomi Project
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'aiprovider_jedomi';
$plugin->version   = 2026051400;
$plugin->requires  = 2026041000; // Moodle 5.2+
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.1.0';
