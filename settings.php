<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_jedomi', get_string('pluginname', 'local_jedomi'));
    $ADMIN->add('localplugins', $settings);

    // Redirigir instantáneamente a la UI con pestañas.
    // El script se ejecuta cuando Moodle renderiza la sección de settings.
    $adminurl = (new moodle_url('/local/jedomi/admin_settings.php'))->out(false);
    $settings->add(new admin_setting_heading(
        'local_jedomi/redirect_heading',
        '',
        '<script>window.location.replace("' . $adminurl . '?tab=general");</script>'
        . '<noscript><a href="' . $adminurl . '?tab=general">'
        . get_string('open_admin_settings', 'local_jedomi') . '</a></noscript>'
    ));
}
