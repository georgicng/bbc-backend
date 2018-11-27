<?php

use Directus\Bootstrap;
use Directus\View\JsonView;
use Directus\Database\TableGateway\RelationalTableGateway as TableGateway;
use Directus\Services\EntriesService;
use Directus\Util\ArrayUtils;
use Directus\Database\TableGatewayFactory;

// Slim App
$app = Bootstrap::get('app');

require_once BASE_PATH.'/inc/functions.php';

// Submit Order endpoint
$app->post(
    '/orders',
    function () use ($app) {
        $entriesService = new EntriesService($app);
        $ZendDb = $app->container->get('zenddb');
        $acl = $app->container->get('acl');
        $payload = $app->request()->post();
        $params = $app->request()->get();
        //save order, check if id=0 else update

        $orderID = ArrayUtils::get($payload, 'id');

        if (isset($orderID)) {
            //update order
            $table = 'orders';
            $tableGateway = new TableGateway($table, $ZendDb, $acl); 
            $order = [
                'id' => ArrayUtils::get($payload, 'id'),
                'shipping_method' => ArrayUtils::get($payload, 'shipping'),
                'payment_method' => ArrayUtils::get($payload, 'payment'),
                'delivery_time' => ArrayUtils::get($payload, 'delivery_time'),
                'delivery_date' => ArrayUtils::get($payload, 'delivery_date'),
                'express_delivery' => ArrayUtils::get($payload, 'express')? "1" : "0",
                'status' => 'created',
                'reference' => uniqid(),
                'total' => getTotal($payload)
            ];
            //get address from request
            $address = ArrayUtils::get($payload, 'address');

            if (ArrayUtils::get($payload, 'address.city') == 'Other') {
                $address['city'] = ArrayUtils::get($payload, 'address.alt_city');
            }
                   
            $tableGateway->updateRecord(array_merge($order, $address), 1);
            $orderID = ArrayUtils::get($payload, 'id');
            
            //delete previous order details
            $table = 'order_details';
            $tableGateway = new TableGateway($table, $ZendDb, $acl);
            $condition = [
                'order_id' => $orderID
            ];
            if ($tableGateway->delete($condition)) {
                //save order items
                $items = $payload['cart'];
                
                foreach ($items as $row) {
                    $record = [
                        "order_id" => $orderID,
                        "product_id" => $row['productid'],
                        "quantity" => $row['quantity'],
                        "price" => $row['price'], //don't use client-side quoted price, calculate price using given options
                        "sub_total" => floatval($row['price']) * intval($row['quantity']),
                        'options' => getProductOptions($row['productid'], $row['options'])
                    ];
                    error_log("Order detail: ".json_encode($record));
                    $entriesService->createEntry($table, $record, $params);
                }
            }
            
            //delete previous totals
            
            //delete previous order details
            $table = 'order_totals';
            $tableGateway = new TableGateway($table, $ZendDb, $acl);
            $condition = [
                'order_id' => $orderID
            ];
            if ($tableGateway->delete($condition)) {
                $coupon = ArrayUtils::get($payload, 'coupon');
                $shipping = ArrayUtils::get($payload, 'shipping');
                $express = ArrayUtils::get($payload, 'express');
                $subtotal = getSubTotal($payload);
                $table = 'order_totals';
                $record = [
                    "order_id" => $orderID,
                    "item" => 'shipping',
                    "total" => getShippingRate($shipping, $express)
                ];
            
                $entriesService->createEntry($table, $record, $params);
            
                $record = [
                    "order_id" => $orderID,
                    "item" => 'sub_total',
                    "total" => $subtotal
                ];
            
                $entriesService->createEntry($table, $record, $params);
    
                if ($coupon) {
                    $record = [
                        "order_id" => $orderID,
                        "item" => 'discount',
                        "total" => getDiscount($coupon, $subtotal)
                    ];
                
                    $entriesService->createEntry($table, $record, $params);
                }
            }
        } else {
            //create order - TODO check if shipping and payment is set and validate address
            $table = 'orders';
            $order = [
                'shipping_method' => ArrayUtils::get($payload, 'shipping'),
                'payment_method' => ArrayUtils::get($payload, 'payment'),
                'delivery_time' => ArrayUtils::get($payload, 'delivery_time'),
                'delivery_date' => ArrayUtils::get($payload, 'delivery_date'),
                'express_delivery' => ArrayUtils::get($payload, 'express')? "1" : "0",
                'status' => 'created',
                'reference' => uniqid(),
                'total' => getTotal($payload)
            ];
            //get address from request
            $address = ArrayUtils::get($payload, 'address');

            if (ArrayUtils::get($payload, 'address.city') == 'Other') {
                $address['city'] = ArrayUtils::get($payload, 'address.alt_city');
            }
        
            $tableGateway = new TableGateway($table, $ZendDb, $acl);
            $newRecord = $entriesService->createEntry($table, array_merge($order, $address), $params);
            $primaryKey = $tableGateway->primaryKeyFieldName;
            $orderID = ArrayUtils::get($newRecord->toArray(), $primaryKey);
            error_log("order Id: ".json_encode($orderID));
            
        
            //save order items
            $items = $payload['cart'];
            $table = 'order_details';
            $tableGateway = new TableGateway($table, $ZendDb, $acl);
            foreach ($items as $row) {
                $record = [
                    "order_id" => $orderID,
                    "product_id" => $row['productid'],
                    "quantity" => $row['quantity'],
                    "price" => $row['price'], //don't use client-side quoted price, calculate price using given options
                    "sub_total" => floatval($row['price']) * intval($row['quantity']),
                    'options' => getProductOptions($row['productid'], $row['options'])
                ];
                error_log("Order detail: ".json_encode($record));
                $entriesService->createEntry($table, $record, $params);
            }
        
            //save totals
            $coupon = ArrayUtils::get($payload, 'coupon');
            $shipping = ArrayUtils::get($payload, 'shipping');
            $express = ArrayUtils::get($payload, 'express');
            $subtotal = getSubTotal($payload);
            $table = 'order_totals';
            $record = [
                "order_id" => $orderID,
                "item" => 'shipping',
                "total" => getShippingRate($shipping, $express)
            ];
        
            $entriesService->createEntry($table, $record, $params);
        
            $record = [
                "order_id" => $orderID,
                "item" => 'sub_total',
                "total" => $subtotal
            ];
        
            $entriesService->createEntry($table, $record, $params);

            if ($coupon) {
                $record = [
                    "order_id" => $orderID,
                    "item" => 'discount',
                    "total" => getDiscount($coupon, $subtotal)
                ];
            
                $entriesService->createEntry($table, $record, $params);
            }           
           
        }

        //return payment info and order details
        $table = "payment_method_meta";
        $tableGateway = new TableGateway($table, $ZendDb, $acl);
        $payment_meta =  $tableGateway->getItems(
            [
                'filters' => [
                    'payment_method' => ['eq' => $payload['payment']],
                    'key' => ['ncontains' => 'private']
                ]
            ]
        )['data'];

        return $app->response(
            [
                'order_id' => $orderID,
                'payment_meta' => $payment_meta,
                'total' => getTotal($payload),
                'reference' => $order['reference']
            ]
        );
    }
);

$app->put(
    '/orders/:id', 
    function ($id) use ($app) {
        $entriesService = new EntriesService($app);
        $ZendDb = $app->container->get('zenddb');
        $acl = $app->container->get('acl');
        $payload = $app->request()->post();
        $params = $app->request()->get();
        $table = 'orders';
        $tableGateway = new TableGateway($table, $ZendDb, $acl);
        $order =  $tableGateway->getItems(
            [
                'id' => $id,
                'single' => 1
            ]
        )['data'];
        if (!$order) {
            return $app->response(
                [
                    'status' => 'error',
                    'message' => 'Order not found',
                ]
            );
        }
        
        if (ArrayUtils::get($payload, 'confirm') && ArrayUtils::get($payload, 'payment') == "Transfer") {
            $order['status'] = 'pending';
            $tableGateway->updateRecord($order, 1);
            return $app->response(
                [
                    'status' => 'success',
                    'data' => $order
                ]
            );
        } 
        
        if ($reference = ArrayUtils::get($payload, 'reference') && ArrayUtils::get($payload, 'payment') == "Paystack" ) {
            //confirm payment
            $table = 'payment_method_meta';
            $tableGateway = new TableGateway($table, $ZendDb, $acl);
            $payment_method =  $tableGateway->getItems(
                [
                    'id' => 2,
                    'depth' => 2
                ]
            )['data'];
            if (!$payment_method) {
                return $app->response(
                    [
                        'status' => 'error',
                        'message' => 'Payment method not found'
                    ]
                );
            }
            $mode = ArrayUtils::get($payment_method, 'mode');
            $meta = _::find(
                ArrayUtils::get($payment_method, 'settings.data'), 
                function ($o) use ($mode) { 
                    if ($mode = 'live') {
                        return ArrayUtils::get($o, 'key') == 'live_private_key';
                    }
                    return ArrayUtils::get($o, 'key') == 'test_private_key';  
                }
            );
            if (!$meta || !ArrayUtils::get($payment_meta, 'value')) {
                return $app->response(
                    [
                        'status' => 'error',
                        'message' => "Can't confirm your payment"
                    ]
                );
            }

            $opts = array(
                'http' => array(
                'header' => "Authorization: Bearer ".ArrayUtils::get($payment_meta, 'value')
                )
            );
            $context = stream_context_create($opts);
            try {
                $response = file_get_contents("https://api.paystack.co/transaction/verify/".$reference, false, $context);
                if ($response === false) {
                    return $app->response(
                        [
                            'status' => 'error',
                            'message' => "Can't reach the payment provider to verify payment"
                        ]
                    );
                }
                $response = json_decode($response, true);
                error_log('paystack response: '.json_encode($response));
                if (ArrayUtils::get($response, 'data.status') == 'success') {
                    if (ArrayUtils::get($order, 'total') == floatval(ArrayUtils::get($response, 'data.amount'))
                        && ArrayUtils::get($order, 'reference') == ArrayUtils::get($response, 'data.reference')
                    ) {
                        $order['status'] = 'processing';
                        $tableGateway->updateRecord($order, 1);
                        return $app->response(
                            [
                                'status' => 'success',
                                'data' => $order
                            ]
                        );
                    } else {
                        //set error
                        return $app->response(
                            [
                                'status' => 'error',
                                'message' => 'Invalid Payment: incorrect amount paid'
                            ]
                        );
                    }
                } else {
                    return $app->response(
                        [
                            'status' => 'error',
                            'message' => 'Payment provider returns error'
                        ]
                    );
                }
            } catch (Exception $e) {
                return $app->response(
                    [
                        'status' => 'error',
                        'message' => 'Something went wrong'
                    ]
                );
            }
        }

        //if not error send email to client and admin

        return $app->response(
            [
                'status' => 'error',
                'message' => 'Unable to process request'
            ]
        );
    }
);

$app->post(
    '/orders/:id',
    function ($id) use ($app) {
        //Update order status
        /* if (!is_numeric_array($payload)) {
            $params[$primaryKey] = ArrayUtils::get($payload, $primaryKey);
            $payload = [$payload];
        }

        $tableGateway->updateCollection($payload); */
        return $app->response(
            [
                'status' => true,
                'message' => 'it works'
            ]
        );
    }
);

// Simple GET endpoint example
$app->get(
    '/checkout_options', 
    function () use ($app) {
        return $app->response(
            [
                "shipping_method" => getShippingMethods(),
                "payment_methods" => getPaymentMethods()
            ]
        );
    }
);
