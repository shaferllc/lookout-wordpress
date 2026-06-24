<?php
/**
 * One-time consent gate for "rich" telemetry.
 *
 * Error and fatal capture work as soon as the plugin is configured. Richer collection that
 * touches visitors or personal data — browser RUM and attaching the logged-in user to error
 * reports — stays off until a site admin explicitly accepts, since the admin is consenting on
 * behalf of their visitors. The decision (accept or decline) is recorded with who and when.
 *
 * @package Lookout
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Lookout_Consent
{
    private const OPTION = 'lookout_consent';

    private const VERSION = 1;

    public static function granted(): bool
    {
        $consent = get_option(self::OPTION);

        return is_array($consent) && ! empty($consent['accepted']);
    }

    /**
     * True once the admin has either accepted or declined, so the prompt stops showing.
     */
    public static function decided(): bool
    {
        $consent = get_option(self::OPTION);

        return is_array($consent) && array_key_exists('accepted', $consent);
    }

    public static function grant(int $user_id): void
    {
        self::record(true, $user_id);
        // Rich mode: browser monitoring turns on once consented (admins can still toggle it off).
        update_option('lookout_rum_enabled', true);
    }

    public static function decline(int $user_id): void
    {
        self::record(false, $user_id);
    }

    /**
     * Whether to show the consent prompt: an admin who can manage options and has not decided yet.
     */
    public static function needs_prompt(): bool
    {
        return function_exists('current_user_can')
            && current_user_can('manage_options')
            && ! self::decided();
    }

    private static function record(bool $accepted, int $user_id): void
    {
        update_option(self::OPTION, [
            'accepted' => $accepted,
            'version' => self::VERSION,
            'at' => time(),
            'user' => $user_id,
        ], false);
    }
}
