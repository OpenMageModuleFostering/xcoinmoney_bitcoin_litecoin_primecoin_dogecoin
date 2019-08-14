<?php
class Coinmoney_IndexController extends Mage_Core_Controller_Front_Action {

    public function indexAction() {

      if (isset($_POST['data']) && isset($_POST['hash'])) {
        $hash = md5(stripcslashes($_POST['data']) . Mage::getStoreConfig('payment/Coinmoney/api_key'));

        if ($_POST['hash'] == $hash) {
          $data = json_decode(stripcslashes($_POST['data']));

          $orderId = $data->item_number;

          if (is_numeric($orderId)) {
            $method = Mage::getModel('Coinmoney/paymentMethod');
            $quote = Mage::getModel('sales/quote')->load($orderId);

            if ($quote->isVirtual()) {
              $quote->getBillingAddress()->setPaymentMethod($method->getCode());
            } else {
              $quote->getShippingAddress()->setPaymentMethod($method->getCode());
            }
            if (!$quote->isVirtual() && $quote->getShippingAddress()) {
              $quote->getShippingAddress()->setCollectShippingRates(true);
            }

            $payment = $quote->getPayment();

            $payment->importData(array('method'=>$method->getCode()));

            $quote->save();
            $quote = Mage::getModel('sales/quote')->load($orderId);

            $convert = Mage::getModel('sales/convert_quote');
            $order = $convert->toOrder($quote);
            $order->setBillingAddress($convert->addressToOrderAddress($quote->getBillingAddress()));
            $order->setShippingAddress($convert->addressToOrderAddress($quote->getShippingAddress()));
            foreach($quote->getAllItems() as $item){
              $orderItem = $convert->itemToOrderItem($item);
              if ($item->getParentItem()) {
                $orderItem->setParentItem($quote->getItemByQuoteItemId($item->getParentItem()->getId()));
              }
              $order->addItem($orderItem);
            }
            $payment = $convert->paymentToOrderPayment($quote->getPayment());
            $order->setPayment($payment);
            $order->setDiscountAmount($quote->getSubtotalWithDiscount() - $quote->getSubtotal());
            $order->setShippingAmount($quote->getShippingAddress()->getShippingAmount());
            $order->setSubtotalWithDiscount($quote->getSubtotalWithDiscount());
            $order->setGrandTotal($quote->getGrandTotal());
            $order->setBaseSubtotal($quote->getBaseSubtotal());
            $order->setSubtotal($quote->getSubtotal());
            $order->setTotalPaid($quote->getTotalPaid());
            $order->setTotalRefunded($quote->getTotalRefunded());
            $order->save();

            $entityId = $order->getEntityId();
            if(!is_numeric($entityId)){
              header("HTTP/1.0 404 Not Found");die();
            }

            $quote->setIsActive(false)->save();
            $method->MarkOrderPaid($order);
            echo "OK";
          } else{
            header("HTTP/1.0 404 Not Found");die();
          }
        } else {
          header("HTTP/1.0 404 Not Found");
          die();
        }
      }

      header("HTTP/1.0 404 Not Found");die();
    }

    public function sendAction() {
      $json = array();

      $session = Mage::getSingleton('checkout/session');

      $quote = $session->getQuote();
      $quoteId = $quote->getId();


      $order = $quote->toArray();
      $address = $quote->getShippingAddress();

      $data = $cart = Mage::getModel('checkout/cart')->getQuote()->getData();

      if ($quote->getQuoteCurrencyCode() == 'USD') {
        $currency = 'DXX';
      } else {
        $currency = $quote->getQuoteCurrencyCode();
      }


      $allowed_currencies = array();

      if (Mage::getStoreConfig('payment/Coinmoney/dxx_account')) {
        $allowed_currencies[] = 'DXX';
      }
      if (Mage::getStoreConfig('payment/Coinmoney/btc_account')) {
        $allowed_currencies[] = 'BTC';
      }
      if (Mage::getStoreConfig('payment/Coinmoney/ltc_account')) {
        $allowed_currencies[] = 'LTC';
      }
      if (Mage::getStoreConfig('payment/Coinmoney/xpm_account')) {
        $allowed_currencies[] = 'XPM';
      }
      if (Mage::getStoreConfig('payment/Coinmoney/doge_account')) {
        $allowed_currencies[] = 'DOGE';
      }

      $options = array();

      $options['cmd'] = 'order';
      $options['user_id'] = Mage::getStoreConfig('payment/Coinmoney/merchant_id');
      $options['amount'] = round($order['grand_total'],2);
      $options['currency'] = $currency;
      $options['allowed_currencies'] = implode(', ', $allowed_currencies);
      $options['payer_pays_fee'] = 0;
      $options['item_name'] = Mage::app()->getStore()->getName();
      $options['item_number'] = $quoteId;
      $options['quantity'] = 1;
      $options['first_name'] = $order['customer_firstname'];
      $options['last_name'] = $order['customer_lastname'];
      $options['email'] = $order['customer_email'];
      $options['address1'] = $address->getStreet1();
      $options['country'] = $address->getCountry();
      $options['city'] = $address->getCity();
      $options['city'] = $address->getCity();
      $options['state'] = $address->getRegionCode();
      $options['zip'] = $address->getPostcode();
      $options['callback_url'] = Mage::getBaseUrl().'coinmoney_callback/';

      $str = '';
      $keys = array_keys($options);
      sort($keys);
      for ($i=0; $i < count($keys); $i++) {
        $str .= $options[$keys[$i]];
      }
      $str .= Mage::getStoreConfig('payment/Coinmoney/api_key');
      $options['hash'] = md5($str);

      $result = $this->coinmoneySendApiCall($options);

      if($result->result == 'success') {
        $json['redirect_url'] = $result->url;
      }

      echo json_encode($json);
    }

    public function checkForPaymentAction()
    {
      $params = $this->getRequest()->getParams();
      $quoteId = $params['quote'];
      $paid = Mage::getModel('Coinmoney/ipn')->GetQuotePaid($quoteId);
      print json_encode(array('paid' => $paid));
      exit();
    }

    public function GetQuoteId()
    {
      $quote = $this->getQuote();
      $quoteId = $quote->getId();
      return $quoteId;
    }

    function coinmoneySendApiCall($options) {
      $result = FALSE;

      try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, Mage::getStoreConfig('payment/Coinmoney/api_url'));
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $options);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        $json = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($json);
        if (isset($result->data)) {
          $result = json_decode($result->data);
        }
      }
      catch (Exception $e) {  }
      return $result;
    }
}
