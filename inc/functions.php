<?php
use Directus\Mail\Mail;
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
    $methods = $paymentTable->getItems(
        [
            "columns" => "id,name,description,mode"
        ]
    )['data'];
    return $methods;
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

function sendConfirmation($id) 
{
    $ordersTable = TableGatewayFactory::create('orders');
    $record=  $ordersTable->getItems(
        [
            'id' => $id,
            'single' => 1,
            'depth' => 2
        ]
    )['data'];
    if ($record) {

        $order = [
            'id' => $id,
            'total' => ArrayUtils::get($record, 'total')
        ];

        if (ArrayUtils::get($record, 'status') == "processing") {
            $message = '<p class="es-p10">Your payment has been received and order been processed</p>';
        } else {
            $message = '<p class="es-p10">Your order has been received and will be processed when payment is confirmed.'.
             '<p class="es-p10">Please find our bank details below.</p>'.
             '<p class="es-p10">You can send proof of payment to this email address  for confirmation</p>';
        }

        $order['user'] = "{$record['first_name']} {$record['last_name']}".
        "<br>{$record['email']}".
        "<br>{$record['phone']}";

        $order['items'] = array_map(
            function ($item) {
                return [
                    'name' => ArrayUtils::get($item, 'product_id.data.name'),
                    'quantity' => ArrayUtils::get($item, 'quantity'),
                    'amount' => ArrayUtils::get($item, 'sub_total')
                ];
            },
            ArrayUtils::get($record, 'order_items.data')
        );

        $shipping =  _::find(
            ArrayUtils::get($record, 'order_totals.data'),
            function ($o) {
                return ArrayUtils::get($o, 'item') == 'shipping';
            }
        );
        
        if ($shipping) {
            $order['shipping'] = ArrayUtils::get($shipping, 'total');
        }

        $discount =  _::find(
            ArrayUtils::get($record, 'order_totals.data'),
            function ($o) {
                return ArrayUtils::get($o, 'item') == 'item';
            }
        );
        
        if ($discount) {
            $order['discount'] = ArrayUtils::get($discount, 'total');
        }

        if (ArrayUtils::get($record, 'express') == "1") {
            $order['deliveryDate'] = "Within 24 Hours";
        } else {
            $order['deliveryDate'] = ArrayUtils::get($record, 'delivery_date').
            '<br>'.ArrayUtils::get($record, 'delivery_time');
        }

        if (ArrayUtils::has($record, 'shipping_method.data.shipping_method') 
            && ArrayUtils::get($record, 'shipping_method.data.shipping_method.data.id') == 3
        ) {
            $order['deliveryAddress'] = ArrayUtils::get($record, 'address').
            '<br>Off: '.ArrayUtils::get($record, 'landmark').
            '<br>'.ArrayUtils::get($record, 'city');
        } else {
            $order['deliveryAddress'] = ArrayUtils::get($record, 'shipping_method.data.title');
        }

        if (ArrayUtils::get($record, 'payment_method.data.id') == 1) {
            $order['payment'] = "Transfer";
        } else {
            $order['payment'] ="Paystack";
        }
        
    }
    $data = [
        'message' => $message,
        'logo' => 'https://www.butterbakescakes.com/static/images/logo.png',
        'store' => 'https://www.butterbakescakes.com/#/products',
        'contactLink' => 'http://www.butterbakescakes.com/#/contact',
        'orderLink' => 'http://www.butterbakescakes.com/#/orders/'.$id,
        'order' => $order? $order : [],
        'type' => 'customer'
    ];

    if (!empty(ArrayUtils::get($record, 'email'))) {
        $subject = "ButterBakes Cakes: Order Received";
        Mail::send(
            'mail/order-confirmation.twig',
            $data,
            function (Swift_Message $message) use ($record, $subject) {
                $message->setSubject($subject);
                $message->setTo(ArrayUtils::get($record, 'email'));
            }
        );
    }
    
    $subject = "New Order";
    $data['type'] = 'admin';
    Mail::send(
        'mail/order-confirmation.twig',
        $data,
        function (Swift_Message $message) use ($record, $subject) {
            $message->setSubject($subject);
            $message->setTo(ArrayUtils::get($record, 'email'));
        }
    );
}