<?php

use Liberu\Payment\Contracts\DTOs\CreatePaymentIntentCommand;
use Liberu\Payment\Contracts\DTOs\MerchantAccountContextDto;
use Liberu\Payment\Contracts\DTOs\MoneyAmount;
use Liberu\Payment\Contracts\DTOs\RefundPaymentCommand;
use Liberu\Payment\Contracts\Enums\PaymentLifecycleState;
use Liberu\Payment\Stripe\StripePaymentAdapter;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

it('captures and refunds through the updated SDK using the configured Stripe API contract', function () {
    config(['services.stripe.secret' => 'sk_test_compatibility', 'services.stripe.api_version' => '2026-08-26.dahlia']);
    $previousClient = ApiRequestor::httpClient();
    $http = Mockery::mock(ClientInterface::class);
    $headers = fn (array $headers): bool => in_array('Stripe-Version: 2026-08-26.dahlia', $headers, true);
    $http->shouldReceive('request')->once()->with('post', 'https://api.stripe.com/v1/payment_intents', Mockery::on($headers), Mockery::on(fn (array $params): bool => $params['amount'] === 1250 && $params['currency'] === 'usd' && $params['payment_method'] === 'pm_test'), false, 'v1', Mockery::any())
        ->andReturn(['{"id":"pi_test","object":"payment_intent","status":"succeeded","amount":1250,"currency":"usd","client_secret":"pi_test_secret"}', 200, []]);
    $http->shouldReceive('request')->once()->with('post', 'https://api.stripe.com/v1/refunds', Mockery::on($headers), Mockery::on(fn (array $params): bool => $params['payment_intent'] === 'pi_test' && $params['amount'] === 500), false, 'v1', Mockery::any())
        ->andReturn(['{"id":"re_test","object":"refund","amount":500,"currency":"usd"}', 200, []]);
    ApiRequestor::setHttpClient($http);

    try {
        $account = new MerchantAccountContextDto(
            tenantId: 'team-1',
            providerKey: 'stripe',
            credentials: ['secret_key' => 'sk_test_compatibility'],
            apiVersion: '2026-08-26.dahlia',
        );
        $adapter = app(StripePaymentAdapter::class);

        $intent = $adapter->createIntent(new CreatePaymentIntentCommand(
            account: $account,
            amount: new MoneyAmount(1250, 'USD'),
            idempotencyKey: 'idem_stripe_create',
            paymentMethodToken: 'pm_test',
        ));
        $refund = $adapter->refund(new RefundPaymentCommand(
            account: $account,
            providerReference: 'pi_test',
            amount: new MoneyAmount(500, 'USD'),
            idempotencyKey: 'idem_stripe_refund',
        ));

        expect($intent->providerIntentId)->toBe('pi_test')
            ->and($intent->state)->toBe(PaymentLifecycleState::Captured)
            ->and($refund->providerRefundId)->toBe('re_test')
            ->and($refund->state)->toBe(PaymentLifecycleState::Refunded);
    } finally {
        ApiRequestor::setHttpClient($previousClient);
    }
});
