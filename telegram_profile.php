<?php
/**
 * User profile page for Telegram account linking.
 *
 * Shows current link status, generates one-time codes, and provides instructions.
 *
 * @package    local_jedomi
 * @copyright  2026 Jedomi Project
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
// Explicitly include plugin lib — guarantee functions are available regardless
// of whether Moodle's local-plugin auto-loader ran (OPcache or bootstrap timing).
require_once(__DIR__ . '/lib.php');

require_login();

$userid = optional_param('userid', $USER->id, PARAM_INT);
if ($userid != $USER->id && !has_capability('moodle/user:update', context_system::instance())) {
    throw new \moodle_exception('nopermissions', 'error', '', 'view other user Telegram settings');
}

$user = \core_user::get_user($userid);
if (!$user || $user->deleted) {
    throw new \moodle_exception('invaliduser', 'error');
}

$context = context_user::instance($userid);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/jedomi/telegram_profile.php', ['userid' => $userid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('telegram_link_title', 'local_jedomi'));
$PAGE->set_heading(fullname($user));

// Check if Telegram is enabled and bot is configured
$enabled      = \local_jedomi\telegram\manager::is_enabled();
$bot_verified = (bool) get_config('local_jedomi', 'telegram_bot_verified');
$bot_username = get_config('local_jedomi', 'telegram_bot_username') ?: '';
$bot_firstname = get_config('local_jedomi', 'telegram_bot_firstname') ?: 'Jedomi';
$bot_available = $enabled && $bot_verified && $bot_username !== '';

$role_ok = local_jedomi_is_telegram_role_allowed();

// Handle actions — only when bot is fully available and role is OK
$action         = optional_param('action', '', PARAM_ALPHA);
$generated_code = null;
$code_expiry    = 0;

if ($bot_available && $role_ok && $action === 'generate' && confirm_sesskey()) {
    $generated_code = \local_jedomi\telegram\manager::generate_link_code($userid);
    $code_expiry    = time() + 600;
}

if ($bot_available && $role_ok && $action === 'unlink' && confirm_sesskey()) {
    global $DB;
    $DB->delete_records('local_jedomi_telegram_links', ['userid' => $userid]);
    redirect($PAGE->url, get_string('telegram_msg_unlinked', 'local_jedomi'), null,
             \core\output\notification::NOTIFY_SUCCESS);
}

// Load current link status
global $DB;
$link = $DB->get_record('local_jedomi_telegram_links', ['userid' => $userid, 'confirmed' => 1]);

echo $OUTPUT->header();
?>

<style>
.jd-tg-step { display:flex; align-items:flex-start; gap:.875rem; margin-bottom:1.25rem; }
.jd-tg-badge { min-width:2.25rem; height:2.25rem; border-radius:.5rem;
               display:flex; align-items:center; justify-content:center;
               font-weight:700; font-size:.875rem; color:#fff; flex-shrink:0; }
.jd-tg-code-box { font-size:2.5rem; font-family:monospace; letter-spacing:.4em;
                  font-weight:900; color:#1e40af; text-align:center;
                  background:#eff6ff; border:2px dashed #93c5fd;
                  border-radius:.75rem; padding:1rem 1.5rem; margin-bottom:.75rem; }
.jd-tg-cmd { background:#1e293b; color:#7dd3fc; border-radius:.375rem;
             padding:.375rem .75rem; font-family:monospace; font-size:.875rem;
             display:inline-flex; align-items:center; gap:.5rem; }
</style>

<div style="max-width:640px; margin:1.5rem auto; padding:0 1rem">

    <!-- Header -->
    <div class="d-flex align-items-center gap-3 mb-4">
        <span style="font-size:2rem">💬</span>
        <div>
            <h2 style="margin:0;font-size:1.375rem"><?php echo get_string('telegram_link_title', 'local_jedomi'); ?></h2>
            <p style="margin:0;color:#6b7280;font-size:.875rem">
                <?php echo get_string('telegram_heading_help', 'local_jedomi'); ?>
            </p>
        </div>
    </div>

    <?php if (!$bot_available): ?>
    <!-- ── Bot not configured — show nothing to regular users ─────────────── -->
    <?php if (is_siteadmin()): ?>
    <div class="alert alert-info d-flex align-items-center gap-2" style="border-radius:.5rem">
        <i class="fa fa-info-circle fa-lg" style="flex-shrink:0"></i>
        <div>
            <strong>Telegram not configured (admin only message)</strong><br>
            <small>Go to <a href="<?php echo (new moodle_url('/local/jedomi/admin_settings.php', ['tab'=>'integrations']))->out(false); ?>">Jedomi Settings → Integrations</a>
            to set up the Telegram bot. Once configured, users will see the Telegram linking option here.</small>
        </div>
    </div>
    <?php else: ?>
        <?php redirect(new moodle_url('/user/profile.php', ['id' => $userid])); ?>
    <?php endif; ?>

    <?php elseif (!$role_ok): ?>
    <!-- ── Role blocked ───────────────────────────────────────────────────── -->
    <div class="alert alert-danger d-flex align-items-center gap-2" style="border-radius:.5rem">
        <i class="fa fa-ban fa-lg" style="flex-shrink:0"></i>
        <div><?php echo get_string('telegram_msg_role_blocked', 'local_jedomi'); ?></div>
    </div>

    <?php elseif ($link): ?>
    <!-- ── LINKED ─────────────────────────────────────────────────────────── -->
    <div class="card mb-4" style="border-color:#22c55e">
        <div class="card-header d-flex align-items-center gap-2"
             style="background:#f0fdf4;border-bottom:1px solid #22c55e">
            <i class="fa fa-check-circle fa-lg" style="color:#16a34a"></i>
            <strong style="color:#166534"><?php echo get_string('telegram_status_connected', 'local_jedomi'); ?></strong>
        </div>
        <div class="card-body">
            <div class="d-flex align-items-center gap-3 mb-3">
                <span style="font-size:2.5rem">✅</span>
                <div>
                    <strong style="font-size:1rem">
                        <?php echo s($link->username ? '@' . $link->username : 'chat_id: ' . $link->chatid); ?>
                    </strong>
                    <p style="margin:0;color:#6b7280;font-size:.8125rem">
                        Linked on <?php echo userdate($link->timecreated, get_string('strftimedatetime', 'langconfig')); ?>
                    </p>
                </div>
            </div>

            <!-- How to use box -->
            <div style="background:#f8fafc;border-radius:.5rem;padding:1rem;margin-bottom:1rem">
                <p style="font-weight:600;font-size:.875rem;margin-bottom:.625rem;color:#374151">
                    <i class="fa fa-info-circle mr-1"></i>
                    How to use Jedomi from Telegram
                </p>
                <ul style="margin:0;padding-left:1.25rem;font-size:.8375rem;color:#475569;line-height:1.75">
                    <li>Send any question → Jedomi answers with live Moodle data</li>
                    <li>Send a screenshot of an error → Jedomi analyzes it (AI vision)</li>
                    <li>Dangerous actions show confirmation buttons before executing</li>
                    <li>Send <code>/desvincular</code> to unlink this Telegram account</li>
                </ul>
            </div>

            <form method="post" action="<?php echo $PAGE->url->out(false); ?>">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="unlink">
                <button type="submit" class="btn btn-outline-danger btn-sm"
                        onclick="return confirm('<?php echo get_string('confirm', 'core'); ?>')">
                    <i class="fa fa-unlink mr-1"></i> <?php echo get_string('unlink', 'local_jedomi'); ?>
                </button>
            </form>
        </div>
    </div>

    <?php else: ?>
    <!-- ── NOT LINKED ────────────────────────────────────────────────────── -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2"
             style="background:#f8fafc">
            <i class="fa fa-telegram" style="color:#0284c7"></i>
            <strong><?php echo get_string('telegram_not_linked', 'local_jedomi'); ?></strong>
        </div>
        <div class="card-body">

            <?php if ($generated_code): ?>
            <!-- Code displayed -->
            <p class="text-muted mb-3" style="font-size:.875rem">
                <?php echo get_string('telegram_link_instructions', 'local_jedomi'); ?>
            </p>

            <div class="jd-tg-code-box">
                <?php echo s($generated_code); ?>
            </div>
            <p class="text-center text-muted" style="font-size:.8125rem;margin-bottom:1rem">
                <?php echo get_string('telegram_link_code_expiry', 'local_jedomi'); ?>
                &nbsp;⏳ <span id="jd-countdown" style="font-weight:600;color:#1e40af">10:00</span>
            </p>

            <!-- Option A: Deep Link — Primary Action -->
            <div style="margin-bottom:1.25rem">
                <a href="https://t.me/<?php echo s($bot_username); ?>?start=<?php echo s($generated_code); ?>"
                   target="_blank" rel="noopener"
                   class="btn btn-success btn-lg d-block w-100"
                   style="padding:.875rem 1rem;font-size:1.05rem;border-radius:.625rem;font-weight:600">
                    <i class="fa fa-telegram mr-2"></i>
                    Abrir en Telegram (Automático)
                </a>
                <p class="text-center text-muted mt-2 mb-0" style="font-size:.8125rem">
                    Te lleva directo a @<?php echo s($bot_username); ?> con tu código listo para vincular
                </p>
            </div>

            <!-- Steps to link -->
            <div style="border:1px solid #e5e7eb;border-radius:.5rem;padding:1rem;background:#f9fafb;margin-bottom:1rem">

                <div class="jd-tg-step">
                    <div class="jd-tg-badge" style="background:#0284c7">1</div>
                    <div>
                        <strong style="font-size:.875rem">Open the bot in Telegram</strong>
                        <p style="font-size:.8125rem;color:#475569;margin:.2rem 0 .375rem">
                            Tap the button below to open the bot directly, or search for
                            <strong>@<?php echo s($bot_username); ?></strong> in Telegram.
                        </p>
                        <a href="https://t.me/<?php echo s($bot_username); ?>?start=<?php echo s($generated_code); ?>"
                           target="_blank" rel="noopener"
                           class="btn btn-sm"
                           style="background:#0088cc;color:#fff;border:none;border-radius:.375rem;font-size:.8125rem">
                            <i class="fa fa-telegram mr-1"></i>
                            Open @<?php echo s($bot_username); ?> in Telegram
                        </a>
                    </div>
                </div>

                <div class="jd-tg-step">
                    <div class="jd-tg-badge" style="background:#7c3aed">2</div>
                    <div>
                        <strong style="font-size:.875rem"><?php echo get_string('telegram_stepU3_title', 'local_jedomi'); ?></strong>
                        <p style="font-size:.8125rem;color:#475569;margin:.2rem 0 .5rem">
                            Send this command to <strong>@<?php echo s($bot_username); ?></strong>:
                        </p>
                        <div class="jd-tg-cmd">
                            /start <?php echo s($generated_code); ?>
                            <button type="button"
                                    onclick="navigator.clipboard.writeText('/start <?php echo s($generated_code); ?>');this.innerHTML='<i class=\'fa fa-check\'></i>';setTimeout(()=>this.innerHTML='<i class=\'fa fa-copy\'></i>',1500)"
                                    style="background:none;border:none;color:#7dd3fc;cursor:pointer;padding:0">
                                <i class="fa fa-copy"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="jd-tg-step" style="margin-bottom:0">
                    <div class="jd-tg-badge" style="background:#16a34a">3</div>
                    <div>
                        <strong style="font-size:.875rem">Done!</strong>
                        <p style="font-size:.8125rem;color:#475569;margin:.2rem 0 0">
                            The bot will confirm the link instantly. After that, send any message to start using Jedomi from Telegram.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Option B: Manual — Fallback for PC users without Telegram app -->
            <div style="border:1px solid #e5e7eb;border-radius:.5rem;padding:1rem;background:#f9fafb;margin-bottom:1rem">
                <p style="font-size:.8125rem;font-weight:600;color:#6b7280;margin-bottom:.75rem;display:flex;align-items:center;gap:.375rem">
                    <i class="fa fa-info-circle" style="color:#9ca3af"></i>
                    ¿No tienes la app de Telegram?
                </p>
                <p style="font-size:.8375rem;color:#374151;margin-bottom:.625rem">
                    Si estás en PC sin la app, abre Telegram en tu móvil, busca
                    <strong>@<?php echo s($bot_username); ?></strong> y envía este mensaje:
                </p>
                <div style="background:#1e293b;color:#7dd3fc;border-radius:.375rem;padding:.5rem .75rem;
                            font-family:monospace;font-size:.9375rem;display:flex;align-items:center;justify-content:space-between">
                    <span>/start <?php echo s($generated_code); ?></span>
                    <button type="button"
                            onclick="navigator.clipboard.writeText('/start <?php echo s($generated_code); ?>');this.innerHTML='<i class=\'fa fa-check\' style=\'color:#16a34a\'></i>';setTimeout(()=>this.innerHTML='<i class=\'fa fa-copy\'></i>',1500)"
                            style="background:none;border:none;color:#7dd3fc;cursor:pointer;padding:.1rem .25rem">
                        <i class="fa fa-copy"></i>
                    </button>
                </div>
            </div>

            <!-- Regenerate form -->
            <form method="post" action="<?php echo $PAGE->url->out(false); ?>">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="generate">
                <button type="submit" class="btn btn-outline-secondary btn-sm">
                    <i class="fa fa-refresh mr-1"></i> Generate a new code
                </button>
            </form>

            <script>
            (function(){
                var expiry = <?php echo $code_expiry * 1000; ?>;
                var el = document.getElementById('jd-countdown');
                function tick(){
                    var r = Math.max(0, expiry - Date.now());
                    var m = Math.floor(r / 60000);
                    var s = Math.floor((r % 60000) / 1000);
                    el.textContent = m + ':' + (s < 10 ? '0' : '') + s;
                    if (r === 0) {
                        el.textContent = 'EXPIRED';
                        el.style.color = '#ef4444';
                    } else {
                        setTimeout(tick, 1000);
                    }
                }
                tick();
            })();
            </script>

            <?php else: ?>
            <!-- Generate button -->
            <p style="font-size:.875rem;color:#374151;margin-bottom:1.25rem">
                <?php echo get_string('telegram_link_instructions', 'local_jedomi'); ?>
            </p>

            <!-- Quick steps overview -->
            <div style="margin-bottom:1.5rem">
                <div class="jd-tg-step">
                    <div class="jd-tg-badge" style="background:#0284c7">1</div>
                    <div style="font-size:.8375rem;color:#475569">
                        <strong style="color:#111827">Click "Generate code" below</strong>
                        — a 6-character code appears, valid for 10 minutes.
                    </div>
                </div>
                <div class="jd-tg-step">
                    <div class="jd-tg-badge" style="background:#7c3aed">2</div>
                    <div style="font-size:.8375rem;color:#475569">
                        <strong style="color:#111827">
                            Open <a href="https://t.me/<?php echo s($bot_username); ?>" target="_blank" rel="noopener"
                                    style="color:#0284c7">@<?php echo s($bot_username); ?></a> in Telegram
                        </strong>
                        — send <code>/start YOUR_CODE</code>.
                    </div>
                </div>
                <div class="jd-tg-step" style="margin-bottom:0">
                    <div class="jd-tg-badge" style="background:#16a34a">3</div>
                    <div style="font-size:.8375rem;color:#475569">
                        <strong style="color:#111827">That's it!</strong>
                        — <?php echo s($bot_firstname); ?> confirms instantly. Ask anything about your Moodle.
                    </div>
                </div>
            </div>

            <form method="post" action="<?php echo $PAGE->url->out(false); ?>">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="generate">
                <button type="submit" class="btn btn-primary btn-lg d-block w-100">
                    <i class="fa fa-telegram mr-2"></i>
                    <?php echo get_string('telegram_link_generate', 'local_jedomi'); ?>
                </button>
            </form>
            <?php endif; ?>

        </div>
    </div>
    <?php endif; ?>

</div>

<?php echo $OUTPUT->footer();
