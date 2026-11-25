<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Services\PaymentGatewayService;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;

it('charges and refunds through the legacy service with the configured Stripe API contract', function () {
    config(['services.stripe.api_version' => '2026-08-26.dahlia']);
    $previousClient = ApiRequestor::httpClient();
    $previousKey = Stripe::getApiKey();
    $previousVersion = Stripe::getApiVersion();
    $http = Mockery::mock(ClientInterface::class);
    $headers = fn (array $headers): bool => in_array('Stripe-Version: 2026-08-26.dahlia', $headers, true);
    $http->shouldReceive('request')->once()->with('post', 'https://api.stripe.com/v1/charges', Mockery::on($headers), Mockery::on(fn (array $params): bool => $params['amount'] === 1250 && $params['source'] === 'tok_test'), false, 'v1', Mockery::any())
        ->andReturn(['{"id":"ch_test","object":"charge","status":"succeeded","paid":true}', 200, []]);
    $http->shouldReceive('request')->once()->with('post', 'https://api.stripe.com/v1/refunds', Mockery::on($headers), Mockery::on(fn (array $params): bool => $params['charge'] === 'ch_test' && $params['amount'] === 500), false, 'v1', Mockery::any())
        ->andReturn(['{"id":"re_test","object":"refund","status":"succeeded"}', 200, []]);
    ApiRequestor::setHttpClient($http);

    try {
        $invoice = Invoice::factory()->create(['total_amount' => 12.50, 'currency' => 'USD', 'status' => 'pending']);
        $gateway = PaymentGateway::create(['name' => 'Stripe', 'api_key' => 'pk_test', 'secret_key' => 'sk_test_compatibility']);
        $payment = Payment::factory()->create(['invoice_id' => $invoice->id, 'payment_gateway_id' => $gateway->id, 'payment_method' => 'credit_card', 'stripe_token' => 'tok_test', 'amount' => 12.50, 'currency' => 'USD']);
        $service = app(PaymentGatewayService::class);
        $charge = $service->processPayment($payment);

        expect($charge->id)->toBe('ch_test')
            ->and($payment->fresh()->status)->toBe('completed')
            ->and($payment->fresh()->transaction_id)->toBe('ch_test')
            ->and($invoice->fresh()->status)->toBe('paid')
            ->and($service->refundPayment($payment, 5.00))->toMatchArray(['success' => true, 'transaction_id' => 're_test']);
    } finally {
        ApiRequestor::setHttpClient($previousClient);
        Stripe::setApiKey($previousKey);
        Stripe::setApiVersion($previousVersion);
    }
});
