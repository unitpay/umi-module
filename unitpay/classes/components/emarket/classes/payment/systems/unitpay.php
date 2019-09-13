<?php

class unitpayPayment extends payment
{
    public function validate() {
        return true;
    }

    public function process($template = 'default') {
        if(!isset($template))
            $template = 'default';

        $this->order->order();

        $public_key = $this->object->getValue('public_key');
        $secret_key = $this->object->getValue('secret_key');
        $sum = (float) $this->order->getActualPrice();
        $account = $this->order->getId();
        $desc = 'Заказ #' . $this->order->getNumber();
        $signature = hash('sha256', join('{up}', array(
            $account,
            $desc,
            $sum,
            $secret_key
        )));

        $payment_url = 'https://unitpay.ru/pay/' . $public_key;
        $params = array(
            'formAction'    =>  $payment_url,
            'sum'           =>  $sum,
            'account'       =>  $account,
            'desc'          =>  $desc,
            'signature'     =>  $signature
        );

        $this->order->setPaymentStatus('initialized');

        list($form_block) =
            def_module::loadTemplates('emarket/payment/unitpay/' . $template, 'form_block');

        return def_module::parseTemplate($form_block, $params);
    }

    public function poll() {
        $params = getRequest('params');
        $method = getRequest('method');

        if (isset($params['signature'])){
            $signature = $params['signature'];
            if (empty($signature)){
                $status_sign = false;
            }else{
                $status_sign = $this->verifySignature($params, $method);
            }
        }else{
            $status_sign = false;
        }
//        $status_sign = true;
        if ($status_sign){
            switch ($method) {
                case 'check':
                    $result = $this->check( $params );
                    break;
                case 'pay':
                    $result = $this->pay( $params );
                    break;
                case 'error':
                    $result = $this->error( $params );
                    break;
                default:
                    $result = array('error' =>
                        array('message' => 'неверный метод')
                    );
                    break;
            }
        }else{
            $result = array('error' =>
                array('message' => 'неверная сигнатура')
            );
        }

        $this->returnJson($result);

    }

    public static function getOrderId() {
        $params = getRequest('params');
        $orderId = (int) $params['account'];
        return $orderId;
    }

    function check( $params )
    {
        $cmsController = cmsController::getInstance();
        $emarket = $cmsController->getModule('emarket');
        /**
         * @var iUmiObject $currency
         */
        $currency = $emarket->getDefaultCurrency();
        $currency = ($currency instanceof iUmiObject) ? $currency->getValue('codename') : 'RUB';

        if ((float)$this->order->getActualPrice() != (float)$params['orderSum']) {
            $result = array('error' =>
                array('message' => 'не совпадает сумма заказа')
            );
        }elseif ($currency != $params['orderCurrency']) {
            $result = array('error' =>
                array('message' => 'не совпадает валюта заказа')
            );
        }
        else{
            $result = array('result' =>
                array('message' => 'Запрос успешно обработан')
            );
        }
        return $result;
    }
    function pay( $params )
    {
        $cmsController = cmsController::getInstance();
        $emarket = $cmsController->getModule('emarket');
        /**
         * @var iUmiObject $currency
         */
        $currency = $emarket->getDefaultCurrency();
        $currency = ($currency instanceof iUmiObject) ? $currency->getValue('codename') : 'RUB';

        if ((float)$this->order->getActualPrice() != (float)$params['orderSum']) {
            $result = array('error' =>
                array('message' => 'не совпадает сумма заказа')
            );
        }elseif ($currency != $params['orderCurrency']) {
            $result = array('error' =>
                array('message' => 'не совпадает валюта заказа')
            );
        }
        else{
            $this->order->setPaymentStatus('accepted');
            $result = array('result' =>
                array('message' => 'Запрос успешно обработан')
            );
        }
        return $result;
    }
    function error( $params )
    {

        $this->order->setPaymentStatus('declined');
        $result = array('result' =>
            array('message' => 'Запрос успешно обработан')
        );
        return $result;
    }
    function getSignature($method, array $params, $secretKey)
    {
        ksort($params);
        unset($params['sign']);
        unset($params['signature']);
        array_push($params, $secretKey);
        array_unshift($params, $method);
        return hash('sha256', join('{up}', $params));
    }
    function verifySignature($params, $method)
    {
        $secret = $this->object->getValue('secret_key');
        return $params['signature'] == $this->getSignature($method, $params, $secret);
    }
    function returnJson( $arr )
    {
        $result = json_encode($arr);

        $buffer = outputBuffer::current();
        $buffer->clear();
        $buffer->contentType('application/json');
        $buffer->push($result);
        $buffer->end();
    }



};
