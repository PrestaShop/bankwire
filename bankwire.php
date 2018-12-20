<?php
/*
* 2007-2016 PrestaShop
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
*  @copyright  2007-2016 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if ( ! defined('_PS_VERSION_'))
	exit;

class BankWire extends PaymentModule
{
	protected $_html = '';
	protected $_postErrors = [];

	public $details;
	public $owner;
	public $address;
	public $extra_mail_vars;

	public function __construct()
	{
		$this->name = 'bankwire';
		$this->tab = 'payments_gateways';
		$this->version = '1.1.2';
		$this->author = 'PrestaShop';
		$this->controllers = ['payment', 'validation'];
		$this->is_eu_compatible = 1;

		$this->currencies = true;
		$this->currencies_mode = 'checkbox';

		$config = Configuration::getMultiple(['BANK_WIRE_DETAILS', 'BANK_WIRE_OWNER', 'BANK_WIRE_ADDRESS']);
		if ( ! empty($config['BANK_WIRE_OWNER']))
			$this->owner = $config['BANK_WIRE_OWNER'];
		if ( ! empty($config['BANK_WIRE_DETAILS']))
			$this->details = $config['BANK_WIRE_DETAILS'];
		if ( ! empty($config['BANK_WIRE_ADDRESS']))
			$this->address = $config['BANK_WIRE_ADDRESS'];

		$this->bootstrap = true;
		parent::__construct();

		$this->displayName = $this->l('Bank wire');
		$this->description = $this->l('Accept payments for your products via bank wire transfer.');
		$this->confirmUninstall = $this->l('Are you sure about removing these details?');
		$this->ps_versions_compliancy = ['min' => '1.6', 'max' => '1.6.99.99'];

		if ( ! isset($this->owner) || ! isset($this->details) || ! isset($this->address))
			$this->warning = $this->l('Account owner and account details must be configured before using this module.');
		if ( ! count(Currency::checkPaymentCurrencies($this->id)))
			$this->warning = $this->l('No currency has been set for this module.');

		$this->extra_mail_vars = [
		    '{bankwire_owner}' => Configuration::get('BANK_WIRE_OWNER'),
		    '{bankwire_details}' => nl2br(Configuration::get('BANK_WIRE_DETAILS')),
		    '{bankwire_address}' => nl2br(Configuration::get('BANK_WIRE_ADDRESS'))
		];
	}

	public function install()
	{
		if ( ! parent::install() || ! $this->registerHook('payment') || ! $this->registerHook('displayPaymentEU') || ! $this->registerHook('paymentReturn'))
			return false;

		return true;
	}

	public function uninstall()
	{
		if ( ! Configuration::deleteByName('BANK_WIRE_DETAILS')
				|| ! Configuration::deleteByName('BANK_WIRE_OWNER')
				|| ! Configuration::deleteByName('BANK_WIRE_ADDRESS')
				|| ! parent::uninstall())
			return false;

		return true;
	}

	protected function _postValidation()
	{
		if (Tools::isSubmit('btnSubmit'))
		{
			if ( ! Tools::getValue('BANK_WIRE_DETAILS'))
				$this->_postErrors[] = $this->l('Account details are required.');
			elseif ( ! Tools::getValue('BANK_WIRE_OWNER'))
				$this->_postErrors[] = $this->l('Account owner is required.');
		}
	}

	protected function _postProcess()
	{
		if (Tools::isSubmit('btnSubmit'))
		{
			Configuration::updateValue('BANK_WIRE_DETAILS', Tools::getValue('BANK_WIRE_DETAILS'));
			Configuration::updateValue('BANK_WIRE_OWNER', Tools::getValue('BANK_WIRE_OWNER'));
			Configuration::updateValue('BANK_WIRE_ADDRESS', Tools::getValue('BANK_WIRE_ADDRESS'));
		}
		$this->_html .= $this->displayConfirmation($this->l('Settings updated'));
	}

	protected function _displayBankWire()
	{
		return $this->display(__FILE__, 'infos.tpl');
	}

	public function getContent()
	{
		if (Tools::isSubmit('btnSubmit'))
		{
			$this->_postValidation();
			if ( ! count($this->_postErrors))
				$this->_postProcess();
			else
				foreach ($this->_postErrors as $err)
					$this->_html .= $this->displayError($err);
		}
		else
			$this->_html .= '<br />';

		$this->_html .= $this->_displayBankWire();
		$this->_html .= $this->renderForm();

		return $this->_html;
	}

	public function hookPayment($params)
	{
		if ( ! $this->active)
			return;
		if ( ! $this->checkCurrency($params['cart']))
			return;

		$this->smarty->assign([
		    'this_path' => $this->_path,
		    'this_path_bw' => $this->_path,
		    'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->name.'/'
		]);

		return $this->display(__FILE__, 'payment.tpl');
	}

	public function hookDisplayPaymentEU($params)
	{
		if ( ! $this->active)
			return;

		if ( ! $this->checkCurrency($params['cart']))
			return;

		$payment_options = [
		    'cta_text' => $this->l('Pay by Bank Wire'),
		    'logo' => Media::getMediaPath(_PS_MODULE_DIR_.$this->name.'/bankwire.jpg'),
		    'action' => $this->context->link->getModuleLink($this->name, 'validation', [], true)
		];

		return $payment_options;
	}

	public function hookPaymentReturn($params)
	{
		if ( ! $this->active)
			return;

		$state = $params['objOrder']->getCurrentState();
		if (in_array($state, [Configuration::get('PS_OS_BANKWIRE'), Configuration::get('PS_OS_OUTOFSTOCK'), Configuration::get('PS_OS_OUTOFSTOCK_UNPAID')]))
		{
			$this->smarty->assign([
			    'total_to_pay' => Tools::displayPrice($params['total_to_pay'], $params['currencyObj'], false),
			    'bankwireDetails' => Tools::nl2br($this->details),
			    'bankwireAddress' => Tools::nl2br($this->address),
			    'bankwireOwner' => $this->owner,
			    'status' => 'ok',
			    'id_order' => $params['objOrder']->id
			]);
			if (isset($params['objOrder']->reference) && ! empty($params['objOrder']->reference))
				$this->smarty->assign('reference', $params['objOrder']->reference);
		}
		else
			$this->smarty->assign('status', 'failed');

		return $this->display(__FILE__, 'payment_return.tpl');
	}

	public function checkCurrency($cart)
	{
		$currency_order = new Currency($cart->id_currency);
		$currencies_module = $this->getCurrency($cart->id_currency);

		if (is_array($currencies_module))
			foreach ($currencies_module as $currency_module)
				if ($currency_order->id == $currency_module['id_currency'])
					return true;

		return false;
	}

	public function renderForm()
	{
		$fields_form = [
		    'form' => [
		        'legend' => [
		            'title' => $this->l('Contact details'),
		            'icon' => 'icon-envelope'
		        ],
		        'input' => [
		            [
		                'type' => 'text',
		                'label' => $this->l('Account owner'),
		                'name' => 'BANK_WIRE_OWNER',
		                'required' => true
		            ],
		            [
		                'type' => 'textarea',
		                'label' => $this->l('Details'),
		                'name' => 'BANK_WIRE_DETAILS',
		                'desc' => $this->l('Such as bank branch, IBAN number, BIC, etc.'),
		                'required' => true
		            ],
		            [
		                'type' => 'textarea',
		                'label' => $this->l('Bank address'),
		                'name' => 'BANK_WIRE_ADDRESS',
		                'required' => true
		            ],
		        ],
		        'submit' => [
		            'title' => $this->l('Save'),
		        ]
		    ],
		];

		$helper = new HelperForm();
		$helper->show_toolbar = false;
		$helper->table = $this->table;
		$lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
		$helper->default_form_language = $lang->id;
		$helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
		$this->fields_form = [];
		$helper->id = (int)Tools::getValue('id_carrier');
		$helper->identifier = $this->identifier;
		$helper->submit_action = 'btnSubmit';
		$helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->tpl_vars = [
		    'fields_value' => $this->getConfigFieldsValues(),
		    'languages' => $this->context->controller->getLanguages(),
		    'id_language' => $this->context->language->id
		];

		return $helper->generateForm([$fields_form]);
	}

	public function getConfigFieldsValues()
	{
		return [
		    'BANK_WIRE_DETAILS' => Tools::getValue('BANK_WIRE_DETAILS', Configuration::get('BANK_WIRE_DETAILS')),
		    'BANK_WIRE_OWNER' => Tools::getValue('BANK_WIRE_OWNER', Configuration::get('BANK_WIRE_OWNER')),
		    'BANK_WIRE_ADDRESS' => Tools::getValue('BANK_WIRE_ADDRESS', Configuration::get('BANK_WIRE_ADDRESS')),
		];
	}
}
