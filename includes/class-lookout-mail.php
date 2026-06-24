<?php

/**
 * Mail monitoring.
 *
 * Reports sent (and failed) wp_mail() messages to /api/ingest/mail, so the Mail watcher shows what
 * the site emailed and whether delivery to the MTA succeeded. Gated on the remote `mail` signal;
 * every callback is wrapped so it can never disrupt mail delivery.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Lookout_Mail
{
    public static function boot(): void
    {
        if (! self::enabled()) {
            return;
        }
        // WP 5.9+ (the plugin requires 6.0+): these fire after wp_mail() hands off to the MTA.
        add_action('wp_mail_succeeded', [self::class, 'on_sent']);
        add_action('wp_mail_failed', [self::class, 'on_failed']);
    }

    private static function enabled(): bool
    {
        if (! get_option('lookout_enabled', false)) {
            return false;
        }
        if ((string) get_option('lookout_api_key', '') === '' || (string) get_option('lookout_base_url', '') === '') {
            return false;
        }

        return Lookout_Config::is_enabled('mail');
    }

    /**
     * @param  mixed  $mail_data  {to, subject, message, headers, attachments}
     */
    public static function on_sent($mail_data): void
    {
        try {
            if (is_array($mail_data)) {
                self::report((string) ($mail_data['subject'] ?? ''), $mail_data['to'] ?? [], 'sent');
            }
        } catch (Throwable $e) {
            // Never let mail monitoring break delivery.
        }
    }

    /**
     * @param  mixed  $error  WP_Error whose error_data carries the original wp_mail() args
     */
    public static function on_failed($error): void
    {
        try {
            $data = is_object($error) && method_exists($error, 'get_error_data') ? $error->get_error_data() : [];
            if (! is_array($data)) {
                $data = [];
            }
            self::report((string) ($data['subject'] ?? ''), $data['to'] ?? [], 'failed');
        } catch (Throwable $e) {
            // Swallow.
        }
    }

    /**
     * @param  mixed  $to
     */
    private static function report(string $subject, $to, string $status): void
    {
        $recipients = array_values(array_filter(
            array_map(static fn ($r): string => is_string($r) ? $r : '', (array) $to),
            static fn (string $r): bool => $r !== ''
        ));

        Lookout_Client::send_mail([
            'mailable' => 'wp_mail',
            'subject' => substr($subject, 0, 512),
            'to' => array_slice($recipients, 0, 20),
            'environment' => (string) get_option('lookout_environment', 'production'),
            'sent_at' => gmdate('c'),
            'meta' => ['status' => $status],
        ]);
    }
}
