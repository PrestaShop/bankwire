<?php
/*
* 2007-2015 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2015 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class BankWire extends PaymentModule
{
    protected $_html = '';
    protected $_postErrors = array();

    public $details;
    public $owner;
    public $address;
    public $extra_mail_vars;

    public function __construct()
    {
        $this->name = 'bankwire';
        $this->tab = 'payments_gateways';
        $this->version = '2.0.0';
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
        $this->author = 'PrestaShop';
        $this->controllers = array('payment', 'validation');
        $this->is_eu_compatible = 1;

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        $config = Configuration::getMultiple(array('BANK_WIRE_DETAILS', 'BANK_WIRE_OWNER', 'BANK_WIRE_ADDRESS', 'BANK_WIRE_RESERVATION_DAYS'));
        if (!empty($config['BANK_WIRE_OWNER'])) {
            $this->owner = $config['BANK_WIRE_OWNER'];
        }
        if (!empty($config['BANK_WIRE_DETAILS'])) {
            $this->details = $config['BANK_WIRE_DETAILS'];
        }
        if (!empty($config['BANK_WIRE_ADDRESS'])) {
            $this->address = $config['BANK_WIRE_ADDRESS'];
        }
        if (!empty($config['BANK_WIRE_RESERVATION_DAYS'])) {
            $this->reservation_days = $config['BANK_WIRE_RESERVATION_DAYS'];
        }

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName = $this->getTranslator()->trans('Bank wire', array(), 'Modules.BankWire.Admin');
        $this->description = $this->getTranslator()->trans('Accept payments for your products via bank wire transfer.', array(), 'Modules.BankWire.Admin');
        $this->confirmUninstall = $this->getTranslator()->trans('Are you sure about removing these details?', array(), 'Modules.BankWire.Admin');
        if (!isset($this->owner) || !isset($this->details) || !isset($this->address)) {
            $this->warning = $this->getTranslator()->trans('Account owner and account details must be configured before using this module.', array(), 'Modules.BankWire.Admin');
        }
        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->getTranslator()->trans('No currency has been set for this module.', array(), 'Modules.BankWire.Admin');
        }

        $this->extra_mail_vars = array(
                                        '{bankwire_owner}' => Configuration::get('BANK_WIRE_OWNER'),
                                        '{bankwire_details}' => nl2br(Configuration::get('BANK_WIRE_DETAILS')),
                                        '{bankwire_address}' => nl2br(Configuration::get('BANK_WIRE_ADDRESS')),
                                        );
    }

    public function install()
    {
        if (!parent::install() || !$this->registerHook('paymentReturn') || !$this->registerHook('paymentOptions')) {
            return false;
        }
        return true;
    }

    public function uninstall()
    {
        $languages = Language::getLanguages(false);
        foreach ($languages as $lang) {
            if (!Configuration::deleteByName('BANK_WIRE_CUSTOM_TEXT', $lang['id_lang'])) {
                return false;
            }
        }

        if (!Configuration::deleteByName('BANK_WIRE_DETAILS')
                || !Configuration::deleteByName('BANK_WIRE_OWNER')
                || !Configuration::deleteByName('BANK_WIRE_ADDRESS')
                || !Configuration::deleteByName('BANK_WIRE_RESERVATION_DAYS')
                || !parent::uninstall()) {
            return false;
        }
        return true;
    }

    protected function _postValidation()
    {
        if (Tools::isSubmit('btnSubmit')) {
            if (!Tools::getValue('BANK_WIRE_DETAILS')) {
                $this->_postErrors[] = $this->getTranslator()->trans('Account details are required.', array(), 'Modules.BankWire.Admin');
            } elseif (!Tools::getValue('BANK_WIRE_OWNER')) {
                $this->_postErrors[] = $this->getTranslator()->trans('Account owner is required.', array(), "Modules.BankWire.Admin");
            }
        }
    }

    protected function _postProcess()
    {
        if (Tools::isSubmit('btnSubmit')) {
            Configuration::updateValue('BANK_WIRE_DETAILS', Tools::getValue('BANK_WIRE_DETAILS'));
            Configuration::updateValue('BANK_WIRE_OWNER', Tools::getValue('BANK_WIRE_OWNER'));
            Configuration::updateValue('BANK_WIRE_ADDRESS', Tools::getValue('BANK_WIRE_ADDRESS'));

            $custom_text = array();
            $languages = Language::getLanguages(false);
            foreach ($languages as $lang) {
                if (Tools::getIsset('BANK_WIRE_CUSTOM_TEXT_'.$lang['id_lang'])) {
                    $custom_text[$lang['id_lang']] = Tools::getValue('BANK_WIRE_CUSTOM_TEXT_'.$lang['id_lang']);
                }
            }
            Configuration::updateValue('BANK_WIRE_RESERVATION_DAYS', Tools::getValue('BANK_WIRE_RESERVATION_DAYS'));
            Configuration::updateValue('BANK_WIRE_CUSTOM_TEXT', $custom_text);
        }
        $this->_html .= $this->displayConfirmation($this->getTranslator()->trans('Settings updated', array(), 'Admin.Global'));
    }

    protected function _displayBankWire()
    {
        return $this->display(__FILE__, 'infos.tpl');
    }

    public function getContent()
    {
        if (Tools::isSubmit('btnSubmit')) {
            $this->_postValidation();
            if (!count($this->_postErrors)) {
                $this->_postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        } else {
            $this->_html .= '<br />';
        }

        $this->_html .= $this->_displayBankWire();
        $this->_html .= $this->renderForm();

        return $this->_html;
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }

        if (!$this->checkCurrency($params['cart'])) {
            return;
        }

        $this->context->smarty->assign(
            $this->getTemplateVarInfos()
        );

        $newOption = new PaymentOption();
        $newOption->setCallToActionText($this->getTranslator()->trans('Pay by Bank Wire', array(), 'Modules.BankWire.Shop'))
                      ->setAction($this->context->link->getModuleLink($this->name, 'validation', array(), true))
                      ->setAdditionalInformation($this->context->smarty->fetch('module:bankwire/views/templates/hook/bankwire_intro.tpl'));
        $payment_options = [
            $newOption,
        ];

        return $payment_options;
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return;
        }

        $state = $params['order']->getCurrentState();
        if (
            in_array(
                $state,
                array(
                    Configuration::get('PS_OS_BANKWIRE'),
                    Configuration::get('PS_OS_OUTOFSTOCK'),
                    Configuration::get('PS_OS_OUTOFSTOCK_UNPAID'),
                )
        )) {
            $bankwireOwner = $this->owner;
            if (!$bankwireOwner) {
                $bankwireOwner = '___________';
            }

            $bankwireDetails = Tools::nl2br($this->details);
            if (!$bankwireDetails) {
                $bankwireDetails = '___________';
            }

            $bankwireAddress = Tools::nl2br($this->address);
            if (!$bankwireAddress) {
                $bankwireAddress = '___________';
            }

            $this->smarty->assign(array(
                'shop_name' => $this->context->shop->name,
                'total' => Tools::displayPrice(
                    $params['order']->getOrdersTotalPaid(),
                    new Currency($params['order']->id_currency),
                    false
                ),
                'bankwireDetails' => $bankwireDetails,
                'bankwireAddress' => $bankwireAddress,
                'bankwireOwner' => $bankwireOwner,
                'status' => 'ok',
                'reference' => $params['order']->reference,
                'contact_url' => $this->context->link->getPageLink('contact', true)
            ));
        } else {
            $this->smarty->assign('status', 'failed');
        }

        return $this->display(__FILE__, 'payment_return.tpl');
    }

    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    public function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->getTranslator()->trans('Contact details', array(), 'Modules.BankWire.Admin'),
                    'icon' => 'icon-envelope'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->getTranslator()->trans('Account owner', array(), 'Modules.BankWire.Admin'),
                        'name' => 'BANK_WIRE_OWNER',
                        'required' => true
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->getTranslator()->trans('Details', array(), 'Modules.BankWire.Admin'),
                        'name' => 'BANK_WIRE_DETAILS',
                        'desc' => $this->getTranslator()->trans('Such as bank branch, IBAN number, BIC, etc.', array(), 'Modules.BankWire.Admin'),
                        'required' => true
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->getTranslator()->trans('Bank address', array(), 'Modules.BankWire.Admin'),
                        'name' => 'BANK_WIRE_ADDRESS',
                        'required' => true
                    ),
                ),
                'submit' => array(
                    'title' => $this->getTranslator()->trans('Save', array(), 'Admin.Actions'),
                )
            ),
        );
        $fields_form_customization = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Customization'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Reservation delay'),
                        'desc' => $this->l('Number of days the goods will be reserved'),
                        'name' => 'BANK_WIRE_RESERVATION_DAYS',
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Information to the customer'),
                        'name' => 'BANK_WIRE_CUSTOM_TEXT',
                        'desc' => $this->l('Information about the bankwire (processing time, starting of the shipping...)'),
                        'lang' => true
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                )
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? : 0;
        $this->fields_form = array();
        $helper->id = (int)Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='
            .$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array($fields_form, $fields_form_customization));
    }

    public function getConfigFieldsValues()
    {
        $custom_text = array();
        $languages = Language::getLanguages(false);
        foreach ($languages as $lang) {
            $custom_text[$lang['id_lang']] = Tools::getValue(
                'BANK_WIRE_CUSTOM_TEXT_'.$lang['id_lang'],
                Configuration::get('BANK_WIRE_CUSTOM_TEXT', $lang['id_lang'])
            );
        }

        return array(
            'BANK_WIRE_DETAILS' => Tools::getValue('BANK_WIRE_DETAILS', Configuration::get('BANK_WIRE_DETAILS')),
            'BANK_WIRE_OWNER' => Tools::getValue('BANK_WIRE_OWNER', Configuration::get('BANK_WIRE_OWNER')),
            'BANK_WIRE_ADDRESS' => Tools::getValue('BANK_WIRE_ADDRESS', Configuration::get('BANK_WIRE_ADDRESS')),
            'BANK_WIRE_RESERVATION_DAYS' => Tools::getValue('BANK_WIRE_RESERVATION_DAYS', Configuration::get('BANK_WIRE_RESERVATION_DAYS')),
            'BANK_WIRE_CUSTOM_TEXT' => $custom_text,
        );
    }

    public function getTemplateVarInfos()
    {
        $cart = $this->context->cart;
        $total = sprintf(
            $this->getTranslator()->trans('%1$s (tax incl.)', array(), 'Modules.BankWire.Shop'),
            Tools::displayPrice($cart->getOrderTotal(true, Cart::BOTH))
        );

         $bankwireOwner = $this->owner;
        if (!$bankwireOwner) {
            $bankwireOwner = '___________';
        }

        $bankwireDetails = Tools::nl2br($this->details);
        if (!$bankwireDetails) {
            $bankwireDetails = '___________';
        }

        $bankwireAddress = Tools::nl2br($this->address);
        if (!$bankwireAddress) {
            $bankwireAddress = '___________';
        }

        $bankwireReservationDays = Configuration::get('BANK_WIRE_RESERVATION_DAYS');
        if (false === $bankwireReservationDays) {
            $bankwireReservationDays = 7;
        }

        $bankwireCustomText = Tools::nl2br(Configuration::get('BANK_WIRE_CUSTOM_TEXT', $this->context->language->id));
        if (false === $bankwireCustomText) {
            $bankwireCustomText = '';
        }

        return array(
            'total' => $total,
            'bankwireDetails' => $bankwireDetails,
            'bankwireAddress' => $bankwireAddress,
            'bankwireOwner' => $bankwireOwner,
            'bankwireReservationDays' => (int)$bankwireReservationDays,
            'bankwireCustomText' => $bankwireCustomText,
        );
    }
}
