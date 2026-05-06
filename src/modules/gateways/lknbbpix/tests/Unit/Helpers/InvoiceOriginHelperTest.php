<?php

namespace Lkn\BBPix\Tests\Unit\Helpers;

use Lkn\BBPix\Helpers\InvoiceOriginHelper;
use PHPUnit\Framework\TestCase;

final class InvoiceOriginHelperTest extends TestCase
{
    public function testClassifyReturnsNovoPedidoWhenInvoiceHasOrder(): void
    {
        $helper = new class (true, false) extends InvoiceOriginHelper {
            private bool $hasOrderValue;

            private bool $hasRecurringItemsValue;

            public function __construct(bool $hasOrderValue, bool $hasRecurringItemsValue)
            {
                $this->hasOrderValue = $hasOrderValue;
                $this->hasRecurringItemsValue = $hasRecurringItemsValue;
            }

            protected function hasOrder(int $invoiceId): bool
            {
                return $this->hasOrderValue;
            }

            protected function hasRecurringItems(int $invoiceId): bool
            {
                return $this->hasRecurringItemsValue;
            }
        };

        self::assertSame(InvoiceOriginHelper::NOVO_PEDIDO, $helper->classify(10));
    }

    public function testClassifyReturnsCronRenovacaoWhenInvoiceHasRecurringItemsWithoutOrder(): void
    {
        $helper = new class (false, true) extends InvoiceOriginHelper {
            private bool $hasOrderValue;

            private bool $hasRecurringItemsValue;

            public function __construct(bool $hasOrderValue, bool $hasRecurringItemsValue)
            {
                $this->hasOrderValue = $hasOrderValue;
                $this->hasRecurringItemsValue = $hasRecurringItemsValue;
            }

            protected function hasOrder(int $invoiceId): bool
            {
                return $this->hasOrderValue;
            }

            protected function hasRecurringItems(int $invoiceId): bool
            {
                return $this->hasRecurringItemsValue;
            }
        };

        self::assertSame(InvoiceOriginHelper::CRON_RENOVACAO, $helper->classify(11));
    }

    public function testClassifyReturnsManualTradicionalWhenNoOrderAndNoRecurringItems(): void
    {
        $helper = new class (false, false) extends InvoiceOriginHelper {
            private bool $hasOrderValue;

            private bool $hasRecurringItemsValue;

            public function __construct(bool $hasOrderValue, bool $hasRecurringItemsValue)
            {
                $this->hasOrderValue = $hasOrderValue;
                $this->hasRecurringItemsValue = $hasRecurringItemsValue;
            }

            protected function hasOrder(int $invoiceId): bool
            {
                return $this->hasOrderValue;
            }

            protected function hasRecurringItems(int $invoiceId): bool
            {
                return $this->hasRecurringItemsValue;
            }
        };

        self::assertSame(InvoiceOriginHelper::MANUAL_TRADICIONAL, $helper->classify(12));
    }
}
