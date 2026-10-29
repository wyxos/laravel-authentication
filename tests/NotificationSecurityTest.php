<?php

namespace Wyxos\LaravelAuthentication\Tests;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Rappasoft\LaravelAuthenticationLog\LaravelAuthenticationLogServiceProvider;
use Wyxos\LaravelAuthentication\AuthenticationServiceProvider;
use Wyxos\LaravelAuthentication\LoginActor;
use Wyxos\LaravelAuthentication\LoginContext;
use Wyxos\LaravelAuthentication\Models\AuthenticationEntry;
use Wyxos\LaravelAuthentication\Models\AuthenticationIdentity;
use Wyxos\LaravelAuthentication\Notifications\CompletedLogin;

class NotificationSecurityTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelAuthenticationLogServiceProvider::class, AuthenticationServiceProvider::class];
    }

    public static function attackerContent(): array
    {
        return [
            'phishing link' => ['[Review security](https://attacker.example/reset)'],
            'tracking image' => ['![Security image](https://attacker.example/pixel.png)'],
            'HTML payload' => ['<img src="https://attacker.example/pixel.png" onerror="alert(1)">'],
        ];
    }

    #[DataProvider('attackerContent')]
    public function test_recipient_email_treats_account_data_as_literal_text(string $payload): void
    {
        $context = new LoginContext(new LoginActor('1', 'User', null, $payload), 'web',
            CarbonImmutable::now('UTC'), '192.0.2.10', 'Chrome on Windows');
        $mail = (new CompletedLogin($context))->toMail(new AuthenticationIdentity(['email' => '2@example.com']));
        $this->assertLiteralContent($mail, $payload);
    }

    #[DataProvider('attackerContent')]
    public function test_new_device_email_treats_raw_user_agent_as_literal_text(string $payload): void
    {
        $entry = new AuthenticationEntry(['login_at' => CarbonImmutable::now('UTC'),
            'user_agent' => 'Chrome/142 Windows '.$payload, 'ip_address' => '192.0.2.10']);
        $class = config('wyxos-authentication.notification');
        $mail = (new $class($entry))->toMail(new AuthenticationIdentity(['email' => '1@example.com']));
        $this->assertLiteralContent($mail, $payload);
    }

    public function test_actual_dispatch_keeps_both_mime_parts_literal(): void
    {
        config(['mail.default' => 'array', 'mail.from.address' => '1@example.com']);
        $payload = '[Review security](https://attacker.example/reset) ![pixel](https://attacker.example/pixel.png) <img src="https://attacker.example/unsafe">';
        $recipient = Notification::route('mail', '2@example.com');
        $context = new LoginContext(new LoginActor('1', 'User', null, $payload), 'web',
            CarbonImmutable::now('UTC'), '192.0.2.10', 'Chrome on Windows');
        Notification::sendNow($recipient, new CompletedLogin($context), ['mail']);
        $class = config('wyxos-authentication.notification');
        Notification::sendNow(new AuthenticationIdentity(['email' => '1@example.com']), new $class(new AuthenticationEntry([
            'login_at' => CarbonImmutable::now('UTC'), 'user_agent' => $payload, 'ip_address' => '192.0.2.10',
        ])), ['mail']);
        $messages = Mail::getSymfonyTransport()->messages();
        $this->assertCount(2, $messages);
        foreach ($messages as $sent) {
            $email = $sent->getOriginalMessage();
            $this->assertStringContainsString($payload, $email->getTextBody());
            $this->assertStringNotContainsString('href="https://attacker.example', $email->getHtmlBody());
            $this->assertStringNotContainsString('src="https://attacker.example', $email->getHtmlBody());
            $this->assertStringContainsString('&lt;img', $email->getHtmlBody());
        }
    }

    public function test_new_device_keeps_upstream_translation_keys(): void
    {
        app('translator')->addJsonPath(__DIR__.'/Fixtures/lang');
        app()->setLocale('fr');
        config(['app.name' => 'Example']);
        $class = config('wyxos-authentication.notification');
        $mail = (new $class(new AuthenticationEntry(['login_at' => CarbonImmutable::now('UTC')])))->toMail(new AuthenticationIdentity(['email' => '1@example.com']));
        $this->assertSame('Nouvel appareil pour Example.', $mail->subject);
        $html = (string) $mail->render();
        $this->assertStringContainsString('Bonjour!', $html);
        $this->assertStringContainsString('Compte :', $html);
    }

    private function assertLiteralContent(MailMessage $mail, string $payload): void
    {
        $html = (string) $mail->render();
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $nodes = (new DOMXPath($document))->query('//a[starts-with(@href,"https://attacker.example")] | //img[starts-with(@src,"https://attacker.example")] | //script | //*[@onerror]');
        $this->assertCount(0, $nodes, 'Security alert contains active attacker-controlled content.');
        $this->assertStringContainsString($payload, html_entity_decode($document->textContent));
    }
}
