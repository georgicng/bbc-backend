<?php
use Directus\Database\TableGatewayFactory;
use Directus\Util\ArrayUtils;

return [
    'postInsert' => function ($TableGateway, $record, $db) {
        $tableName = $TableGateway->getTable();
        if ($tableName == 'enquiries') {
            //send enquiry email
            $to      = $record['email'];
            $subject = $record['subject'];
            $message = $record['message'];
            $headers = array(
                'From' => 'hello@butterbakescakes.com',
                'Reply-To' => 'hello@butterbakescakes.com',
                'X-Mailer' => 'PHP/' . phpversion()
            );

            mail($to, $subject, $message, $headers);
        } elseif ($tableName == 'issues') {
            //send issue email
            $to      = $record['email'];
            $subject = $record['subject'];
            $message = $record['description'];
            $headers = array(
                'From' => 'hello@butterbakescakes.com',
                'Reply-To' => 'hello@butterbakescakes.com',
                'X-Mailer' => 'PHP/' . phpversion()
            );

            mail($to, $subject, $message, $headers);
        } elseif ($tableName == 'products') {
            //add general product options to new products
            $id = ArrayUtils::get($record, 'id');
            $generalOptionsTable = TableGatewayFactory::create('general_product_options');
            $options = $generalOptionsTable->getItems(
                [
                    'depth' => 1,
                ]
            )['data'];
            if (!empty($options)) {
                $productOptionsTable = TableGatewayFactory::create('product_options');
                $productOptionValuesTable = TableGatewayFactory::create('product_option_values');
                foreach ($options as $option) {
                    $option_entry = [
                        'product_id' => $id,
                        'option_id' => ArrayUtils::get($option, 'product_option.data.id'),
                        'price_increment' => ArrayUtils::get($option, 'price_increment'),
                        'required' => ArrayUtils::get($option, 'required'),
                        'minimum' => ArrayUtils::get($option, 'minimum'),
                        'maximum' => ArrayUtils::get($option, 'maximum'),
                        'comment' => ArrayUtils::get($option, 'comment'),
                    ];
                    $newRecord = $productOptionsTable->updateRecord($option_entry);
                    $primaryKey = $productOptionsTable->primaryKeyFieldName;
                    $optionID = ArrayUtils::get($newRecord->toArray(), $primaryKey);
                    $values = ArrayUtils::get($option, 'values.data');
                    if (!empty($values)) {
                        foreach ($values as $value) {
                            $value_entry = [
                                "product_option" => $optionID,
                                "option_value" => ArrayUtils::get($value, 'option_value'),
                                "price_increment" => ArrayUtils::get($value, 'price_increment')
                            ];
                            $productOptionValuesTable->updateRecord($value_entry);
                        }
                    }
                }
            }
        }
    },
    'postUpdate' => function ($TableGateway, $record, $db) {
        $tableName = $TableGateway->getTable();
        switch ($tableName) {
            // ...
        }
    }
];