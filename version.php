<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_jedomi';
$plugin->version   = 2026052405; // + MEDIUMTEXT usercontext + try-catch state save + truncation fix
$plugin->requires = 2024100700; // Moodle 4.5.
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.2.0';
