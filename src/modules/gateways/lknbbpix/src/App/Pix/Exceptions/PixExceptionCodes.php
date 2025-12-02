<?php

namespace Lkn\BBPix\App\Pix\Exceptions;

enum PixExceptionCodes
{
    case EXTERNAL_API_ERROR;
    case TRIED_TO_PAY_INVOICE_WITH_STATUS_OTHER_THAN_UNPAID;
    case PAYMENT_VALUE_EXCEEDS_INVOICE_BALANCE;
    case COULD_NOT_REGISTER_TRANSACTION_ON_INVOICE;
    case COULD_NOT_CONSULT_PIX_BY_TXID;
    case COULD_NOT_CREATE_PIX_BY_TXID;
    case COULD_NOT_REQUEST_PIX_REFUND;
    case COULD_NOT_CREATE_ACCESS_TOKEN;
    case FOUND_PRODUCTS_WITH_SAME_SAMES_WHEN_CALC_DISCOUNTS;
    case FOUND_PRODUCTS_GROUPS_WITH_SAME_SAMES_WHEN_CALC_DISCOUNTS;
    case INVALID_CPF;
    case INVALID_CNPJ;
    case INVALID_DUE_DATE;
    case COULD_NOT_CANCEL_PIX;

    public function label(): string
    {
        return match ($this) {
            PixExceptionCodes::EXTERNAL_API_ERROR => 'Ocorreu um erro ao se comunicar com a API.',
            PixExceptionCodes::TRIED_TO_PAY_INVOICE_WITH_STATUS_OTHER_THAN_UNPAID => 'Fatura não está com status "não pago."',
            PixExceptionCodes::PAYMENT_VALUE_EXCEEDS_INVOICE_BALANCE => 'O valor do pagamento excede o dispoível para pagamento.',
            PixExceptionCodes::COULD_NOT_REGISTER_TRANSACTION_ON_INVOICE => 'O Pix foi gerado, mas não possível registrar a transação no sistema.',
            PixExceptionCodes::COULD_NOT_CONSULT_PIX_BY_TXID => 'Não foi possível consultar o Pix.',
            PixExceptionCodes::COULD_NOT_CREATE_PIX_BY_TXID => 'Não foi possível criar o Pix.',
            PixExceptionCodes::COULD_NOT_REQUEST_PIX_REFUND => 'Não foi possível solicitar a devolução do Pix.',
            PixExceptionCodes::COULD_NOT_CREATE_PIX_BY_TXID => 'Não foi possível se autenticar.',
            PixExceptionCodes::FOUND_PRODUCTS_WITH_SAME_SAMES_WHEN_CALC_DISCOUNTS => 'Produtos com os mesmos dados foram encontrados. Não é possível gerar o Pix para essa fatura.',
            PixExceptionCodes::FOUND_PRODUCTS_GROUPS_WITH_SAME_SAMES_WHEN_CALC_DISCOUNTS => 'Grupos de produtos com os mesmos dados foram encontrados. Não é possível gerar o Pix para essa fatura.',
            PixExceptionCodes::INVALID_CPF => 'CPF inválido.',
            PixExceptionCodes::INVALID_CNPJ => 'CNPJ inválido.',
            PixExceptionCodes::INVALID_DUE_DATE => 'Não foi possível gerar o PIX, fatura está vencida entre em contato com o financeiro',
            PixExceptionCodes::COULD_NOT_CANCEL_PIX => 'Não foi possível cancelar o Pix.',
            default => 'Erro não identificado.'
        };
    }
}
