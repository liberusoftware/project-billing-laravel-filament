<?php

use Liberu\Billing\Payments\Models\Payment;
use Liberu\Billing\Stripe\StripeGatewayDriver;
use Liberu\Billing\Stripe\StripeServiceProvider;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;

it('captures and refunds through the updated SDK using the configured Stripe API contract', function () {
    config(['services.stripe.secret' => 'sk_test_compatibility', 'services.stripe.api_version' => '2026-08-26.dahlia']);
    app()->forgetInstance(StripeClient::class);
    new StripeServiceProvider(app())->register();
    $previousClient = ApiRequestor::httpClient();
    $http = Mockery::mock(ClientInterface::class);
    $headers = fn (array $headers): bool => in_array('Stripe-Version: 2026-08-26.dahlia', $headers, true);
    $http->shouldReceive('request')->once()->with('post', 'https://api.stripe.com/v1/payment_intents', Mockery::on($headers), Mockery::on(fn (array $params): bool => $params['amount'] === 1250 && $params['currency'] === 'usd' && $params['payment_method'] === 'pm_test'), false, 'v1', Mockery::any())
        ->andReturn(['{"id":"pi_test","object":"payment_intent","status":"succeeded"}', 200, []]);
    $http->shouldReceive('request')->once()->with('post', 'https://api.stripe.com/v1/refunds', Mockery::on($headers), Mockery::on(fn (array $params): bool => $params['payment_intent'] === 'pi_test' && $params['amount'] === 500), false, 'v1', Mockery::any())
        ->andReturn(['{"id":"re_test","object":"refund"}', 200, []]);
    ApiRequestor::setHttpClient($http);

    try {
        $payment = new Payment(['amount_minor' => 1250, 'currency' => 'USD', 'metadata' => ['stripe_payment_method' => 'pm_test'], 'provider_reference' => 'pi_test']);
        $driver = app(StripeGatewayDriver::class);

        expect($driver->capture($payment))->toBe(['reference' => 'pi_test'])
            ->and($driver->refund($payment, 500))->toBe(['reference' => 're_test']);
    } finally {
        ApiRequestor::setHttpClient($previousClient);
    }
});
