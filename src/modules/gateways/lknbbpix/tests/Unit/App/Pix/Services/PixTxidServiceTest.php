<?php

namespace Lkn\BBPix\Tests\Unit\App\Pix\Services;

use Lkn\BBPix\App\Pix\Services\PixTxidService;
use PHPUnit\Framework\TestCase;

final class PixTxidServiceTest extends TestCase
{
    public function testGenerateForInvoiceReturnsExactlyTwentySixCharacters(): void
    {
        $txid = PixTxidService::generateForInvoice(123);

        self::assertSame(26, strlen($txid));
    }

    public function testGenerateForInvoiceReturnsUppercaseAlphanumericString(): void
    {
        $txid = PixTxidService::generateForInvoice(123);

        self::assertMatchesRegularExpression('/^[A-Z0-9]{26}$/', $txid);
    }

    public function testGenerateForInvoiceIsDeterministicForSameInvoiceId(): void
    {
        $firstTxid = PixTxidService::generateForInvoice(123);
        $secondTxid = PixTxidService::generateForInvoice(123);

        self::assertSame($firstTxid, $secondTxid);
    }

    public function testGenerateForInvoiceReturnsDifferentValuesForDifferentInvoiceIds(): void
    {
        $firstTxid = PixTxidService::generateForInvoice(123);
        $secondTxid = PixTxidService::generateForInvoice(124);

        self::assertNotSame($firstTxid, $secondTxid);
    }
}