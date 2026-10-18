<?php
/**
 * Capability definitions for local_jedomi.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/jedomi:viewtrainingdata' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/jedomi:managetrainingdata' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/jedomi:manageagents' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
    // Allows a Moodle service account to call the CompanionWS functions.
    // Only the jedomi_companion service token should have this capability.
    // ALL other role archetypes are explicitly prohibited.
    'local/jedomi:companion_api' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager'        => CAP_ALLOW,
            'coursecreator'  => CAP_PROHIBIT,
            'editingteacher' => CAP_PROHIBIT,
            'teacher'        => CAP_PROHIBIT,
            'student'        => CAP_PROHIBIT,
            'guest'          => CAP_PROHIBIT,
            'user'           => CAP_PROHIBIT,
            'frontpage'      => CAP_PROHIBIT,
        ],
    ],
    // Configurar el tutor STEM (modo, instrucciones, tareas evaluadas) dentro de un curso.
    'local/jedomi:configuretutor' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => [
            'editingteacher' => CAP_ALLOW,
            'manager'        => CAP_ALLOW,
        ],
    ],
    // La estación física (token de servicio) ingiere eventos de observación y propone rúbricas.
    // Ningún arquetipo de usuario normal la tiene: se asigna explícitamente al rol/token de la estación.
    'local/jedomi:ingestassessment' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager'        => CAP_ALLOW,
            'editingteacher' => CAP_PROHIBIT,
            'teacher'        => CAP_PROHIBIT,
            'student'        => CAP_PROHIBIT,
            'guest'          => CAP_PROHIBIT,
            'user'           => CAP_PROHIBIT,
        ],
    ],
    // Permite activar auto_release (publicar la nota propuesta por la estación sin revisión) en un curso.
    'local/jedomi:autorelease' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'riskbitmask'  => RISK_DATALOSS,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
