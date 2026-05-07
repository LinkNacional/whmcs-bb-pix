<?php

namespace Lkn\BBPix\Tests\Unit\Helpers;

use Lkn\BBPix\Helpers\ParserHelper;
use PHPUnit\Framework\TestCase;

final class ParserHelperTest extends TestCase
{
    public function testFindFirstValueReturnsRootValue(): void
    {
        $payload = ['idRec' => 'REC123'];

        self::assertSame('REC123', ParserHelper::findFirstValue($payload, ['idRec', 'id_rec']));
    }

    public function testFindFirstValueReturnsNestedValue(): void
    {
        $payload = ['data' => ['id_rec' => 'REC456']];

        self::assertSame('REC456', ParserHelper::findFirstValue($payload, ['idRec', 'id_rec']));
    }

    public function testFindFirstValueReturnsDeepNestedValue(): void
    {
        $payload = ['a' => ['b' => ['c' => ['status' => 'APROVADA']]]];

        self::assertSame('APROVADA', ParserHelper::findFirstValue($payload, ['status', 'situacao', 'estado']));
    }

    public function testFindFirstValueRespectsKeyPriorityOrder(): void
    {
        $payload = [
            'situacao' => 'REJEITADA',
            'status' => 'APROVADA',
        ];

        self::assertSame('APROVADA', ParserHelper::findFirstValue($payload, ['status', 'situacao']));
    }

    public function testFindFirstValueTrimsWhitespace(): void
    {
        $payload = ['idRec' => '  REC789  '];

        self::assertSame('REC789', ParserHelper::findFirstValue($payload, ['idRec']));
    }

    public function testFindFirstValueIgnoresArrayValueAndFindsScalarLater(): void
    {
        $payload = [
            'idRec' => ['invalid'],
            'meta' => ['idRec' => 'REC_VALID'],
        ];

        self::assertSame('REC_VALID', ParserHelper::findFirstValue($payload, ['idRec']));
    }

    public function testFindFirstValueReturnsEmptyWhenNotFound(): void
    {
        self::assertSame('', ParserHelper::findFirstValue(['foo' => 'bar'], ['idRec']));
    }

    public function testFindAmountFromValorOriginal(): void
    {
        $payload = ['valor' => ['original' => '123.45']];

        self::assertSame(123.45, ParserHelper::findAmount($payload));
    }

    public function testFindAmountFromValorScalar(): void
    {
        $payload = ['valor' => '77.90'];

        self::assertSame(77.90, ParserHelper::findAmount($payload));
    }

    public function testFindAmountFromNestedCobr(): void
    {
        $payload = ['cobr' => ['valor' => '11.30']];

        self::assertSame(11.30, ParserHelper::findAmount($payload));
    }

    public function testFindAmountFromPixArray(): void
    {
        $payload = ['pix' => [['valor' => '50.00']]];

        self::assertSame(50.00, ParserHelper::findAmount($payload));
    }

    public function testFindAmountReturnsZeroWhenMissing(): void
    {
        self::assertSame(0.0, ParserHelper::findAmount(['foo' => 'bar']));
    }

    public function testExtractInvoiceIdFromDeterministicTxid(): void
    {
        $txid = 'LKN0000000123ABCDE12345ABC';

        self::assertSame(123, ParserHelper::extractInvoiceIdFromTxid($txid));
    }

    public function testExtractInvoiceIdFromDeterministicTxidIsCaseInsensitive(): void
    {
        $txid = 'lkn0000000456abcde12345abc';

        self::assertSame(456, ParserHelper::extractInvoiceIdFromTxid($txid));
    }

    public function testExtractInvoiceIdFromDeterministicTxidWithZeroReturnsZero(): void
    {
        $txid = 'LKN0000000000ABCDE12345ABC';

        self::assertSame(0, ParserHelper::extractInvoiceIdFromTxid($txid));
    }

    public function testExtractInvoiceIdFromRealDeterministicTxid(): void
    {
        $txid = 'LKN0000065362ACADEA96976C8';

        self::assertSame(65362, ParserHelper::extractInvoiceIdFromTxid($txid));
    }

    public function testExtractInvoiceIdFromLegacyTxid(): void
    {
        $txid = '987xlegacysuffix';

        self::assertSame(987, ParserHelper::extractInvoiceIdFromTxid($txid));
    }

    public function testExtractInvoiceIdFromInvalidTxidReturnsZero(): void
    {
        self::assertSame(0, ParserHelper::extractInvoiceIdFromTxid('INVALID_TXID'));
    }

    public function testExtractInvoiceIdFromEmptyTxidReturnsZero(): void
    {
        self::assertSame(0, ParserHelper::extractInvoiceIdFromTxid(''));
    }
}