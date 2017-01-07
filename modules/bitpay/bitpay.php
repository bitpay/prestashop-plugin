<?php

/**
 * The MIT License (MIT)
 * 
 * Copyright (c) 2011-2014 BitPay
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * Originally written by Kris, 2012
 * Updated to work with Prestashop 1.6 by Rich Morgan, rich@bitpay.com
 */


if (!defined('_PS_VERSION_'))
  exit;

function bplog($contents) {
  if(isset($contents)) {
    if(is_resource($contents))
      return error_log(serialize($contents));
    else
      return error_log(var_dump($contents, true));
  } else {
    return false;
  }
}

class bitpay extends PaymentModule {
    private $_html       = '';
    private $_postErrors = array();
    private $key;

    public function __construct() {
      include(dirname(__FILE__).'/config.php');
      $this->name            = 'bitpay';
      $this->version         = '0.4';
      $this->author          = 'BitPay';
      $this->className       = 'bitpay';
      $this->currencies      = true;
      $this->currencies_mode = 'checkbox';
      $this->tab             = 'payments_gateways';
      $this->bitpayurl       = $bitpayurl;
      $this->apiurl          = $apiurl;
      $this->sslport         = $sslport;
      $this->verifypeer      = $verifypeer;
      $this->verifyhost      = $verifyhost;
      if (_PS_VERSION_ > '1.5')
      $this->controllers = array('payment', 'validation');

      parent::__construct();

      $this->page = basename(__FILE__, '.php');
      $this->displayName      = $this->l('bitpay');
      $this->description      = $this->l('Accepts Bitcoin payments via BitPay.');
      $this->confirmUninstall = $this->l('Are you sure you want to delete your details?');

      // Backward compatibility
      require(_PS_MODULE_DIR_ . 'bitpay/backward_compatibility/backward.php');

      $this->context->smarty->assign('base_dir',__PS_BASE_URI__);

    }

    public function install() {

      if(!function_exists('curl_version')) {
        $this->_errors[] = $this->l('Sorry, this module requires the cURL PHP extension but it is not enabled on your server.  Please ask your web hosting provider for assistance.');
        return false;
      }

      if (!parent::install() || !$this->registerHook('invoice') || !$this->registerHook('payment') || !$this->registerHook('paymentReturn')) {
        return false;
      }

      $db = Db::getInstance();

      $query = "CREATE TABLE `"._DB_PREFIX_."order_bitcoin` (
                `id_payment` int(11) NOT NULL AUTO_INCREMENT,
                `id_order` int(11) NOT NULL,
                `cart_id` int(11) NOT NULL,
                `invoice_id` varchar(255) NOT NULL,
                `status` varchar(255) NOT NULL,
                PRIMARY KEY (`id_payment`),
                UNIQUE KEY `invoice_id` (`invoice_id`)
                ) ENGINE="._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8';

      $db->Execute($query);
      $query = "INSERT IGNORE INTO `ps_configuration` (`name`, `value`, `date_add`, `date_upd`) VALUES ('PS_OS_BITPAY', '13', NOW(), NOW());";
      $db->Execute($query);

      return true;
    }

    public function uninstall() {
      Configuration::deleteByName('bitpay_APIKEY');

      return parent::uninstall();
    }

    public function getContent() {
      $this->_html .= '<h2>'.$this->l('bitpay').'</h2>';

      $this->_postProcess();
      $this->_setbitpaySubscription();
      $this->_setConfigurationForm();

      return $this->_html;
    }

    public function hookPayment($params) {
      global $smarty;

      $smarty->assign(array(
                            'this_path' => $this->_path,
                            'this_path_ssl' => Configuration::get('PS_FO_PROTOCOL').$_SERVER['HTTP_HOST'].__PS_BASE_URI__."modules/{$this->name}/")
                           );

      return $this->display(__FILE__, 'payment.tpl');
    }

    private function _setbitpaySubscription() {
      $this->_html .= '<div style="float: right; width: 440px; height: 150px; border: dashed 1px #666; padding: 8px; margin-left: 12px;">
                       <h2>'.$this->l('Opening your bitpay account').'</h2>
                       <div style="clear: both;"></div>
                       <p>'.$this->l('When opening your BitPay account by clicking on the following image, you are helping us significantly to improve the BitPay solution:').'</p>
                       <p style="text-align: center;"><a href="https://bitpay.com/"><img src="../modules/bitpay/prestashop_bitpay.png" alt="PrestaShop & bitpay" style="margin-top: 12px;" /></a></p>
                       <div style="clear: right;"></div>
                       </div>
                       <img src="../modules/bitpay/bitcoin.png" style="float:left; margin-right:15px;" />
                       <b>'.$this->l('This module allows you to accept payments by BitPay.').'</b><br /><br />
                       '.$this->l('If the client chooses this payment mode, your BitPay account will be automatically credited.').'<br />
                       '.$this->l('You need to configure your BitPay account before using this module.').'
                       <div style="clear:both;">&nbsp;</div>';
    }

    private function _setConfigurationForm() {
      $this->_html .= '<form method="post" action="'.htmlentities($_SERVER['REQUEST_URI']).'">
                       <script type="text/javascript">
                       var pos_select = '.(($tab = (int)Tools::getValue('tabs')) ? $tab : '0').';
                       </script>';

      if (_PS_VERSION_ <= '1.5') {
        $this->_html .= '<script type="text/javascript" src="'._PS_BASE_URL_._PS_JS_DIR_.'tabpane.js"></script>
                         <link type="text/css" rel="stylesheet" href="'._PS_BASE_URL_._PS_CSS_DIR_.'tabpane.css" />';
      } else {
        $this->_html .= '<script type="text/javascript" src="'._PS_BASE_URL_._PS_JS_DIR_.'jquery/plugins/tabpane/jquery.tabpane.js"></script>
                         <link type="text/css" rel="stylesheet" href="'._PS_BASE_URL_._PS_JS_DIR_.'jquery/plugins/tabpane/jquery.tabpane.css" />';
      }

      $this->_html .= '<input type="hidden" name="tabs" id="tabs" value="0" />
                       <div class="tab-pane" id="tab-pane-1" style="width:100%;">
                       <div class="tab-page" id="step1">
                       <h4 class="tab">'.$this->l('Settings').'</h2>
                       '.$this->_getSettingsTabHtml().'
                       </div>
                       </div>
                       <div class="clear"></div>
                       <script type="text/javascript">
                       function loadTab(id){}
                       setupAllTabs();
                       </script>
                       </form>';
    }

    private function _getSettingsTabHtml() {
      global $cookie;

      $lowSelected    = '';
      $mediumSelected = '';
      $highSelected   = '';

      // Remember which speed has been selected and display that upon reaching the settings page; default to low
      if (Configuration::get('bitpay_TXSPEED') == "high") {
        $highSelected = "selected=\"selected\"";
      } elseif (Configuration::get('bitpay_TXSPEED') == "medium") {
        $mediumSelected = "selected=\"selected\"";
      } else {
        $lowSelected = "selected=\"selected\"";
      }

      $html = '<h2>'.$this->l('Settings').'</h2>
               <h3 style="clear:both;">'.$this->l('API Key').'</h3>
               <div class="margin-form">
               <input type="text" name="apikey_bitpay" value="'.htmlentities(Tools::getValue('apikey', Configuration::get('bitpay_APIKEY')), ENT_COMPAT, 'UTF-8').'" />
               </div>
               <h3 style="clear:both;">'.$this->l('Transaction Speed').'</h3>
               <div class="margin-form">
               <select name="txspeed_bitpay">
               <option value="low" '.$lowSelected.'>Low</option>
               <option value="medium" '.$mediumSelected.'>Medium</option>
               <option value="high" '.$highSelected.'>High</option>
               </select>
               </div>
               <p class="center"><input class="button" type="submit" name="submitbitpay" value="'.$this->l('Save settings').'" /></p>';

      return $html;
    }

    private function _postProcess() {
      global $currentIndex, $cookie;

      if (Tools::isSubmit('submitbitpay')) {
        $template_available = array('A', 'B', 'C');
        $this->_errors      = array();

        if (Tools::getValue('apikey_bitpay') == NULL)
          $this->_errors[]  = $this->l('Missing API Key');

        if (count($this->_errors) > 0) {
          $error_msg = '';
          
          foreach ($this->_errors AS $error)
            $error_msg .= $error.'<br />';
          
          $this->_html = $this->displayError($error_msg);
        } else {
          Configuration::updateValue('bitpay_APIKEY', trim(Tools::getValue('apikey_bitpay')));
          Configuration::updateValue('bitpay_TXSPEED', trim(Tools::getValue('txspeed_bitpay')));

          $this->_html = $this->displayConfirmation($this->l('Settings updated'));
        }

      }

    }

    public function execPayment($cart) {
      // Create invoice
      $currency                    = Currency::getCurrencyInstance((int)$cart->id_currency);
      $options                     = $_POST;
      $options['transactionSpeed'] = Configuration::get('bitpay_TXSPEED');
      $options['currency']         = $currency->iso_code;
      $total                       = $cart->getOrderTotal(true);
      
      $options['notificationURL']  = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8').__PS_BASE_URI__.'modules/'.$this->name.'/ipn.php';
      if (_PS_VERSION_ <= '1.5')
        $options['redirectURL']    = (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://').htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8').__PS_BASE_URI__.'order-confirmation.php?id_cart='.$cart->id.'&id_module='.$this->id.'&id_order='.$this->currentOrder;
      else
        $options['redirectURL']    = Context::getContext()->link->getModuleLink('bitpay', 'validation');

      $options['posData']          = '{"cart_id": "' . $cart->id . '"';
      $options['posData']         .= ', "hash": "' . crypt($cart->id, Configuration::get('bitpay_APIKEY')) . '"';

      $this->key                   = $this->context->customer->secure_key;
      
      $options['posData']         .= ', "key": "' . $this->key . '"}';
      $options['orderID']          = $cart->id;
      $options['price']            = $total;
      $options['fullNotifications'] = true;

      $postOptions                 = array('orderID', 'itemDesc', 'itemCode', 
                                           'notificationEmail', 'notificationURL', 'redirectURL', 
                                           'posData', 'price', 'currency', 'physical', 'fullNotifications',
                                           'transactionSpeed', 'buyerName', 'buyerAddress1', 
                                           'buyerAddress2', 'buyerCity', 'buyerState', 'buyerZip', 
                                           'buyerEmail', 'buyerPhone');

      foreach($postOptions as $o) {
        if (array_key_exists($o, $options))
          $post[$o] = $options[$o];
      }

      if(function_exists('json_encode'))
        $post = json_encode($post);
      else
        $post = rmJSONencode($post);

      // Call BitPay
      $curl = curl_init($this->apiurl.'/api/invoice/');
      $length = 0;

      if ($post) {
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post);

        $length = strlen($post);
      }

      $uname  = base64_encode(Configuration::get('bitpay_APIKEY'));
      $header = array(
                      'Content-Type: application/json',
                      'Content-Length: ' . $length,
                      'Authorization: Basic ' . $uname,
                      'X-BitPay-Plugin-Info: prestashop0.4',
                     );

      curl_setopt($curl, CURLINFO_HEADER_OUT, true);
      curl_setopt($curl, CURLOPT_PORT, $this->sslport);
      curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
      curl_setopt($curl, CURLOPT_TIMEOUT, 10);
      curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC ) ;
      curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $this->verifypeer); // verify certificate (1)
      curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, $this->verifyhost); // check existence of CN and verify that it matches hostname (2)
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
      curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);
      

      $responseString = curl_exec($curl);

      if(!$responseString) {
        $response = curl_error($curl);

        die(Tools::displayError("Error: no data returned from API server!"));
      
      } else {

        if(function_exists('json_decode'))
          $response = json_decode($responseString, true);
        else
          $response = rmJSONdecode($responseString);
      }

      curl_close($curl);

      if(isset($response['error'])) {
        bplog($response['error']);

        die(Tools::displayError("Error occurred! (" . $response['error']['type'] . " - " . $response['error']['message'] . ")"));
      } else if(!$response['url']) {
        die(Tools::displayError("Error: Response did not include invoice url!"));
      } else {
        header('Location:  ' . $response['url']);
      }
 
    }

    public function writeDetails($id_order, $cart_id, $invoice_id, $status) {
      $invoice_id = stripslashes(str_replace("'", '', $invoice_id));
      $status = stripslashes(str_replace("'", '', $status));
      $db = Db::getInstance();
      $result = $db->Execute('INSERT INTO `' . _DB_PREFIX_ . 'order_bitcoin` (`id_order`, `cart_id`, `invoice_id`, `status`) VALUES(' . intval($id_order) . ', ' . intval($cart_id) . ', "' . $invoice_id . '", "' . $status . '") on duplicate key update `status`="'.$status.'"');
    }

    public function readBitcoinpaymentdetails($id_order) {
      $db = Db::getInstance();
      $result = array();
      $result = $db->ExecuteS('SELECT * FROM `' . _DB_PREFIX_ . 'order_bitcoin` WHERE `id_order` = ' . intval($id_order) . ';');

      if (count($result)>0) {
        return $result[0];
      } else {
        // $result can be empty
        return array( 'invoice_id' => 0, 'status' =>'null');
      }
      
    }

    public function hookInvoice($params) {
      global $smarty;

      $id_order = $params['id_order'];

      $bitcoinpaymentdetails = $this->readBitcoinpaymentdetails($id_order);

      $smarty->assign(array(
                            'bitpayurl'    =>  $this->bitpayurl,
                            'invoice_id'    => $bitcoinpaymentdetails['invoice_id'],
                            'status'        => $bitcoinpaymentdetails['status'],
                            'id_order'      => $id_order,
                            'this_page'     => $_SERVER['REQUEST_URI'],
                            'this_path'     => $this->_path,
                            'this_path_ssl' => Configuration::get('PS_FO_PROTOCOL').$_SERVER['HTTP_HOST'].__PS_BASE_URI__."modules/{$this->name}/"
                           ));

      return $this->display(__FILE__, 'invoice_block.tpl');
    }

    public function hookPaymentReturn($params) {
      global $smarty;
      
      $order = ($params['objOrder']);
      $state = $order->current_state;

      $smarty->assign(array(
                            'state'         => $state,
                            'this_path'     => $this->_path,
                            'this_path_ssl' => Configuration::get('PS_FO_PROTOCOL').$_SERVER['HTTP_HOST'].__PS_BASE_URI__."modules/{$this->name}/"));

      return $this->display(__FILE__, 'payment_return.tpl');
    }
    
    public function rmJSONdecode($jsondata) {
      $jsondata = trim(stripcslashes(str_ireplace('"','',str_ireplace('\'','',$jsondata))));
      $jsonarray = array();
      $level = 0;

      if($jsondata == '')
        return false;

      if($jsondata[0] == '[')
        $jsondata = trim(substr($jsondata,1,strlen($jsondata)));

      if($jsondata[0] == '{')
        $jsondata = trim(substr($jsondata,1,strlen($jsondata)));

      if(substr($jsondata,strlen($jsondata)-1,1) == ']')
        $jsondata = trim(substr($jsondata,0,strlen($jsondata)-1));

      if(substr($jsondata,strlen($jsondata)-1,1) == '}')
        $jsondata = trim(substr($jsondata,0,strlen($jsondata)-1));

      $break = false;

      while(!$break) {
        if(stripos($jsondata,"\t") !== false)
          $jsondata = str_ireplace("\t",' ',$jsondata);

        if(stripos($jsondata,"\r") !== false)
          $jsondata = str_ireplace("\r",'',$jsondata);

        if(stripos($jsondata,"\n") !== false)
          $jsondata = str_ireplace("\n",'',$jsondata);

        if(stripos($jsondata,'  ') !== false)
          $jsondata = str_ireplace('  ',' ',$jsondata);
        else
          $break=true;
      }

      $level = 0;
      $x = 0;
      $array = false;
      $object = false;

      while($x<strlen($jsondata)) {
        $var = '';
        $val = '';

        while($x < strlen($jsondata) && $jsondata[$x] == ' ')
          $x++;
    
        switch($jsondata[$x]) {
          case '[':
            $level++;
            break;
          case '{':
            $level++;
            break;
        }

        if($level <= 0) {
          while($x < strlen($jsondata) && $jsondata[$x] != ':') {
            if($jsondata[$x] != ' ') $var .= $jsondata[$x];
            $x++;
          }

          $var = trim(stripcslashes(str_ireplace('"','',$var)));

          while($x < strlen($jsondata) && ($jsondata[$x] == ' ' || $jsondata[$x] == ':'))
            $x++;

          switch($jsondata[$x]) {
            case '[':
              $level++;
              break;
            case '{':
              $level++;
              break;
          }
        }

        if($level > 0) {
 
          while($x<strlen($jsondata) && $level > 0) {
            $val .= $jsondata[$x];
            $x++;

            switch($jsondata[$x]) {
              case '[':
                $level++;
                break;
              case '{':
                $level++;
                break;
              case ']':
                $level--;
                break;
              case '}':
                $level--;
                break;
            }
          }

          if($jsondata[$x] == ']' || $jsondata[$x] == '}')
            $val .= $jsondata[$x];

          $val = trim(stripcslashes(str_ireplace('"','',$val)));

          while($x < strlen($jsondata) && ($jsondata[$x] == ' ' || $jsondata[$x] == ',' || $jsondata[$x] == ']' || $jsondata[$x] == '}'))
            $x++;
      
        } else {

          while($x<strlen($jsondata) && $jsondata[$x] != ',') {
            $val .= $jsondata[$x];
            $x++;
          }

          $val = trim(stripcslashes(str_ireplace('"','',$val)));

          while($x < strlen($jsondata) && ($jsondata[$x] == ' ' || $jsondata[$x] == ','))
            $x++;
        }

        $jsonarray[$var] = $val;

        if($level < 0) $level = 0;
      }

      return $jsonarray;

    }
    
    public function rmJSONencode($data) {
      if(is_array($data)) {
        $jsondata = '{';

        foreach($data as $key => $value) {
          $jsondata .= '"' . $key . '": ';

          if(is_array($value))
            $jsondata .= rmJSONencode($value) . ', ';

          if(is_numeric($value))
            $jsondata .= $value . ', ';

          if(is_string($value))
            $jsondata .= '"' . $value . '", ';

          if(is_bool($value)) {
            if($value)
              $jsondata .= 'true, ';
            else
              $jsondata .= 'false, ';
          }

          if(is_null($value))
            $jsondata .= 'null, ';
        }

        $jsondata = substr($jsondata,0,strlen($jsondata)-2);
        $jsondata .= '}';
      } else {
        $jsondata = '{"' . $data . '"}';
      }

      return $jsondata;
    }


    private function makeCurlCall($post, $curl) {
      $length = 0;

      if ($post) {
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post);

        $length = strlen($post);
      }

      $uname  = base64_encode(Configuration::get('bitpay_APIKEY'));
      $header = array(
                      'Content-Type: application/json',
                      'Content-Length: ' . $length,
                      'Authorization: Basic ' . $uname,
                      'X-BitPay-Plugin-Info: prestashop0.4',
                     );

      curl_setopt($curl, CURLOPT_PORT, $this->sslport);
      curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
      curl_setopt($curl, CURLOPT_TIMEOUT, 10);
      curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC ) ;
      curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $this->verifypeer); // verify certificate (1)
      curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, $this->verifyhost); // check existence of CN and verify that it matches hostname (2)
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
      curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);
      
      return curl_exec($curl);
    }
  }

?>
