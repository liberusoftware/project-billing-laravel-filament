<?php

declare(strict_types=1);

namespace Liberu\Payment\Contracts\Contracts;

use Liberu\Payment\Contracts\DTOs\BeginPaymentMethodSetupCommand;
use Liberu\Payment\Contracts\DTOs\ConfirmPaymentMethodSetupCommand;
use Liberu\Payment\Contracts\DTOs\DetachPaymentMethodCommand;
use Liberu\Payment\Contracts\DTOs\PaymentMethodSetupDto;
use Liberu\Payment\Contracts\DTOs\VaultedPaymentMethodDto;

interface SavedPaymentMethodVaultContract
{
    public function beginSetup(BeginPaymentMethodSetupCommand $command): PaymentMethodSetupDto;

    public function confirmSetup(ConfirmPaymentMethodSetupCommand $command): VaultedPaymentMethodDto;

    public function detachPaymentMethod(DetachPaymentMethodCommand $command): void;
}
