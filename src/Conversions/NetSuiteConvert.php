<?php

declare(strict_types=1);

namespace Anibalealvarezs\NetSuiteHubDriver\Conversions;

use Anibalealvarezs\ApiDriverCore\Conversions\UniversalEntityConverter;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * NetSuiteConvert
 * 
 * Standardizes NetSuite entity data (Customers, Orders, Products, etc.)
 * into APIs Hub objects using the UniversalEntityConverter.
 */
class NetSuiteConvert
{
    public static function customers(array $customers): ArrayCollection
    {
        $addressLineKeys = [
            'addressaddr1', 'addressaddr2', 'addresscity', 'addresscountry',
            'addressstate', 'addressdropdownstate', 'addresszip', 'addressaddressee',
            'addressphone', 'addresstext', 'addressattention', 'addressoverride',
            'addressrecordowner'
        ];

        $customersArray = [];
        foreach ($customers as $customer) {
            $entityId = $customer['entityid'] ?? null;
            if (!$entityId) {
                continue;
            }
            if (!isset($customersArray[$entityId])) {
                $customerData = array_diff_key($customer, array_flip($addressLineKeys));
                $customerData['addresses'] = [];
                $customersArray[$entityId] = [
                    'entityid' => $entityId,
                    'email' => $customerData['email'] ?? '',
                    'datecreated' => $customerData['datecreated'] ?? '',
                    'data' => $customerData,
                ];
            }
            $address = array_intersect_key($customer, array_flip($addressLineKeys));
            $customersArray[$entityId]['data']['addresses'][] = $address;
        }

        return UniversalEntityConverter::convert(array_values($customersArray), [
            'channel' => 'netsuite',
            'platform_id_field' => 'entityid',
            'date_field' => 'datecreated',
            'mapping' => [
                'email' => 'email',
                'data' => 'data',
            ],
        ]);
    }

    public static function discounts(array $discounts): ArrayCollection
    {
        return UniversalEntityConverter::convert($discounts, [
            'channel' => 'netsuite',
            'platform_id_field' => 'id',
            'date_field' => 'created_at',
            'mapping' => [
                'code' => 'code',
            ],
        ]);
    }

    public static function priceRules(array $priceRules): ArrayCollection
    {
        return UniversalEntityConverter::convert($priceRules, [
            'channel' => 'netsuite',
            'platform_id_field' => 'id',
            'date_field' => 'created_at',
        ]);
    }

    public static function orders(array $orders): ArrayCollection
    {
        $transactionLineKeys = [
            'itemid', 'itemwebstoredesignitem', 'itemparent', 'itemsku',
            'transactionlineactualshipdate', 'transactionlineclosedate', 'transactionlinecostestimate',
            'transactionlinecostestimaterate', 'transactionlinecostestimatetype', 'transactionlinecreditforeignamount',
            'transactionlinedesigncode', 'transactionlinedesignmarket', 'transactionlinepromocode',
            'transactionlineestgrossprofit', 'transactionlineestgrossprofitpercent', 'transactionlineexpenseaccount',
            'transactionlineexpectedshipdate', 'transactionlineforeignamount', 'transactionlineid',
            'transactionlineisclosed', 'transactionlineisfullyshipped', 'transactionlineitemtype',
            'transactionlinelastmodifieddate', 'transactionlinesequencenumber', 'transactionlinememo',
            'transactionlinenetamount', 'transactionlineprice', 'transactionlinequantity',
            'transactionlinequantitybackordered', 'transactionlinequantitybilled', 'transactionlinequantitypacked',
            'transactionlinequantitypicked', 'transactionlinequantityrejected', 'transactionlinequantityshiprecv',
            'transactionlinerate', 'transactionlinerateamount', 'transactionlineuniquekey'
        ];

        $ordersArray = [];
        foreach ($orders as $order) {
            $orderId = $order['id'] ?? null;
            if (!$orderId) {
                continue;
            }
            if (!isset($ordersArray[$orderId])) {
                $orderWithoutTransactionLine = array_diff_key($order, array_flip($transactionLineKeys));
                $ordersArray[$orderId] = [
                    'id' => $orderId,
                    'created_at' => $orderWithoutTransactionLine['createddate'] ?? '',
                    'data' => [
                        ...$orderWithoutTransactionLine,
                        'line_items' => [],
                        'taxTotal' => 0,
                        'shippingHandlingTotal' => 0,
                        'discountTotal' => 0,
                        'subtotalBeforeDiscounts' => 0,
                        'subtotalAfterDiscounts' => 0,
                    ],
                    'line_items' => [],
                    'discounts' => ($order['promotioncodename'] ?? ($order['PromotionCodeName'] ?? null)) ? [($order['promotioncodename'] ?? $order['PromotionCodeName'])] : [],
                ];
            }
            $transactionLine = array_intersect_key($order, array_flip($transactionLineKeys));
            $itemType = $transactionLine['transactionlineitemtype'] ?? ($transactionLine['TransactionLineItemType'] ?? null);
            
            switch ($itemType) {
                case 'TaxItem':
                    $val = (float)($transactionLine['transactionlineforeignamount'] ?? ($transactionLine['TransactionLineForeignAmount'] ?? 0));
                    $ordersArray[$orderId]['data']['taxTotal'] -= $val;
                    break;
                case 'ShipItem':
                    $val = (float)($transactionLine['transactionlineforeignamount'] ?? ($transactionLine['TransactionLineForeignAmount'] ?? 0));
                    $ordersArray[$orderId]['data']['shippingHandlingTotal'] -= $val;
                    break;
                case 'Discount':
                    $val = (float)($transactionLine['transactionlineforeignamount'] ?? ($transactionLine['TransactionLineForeignAmount'] ?? 0));
                    $ordersArray[$orderId]['data']['discountTotal'] += $val;
                    break;
                case 'Assembly':
                case 'NonInvtPart':
                    $ordersArray[$orderId]['data']['line_items'][] = $transactionLine;
                    break;
            }
            $foreigntotal = (float)($ordersArray[$orderId]['data']['foreigntotal'] ?? ($ordersArray[$orderId]['data']['ForeignTotal'] ?? 0));
            $ordersArray[$orderId]['data']['subtotalAfterDiscounts'] = $foreigntotal - $ordersArray[$orderId]['data']['taxTotal'] - $ordersArray[$orderId]['data']['shippingHandlingTotal'];
            $ordersArray[$orderId]['data']['subtotalBeforeDiscounts'] = $ordersArray[$orderId]['data']['subtotalAfterDiscounts'] + $ordersArray[$orderId]['data']['discountTotal'];
        }

        return UniversalEntityConverter::convert(array_values($ordersArray), [
            'channel' => 'netsuite',
            'platform_id_field' => 'id',
            'date_field' => 'created_at',
            'mapping' => [
                'data' => 'data',
                'customer' => fn ($r) => (object) [
                    'id' => $r['data']['entity'] ?? '',
                    'email' => $r['data']['customeremail'] ?? ($r['data']['CustomerEmail'] ?? ''),
                ],
                'discountCodes' => 'discounts',
            ],
        ]);
    }

    public static function products(array $products): ArrayCollection
    {
        $productsArray = [];
        foreach ($products as $product) {
            if (($product['itemtype'] === 'Assembly') && (!isset($product['parent']) || !isset($product['custitem_web_store_design_item']))) {
                continue;
            }
            if ($product['itemtype'] === 'NonInvtPart') {
                if (!isset($productsArray[$product['id']])) {
                    $productsArray[$product['id']] = [
                        'id' => $product['id'],
                        'sku' => $product['itemid'] ?? '',
                        'created_at' => $product['createddate'] ?? '',
                        'design_id' => $product['custitem_design_code'] ?? '',
                        'vendors' => isset($product['vendorname']) && $product['vendorname'] ? [['name' => $product['vendorname'], 'data' => []]] : [],
                        'variants' => [],
                        'categories' => isset($product['commercecategoryid']) ? [['id' => $product['commercecategoryid'], 'data' => []]] : [],
                        'data' => $product,
                    ];
                }
                continue;
            }
            $webStoreDesignItem = $product['custitem_web_store_design_item'] ?? null;
            if (($product['itemtype'] === 'Assembly') && $webStoreDesignItem && !isset($productsArray[$webStoreDesignItem])) {
                $productsArray[$webStoreDesignItem] = [
                    'id' => $webStoreDesignItem,
                    'sku' => '', 'created_at' => '',
                    'design_id' => $product['custitem_design_code'] ?? '',
                    'vendors' => isset($product['vendorname']) && $product['vendorname'] ? [['name' => $product['vendorname'], 'data' => []]] : [],
                    'variants' => [],
                    'categories' => isset($product['commercecategoryid']) ? [['id' => $product['commercecategoryid'], 'data' => []]] : [],
                    'data' => [],
                ];
            }
            if (($product['itemtype'] === 'Assembly') && $webStoreDesignItem) {
                $productsArray[$webStoreDesignItem]['variants'][] = $product;
            }
        }

        return UniversalEntityConverter::convert(array_values($productsArray), [
            'channel' => 'netsuite',
            'platform_id_field' => 'id',
            'date_field' => 'created_at',
            'mapping' => [
                'sku' => 'sku',
                'data' => 'data',
                'vendor' => fn ($r) => self::vendors($r['vendors'])->first(),
                'variants' => fn ($r) => self::productVariants($r['variants'] ?? []),
                'categories' => fn ($r) => self::productCategories($r['categories'] ?? []),
            ],
        ]);
    }

    public static function productVariants(array $productVariants): ArrayCollection
    {
        return UniversalEntityConverter::convert($productVariants, [
            'channel' => 'netsuite',
            'platform_id_field' => 'id',
            'date_field' => 'createddate',
            'mapping' => [
                'sku' => 'itemid',
                'data' => 'data',
            ],
        ]);
    }

    public static function productCategories(array $productCategories): ArrayCollection
    {
        return UniversalEntityConverter::convert($productCategories, [
            'channel' => 'netsuite',
            'platform_id_field' => 'id',
            'date_field' => 'created',
            'mapping' => [
                'data' => 'data',
                'isSmartCollection' => fn ($r) => false,
            ],
        ]);
    }

    public static function vendors(array $vendors): ArrayCollection
    {
        return UniversalEntityConverter::convert($vendors, [
            'channel' => 'netsuite',
            'platform_id_field' => 'id',
            'date_field' => 'created',
            'mapping' => [
                'name' => 'name',
                'data' => 'data',
            ],
        ]);
    }
}
