<?php
/**
 * Settings UI under Settings → Lookout.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

$lookout_base = (string) get_option('lookout_base_url', '');
$lookout_key = (string) get_option('lookout_api_key', '');
$lookout_connected = get_option('lookout_enabled') && $lookout_base !== '' && $lookout_key !== '';
?>
<style>
    .lookout-admin { max-width: 860px; margin: 24px 20px 40px 0; color: #1e293b; }
    .lookout-admin * { box-sizing: border-box; }
    .lookout-hero {
        display: flex; align-items: center; justify-content: space-between; gap: 24px;
        padding: 28px 32px; border-radius: 16px; color: #e2e8f0;
        background: radial-gradient(120% 140% at 0% 0%, #16324a 0%, #0b1220 60%);
        box-shadow: 0 10px 30px -12px rgba(8, 15, 30, 0.55);
    }
    .lookout-hero h1 { color: #fff; font-size: 26px; line-height: 1.1; margin: 0 0 6px; font-weight: 700; letter-spacing: -0.01em; }
    .lookout-hero p { margin: 0; color: #94a3b8; font-size: 14px; max-width: 46ch; }
    .lookout-mark { display: inline-flex; align-items: center; gap: 11px; margin-bottom: 14px; }
    .lookout-mark .dot { width: 12px; height: 12px; border-radius: 50%; background: #34d399; box-shadow: 0 0 0 4px rgba(52, 211, 153, 0.18); }
    .lookout-mark span { font-size: 12px; letter-spacing: 0.22em; text-transform: uppercase; color: #5eead4; font-weight: 600; }
    .lookout-status { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 999px; font-size: 13px; font-weight: 600; white-space: nowrap; }
    .lookout-status.on { background: rgba(52, 211, 153, 0.14); color: #6ee7b7; border: 1px solid rgba(52, 211, 153, 0.35); }
    .lookout-status.off { background: rgba(148, 163, 184, 0.12); color: #cbd5e1; border: 1px solid rgba(148, 163, 184, 0.3); }
    .lookout-status .pulse { width: 8px; height: 8px; border-radius: 50%; background: currentColor; }
    .lookout-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 24px 28px; margin-top: 20px; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
    .lookout-card h2 { font-size: 16px; font-weight: 700; margin: 0 0 4px; color: #0f172a; }
    .lookout-card .sub { margin: 0 0 18px; color: #64748b; font-size: 13px; }
    .lookout-field { padding: 16px 0; border-top: 1px solid #f1f5f9; }
    .lookout-field:first-of-type { border-top: 0; padding-top: 4px; }
    .lookout-field label.lbl { display: block; font-weight: 600; font-size: 13px; color: #0f172a; margin-bottom: 6px; }
    .lookout-field input[type=url], .lookout-field input[type=password], .lookout-field input[type=text] {
        width: 100%; max-width: 480px; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 9px; font-size: 14px; background: #f8fafc;
    }
    .lookout-field input:focus { border-color: #34d399; box-shadow: 0 0 0 3px rgba(52, 211, 153, 0.18); outline: none; background: #fff; }
    .lookout-field .hint { margin: 7px 0 0; color: #94a3b8; font-size: 12px; }
    .lookout-toggle { display: flex; align-items: flex-start; gap: 12px; }
    .lookout-toggle input { margin-top: 3px; width: 16px; height: 16px; accent-color: #10b981; }
    .lookout-toggle .body strong { display: block; font-size: 13px; color: #0f172a; }
    .lookout-toggle .body span { display: block; color: #64748b; font-size: 12px; margin-top: 2px; }
    .lookout-actions { margin-top: 22px; display: flex; align-items: center; gap: 12px; }
    .lookout-btn { display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px; border-radius: 9px; font-size: 14px; font-weight: 600; border: 0; cursor: pointer; text-decoration: none; }
    .lookout-btn.primary { background: #0f766e; color: #fff; }
    .lookout-btn.primary:hover { background: #115e59; }
    .lookout-btn.ghost { background: #f1f5f9; color: #334155; }
    .lookout-btn.ghost:hover { background: #e2e8f0; }
    .lookout-notice { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 18px; }
    .lookout-notice.ok { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
    .lookout-notice.err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    .lookout-foot { margin-top: 16px; color: #94a3b8; font-size: 12px; }
</style>

<div class="lookout-admin">
    <?php if (isset($_GET['lookout_test'])) { ?>
        <?php if ((string) $_GET['lookout_test'] === '1') { ?>
            <div class="lookout-notice ok"><?php esc_html_e('Test event sent. Check your Lookout project for a new issue.', 'lookout'); ?></div>
        <?php } else { ?>
            <div class="lookout-notice err"><?php esc_html_e('Could not send test: enable the plugin and fill API key and base URL.', 'lookout'); ?></div>
        <?php } ?>
    <?php } ?>

    <div class="lookout-hero">
        <div>
            <div class="lookout-mark"><span class="dot"></span><span><?php esc_html_e('Lookout', 'lookout'); ?></span></div>
            <h1><?php esc_html_e('Monitoring &amp; performance', 'lookout'); ?></h1>
            <p><?php esc_html_e('Send errors, fatals, 404s, request performance, and browser vitals from this site to your Lookout project.', 'lookout'); ?></p>
        </div>
        <div class="lookout-status <?php echo $lookout_connected ? 'on' : 'off'; ?>">
            <span class="pulse"></span>
            <?php echo $lookout_connected ? esc_html__('Connected', 'lookout') : esc_html__('Not connected', 'lookout'); ?>
        </div>
    </div>

    <form action="options.php" method="post">
        <?php settings_fields('lookout'); ?>

        <div class="lookout-card">
            <h2><?php esc_html_e('Connection', 'lookout'); ?></h2>
            <p class="sub"><?php esc_html_e('Use a dedicated project API key from your Lookout project settings.', 'lookout'); ?></p>

            <div class="lookout-field">
                <div class="lookout-toggle">
                    <input type="hidden" name="lookout_enabled" value="0" />
                    <input type="checkbox" id="lookout_enabled" name="lookout_enabled" value="1" <?php checked(get_option('lookout_enabled')); ?> />
                    <div class="body">
                        <strong><?php esc_html_e('Enable Lookout', 'lookout'); ?></strong>
                        <span><?php esc_html_e('Master switch. When off, nothing is sent.', 'lookout'); ?></span>
                    </div>
                </div>
            </div>

            <div class="lookout-field">
                <label class="lbl" for="lookout_base_url"><?php esc_html_e('Lookout base URL', 'lookout'); ?></label>
                <input type="url" id="lookout_base_url" name="lookout_base_url" value="<?php echo esc_attr($lookout_base); ?>" placeholder="https://app.example.com" autocomplete="off" />
                <p class="hint"><?php esc_html_e('Origin only, no trailing slash (e.g. https://lookout.example.com).', 'lookout'); ?></p>
            </div>

            <div class="lookout-field">
                <label class="lbl" for="lookout_api_key"><?php esc_html_e('Project API key', 'lookout'); ?></label>
                <input type="password" id="lookout_api_key" name="lookout_api_key" value="<?php echo esc_attr($lookout_key); ?>" autocomplete="off" />
            </div>

            <div class="lookout-field">
                <label class="lbl" for="lookout_environment"><?php esc_html_e('Environment', 'lookout'); ?></label>
                <input type="text" id="lookout_environment" name="lookout_environment" value="<?php echo esc_attr((string) get_option('lookout_environment', 'production')); ?>" />
                <p class="hint"><?php esc_html_e('Tagged on each event (e.g. production, staging).', 'lookout'); ?></p>
            </div>
        </div>

        <div class="lookout-card">
            <h2><?php esc_html_e('What to send', 'lookout'); ?></h2>
            <p class="sub"><?php esc_html_e('Errors and fatals are always sent while enabled. Sampling rates are controlled from your Lookout project settings.', 'lookout'); ?></p>

            <div class="lookout-field">
                <div class="lookout-toggle">
                    <input type="hidden" name="lookout_report_http_404" value="0" />
                    <input type="checkbox" id="lookout_report_http_404" name="lookout_report_http_404" value="1" <?php checked(get_option('lookout_report_http_404', true)); ?> />
                    <div class="body">
                        <strong><?php esc_html_e('404 pages', 'lookout'); ?></strong>
                        <span><?php esc_html_e('Report missing pages (HTTP 404) as warnings.', 'lookout'); ?></span>
                    </div>
                </div>
            </div>

            <div class="lookout-field">
                <div class="lookout-toggle">
                    <input type="hidden" name="lookout_traces_enabled" value="0" />
                    <input type="checkbox" id="lookout_traces_enabled" name="lookout_traces_enabled" value="1" <?php checked(get_option('lookout_traces_enabled', true)); ?> />
                    <div class="body">
                        <strong><?php esc_html_e('Performance traces', 'lookout'); ?></strong>
                        <span><?php esc_html_e('Request timing, slow DB queries, and outbound HTTP for sampled page views.', 'lookout'); ?></span>
                    </div>
                </div>
            </div>

            <div class="lookout-field">
                <div class="lookout-toggle">
                    <input type="hidden" name="lookout_profiling_enabled" value="0" />
                    <input type="checkbox" id="lookout_profiling_enabled" name="lookout_profiling_enabled" value="1" <?php checked(get_option('lookout_profiling_enabled', true)); ?> />
                    <div class="body">
                        <strong><?php esc_html_e('CPU profiles', 'lookout'); ?></strong>
                        <span><?php esc_html_e('Sample a profile on each sampled trace so it carries a flame graph / hotspots. Uses the Excimer PHP extension when available; otherwise a lightweight cooperative sampler that needs no extension.', 'lookout'); ?></span>
                    </div>
                </div>
            </div>

            <div class="lookout-field">
                <div class="lookout-toggle">
                    <input type="hidden" name="lookout_rum_enabled" value="0" />
                    <input type="checkbox" id="lookout_rum_enabled" name="lookout_rum_enabled" value="1" <?php checked(get_option('lookout_rum_enabled', false)); ?> />
                    <div class="body">
                        <strong><?php esc_html_e('Browser monitoring (RUM)', 'lookout'); ?></strong>
                        <span><?php esc_html_e('Web Vitals and front-end JS errors from visitors. No cookies set, no IP collected by the script. Disclose this in your privacy policy. Requires consent.', 'lookout'); ?></span>
                    </div>
                </div>
            </div>

            <div class="lookout-field">
                <div class="lookout-toggle">
                    <input type="hidden" name="lookout_cron_monitoring" value="0" />
                    <input type="checkbox" id="lookout_cron_monitoring" name="lookout_cron_monitoring" value="1" <?php checked(get_option('lookout_cron_monitoring', false)); ?> />
                    <div class="body">
                        <strong><?php esc_html_e('WP-Cron monitoring', 'lookout'); ?></strong>
                        <span><?php esc_html_e('Report each scheduled WP-Cron event as a check-in so Lookout can alert on missed or failing crons. Noisy core maintenance hooks are skipped.', 'lookout'); ?></span>
                    </div>
                </div>
            </div>

            <div class="lookout-field">
                <div class="lookout-toggle">
                    <input type="hidden" name="lookout_logs_enabled" value="0" />
                    <input type="checkbox" id="lookout_logs_enabled" name="lookout_logs_enabled" value="1" <?php checked(get_option('lookout_logs_enabled', false)); ?> />
                    <div class="body">
                        <strong><?php esc_html_e('PHP logs', 'lookout'); ?></strong>
                        <span><?php esc_html_e('Forward PHP warnings, notices, and deprecations (the entries that go to debug.log) to your Logs stream. Fatals are already reported as errors. Off by default — this stream can be noisy on some sites.', 'lookout'); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="lookout-actions">
            <button type="submit" class="lookout-btn primary"><?php esc_html_e('Save changes', 'lookout'); ?></button>
        </div>
    </form>

    <div class="lookout-card">
        <h2><?php esc_html_e('Send a test event', 'lookout'); ?></h2>
        <p class="sub"><?php esc_html_e('Posts a harmless info-level event so you can confirm the key and URL.', 'lookout'); ?></p>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" class="lookout-actions" style="margin-top:0;">
            <?php wp_nonce_field('lookout_send_test'); ?>
            <input type="hidden" name="action" value="lookout_send_test" />
            <button type="submit" class="lookout-btn ghost"><?php esc_html_e('Send test event', 'lookout'); ?></button>
        </form>
    </div>

    <p class="lookout-foot">
        <?php esc_html_e('If WordPress and Lookout share the same hostname, the plugin skips sending to avoid recursion. Use a different host for your site or for Lookout.', 'lookout'); ?>
    </p>
</div>
