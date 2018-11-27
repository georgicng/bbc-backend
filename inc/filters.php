<?php
use Directus\Application\Application;
use Directus\Database\TableGatewayFactory;
use Directus\Util\ArrayUtils;
$app = Application::getInstance();
return [
    // 'table.insert.products:before' => \Directus\Customs\Hooks\BeforeInsertProducts::class,
    'response.directus_users.get' => function ($payload) {
        /*
        // assigned by reference to directly change the value on $payload->data
        $data = &$payload->data;
        $meta = $payload->meta;

        // add a new attribute merging the first and last name
        $formatOutput = function (&$row) {
            $format = '%s %s';
            $fname = \Directus\Util\ArrayUtils::get($row, 'first_name', '');
            $lname = \Directus\Util\ArrayUtils::get($row, 'last_name', '');
            $row['name'] = sprintf($format, $fname, $lname);
        };

        if ($meta['type'] === 'collection') {
            // collection on API 1 are wrapped inside 'rows' attributes
            $attributeName = $payload->apiVersion === 1 ? 'rows' : 'data';
            $rows =  $data[$attributeName];

            foreach ($rows as $key => $row) {
                $formatOutput($data[$attributeName][$key]);
            }
        } else {
            // all content on API 1.1 are wrapped inside 'data'
            if ($payload->apiVersion > 1) {
                $formatOutput($data['data']);
            } else {
                $formatOutput($data);
            }
        }
        */
        return $payload;
    },
    'response.products.get' => function ($payload) use ($app) {
        $params = $app->request()->get();
        $single = ArrayUtils::has($params, 'id') || ArrayUtils::has($params, 'single');
        if (!$single && ArrayUtils::get($params, 'filters')) {
            $productsTable = TableGatewayFactory::create('products');
            $results = $productsTable->getItems(
                [
                    'filters' => ArrayUtils::get($params, 'filters')
                ]
            );
            $count = ArrayUtils::get($results, 'meta.total');
            if ($count) {
                $meta = $payload['meta'];
                $meta['query_total'] = $count;
                $payload->set('meta', $meta);
            }
        }
        return $payload;
    }
];