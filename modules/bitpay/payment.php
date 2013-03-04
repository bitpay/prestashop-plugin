<?php

include(dirname(__FILE__).'/../../config/config.inc.php');
include(dirname(__FILE__).'/../../header.php');
include(dirname(__FILE__).'/bitpay.php');

$bitpay = new bitpay();
echo $bitpay->execPayment($cart);

include_once(dirname(__FILE__).'/../../footer.php');

?>