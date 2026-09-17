<?php

namespace App\Services;

use App\Enums\EmailDriver;
use App\Models\EmailProvider;
use App\Support\ConfigValue;
use PHPMailer\PHPMailer\Exception as PhpMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

class PhpMailerSmtpMailer
{
    /**
     * Send via PHPMailer SMTP using the provider's encrypted config (with env fallbacks).
     *
     * @param  array<int, string>  $cc
     * @param  array<int, string>  $bcc
     *
     * @throws RuntimeException
     */
    public function send(
        EmailProvider $provider,
        string $to,
        string $subject,
        string $body,
        bool $isHtml,
        string $fromAddress,
        string $fromName,
        array $cc = [],
        array $bcc = [],
    ): void {
        if ($provider->driver !== EmailDriver::Smtp) {
            throw new RuntimeException('PHPMailer SMTP transport requires an SMTP provider.');
        }

        $host = (string) (ConfigValue::firstNonEmpty(
            $provider->configValue('host'),
            config('mail.mailers.smtp.host'),
        ) ?? '');

        if ($host === '') {
            throw new RuntimeException('SMTP host is not configured for this provider.');
        }

        $port = (int) (ConfigValue::firstNonEmpty(
            $provider->configValue('port'),
            config('mail.mailers.smtp.port'),
        ) ?? 587);

        $encryption = strtolower((string) (ConfigValue::firstNonEmpty(
            $provider->configValue('encryption'),
            config('mail.mailers.smtp.encryption'),
        ) ?? ''));

        $username = (string) (ConfigValue::firstNonEmpty(
            $provider->configValue('username'),
            config('mail.mailers.smtp.username'),
        ) ?? '');

        $password = (string) (ConfigValue::firstNonEmpty(
            $provider->configValue('password'),
            config('mail.mailers.smtp.password'),
        ) ?? '');

        if ($fromAddress === '') {
            throw new RuntimeException('From address is required to send SMTP mail.');
        }

        $mail = new PHPMailer(true);

        try {
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $port;
            $mail->Timeout = 30;

            if ($username !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $username;
                $mail->Password = $password;
            } else {
                $mail->SMTPAuth = false;
            }

            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->SMTPAutoTLS = true;
            } elseif ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->SMTPAutoTLS = true;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom($fromAddress, $fromName);
            $mail->addAddress($to);

            foreach ($cc as $address) {
                $mail->addCC($address);
            }

            foreach ($bcc as $address) {
                $mail->addBCC($address);
            }

            $mail->Subject = $subject;

            if ($isHtml) {
                $mail->isHTML(true);
                $mail->Body = $body;
                $mail->AltBody = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            } else {
                $mail->isHTML(false);
                $mail->Body = $body;
            }

            $mail->send();
        } catch (PhpMailerException $e) {
            throw new RuntimeException('SMTP send failed: '.$e->getMessage(), previous: $e);
        }
    }
}
