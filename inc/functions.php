<?php
use Directus\Util\ArrayUtils;
use Directus\Database\TableGatewayFactory;

function getTotal($payload)
{
    $coupon = ArrayUtils::get($payload, 'coupon');
    $shipping = ArrayUtils::get($payload, 'shipping');
    $express = ArrayUtils::get($payload, 'express');
    $subtotal = getSubTotal($payload);

    if (empty($coupon)) {
        return $subtotal + getShippingRate($shipping, $express);
    }

    return getSubTotal($payload) + getShippingRate($shipping, $express) - getDiscount($coupon, $subtotal);
}


function getSubTotal($payload)
{
    $cart = ArrayUtils::get($payload, 'cart');
    if (!is_array($cart)) {
        return false;
    }

    $ids = array_map(
        function ($item) { 
            return $item['productid']; 
        }, 
        $cart
    );

    $productsTable = TableGatewayFactory::create('products');
    $products = $productsTable->getItems(
        [
            'ids' => implode(',', $ids),
            'depth' => 3,
        ]
    )['data'];

    if (empty($products)) {
        return false;
    }

    return array_reduce(
        $cart, 
        function ($sum, $item) use ($products) {
            $productid = ArrayUtils::get($item, 'productid');
            $quantity = ArrayUtils::get($item, 'quantity');
            $options = ArrayUtils::get($item, 'options');
            $quoted_price = ArrayUtils::get($item, 'price');
            $price = 0;
            
            $product = _::find(
                $products,
                function ($item) use ($productid) {
                    return ArrayUtils::get($item, 'id')  == $productid;
                }
            );

            if ($product) {
                $price = ArrayUtils::get($product, 'price');
                foreach ($options as $obj) {
                    $option = _::find(
                        ArrayUtils::get($product, 'options.data'), 
                        function ($o) use ($obj) { 
                            return ArrayUtils::get($o, 'option_id.data.slug') == ArrayUtils::get($obj, 'name');
                        }
                    );
                    if ($option) {
                        $optionValue =  _::find(
                            ArrayUtils::get($option, 'option_values.data'), 
                            function ($o) use ($obj) { 
                                return ArrayUtils::get($o, 'id') == ArrayUtils::get($obj, 'value');
                            }
                        );

                        if ($optionValue) {
                            $price += ArrayUtils::get($optionValue, 'price_increment');
                        }
                    }
                    
                }

                if (_::size(_::filter($options, ['name' => 'flavours'])) == 3) {
                    $price += 1000;
                }
            }

            return $sum + ($price * $quantity);
        },
        0
    );
}

function getShippingRate($id, $express = false)
{
    
    if (!is_numeric($id)) {
        return false;
    }

    $shippingRateTable = TableGatewayFactory::create('shipping_rates');
    $shipping = $shippingRateTable->getItems(
        [
            'single' => 1,
            'id' => $id
        ]
    )['data'];

    if (empty($shipping)) {
        return false;
    }

    $cost = floatval($shipping['cost']);
    $shippingType = ArrayUtils::get($shipping, 'shipping_method.data.id');

    //check for express shipping for deliver to me
    if ($shippingType == 3 && $express) {
        $cost += 1000;
    }

    return $cost;
}

function getDiscount($code, $subtotal)
{
    if (! is_string($code)) {
        return false;
    }

    $couponTable = TableGatewayFactory::create('coupons');
    $coupon = $couponTable->getItems(
        [
            'single' => 1,
            'filters' => [
                'code' => ['eq' => $code]
            ]
        ]
    )['data'];

    if (empty($coupon)) {
        return 0;
    }

    if (isset($subtotal) && is_numeric($subtotal)) {
        if ($coupon['type'] == 'percentages') {
            $discount = (floatval($coupon['discount']) / 100) * $subtotal;
        }
    
        if ($coupon['type'] == 'amount') {
            $discount = floatval($coupon['discount']);
        }

        return isset($discount)? $discount : 0;
    }

    return 0;
}

function getPaymentMethods()
{
    $paymentTable = TableGatewayFactory::create('payment_methods');
    return $paymentTable->getItems()['data'];
}

function getShippingMethods()
{
    $shippingRateTable = TableGatewayFactory::create('shipping_rates');
    return $shippingRateTable->getItems(
        [
            'depth' => 2
        ]
    )['data'];
}

function getShippingCities()
{
    $shippingRateTable = TableGatewayFactory::create('shipping_rates');
    $shipping = $shippingRateTable->getItems(
        [
            'depth' => 2
        ]
    )['data'];

    return  array_reduce(
        $shipping, 
        function ($acc, $item) {
            if (ArrayUtils::get($item, 'shipping_method.data.id') != 3) {
                return $acc;
            }

            return array_merge($acc, [ ArrayUtils::get($item, 'title') ]);
        },
        []
    );
}

function getProductOptions($id, $options)
{
    $comment = '';
    $productsTable = TableGatewayFactory::create('products');
    $product = $productsTable->getItems(
        [
            'id' => $id,
            'depth' => 3,
            'single' => 1,
        ]
    )['data'];
    if ($product) {
        foreach ($options as $obj) {
            $option = _::find(
                ArrayUtils::get($product, 'options.data'), 
                function ($o) use ($obj) { 
                    return ArrayUtils::get($o, 'option_id.data.slug') == ArrayUtils::get($obj, 'name');
                }
            );
            if ($option) {
                error_log('options '.json_encode($option));
                $optionValue =  _::find(
                    ArrayUtils::get($option, 'option_values.data'),
                    function ($o) use ($obj) {
                        return ArrayUtils::get($o, 'id') == ArrayUtils::get($obj, 'value');
                    }
                );
                if ($optionValue) {
                    $comment .= ArrayUtils::get($option, 'option_id.data.name').": "
                        .ArrayUtils::get($optionValue, 'option_value.data.value')
                        .PHP_EOL;
                } elseif (!empty(ArrayUtils::get($obj, 'value'))) {
                    $comment .= ArrayUtils::get($option, 'option_id.data.name').": "
                        .ArrayUtils::get($obj, 'value')
                        .PHP_EOL;
                }
            }
        }
    }
    return $comment;
}