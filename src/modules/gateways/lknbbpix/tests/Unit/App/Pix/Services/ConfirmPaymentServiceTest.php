<?php

namespace Lkn\BBPix\Tests\Unit\App\Pix\Services;

use Lkn\BBPix\App\Pix\Entity\PixTaxId;
use Lkn\BBPix\App\Pix\Services\ConfirmPaymentService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ConfirmPaymentServiceTest extends TestCase
{
    public function testResolveTransactionIdPreservesOriginalEndToEndIdCasing(): void
    {
        $service = new ConfirmPaymentService();
        $pixTaxId = PixTaxId::fromDeterministicTxid('LKN0000065552EBF9CC24C6DC9', 'PAGO');

        $method = new ReflectionMethod(ConfirmPaymentService::class, 'resolveTransactionId');
        $method->setAccessible(true);

        $transactionId = $method->invoke(
            $service,
            'E18236120202605111625s085d096fe7',
            'LKN0000065552EBF9CC24C6DC9',
            $pixTaxId
        );

        self::assertSame(
            'PAGOxLKN0000065552EBF9CC24C6DC9xE18236120202605111625s085d096fe7',
            $transactionId
        );
    }

    public function testBuildPaymentLockKeyRemainsCaseInsensitive(): void
    {
        $service = new ConfirmPaymentService();

        $method = new ReflectionMethod(ConfirmPaymentService::class, 'buildPaymentLockKey');
        $method->setAccessible(true);

        $lowercaseKey = $method->invoke(
            $service,
            65552,
            'E18236120202605111625s085d096fe7',
            'PAGOxLKN0000065552EBF9CC24C6DC9xE18236120202605111625s085d096fe7'
        );

        $uppercaseKey = $method->invoke(
            $service,
            65552,
            'E18236120202605111625S085D096FE7',
            'PAGOxLKN0000065552EBF9CC24C6DC9xE18236120202605111625S085D096FE7'
        );

        self::assertSame($lowercaseKey, $uppercaseKey);
    }
}
