<?php


class bitpayValidationModuleFrontController extends ModuleFrontController
{
        /**
         * @see FrontController::postProcess()
         */
	public function postProcess()
        {
                $cart = $this->context->cart;
                if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active)
                        Tools::redirect('index.php?controller=order&step=1');

                // Check that this payment option is still available in case the customer changed his address just before the end of the checkout process
                $authorized = false;
                foreach (Module::getPaymentModules() as $module)
                        if ($module['name'] == 'bitpay')
                        {
                                $authorized = true;
                                break;
                        }
                if (!$authorized)
                        die($this->module->l('This payment method is not available.', 'validation'));

                $customer = new Customer($cart->id_customer);
                if (!Validate::isLoadedObject($customer))
                        Tools::redirect('index.php?controller=order&step=1');

                $currency = $this->context->currency;
                $total = (float)$cart->getOrderTotal(true, Cart::BOTH);

                $mailVars = array(
                );

                Tools::redirect('index.php?controller=order-confirmation&id_cart='.(int)$cart->id.'&id_module='.(int)$this->module->id.'&id_order='.$this->module->currentOrder.'&key='.$customer->secure_key);
        }
}

