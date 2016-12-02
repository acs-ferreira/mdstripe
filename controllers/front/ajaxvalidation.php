<?php
/**
 * 2016 Michael Dekker
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@michaeldekker.com so we can send you a copy immediately.
 *
 *  @author    Michael Dekker <prestashop@michaeldekker.com>
 *  @copyright 2016 Michael Dekker
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

require_once dirname(__FILE__).'/../../vendor/autoload.php';

/**
 * Class MdstripeValidationModuleFrontController
 */
class MdstripeAjaxvalidationModuleFrontController extends ModuleFrontController
{
    /** @var MdStripe $module */
    public $module;

    /**
     * MdstripeAjaxvalidationModuleFrontController constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->ssl = Tools::usingSecureMode();
    }

    /**
     * Post process
     *
     * @return bool Whether the info has been successfully processed
     * @throws PrestaShopException
     */
    public function postProcess()
    {
        header('Content-Type: application/json');
        $cart = $this->context->cart;
        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            http_response_code(400);
            die(Tools::jsonEncode(array('success' => false)));
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            http_response_code(400);
            die(Tools::jsonEncode(array('success' => false)));
        }

        $orderProcess = Configuration::get('PS_ORDER_PROCESS_TYPE') ? 'order-opc' : 'order';
        $this->context->smarty->assign(array(
            'orderLink' => $this->context->link->getPageLink($orderProcess, true),
        ));

        if ((Tools::isSubmit('mdstripe-id_cart') == false) || (Tools::isSubmit('mdstripe-token') == false) || (int) Tools::getValue('mdstripe-id_cart') != $cart->id) {
            http_response_code(400);
            die(Tools::jsonEncode(array('success' => false)));
        }

        $token = Tools::getValue('mdstripe-token');
        $idCart = Tools::getValue('mdstripe-id_cart');

        $cart = new Cart((int) $idCart);
        $customer = new Customer((int) $cart->id_customer);
        $currency = new Currency((int) $cart->id_currency);

        $stripe = array(
            'secret_key' => Configuration::get(MdStripe::SECRET_KEY),
            'publishable_key' => Configuration::get(MdStripe::PUBLISHABLE_KEY),
        );
        
        \Stripe\Stripe::setAppInfo("MDStripe", "1.0.12", "https://github.com/firstred/mdstripe");
        \Stripe\Stripe::setApiKey($stripe['secret_key']);

        try {
            $stripeCustomer = \Stripe\Customer::create(array(
                'email' => $customer->email,
                'source' => $token,
            ));
        } catch (Exception $e) {
            http_response_code(400);
            die(Tools::jsonEncode(array('success' => false)));
        }

        $stripeAmount = $cart->getOrderTotal();
        if (!in_array(Tools::strtolower($currency->iso_code), MdStripe::$zeroDecimalCurrencies)) {
            $stripeAmount = (int) ($stripeAmount * 100);
        }

        try {
            $stripeCharge = \Stripe\Charge::create(
                array(
                    'customer' => $stripeCustomer->id,
                    'amount' => $stripeAmount,
                    'currency' => Tools::strtolower($currency->iso_code),
                )
            );
        } catch (Exception $e) {
            http_response_code(400);
            die(Tools::jsonEncode(array('success' => false)));
        }

        if ($stripeCharge->paid === true) {
            $paymentStatus = Configuration::get(MdStripe::STATUS_VALIDATED);
            $message = null;

            /**
             * Converting cart into a valid order
             */
            $currencyId = (int) Context::getContext()->currency->id;

            $this->module->validateOrder($idCart, $paymentStatus, $cart->getOrderTotal(), 'Stripe', $message, array(), $currencyId, false, $cart->secure_key);

            /**
             * If the order has been validated we try to retrieve it
             */
            $idOrder = Order::getOrderByCartId((int) $cart->id);

            if ($idOrder) {
                // Log transaction
                $stripeTransaction = new StripeTransaction();
                $stripeTransaction->card_last_digits = (int) $stripeCharge->source['last4'];
                $stripeTransaction->id_charge = $stripeCharge->id;
                $stripeTransaction->amount = $stripeAmount;
                $stripeTransaction->id_order = $idOrder;
                $stripeTransaction->type = StripeTransaction::TYPE_CHARGE;
                $stripeTransaction->source = StripeTransaction::SOURCE_FRONT_OFFICE;
                $stripeTransaction->add();

                die(Tools::jsonEncode(array('success' => true, 'idOrder' => (int) $idOrder)));
            } else {
                http_response_code(400);
                die(Tools::jsonEncode(array('success' => false)));
            }
        } else {
            $stripeTransaction = new StripeTransaction();
            $stripeTransaction->card_last_digits = (int) $stripeCharge->source['last4'];
            $stripeTransaction->id_charge = $stripeCharge->id;
            $stripeTransaction->amount = 0;
            $stripeTransaction->id_order = 0;
            $stripeTransaction->type = StripeTransaction::TYPE_CHARGE_FAIL;
            $stripeTransaction->source = StripeTransaction::SOURCE_FRONT_OFFICE;
            $stripeTransaction->add();
        }
        http_response_code(400);
        die(Tools::jsonEncode(array('success' => false)));
    }
}
