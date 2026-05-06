<?php

namespace Lkn\BBPix\Tests\Unit\Helpers;

use Lkn\BBPix\Helpers\PayerResolver;
use PHPUnit\Framework\TestCase;

final class PayerResolverTest extends TestCase
{
    public function testResolveClientDataPrefersValidCnpjOverCpf(): void
    {
        $response = PayerResolver::resolveClientData(
            [
                'firstname' => 'Leonardo',
                'lastname' => 'Goularte',
                'companyname' => 'Familiar Imoveis LTDA',
                'customfields' => [
                    ['id' => 1, 'value' => '056.927.799-00'],
                    ['id' => 2, 'value' => '18.504.432/0001-03'],
                ],
            ],
            [
                'cpf_cf_id' => 1,
                'cnpj_cf_id' => 2,
                'cpf_cnpj_cf_id' => 0,
            ],
            true
        );

        self::assertTrue($response['success']);
        self::assertSame('cnpj', $response['data']['payerDocType']);
        self::assertSame('18504432000103', $response['data']['payerDocValue']);
        self::assertSame('Familiar Imoveis Ltda', $response['data']['clientFullName']);
    }

    public function testResolveClientDataUsesCpfWhenCnpjIsMissing(): void
    {
        $response = PayerResolver::resolveClientData(
            [
                'firstname' => 'Leonardo',
                'lastname' => 'Goularte',
                'companyname' => '',
                'customfields' => [
                    ['id' => 1, 'value' => '056.927.799-00'],
                    ['id' => 2, 'value' => ''],
                ],
            ],
            [
                'cpf_cf_id' => 1,
                'cnpj_cf_id' => 2,
                'cpf_cnpj_cf_id' => 0,
            ],
            true
        );

        self::assertTrue($response['success']);
        self::assertSame('cpf', $response['data']['payerDocType']);
        self::assertSame('05692779900', $response['data']['payerDocValue']);
        self::assertSame('Leonardo Goularte', $response['data']['clientFullName']);
    }

    public function testResolveClientDataReturnsProfileUpdateRequiredWhenDocumentIsMissing(): void
    {
        $response = PayerResolver::resolveClientData(
            [
                'firstname' => 'Leonardo',
                'lastname' => 'Goularte',
                'companyname' => '',
                'customfields' => [],
            ],
            [
                'cpf_cf_id' => 1,
                'cnpj_cf_id' => 2,
                'cpf_cnpj_cf_id' => 0,
            ],
            true
        );

        self::assertFalse($response['success']);
        self::assertSame(PayerResolver::PROFILE_UPDATE_REQUIRED_CODE, $response['data']['code']);
        self::assertSame(PayerResolver::PROFILE_UPDATE_REQUIRED_MESSAGE, $response['data']['error']);
        self::assertSame(PayerResolver::PROFILE_DETAILS_URL, $response['data']['profileUrl']);
    }

    public function testResolveClientDataSanitizesDocumentAndTruncatesName(): void
    {
        $response = PayerResolver::resolveClientData(
            [
                'firstname' => str_repeat('A', 210),
                'lastname' => '',
                'companyname' => '',
                'customfields' => [
                    ['id' => 1, 'value' => '056.927.799-00'],
                ],
            ],
            [
                'cpf_cf_id' => 1,
                'cnpj_cf_id' => 2,
                'cpf_cnpj_cf_id' => 0,
            ],
            true
        );

        self::assertTrue($response['success']);
        self::assertSame('05692779900', $response['data']['payerDocValue']);
        self::assertSame(200, mb_strlen($response['data']['clientFullName']));
    }
}