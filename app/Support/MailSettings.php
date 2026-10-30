<?php

namespace App\Support;

use App\Models\MailSetting;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * How the book sends email, set in Settings › Email instead of .env.
 *
 * When switched on, these settings take over from the .env ones the moment
 * Laravel's mailer is first used (every email the book sends: password
 * resets, comment alerts, new posts to subscribers, letters). Switched off
 * or never set, the .env settings are used exactly as before.
 */
class MailSettings
{
    /** The mailer name used for these settings. */
    public const MAILER = 'booklet';

    /** Services and their usual server details. Facts about each, for the hints. */
    public const SERVICES = [
        'gmail' => [
            'name' => 'Gmail or Google Workspace', 'host' => 'smtp.gmail.com', 'port' => 587, 'security' => 'tls', 'username' => '',
            'note' => 'Your username is your full Gmail address. For the password, make an app password in your Google account (it needs 2-Step Verification on). Your normal password won’t work. Gmail allows around 500 emails a day.',
            'url' => 'https://myaccount.google.com/apppasswords', 'url_label' => 'Make an app password',
        ],
        'outlook' => [
            'name' => 'Microsoft 365 or Outlook', 'host' => 'smtp.office365.com', 'port' => 587, 'security' => 'tls', 'username' => '',
            'note' => 'Your username is your full email address. On Microsoft 365, your admin may need to turn on “Authenticated SMTP” for the mailbox first.',
            'url' => 'https://learn.microsoft.com/exchange/clients-and-mobile-in-exchange-online/authenticated-client-smtp-submission', 'url_label' => 'How to turn it on',
        ],
        'zoho' => [
            'name' => 'Zoho Mail', 'host' => 'smtp.zoho.com', 'port' => 587, 'security' => 'tls', 'username' => '',
            'note' => 'Your username is your full Zoho address. If your account is in Europe or India, the server is smtp.zoho.eu or smtp.zoho.in. With two-factor sign-in on, use an app-specific password.',
            'url' => '', 'url_label' => '',
        ],
        'brevo' => [
            'name' => 'Brevo', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'security' => 'tls', 'username' => '',
            'note' => 'Use the SMTP login and an SMTP key from Brevo (SMTP & API in your account), not your account password.',
            'url' => 'https://app.brevo.com/settings/keys/smtp', 'url_label' => 'Find your SMTP key',
        ],
        'mailgun' => [
            'name' => 'Mailgun', 'host' => 'smtp.mailgun.org', 'port' => 587, 'security' => 'tls', 'username' => '',
            'note' => 'Use the SMTP username and password for your sending domain. For a domain in Mailgun’s EU region, the server is smtp.eu.mailgun.org.',
            'url' => '', 'url_label' => '',
        ],
        'postmark' => [
            'name' => 'Postmark', 'host' => 'smtp.postmarkapp.com', 'port' => 587, 'security' => 'tls', 'username' => '',
            'note' => 'Paste your Server API token as both the username and the password.',
            'url' => '', 'url_label' => '',
        ],
        'sendgrid' => [
            'name' => 'SendGrid', 'host' => 'smtp.sendgrid.net', 'port' => 587, 'security' => 'tls', 'username' => 'apikey',
            'note' => 'The username is the word apikey, exactly. The password is an API key you make in SendGrid.',
            'url' => '', 'url_label' => '',
        ],
        'ses' => [
            'name' => 'Amazon SES', 'host' => 'email-smtp.us-east-1.amazonaws.com', 'port' => 587, 'security' => 'tls', 'username' => '',
            'note' => 'Change us-east-1 to your SES region. Use SES SMTP credentials (made in the SES console), not your AWS access keys.',
            'url' => '', 'url_label' => '',
        ],
        'other' => [
            'name' => 'Another mail server', 'host' => '', 'port' => 587, 'security' => 'tls', 'username' => '',
            'note' => 'Your web host or email provider lists these as “SMTP” or “outgoing mail” settings.',
            'url' => '', 'url_label' => '',
        ],
    ];

    public const SECURITY = [
        'tls' => 'STARTTLS (usually port 587)',
        'ssl' => 'SSL/TLS (usually port 465)',
        'none' => 'None (not encrypted)',
    ];

    /** .env's mailer, before these settings took over (for the status line). */
    protected static ?string $envMailer = null;

    public static function record(): ?MailSetting
    {
        return rescue(fn () => Schema::hasTable('mail_settings') ? MailSetting::query()->first() : null, null, false);
    }

    public static function siteName(): string
    {
        return (string) rescue(fn () => app(\App\Booklet\Site::class)->name(), config('app.name'), false);
    }

    /** Use the saved settings, if they're switched on. Called when the mailer is first needed. */
    public static function apply(): void
    {
        // Emails come from the site's name, not the framework's ("Laravel" until APP_NAME is changed).
        $from = trim((string) config('mail.from.name'));
        if ($from === '' || $from === 'Laravel' || $from === '${APP_NAME}') {
            config(['mail.from.name' => self::siteName()]);
        }

        $record = self::record();
        if (! $record?->isReady()) {
            return;
        }

        self::$envMailer ??= (string) config('mail.default');
        config([
            'mail.mailers.'.self::MAILER => self::config($record),
            'mail.default' => self::MAILER,
            'mail.from.address' => $record->from_address,
            'mail.from.name' => $record->from_name ?: config('mail.from.name'),
        ]);
    }

    /**
     * A mailer config from settings (saved or still on the form).
     *
     * @return array<string, mixed>
     */
    public static function config(MailSetting $s): array
    {
        $security = array_key_exists((string) $s->security, self::SECURITY) ? $s->security : 'tls';

        return array_filter([
            'transport' => 'smtp',
            'scheme' => $security === 'ssl' ? 'smtps' : 'smtp',
            'host' => $s->host,
            'port' => (int) $s->port,
            'username' => $s->username ?: null,
            'password' => $s->password ?: null,
            'timeout' => 15,
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
            // STARTTLS must happen (nothing is sent in the clear); "none" never tries it.
            'require_tls' => $security === 'tls' ? true : null,
            'auto_tls' => $security === 'none' ? false : null,
            'from' => ['address' => $s->from_address, 'name' => $s->from_name ?: self::siteName()],
        ], fn ($v) => $v !== null);
    }

    /**
     * Where the book's email goes right now, in words.
     *
     * @return array{state: string, text: string}  state: on, env, off
     */
    public static function status(): array
    {
        $record = self::record();
        if ($record?->isReady()) {
            return ['state' => 'on', 'text' => __('Sending through :host as :address.', ['host' => $record->host, 'address' => $record->from_address])];
        }

        $lost = $record?->enabled && $record->passwordLost()
            ? __('The saved email password can’t be read since the site’s APP_KEY changed. Type it again in Settings › Email. Until then:').' '
            : '';

        $env = self::$envMailer ?? (string) config('mail.default');
        if (in_array($env, ['log', 'array', ''], true)) {
            return ['state' => 'off', 'text' => $lost.($lost ? __('not sending.') : __('Not sending.')).' '.__('Emails are only written to the log file, so nobody receives them.')];
        }

        $host = $env === 'smtp' ? (string) config('mail.mailers.smtp.host') : '';

        return ['state' => 'env', 'text' => $lost.($lost ? __('using the server’s own settings (:which).', ['which' => '.env, '.($host ?: $env)]) : __('Using the server’s own settings (:which).', ['which' => '.env, '.($host ?: $env)]))];
    }

    /**
     * Send a test email with these settings. Returns null when it went, or
     * what went wrong, in words.
     */
    public static function test(MailSetting $settings, string $to): ?string
    {
        $name = self::MAILER.'-test';
        config(['mail.mailers.'.$name => self::config($settings)]);
        app('mail.manager')->purge($name);

        try {
            Mail::mailer($name)->raw(
                "This is a test from ".self::siteName().".\n\nIf you can read this, your email settings work: password resets, comment alerts and new posts to subscribers will arrive like this one.",
                fn ($m) => $m->to($to)->from($settings->from_address, $settings->from_name ?: self::siteName())->subject('Test email from '.self::siteName()),
            );
        } catch (Throwable $e) {
            return self::explain($e, $settings);
        } finally {
            app('mail.manager')->purge($name);
        }

        return null;
    }

    /** A mail server's error, as advice. */
    public static function explain(Throwable $e, MailSetting $s): string
    {
        $m = $e->getMessage();
        $where = $s->host.':'.$s->port;

        return match (true) {
            (bool) preg_match('/\b(535|534|530)\b|authenticat|username and password not accepted/i', $m) => 'The mail server didn’t accept the username or password.'.($s->service === 'gmail' ? ' Gmail needs an app password, not your normal one.' : ' Check both, and whether your service wants an app password or SMTP key instead.'),
            (bool) preg_match('/Connection could not be established|Connection refused|timed out|getaddrinfo|Network is unreachable|No such host/i', $m) => 'Couldn’t reach '.$where.'. Check the server and port. Some hosts block outgoing mail ports; port 587 or 465 usually works.',
            (bool) preg_match('/certificate|SSL|TLS|crypto|STARTTLS/i', $m) => 'The secure connection to '.$where.' failed. Try the other security choice (STARTTLS for port 587, SSL/TLS for port 465).',
            (bool) preg_match('/\b(550|551|553|554)\b|sender|not allowed to send|not owned|verify/i', $m) => 'The server refused to send from '.$s->from_address.'. Use an address your mail service lets you send from (often the same as the username).',
            default => 'The test didn’t go through: '.\Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', $m)), 220),
        };
    }
}
