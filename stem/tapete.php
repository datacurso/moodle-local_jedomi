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
 * Descarga del tapete ArUco (PDF/PNG) empaquetado con el plugin. Requiere sesión iniciada (profesores y alumnos).
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/filelib.php'); // send_file() no se carga automáticamente en Moodle 5.x
require_login();

$allowed = ['tapete_A4_1hoja.pdf', 'tapete_2xA4.pdf', 'tapete_Carta_1hoja.pdf', 'tapete_2xCarta.pdf', 'tapete_lab_800x400.png'];
$file = required_param('file', PARAM_FILE);
if (!in_array($file, $allowed, true)) {
    throw new moodle_exception('invalidarguments');
}
$path = __DIR__ . '/tapete/' . $file;
if (!is_readable($path)) {
    throw new moodle_exception('filenotfound', 'error');
}
send_file($path, $file, 0, 0, false, true);
