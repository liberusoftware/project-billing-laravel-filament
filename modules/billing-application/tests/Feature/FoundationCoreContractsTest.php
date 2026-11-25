<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Liberu\Foundation\FeatureFlags\Support\FlagEvaluator;
use Liberu\Foundation\Localization\Context\LocaleContext;
use Liberu\Foundation\Localization\Context\LocaleResolver;
use Liberu\Foundation\Localization\Formatting\LocaleFormatter;
use Liberu\Foundation\Localization\Http\Middleware\SetLocale;
use Liberu\Foundation\Localization\Translation\TranslationRegistry;
use Liberu\Localization\Contracts\TranslationProvider;
use Liberu\Localization\Contracts\TranslationProviderRegistry;

it('resolves projected application users safely and attaches request locale context', function () {
    config(['app.locale' => 'en', 'app.timezone' => 'UTC', 'localization.locales' => ['en' => 'English', 'ar' => 'Arabic'], 'localization.rtl_locales' => ['ar'], 'localization.site_locale' => 'en', 'localization.site_timezone' => 'UTC', 'localization.team_locale' => null, 'localization.team_timezone' => null]);
    Session::flush();
    $actor = User::factory()->create();
    auth()->setUser(User::query()->select(['id', 'name'])->findOrFail($actor->id));
    $previous = Model::preventsAccessingMissingAttributes();
    Model::preventAccessingMissingAttributes();
    try {
        expect(app(LocaleResolver::class)->resolve(Request::create('/'))->payload())->toBe(['locale' => 'en', 'timezone' => 'UTC']);
    } finally {
        Model::preventAccessingMissingAttributes($previous);
    }
    $context = app(LocaleResolver::class)->resolve(Request::create('/', 'GET', ['locale' => 'ar']));
    expect($context->payload())->toBe(['locale' => 'ar', 'timezone' => 'UTC'])->and($context->direction)->toBe('rtl');
    $request = Request::create('/', 'GET', ['locale' => 'en']);
    $response = (new SetLocale())->handle($request, fn () => response('ok', 202));
    expect($response->getStatusCode())->toBe(202)->and(Session::get('locale'))->toBe('en')
        ->and($request->attributes->get(LocaleContext::class)->locale)->toBe('en');
    Session::flush();
    auth()->forgetUser();
    config(['localization.locales' => [], 'localization.site_timezone' => 'Invalid/Timezone']);
    expect(app(LocaleResolver::class)->resolve(Request::create('/'))->payload())->toBe(['locale' => 'en', 'timezone' => 'UTC']);
});

it('formats localized display values through the installed core', function () {
    $formatter = new LocaleFormatter();
    expect($formatter->number(1234.5, 'de_DE'))->toBe('1.234,5')
        ->and($formatter->date(new DateTimeImmutable('2026-01-01T00:30:00Z'), 'en_US', 'America/Los_Angeles'))->toBe('Dec 31, 2025')
        ->and($formatter->list(['One', 'Two'], 'en'))->toBe('One and Two');
});

it('shares the translation registry behind its public contract and rejects duplicate or unknown names', function () {
    $registry = app(TranslationProviderRegistry::class);
    expect($registry)->toBe(app(TranslationRegistry::class));
    $provider = new class() implements TranslationProvider
    {
        public function name(): string
        {
            return 'host-contract-test';
        }

        public function translate(string $text, string $targetLanguage, string $sourceLanguage = 'en'): string
        {
            return $text;
        }

        public function translateBatch(array $texts, string $targetLanguage, string $sourceLanguage = 'en'): array
        {
            return $texts;
        }

        public function supportedLanguages(): array
        {
            return ['en' => 'English'];
        }
    };
    $registry->register($provider);
    expect($registry->get('host-contract-test'))->toBe($provider)->and($registry->all())->toHaveKey('host-contract-test')
        ->and(fn () => $registry->register($provider))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->get('unknown-host-provider'))->toThrow(InvalidArgumentException::class);
});

it('preserves feature expiry environment targeting and rollout boundaries in the host', function () {
    $flags = new FlagEvaluator();
    expect($flags->enabled('release', [], 'production'))->toBeFalse()
        ->and($flags->enabled('release', ['enabled' => true, 'expires_at' => '2000-01-01'], 'production'))->toBeFalse()
        ->and($flags->enabled('release', ['enabled' => true, 'environments' => ['staging']], 'production'))->toBeFalse()
        ->and($flags->enabled('release', ['enabled' => true, 'percentage' => 0, 'tenants' => [4]], 'production', 4))->toBeTrue()
        ->and($flags->enabled('release', ['enabled' => true, 'percentage' => 0, 'actors' => [7]], 'production', actor: 7))->toBeTrue()
        ->and($flags->enabled('release', ['enabled' => true, 'percentage' => 100], 'production'))->toBeTrue()
        ->and($flags->enabled('release', ['enabled' => true, 'percentage' => 0], 'production'))->toBeFalse();
});
