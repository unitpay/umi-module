<?php

class unitpayPayment extends payment
{

    private $taxRates = [];

    public function validate() {
        return true;
    }

    public function process($template = 'default') {
        if(!isset($template))
            $template = 'default';

        $this->order->order();

        $domain = $this->object->getValue('unitpay_domain');
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

        $email = customer::get()->getEmail();
        $phone = preg_replace('/\D/', '', customer::get()->getPhone());

        $orderItems = '';

        if ($email || $phone){
            $orderItems = $this->getCashItems($this->order);
        }

        $payment_url = "https://$domain/pay/" . $public_key;
        $params = array(
            'formAction'    =>  $payment_url,
            'sum'           =>  $sum,
            'account'       =>  $account,
            'desc'          =>  $desc,
            'signature'     =>  $signature,
            'email'         =>  $email,
            'phone'         =>  $phone,
            'items'         =>  $orderItems

        );

        $this->order->setPaymentStatus('initialized');

        list($form_block) =
            def_module::loadTemplates('emarket/payment/unitpay/' . $template, 'form_block');

        return def_module::parseTemplate($form_block, $params);
    }

    private function getCashItems()
    {

        $currencyCode = (\UmiCms\Service::CurrencyFacade())->getCurrent()->getISOCode();

        $orderProducts = array_map(function ($item) use ($currencyCode) {

            return [
                'name'     => $item->getName(),
                'count'    => $item->getAmount(),
                'price'    => round($item->getBasketPrice(), 2),
                'currency' => $currencyCode,
                'type'     => 'commodity',
                'nds'      => $this->getTaxRates($item->getTaxRateId()),
            ];
        }, $this->order->getItems());

        if ($this->order->getDeliveryId() && ($this->order->getDeliveryPrice() > 0)) {
            $delivery = delivery::get($this->order->getDeliveryId());

            $orderProducts[] = array(
                'name'     => $delivery->getName(),
                'count'    => 1,
                'price'    => round($delivery->getDeliveryPrice(), 2),
                'currency' => $currencyCode,
                'type'     => 'service',
                'nds'      => $this->getTaxRates($delivery->getTaxRateId()),
            );
        }

        return base64_encode(json_encode($orderProducts));
    }

    private function getTaxRates($idTaxRate){
        if (!isset($this->taxRates[$idTaxRate])){
            $taxRateFacade = \UmiCms\Service::get('TaxRateVat');;
            $this->taxRates[$idTaxRate] = $taxRateFacade->get($idTaxRate)->getRate();
        }

        switch ($this->taxRates[$idTaxRate]){
            case  '10':
                $vat = 'vat10';
                break;
            case '20':
                $vat = 'vat20';
                break;
            case '0':
                $vat = 'vat0';
                break;
            default:
                $vat = 'none';
        }

        return $vat;
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
