<?php

declare(strict_types=1);

use App\Contracts\VoipPlatformClient;
use App\Enums\VoipAccountStatus;
use App\Enums\VoipPlatform;
use App\Jobs\SendEmailNotification;
use App\Models\CallRateRule;
use App\Models\VoipAccount;
use App\Services\EmailNotificationService;
use App\Services\SmsService;
use App\Services\Voip\HttpVoipPlatformClient;
use App\Services\VoipBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('send email notification job sends email and logs success', function (): void {
    Mail::fake();
    Log::spy();

    $mailable = new class() extends Mailable
    {
        public function build(): self
        {
            return $this->subject('Queued Notice')->html('<p>Hello</p>');
        }
    };

    $job = new SendEmailNotification($mailable, 'recipient@example.test');
    $job->handle();

    Mail::assertSent($mailable::class, fn ($sent) => $sent->hasTo('recipient@example.test'));
    Log::shouldHaveReceived('info')->once();
});

test('send email notification job logs error and rethrows on mail failure and logs permanent failure', function (): void {
    Log::spy();

    $mailable = new class() extends Mailable
    {
        public function build(): self
        {
            return $this->subject('Failing Notice')->html('<p>Oops</p>');
        }
    };

    Mail::shouldReceive('to')
        ->once()
        ->with('fail@example.test')
        ->andThrow(new RuntimeException('SMTP connection lost'));

    $job = new SendEmailNotification($mailable, 'fail@example.test');

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'SMTP connection lost');

    $job->failed(new RuntimeException('Permanent mail failure'));

    Log::shouldHaveReceived('error')->twice();
});

test('email notification service handles queue push exceptions, sendNow retries, and zero-retry fallback', function (): void {
    Log::spy();

    $mailable = new class() extends Mailable
    {
        public function build(): self
        {
            return $this->subject('Retry Test')->html('<p>Retry</p>');
        }
    };

    Queue::shouldReceive('push')
        ->once()
        ->andThrow(new RuntimeException('Queue broker unavailable'));

    $service = new EmailNotificationService();

    expect($service->send($mailable, 'queue-fail@example.test'))->toBeFalse();

    Mail::shouldReceive('to')
        ->times(3)
        ->with('retry-fail@example.test')
        ->andThrow(new RuntimeException('Temporary SMTP failure'));

    expect($service->sendNow($mailable, 'retry-fail@example.test'))->toBeFalse();

    $zeroRetryService = new class() extends EmailNotificationService
    {
        protected $maxRetries = 0;
    };

    expect($zeroRetryService->sendNow($mailable, 'zero-retry@example.test'))->toBeFalse();

    Log::shouldHaveReceived('warning')->times(3);
    Log::shouldHaveReceived('error')->twice();
});

test('sms service catches http client exceptions and returns false', function (): void {
    Log::spy();
    config([
        'services.sms.api_key' => 'test-key',
        'services.sms.from_number' => '+15550001111',
        'services.sms.base_url' => 'https://sms.example.test',
        'services.sms.enabled' => true,
    ]);

    Http::fake(function (): never {
        throw new ConnectionException('SMS gateway unreachable');
    });

    $result = (new SmsService())->send('4155552671', 'Alert message');

    expect($result)->toBeFalse();
    Log::shouldHaveReceived('error')->once();
});

test('http voip platform client synchronizes and suspends accounts', function (): void {
    config([
        'voip.platforms.asterisk.url' => 'https://asterisk.example.test/api',
        'voip.platforms.asterisk.token' => 'secret-token',
    ]);
    Http::fake(['*' => Http::response([], 200)]);

    $account = VoipAccount::factory()->create([
        'platform' => VoipPlatform::Asterisk,
        'sip_username' => 'sip user/1',
        'sip_secret' => 'very-secure-secret',
    ]);

    $client = new HttpVoipPlatformClient(VoipPlatform::Asterisk);
    $client->synchronizeAccount($account);
    $client->suspendAccount($account);

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/api/sip/accounts/sip%20user%2F1')
        && $request['username'] === 'sip user/1');

    Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/api/sip/accounts/sip%20user%2F1')
        && $request['enabled'] === false);
});

test('http voip platform client throws on http errors and missing configuration', function (): void {
    config([
        'voip.platforms.asterisk.url' => 'https://asterisk.example.test/api',
        'voip.platforms.asterisk.token' => 'secret-token',
    ]);
    Http::fake(['*' => Http::response(['error' => 'failed'], 500)]);

    $account = VoipAccount::factory()->create([
        'platform' => VoipPlatform::Asterisk,
        'sip_username' => 'sip-err-1',
        'sip_secret' => 'very-secure-secret',
    ]);

    $client = new HttpVoipPlatformClient(VoipPlatform::Asterisk);

    expect(fn () => $client->suspendAccount($account))
        ->toThrow(RuntimeException::class, 'asterisk VoIP operation failed.');

    config([
        'voip.platforms.asterisk.url' => '',
        'voip.platforms.asterisk.token' => '',
    ]);

    expect(fn () => $client->provisionAccount($account))
        ->toThrow(RuntimeException::class, 'asterisk VoIP operation failed.');
});

test('voip billing service rejects terminated accounts and handles unrated or zero-duration cdrs', function (): void {
    $terminated = VoipAccount::factory()->create([
        'status' => VoipAccountStatus::Terminated,
    ]);

    $noopClient = new class() implements VoipPlatformClient
    {
        public function provisionAccount(VoipAccount $account): void {}

        public function synchronizeAccount(VoipAccount $account): void {}

        public function suspendAccount(VoipAccount $account): void {}
    };

    $service = app(VoipBillingService::class);

    expect(fn () => $service->provision($terminated, $noopClient))
        ->toThrow(InvalidArgumentException::class, 'A terminated VoIP account cannot be provisioned.');

    $activeAccount = VoipAccount::factory()->create([
        'status' => VoipAccountStatus::Active,
    ]);

    $unratedCdr = $service->ingestCdr($activeAccount, [
        'external_id' => 'unrated-call-1',
        'source' => '+15551234567',
        'destination' => '+888999000',
        'started_at' => now()->subMinute()->toISOString(),
        'duration_seconds' => 45,
    ]);

    expect($unratedCdr->call_rate_rule_id)->toBeNull()
        ->and($unratedCdr->billable_seconds)->toBe(0)
        ->and((float) $unratedCdr->rated_cost)->toBe(0.0)
        ->and($unratedCdr->currency)->toBe('USD');

    CallRateRule::query()->create([
        'team_id' => $activeAccount->team_id,
        'name' => 'Euro Rate',
        'destination_prefix' => '+33',
        'rate_per_minute' => 0.15,
        'billing_increment_seconds' => 60,
        'currency' => 'EUR',
    ]);

    $zeroDurationCdr = $service->ingestCdr($activeAccount, [
        'external_id' => 'zero-duration-call-1',
        'source' => '+15551234567',
        'destination' => '+33123456789',
        'started_at' => now()->subMinute()->toISOString(),
        'duration_seconds' => 0,
    ]);

    expect($zeroDurationCdr->billable_seconds)->toBe(0)
        ->and((float) $zeroDurationCdr->rated_cost)->toBe(0.0)
        ->and($zeroDurationCdr->currency)->toBe('EUR');
});
