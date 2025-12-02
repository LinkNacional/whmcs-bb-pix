<?php

namespace Lkn\BBPix\App\Pix\Services;

use Lkn\BBPix\App\Pix\Repositories\DiscountRepository;
use Lkn\BBPix\Helpers\Config;
use Lkn\BBPix\Helpers\Invoice;
use Lkn\BBPix\Helpers\Logger;
use WHMCS\Database\Capsule;

/**
 * Responsible for calculating all discounts for the invoice and its items
 * related to products.
 *
 * @since 1.2.0
 */
final class DiscountService
{
    private readonly DiscountRepository $discountRepository;
    private readonly int $clientId;
    private readonly int $invoiceId;
    private readonly ?int $orderId;
    private readonly float $invoiceBalance;
    private readonly array $invoiceItemsWithProductsIds;

    public function __construct(int $invoiceId)
    {
        $this->invoiceId = $invoiceId;
        $this->clientId = Invoice::getClientId($this->invoiceId);
        $this->invoiceBalance = Invoice::getBalance($this->invoiceId);
        $this->discountRepository = new DiscountRepository();

        $this->orderId = (int) (Capsule::table('tblorders')
            ->where('invoiceid', $this->invoiceId)
            ->first('id')
            ->id);

        $this->invoiceItemsWithProductsIds = $this->getInvoiceItemsProductsIds([]);
    }

    public function calculate(): string
    {
        if (
            is_int($this->orderId) &&
            $this->satisfiesProductDiscountRule() &&
            $this->doesAnyProductOnInvoiceHasDiscount()

        ) {
            return $this->calculateInvoiceDiscountBasedOnProductsDiscounts();
        } else {
            return $this->calculateDiscountBasedOnItsTotal();
        }
    }

    private function satisfiesProductDiscountRule(): bool
    {
        $productDiscountRule = Config::setting('product_discount_rule');

        if ($productDiscountRule === 'disabled') {
            return false;
        } elseif ($productDiscountRule === 'first_orders') {
            $clientOrdersCount = Capsule::table('tblorders')
                        ->where('userid', $this->clientId)
                        ->limit(2)
                        ->count();

            return $clientOrdersCount === 1;
        }

        // If orderId is set, them there is an new order for $this->invoiceId.
        return is_int($this->orderId);
    }

    /**
     * Calculates the discount for the invoice based on the discount for Pix
     * payment setting.
     *
     * @since 1.2.0
     *
     * @return string
     */
    private function calculateDiscountBasedOnItsTotal(): string
    {
        $discountForPixPayment = Config::setting('discount_for_pix_payment_percentage');
        $totalDiscount = $discountForPixPayment;

        $ruledDiscountCriteria = Config::setting('ruled_discount_criteria');

        if ($ruledDiscountCriteria !== 'disabled') {
            $hasOrderInPedingStatus = Capsule::table('tblorders')
                ->where('invoiceid', $this->invoiceId)
                ->where('status', 'Pending')
                ->exists();

            if ($hasOrderInPedingStatus) {
                $ruledDiscount = Config::setting('ruled_discount_percentage');

                $totalDiscount += $ruledDiscount;
            }
        }

        $invoiceBalance = $this->invoiceBalance;

        Logger::log(
            'Calcular desconto por pagamento via Pix',
            [
                'discountForPixPayment' => $discountForPixPayment,
                'ruledDiscountCriteria' => $ruledDiscountCriteria,
                'ruledDiscount' => ($ruledDiscount ?? null),
            ],
            ['totalDiscount' => $totalDiscount]
        );

        if ($totalDiscount !== 0) {
            $paymentValueWithDiscount = $invoiceBalance - ($invoiceBalance * $totalDiscount);

            return number_format($paymentValueWithDiscount, 2, '.', '');
        }

        return number_format($invoiceBalance, 2, '.', '');
    }

    /**
     * When a product on invoice has a discount, the discount for that invoice
     * must consider only the discount for the products, not the global discount
     * for Pix payment setting.
     *
     * @since 1.2.0
     *
     * @return string
     */
    private function calculateInvoiceDiscountBasedOnProductsDiscounts(): string
    {
        $invoiceValueWithDiscount = 0.0;

        $invoiceItems = $this->invoiceItemsWithProductsIds;

        foreach ($invoiceItems as $item) {
            $productValue = (float) ($item['amount']);

            $discountPercentage = 0.0;
            $productValueWithDiscount = 0.0;

            if (in_array($item['type'], ['Setup'], true)) {
                continue;
            }

            switch ($item['type']) {
                case 'DomainRegister':
                    $discountPercentage = Config::setting('domain_register_discount_percentage');

                    $productValueWithDiscount = $productValue - ($productValue * $discountPercentage);
                    $invoiceValueWithDiscount += $productValueWithDiscount;

                    break;

                case 'Hosting':
                    $itemRelid = (int) ($item['relid']);
                    $itemId = (int) ($item['id']);

                    $taxes = array_filter(
                        $this->invoiceItemsWithProductsIds,
                        function (array $item) use ($itemRelid, $itemId): bool {
                            return (int) ($item['relid']) === $itemRelid && (int) ($item['id']) !== $itemId;
                        }
                    );

                    $taxesAmount = array_sum(array_column($taxes, 'amount'));

                    $productValue += $taxesAmount;

                    $item['amount'] = $productValue;

                    $response = $this->discountRepository->getPercentageForProductId($item['product_id']);
                    $discountPercentage = isset($response['data']['percentage']) ? ($response['data']['percentage'] / 100) : 0;

                    $productValueWithDiscount = $productValue - ($productValue * $discountPercentage);
                    $invoiceValueWithDiscount += $productValueWithDiscount;

                    break;
                default:
                    if (!isset($item['product_id'])) {
                        $invoiceValueWithDiscount += $productValue;

                        continue;
                    }

                    $response = $this->discountRepository->getPercentageForProductId($item['product_id']);
                    $discountPercentage = isset($response['data']['percentage']) ? ($response['data']['percentage'] / 100) : 0;

                    $productValueWithDiscount = $productValue - ($productValue * $discountPercentage);
                    $invoiceValueWithDiscount += $productValueWithDiscount;

                    break;
            }

            $logTemp = ['product' => $item];

            if (!empty($discountPercentage) && !empty($productValueWithDiscount)) {
                $logTemp['discountPercentage'] = $discountPercentage;
                $logTemp['productValueWithDiscount'] = $productValueWithDiscount;
            }

            $log[] = $logTemp;
        }

        Logger::log(
            'Calcular desconto por produto',
            $log,
            [
                'invoiceValueWithDiscount' => $invoiceValueWithDiscount,
                'invoiceBalance' => $this->invoiceBalance
            ]
        );

        return number_format($invoiceValueWithDiscount, 2, '.', '');
    }

    private function doesAnyProductOnInvoiceHasDiscount(): bool
    {
        foreach ($this->invoiceItemsWithProductsIds as $item) {
            if ($item['type'] === 'DomainRegister') {
                $discountPercentage = Config::setting('domain_register_discount_percentage');

                if ($discountPercentage > 0) {
                    return true;
                }

                continue;
            }

            if (!isset($item['product_id'])) {
                continue;
            }

            $response = $this->discountRepository->getPercentageForProductId($item['product_id']);
            $discountPercentage = isset($response['data']['percentage']) ? ($response['data']['percentage'] / 100) : 0;

            if ($discountPercentage) {
                return true;
            }
        }

        return false;
    }

    /**
     * @since 2.0.0
     *
     * @return array An array of items like: (
     *               [id] =>
     *               [type] =>
     *               [relid] =>
     *               [description] =>
     *               [amount] =>
     *               [taxed] =>
     *               [product_id] =>
     *               )
     *               Some items may not have a product_id since it must be a manually-added product or a taxe.
     */
    private function getInvoiceItemsProductsIds(): array
    {
        $invoiceItems = localAPI('GetInvoice', ['invoiceid' => $this->invoiceId])['items']['item'];

        $takenRelids = [];
        $invoiceItemsWithProductsIds = [];

        foreach ($invoiceItems as $item) {
            $relid = (int) ($item['relid']);

            if (
                in_array($relid, $takenRelids, true)
                || in_array($item['type'], ['DomainRegister', 'Setup'], true)
                || empty($item['type'])
            ) {
                $invoiceItemsWithProductsIds[] = $item;

                continue;
            }

            $takenRelids[] = $relid;

            // https://whmcs.community/topic/294264-api-get-the-product-id-from-getorders-call/#comment-1315022
            $product = Capsule::table('tblhosting')
                ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
                ->where('tblhosting.id', '=', $relid)
                ->first('tblproducts.id as product_id');

            $invoiceItemsWithProductsIds[] = [...$item, 'product_id' => $product->product_id];
        }

        return $invoiceItemsWithProductsIds;
    }
}
