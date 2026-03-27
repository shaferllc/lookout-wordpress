<?php
/**
 * Settings UI under Settings → Lookout.
 *
 * @package Lookout
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

?>
<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php if (isset($_GET['lookout_test'])) : ?>
        <?php if ((string) $_GET['lookout_test'] === '1') : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Test event sent. Check your Lookout project for a new issue.', 'lookout'); ?></p></div>
        <?php else : ?>
            <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Could not send test: enable the plugin and fill API key and base URL.', 'lookout'); ?></p></div>
        <?php endif; ?>
    <?php endif; ?>

    <p class="description">
        <?php
        echo esc_html__(
            'Sends uncaught exceptions and fatal PHP errors to your Lookout project. Use a dedicated project API key from Lookout project settings.',
            'lookout'
        );
        ?>
    </p>

    <form action="options.php" method="post" class="lookout-settings" style="max-width: 42rem; margin-top: 1.5rem;">
        <?php settings_fields('lookout'); ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Enable reporting', 'lookout'); ?></th>
                <td>
                    <input type="hidden" name="lookout_enabled" value="0" />
                    <label>
                        <input type="checkbox" name="lookout_enabled" value="1" <?php checked(get_option('lookout_enabled')); ?> />
                        <?php esc_html_e('Send errors to Lookout', 'lookout'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lookout_base_url"><?php esc_html_e('Lookout base URL', 'lookout'); ?></label></th>
                <td>
                    <input type="url" class="regular-text code" id="lookout_base_url" name="lookout_base_url"
                           value="<?php echo esc_attr((string) get_option('lookout_base_url', '')); ?>"
                           placeholder="https://app.example.com" autocomplete="off" />
                    <p class="description"><?php esc_html_e('Origin only, no trailing slash (e.g. https://lookout.example.com).', 'lookout'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lookout_api_key"><?php esc_html_e('Project API key', 'lookout'); ?></label></th>
                <td>
                    <input type="password" class="regular-text code" id="lookout_api_key" name="lookout_api_key"
                           value="<?php echo esc_attr((string) get_option('lookout_api_key', '')); ?>"
                           placeholder="" autocomplete="off" />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lookout_environment"><?php esc_html_e('Environment', 'lookout'); ?></label></th>
                <td>
                    <input type="text" class="regular-text" id="lookout_environment" name="lookout_environment"
                           value="<?php echo esc_attr((string) get_option('lookout_environment', 'production')); ?>" />
                    <p class="description"><?php esc_html_e('Tagged on each event (e.g. production, staging).', 'lookout'); ?></p>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Save changes', 'lookout')); ?>
    </form>

    <hr style="max-width:42rem;" />

    <h2><?php esc_html_e('Send test event', 'lookout'); ?></h2>
    <p class="description"><?php esc_html_e('Posts a harmless info-level event so you can confirm the key and URL.', 'lookout'); ?></p>
    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" style="margin-top: 0.75rem;">
        <?php wp_nonce_field('lookout_send_test'); ?>
        <input type="hidden" name="action" value="lookout_send_test" />
        <?php submit_button(__('Send test event', 'lookout'), 'secondary'); ?>
    </form>

    <p class="description" style="max-width:42rem; margin-top:2rem;">
        <?php esc_html_e('If WordPress and Lookout share the same hostname, the plugin skips sending to avoid recursion. Use a different host for your site or for Lookout.', 'lookout'); ?>
    </p>
</div>
