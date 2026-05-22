<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_jedomi';
$plugin->version   = 2026052200; // + set_time_limit(0) en proxy para tool loops largos
$plugin->requires  = 2024041000; // Moodle 5.2+
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.0.0';
