<?php
declare(strict_types=1);

namespace Yiyunying\Services;

use Yiyunying\Core\HttpException;

/**
 * Sends verification mail and reports only what the transport can prove.
 * SMTP 250 and PHP mail() true mean accepted for delivery, not inbox delivery.
 */
final class VerificationEmailDeliveryService
{
    public static function deliver(
        string $email,
        string $appName,
        string $code,
        string $purpose
    ): array {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException('邮箱格式错误', 0, 422);
        }
        $subject = $appName . $purpose . '验证码';
        $message = "您正在进行{$purpose}操作，验证码是：{$code}\n\n10 分钟内有效。如非本人操作，请忽略本邮件。";
        return self::deliverMessage($email, $subject, $message);
    }

    public static function sendConfigurationTest(string $email): array
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException('测试收件邮箱格式错误', 0, 422);
        }
        $timestamp = date('Y-m-d H:i:s T');
        return self::deliverMessage(
            $email,
            '易运盈后台邮件配置测试',
            "这是一封由一级平台总控主动发起的邮件配置测试。\n\n服务器时间：{$timestamp}\n若非您本人操作，请联系平台所有者。"
        );
    }

    private static function deliverMessage(string $email, string $subject, string $message): array
    {
        $configuration = MailConfigurationService::effective();
        $transport = strtolower(trim((string) ($configuration['transport'] ?? 'disabled')));

        if ($transport === 'log') {
            if ((string) config('app.env', 'production') === 'production'
                || !(bool) config('app.debug', false)) {
                throw new HttpException('邮件发送通道未配置', 503, 503);
            }
            self::writeDevelopmentLog($email, $message);
            return ['delivery_status' => 'simulated'];
        }
        if ($transport === 'native') {
            self::deliverNative($configuration, $email, $subject, $message);
            return ['delivery_status' => 'accepted_unconfirmed'];
        }
        if ($transport === 'smtp') {
            self::deliverSmtp($configuration, $email, $subject, $message);
            return ['delivery_status' => 'accepted_unconfirmed'];
        }
        throw new HttpException('邮件发送通道未配置', 503, 503);
    }

    private static function deliverNative(array $configuration, string $email, string $subject, string $message): void
    {
        [$from, $fromName] = self::senderIdentity($configuration);
        if (!function_exists('mail')) {
            throw new HttpException('验证码邮件服务不可用，请联系管理员', 503, 503);
        }
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'From: ' . self::encodedHeader($fromName) . ' <' . $from . '>',
            'Auto-Submitted: auto-generated',
        ];
        $body = rtrim(chunk_split(base64_encode($message), 76, "\r\n"));
        // A true return value only proves that the local transport accepted the message.
        if (!@mail($email, self::encodedHeader($subject), $body, implode("\r\n", $headers))) {
            throw new HttpException('验证码邮件服务拒绝了投递请求，请稍后重试', 503, 503);
        }
    }

    private static function deliverSmtp(array $configuration, string $email, string $subject, string $message): void
    {
        [$from, $fromName] = self::senderIdentity($configuration);
        $smtp = is_array($configuration['smtp'] ?? null) ? $configuration['smtp'] : [];
        $host = trim((string) ($smtp['host'] ?? ''));
        $port = (int) ($smtp['port'] ?? 0);
        $encryption = strtolower(trim((string) ($smtp['encryption'] ?? 'tls')));
        $username = (string) ($smtp['username'] ?? '');
        $password = (string) ($smtp['password'] ?? '');
        $timeout = max(3, min(30, (int) ($smtp['timeout'] ?? 10)));

        if (preg_match('/^[A-Za-z0-9.-]+$/', $host) !== 1 || $port < 1 || $port > 65535) {
            throw new HttpException('邮件 SMTP 配置不完整', 503, 503);
        }
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            throw new HttpException('邮件 SMTP 加密配置无效', 503, 503);
        }
        $localUnencrypted = $encryption === 'none'
            && (string) config('app.env', 'production') !== 'production'
            && in_array(strtolower($host), ['127.0.0.1', 'localhost'], true);
        if ($encryption === 'none' && !$localUnencrypted) {
            throw new HttpException('生产邮件 SMTP 必须启用 TLS', 503, 503);
        }
        if (($username === '') !== ($password === '')) {
            throw new HttpException('邮件 SMTP 账号配置不完整', 503, 503);
        }
        if (in_array($encryption, ['tls', 'ssl'], true) && !extension_loaded('openssl')) {
            throw new HttpException('服务器缺少邮件 TLS 支持', 503, 503);
        }

        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ]]);
        $endpoint = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $errorNumber = 0;
        $errorMessage = '';
        $stream = @stream_socket_client(
            $endpoint,
            $errorNumber,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($stream)) {
            throw new HttpException('验证码邮件服务连接失败，请稍后重试', 503, 503);
        }

        try {
            stream_set_timeout($stream, $timeout);
            self::expect($stream, [220]);
            $helo = trim((string) ($smtp['helo'] ?? ''));
            $capabilities = self::hello($stream, $helo);
            if ($encryption === 'tls') {
                if (!str_contains(strtoupper($capabilities), 'STARTTLS')) {
                    throw new HttpException('邮件 SMTP 不支持要求的 TLS', 503, 503);
                }
                self::command($stream, 'STARTTLS', [220]);
                if (@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new HttpException('邮件 SMTP TLS 握手失败', 503, 503);
                }
                $capabilities = self::hello($stream, $helo);
            }
            if ($username !== '') self::authenticate($stream, $capabilities, $username, $password);

            self::command($stream, 'MAIL FROM:<' . $from . '>', [250]);
            self::command($stream, 'RCPT TO:<' . $email . '>', [250, 251]);
            self::command($stream, 'DATA', [354]);
            $wireMessage = self::smtpMessage($email, $from, $fromName, $subject, $message);
            if (@fwrite($stream, self::dotStuff($wireMessage) . "\r\n.\r\n") === false) {
                throw new HttpException('验证码邮件内容提交失败，请稍后重试', 503, 503);
            }
            self::expect($stream, [250]);
            self::command($stream, 'QUIT', [221]);
        } finally {
            fclose($stream);
        }
    }

    private static function senderIdentity(array $configuration): array
    {
        $from = trim((string) ($configuration['from_address'] ?? ''));
        $fromName = self::singleLine((string) ($configuration['from_name'] ?? '易运盈后台'));
        if (filter_var($from, FILTER_VALIDATE_EMAIL) === false || $fromName === '') {
            throw new HttpException('邮件发件人配置无效', 503, 503);
        }
        $domain = strtolower((string) substr(strrchr($from, '@') ?: '', 1));
        if ((string) config('app.env', 'production') === 'production'
            && ($domain === '' || preg_match('/(?:^|\.)(?:example|invalid|test|localhost)$/', $domain) === 1)) {
            throw new HttpException('生产邮件发件人域名未配置', 503, 503);
        }
        return [$from, $fromName];
    }

    private static function hello($stream, string $configured): string
    {
        $configured = trim($configured);
        $identity = preg_match('/^[A-Za-z0-9.-]+$/', $configured) === 1
            ? $configured
            : 'localhost.localdomain';
        return self::command($stream, 'EHLO ' . $identity, [250]);
    }

    private static function authenticate($stream, string $capabilities, string $username, string $password): void
    {
        $upper = strtoupper($capabilities);
        if (str_contains($upper, 'AUTH PLAIN')) {
            self::command($stream, 'AUTH PLAIN ' . base64_encode("\0{$username}\0{$password}"), [235]);
            return;
        }
        if (str_contains($upper, 'AUTH LOGIN')) {
            self::command($stream, 'AUTH LOGIN', [334]);
            self::command($stream, base64_encode($username), [334]);
            self::command($stream, base64_encode($password), [235]);
            return;
        }
        throw new HttpException('邮件 SMTP 不支持已配置的账号认证', 503, 503);
    }

    private static function command($stream, string $command, array $expected): string
    {
        if (str_contains($command, "\r") || str_contains($command, "\n")
            || @fwrite($stream, $command . "\r\n") === false) {
            throw new HttpException('验证码邮件服务通信失败，请稍后重试', 503, 503);
        }
        return self::expect($stream, $expected);
    }

    private static function expect($stream, array $expected): string
    {
        $lines = [];
        $code = 0;
        do {
            $line = fgets($stream, 8192);
            if ($line === false || preg_match('/^(\d{3})([ -])/', $line, $matches) !== 1) {
                throw new HttpException('验证码邮件服务响应异常，请稍后重试', 503, 503);
            }
            $code = (int) $matches[1];
            $continued = $matches[2] === '-';
            $lines[] = rtrim($line, "\r\n");
        } while ($continued);
        if (!in_array($code, $expected, true)) {
            throw new HttpException('验证码邮件服务暂时拒绝投递请求，请稍后重试', 503, 503);
        }
        return implode("\n", $lines);
    }

    private static function smtpMessage(
        string $email,
        string $from,
        string $fromName,
        string $subject,
        string $message
    ): string {
        $domain = (string) substr(strrchr($from, '@') ?: '@localhost.localdomain', 1);
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'From: ' . self::encodedHeader($fromName) . ' <' . $from . '>',
            'To: <' . $email . '>',
            'Subject: ' . self::encodedHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n"
            . rtrim(chunk_split(base64_encode($message), 76, "\r\n"));
    }

    private static function dotStuff(string $message): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $message);
        $normalized = preg_replace('/^\./m', '..', $normalized) ?? $normalized;
        return str_replace("\n", "\r\n", $normalized);
    }

    private static function encodedHeader(string $value): string
    {
        $value = self::singleLine($value);
        return preg_match('/^[\x20-\x7E]+$/', $value) === 1
            ? $value
            : '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private static function singleLine(string $value): string
    {
        return trim(str_replace(["\r", "\n", "\0"], ' ', $value));
    }

    private static function writeDevelopmentLog(string $email, string $message): void
    {
        $directory = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new HttpException('本地验证码日志不可写', 503, 503);
        }
        $written = @file_put_contents(
            $directory . '/verification-mail.log',
            date('c') . "\t{$email}\t" . self::singleLine($message) . "\n",
            FILE_APPEND | LOCK_EX
        );
        if ($written === false) throw new HttpException('本地验证码日志写入失败', 503, 503);
    }
}
