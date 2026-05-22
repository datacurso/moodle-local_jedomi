<?php
/**
 * Jedomi Cache Definitions
 *
 * Usa MODE_SESSION para que el cache viva EXACTAMENTE mientras dure la sesión Moodle.
 * No hay persistencia — cuando el usuario hace logout, los datos desaparecen.
 * El admin nunca puede acceder al cache de otro usuario (es por sesión).
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'conversation' => [
        'mode'             => cache_store::MODE_SESSION,
        'simplekeys'       => true,
        'simpledata'       => false,   // datos complejos (array de mensajes)
        'staticacceleration' => true,  // mantener en RAM durante el request
        'staticaccelerationsize' => 1, // solo 1 conversación activa
        'ttl'              => 0,       // 0 = expira con la sesión (no TTL fijo)
    ],
    // Application-wide cache for Telegram link codes.
    // MUST be MODE_APPLICATION — the Telegram webhook runs in a different HTTP
    // request with no user session, so MODE_SESSION would never find the codes.
    'telegram_link_codes' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,   // values are simple strings (code or userid)
        'ttl'        => 600,    // 10 minutes — same as CODE_TTL in manager.php
    ],

    // Application-wide cache for database schema introspection (table_descriptor).
    // MODE_APPLICATION — DB schema is site-wide, not per-user.
    // TTL 86400 (24h) — schema changes only during upgrades or plugin installs.
    'schema_cache' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl'        => 86400,    // 24 hours
    ],
];
