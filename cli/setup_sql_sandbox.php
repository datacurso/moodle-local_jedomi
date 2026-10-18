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
 * Crea/actualiza el rol de base de datos de solo lectura bajo el que se ejecuta jedomi_execute_sql (PostgreSQL).
 *
 * La restricción la impone el motor (privilegios por tabla y por columna), no un filtro de texto sobre el SQL:
 * un SELECT u FROM mdl_user u, row_to_json(u) o u::text fallan con "permission denied" porque la referencia a la
 * fila completa exige SELECT sobre TODAS las columnas, y password/secret no están concedidas.
 *
 * Uso (dentro del contenedor/servidor):
 *   php local/jedomi/cli/setup_sql_sandbox.php            # crea o actualiza el rol y los privilegios
 *   php local/jedomi/cli/setup_sql_sandbox.php --check    # sólo comprueba y prueba los bypass conocidos
 *   php local/jedomi/cli/setup_sql_sandbox.php --drop     # elimina el rol
 *
 * Requiere que el usuario de BD de Moodle pueda crear roles (CREATEROLE o superusuario) y sea propietario de
 * las tablas (lo habitual). Sólo PostgreSQL por ahora.
 *
 * @package    local_jedomi
 * @copyright  2026 Datacurso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_jedomi\external\execute_sql;

[$options, $unrecognised] = cli_get_params(['check' => false, 'drop' => false, 'help' => false], ['h' => 'help']);
if ($options['help']) {
    cli_writeln("Crea el rol PostgreSQL de solo lectura para jedomi_execute_sql.\nOpciones: --check  --drop  --help");
    exit(0);
}

$role = execute_sql::SANDBOX_ROLE;
if ($DB->get_dbfamily() !== 'postgres') {
    cli_error("Este sandbox sólo está implementado para PostgreSQL (motor actual: " . $DB->get_dbfamily() . ").", 2);
}
$schema = $DB->get_field_sql('SELECT current_schema()');
$dbuser = $CFG->dbuser;
$prefix = $CFG->prefix;
$q = function (string $ident): string {
    return '"' . str_replace('"', '""', $ident) . '"';
};

$exists = (bool) $DB->get_field_sql('SELECT 1 FROM pg_roles WHERE rolname = :r', ['r' => $role]);

if ($options['drop']) {
    if ($exists) {
        $DB->execute('REASSIGN OWNED BY ' . $q($role) . ' TO ' . $q($dbuser));
        $DB->execute('DROP OWNED BY ' . $q($role));
        $DB->execute('DROP ROLE ' . $q($role));
        cli_writeln("Rol $role eliminado.");
    } else {
        cli_writeln("Rol $role no existe.");
    }
    exit(0);
}

if (!$options['check']) {
    if (!$exists) {
        $DB->execute('CREATE ROLE ' . $q($role) . ' NOLOGIN');
        cli_writeln("Rol $role creado.");
    }
    $DB->execute('GRANT ' . $q($role) . ' TO ' . $q($dbuser));
    $DB->execute('GRANT USAGE ON SCHEMA ' . $q($schema) . ' TO ' . $q($role));
    $DB->execute('GRANT SELECT ON ALL TABLES IN SCHEMA ' . $q($schema) . ' TO ' . $q($role));
    $DB->execute('ALTER DEFAULT PRIVILEGES FOR ROLE ' . $q($dbuser) . ' IN SCHEMA ' . $q($schema)
        . ' GRANT SELECT ON TABLES TO ' . $q($role));

    // Tablas completamente vetadas (credenciales, tokens, secretos, sesiones).
    $existing = $DB->get_fieldset_sql('SELECT tablename FROM pg_tables WHERE schemaname = :s', ['s' => $schema]);
    $revoked = [];
    foreach (execute_sql::SANDBOX_DENIED_TABLES as $t) {
        $full = $prefix . $t;
        if (in_array($full, $existing, true)) {
            $DB->execute('REVOKE ALL ON ' . $q($schema) . '.' . $q($full) . ' FROM ' . $q($role));
            $revoked[] = $full;
        }
    }
    // Tablas con columnas vetadas: se concede SELECT sólo sobre el resto de columnas.
    $columnlimited = [];
    foreach (execute_sql::SANDBOX_DENIED_COLUMNS as $t => $denied) {
        $full = $prefix . $t;
        if (!in_array($full, $existing, true)) {
            continue;
        }
        $cols = $DB->get_fieldset_sql(
            'SELECT column_name FROM information_schema.columns WHERE table_schema = :s AND table_name = :t ORDER BY ordinal_position',
            ['s' => $schema, 't' => $full]);
        $allowed = array_values(array_diff($cols, $denied));
        $DB->execute('REVOKE ALL ON ' . $q($schema) . '.' . $q($full) . ' FROM ' . $q($role));
        $DB->execute('GRANT SELECT (' . implode(', ', array_map($q, $allowed)) . ') ON ' . $q($schema) . '.' . $q($full) . ' TO ' . $q($role));
        $columnlimited[$full] = $denied;
    }
    cli_writeln("Privilegios aplicados. Tablas vetadas: " . count($revoked) . ". Tablas con columnas vetadas: " . json_encode($columnlimited));
}

// --- Comprobación: los bypass conocidos deben fallar y una consulta legítima funcionar ---
$tests = [
    'SELECT id, username, email FROM ' . $prefix . 'user WHERE id = 2'                 => true,
    'SELECT COUNT(*) AS n FROM ' . $prefix . 'course'                                   => true,
    'SELECT password FROM ' . $prefix . 'user LIMIT 1'                                  => false,
    'SELECT u FROM ' . $prefix . 'user u LIMIT 1'                                       => false,
    'SELECT row_to_json(u) FROM ' . $prefix . 'user u LIMIT 1'                          => false,
    'SELECT u::text FROM ' . $prefix . 'user u LIMIT 1'                                 => false,
    'SELECT (SELECT u FROM ' . $prefix . 'user u LIMIT 1) AS whole'                     => false,
    'SELECT t FROM ' . $prefix . 'external_tokens t LIMIT 1'                            => false,
    'SELECT * FROM ' . $prefix . 'sessions LIMIT 1'                                     => false,
    'SELECT value FROM ' . $prefix . 'config_plugins WHERE name = \'hmac_secret\''      => false,
    'SELECT * FROM ' . $prefix . 'course LIMIT 1'                                       => true,
];
$ok = true;
$DB->execute('SET ROLE ' . $q($role));
foreach ($tests as $sql => $shouldwork) {
    try {
        $DB->get_records_sql('SELECT ROW_NUMBER() OVER () AS rn, q.* FROM (' . $sql . ') q LIMIT 2');
        $worked = true;
        $why = 'ok';
    } catch (\Throwable $e) {
        $worked = false;
        $why = stripos($e->getMessage() . $e->debuginfo, 'permission denied') !== false ? 'permission denied' : substr($e->getMessage(), 0, 60);
    }
    $pass = ($worked === $shouldwork);
    $ok = $ok && $pass;
    cli_writeln(($pass ? 'PASS ' : 'FAIL ') . str_pad(substr($sql, 0, 70), 72) . ($worked ? 'EJECUTA' : 'BLOQUEA') . " ($why)");
}
$DB->execute('RESET ROLE');
cli_writeln($ok ? "SANDBOX OK: el rol $role bloquea los bypass conocidos y permite consultas legítimas." : "SANDBOX INCOMPLETO: revisa los FAIL.");
exit($ok ? 0 : 1);
