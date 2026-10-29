<?php

namespace CraftCms\Prepper\Console\Advisors;

use CraftCms\Prepper\Console\NextStepSection;
use CraftCms\Prepper\Console\Project;

/**
 * Suggests the Laravel mailer that replaces the email transport from project config.
 */
class MailerAdvisor implements Advisor
{
    /**
     * Laravel mailers, indexed by a pattern matching the Craft transport adapter classes.
     */
    private const MAILERS = [
        '/gmail|smtp/i' => 'smtp',
        '/sendmail/i' => 'sendmail',
        '/mailgun/i' => 'mailgun',
        '/postmark/i' => 'postmark',
        '/resend/i' => 'resend',
        '/amazonses|\\\\ses\b/i' => 'ses',
    ];

    public function advise(Project $project): void
    {
        $email = $project->projectConfig()['email'] ?? null;
        $type = is_array($email) ? ($email['transportType'] ?? null) : null;

        if (! is_string($type) || $type === '') {
            return;
        }

        $mailer = collect(self::MAILERS)->first(fn (string $mailer, string $pattern) => preg_match($pattern, $type));

        $settings = is_array($email['transportSettings'] ?? null) ? $email['transportSettings'] : [];
        $details = collect(['host', 'port', 'username', 'encryptionMethod', 'domain', 'endpoint'])
            ->filter(fn (string $key) => is_scalar($settings[$key] ?? null) && (string) $settings[$key] !== '')
            ->map(fn (string $key) => "$key: {$settings[$key]}")
            ->implode(', ');
        $details = $details === '' ? '' : " ($details)";

        if ($mailer === null) {
            $project->suggest(NextStepSection::Mail, "Craft no longer reads the email transport from project config. Configure a Laravel mailer in config/mail.php to replace <options=bold>$type</>$details, and set <options=bold>MAIL_MAILER</> in .env");

            return;
        }

        if ($mailer === 'sendmail') {
            $project->suggest(NextStepSection::Mail, 'Craft no longer reads the email transport from project config. Set <options=bold>MAIL_MAILER=sendmail</> in .env to keep sending email with Sendmail');

            return;
        }

        $project->suggest(NextStepSection::Mail, sprintf(
            'Craft no longer reads the email transport from project config. Set <options=bold>MAIL_MAILER=%s</> in .env and configure the <options=bold>%s</> mailer in config/mail.php to replace <options=bold>%s</>%s%s',
            $mailer,
            $mailer,
            $type,
            $details,
            in_array($mailer, ['mailgun', 'postmark', 'resend', 'ses']) ? ', following https://laravel.com/docs/mail#driver-prerequisites' : '',
        ));
    }
}
