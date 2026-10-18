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

namespace local_jedomi;

/**
 * Cabeceras HTTP comunes para todas las llamadas servidor → gateway de IA.
 *
 * Centraliza Content-Type, Authorization y, si está configurado, "anthropic-workspace-id" (exigido por el endpoint
 * compatible OpenAI de Anthropic cuando la API key no está asociada a un workspace). Rama local/stem-fisica.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gateway_headers {

    /**
     * @param string $apikey Clave Bearer del gateway.
     * @param bool $json Añadir Content-Type: application/json.
     * @return string[] Cabeceras listas para CURLOPT_HTTPHEADER.
     */
    public static function build(string $apikey, bool $json = true): array {
        $headers = [];
        if ($json) {
            $headers[] = 'Content-Type: application/json';
        }
        $headers[] = 'Authorization: Bearer ' . $apikey;
        $workspace = trim((string) get_config('local_jedomi', 'workspace_id'));
        if ($workspace !== '' && preg_match('/^[A-Za-z0-9_-]{1,128}$/', $workspace)) {
            $headers[] = 'anthropic-workspace-id: ' . $workspace;
        }
        return $headers;
    }
}
