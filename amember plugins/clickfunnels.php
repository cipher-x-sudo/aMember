<?php

/**
 * @logo_url clickfunnels.png
 * @am_payment_api 6.0
 */
class Am_Paysystem_Clickfunnels extends Am_Paysystem_Abstract
{
    const
        PLUGIN_STATUS = self::STATUS_BETA,
        PLUGIN_REVISION = '6.3.29';

    protected
        $defaultTitle = 'ClickFunnels',
        $defaultDescription = '';

    public $_isBackground = true;

    function __construct(Am_Di $di, array $config, $id = false)
    {
        parent::__construct($di, $config, $id);
        foreach ($di->paysystemList->getList() as $p)
        {
            if ($p->getId() == $this->getId())
                $p->setPublic(false);
        }
        $di->billingPlanTable->customFields()->add(new Am_CustomFieldText('clickfunnels_id', "Clickfunnels product ID", "please see product readme"));
    }

    function _initSetupForm(Am_Form_Setup $form)
    {

    }

    function getRecurringType()
    {
        return self::REPORTS_REBILL;
    }

    function getSupportedCurrencies()
    {
        return ['USD'];
    }

    function supportsCancelPage()
    {
        return false;
    }

    function canAutoCreate()
    {
        return true;
    }

    protected function _afterInitSetupForm(Am_Form_Setup $form)
    {
        parent::_afterInitSetupForm($form);
        $form->removeElementByName($this->_configPrefix . $this->getId() . '.auto_create');
    }

    function getConfig($key = null, $default = null)
    {
        switch ($key)
        {
            case 'testing' : return false;
            case 'auto_create' : return true;
            default: return parent::getConfig($key, $default);
        }
    }

    function _process($invoice, $request, $result)
    {

    }

    function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Clickfunnels($this, $request, $response, $invokeArgs);
    }
}

class Am_Paysystem_Transaction_Clickfunnels extends Am_Paysystem_Transaction_Incoming
{
    protected
        $_autoCreateMap = [],
        $req = [];

    function __construct($plugin, $request, $response, $invokeArgs)
    {

        parent::__construct($plugin, $request, $response, $invokeArgs);
        $this->req = json_decode($request->getRawBody(), true);
        foreach($this->req['included'] as $item)
            if($item['type'] == 'contact') $this->contact = $item;

    }

    function generateInvoiceExternalId()
    {
        return $this->req['data']['id'];
    }

    function generateUserExternalId(array $userInfo)
    {
        return $this->contact['attributes']['email_address'];
    }

    function fetchUserInfo()
    {
        $ret = [];
        foreach ([
                'name_f' => 'first_name',
                'name_l' => 'last_name',
                'email' => 'email_address',
                'phone' => 'phone_number'
                 ] as $k => $v)
            $ret[$k] = $this->contact['attributes'][$v];
        return $ret;
    }

    function autoCreateGetProducts()
    {
        $products = [];
        foreach($this->req['included'] as $item)
        {
            if($item['type'] != 'orders/line_item') continue;
            if (!($pl = Am_Di::getInstance()->billingPlanTable->findFirstByData('clickfunnels_id', $item['attributes']['original_product_id'])) &&
                !($pl = Am_Di::getInstance()->billingPlanTable->findFirstByData('clickfunnels_id', $item['attributes']['original_product_sku'])) &&
                !($pl = Am_Di::getInstance()->billingPlanTable->findFirstByData('clickfunnels_id', $item['attributes']['original_product_obfuscated_id'])))
                continue;
            if ($p = $pl->getProduct()) {
                $products[] = $p;
            }
        }
        return $products;
    }

    function findInvoiceId()
    {
        return $this->req['data']['id'];
    }

    function getUniqId()
    {
        return $this->req['data']['id'];
    }

    function validateSource()
    {
        return true;
    }

    function getAmount()
    {
        $amount = 0;
        foreach($this->req['included'] as $item)
            if($item['type'] == 'orders/line_item')
                $amount += $item['attributes']['amount'];
        return $amount;
    }

    function validateStatus()
    {
        return true;
    }

    function validateTerms()
    {
        return $this->getAmount() == $this->invoice->first_period;
    }

    public function processValidated()
    {
        switch ($this->req['event_type'])
        {
            case 'order.completed':
            case 'subscription.activated':
                if (!count($this->invoice->getAccessRecords()) && (floatval($this->invoice->first_total) == 0)) {
                    $this->invoice->addAccessPeriod($this);
                } else {
                    $this->invoice->addPayment($this);
                }
                break;
            case 'cancel':
                $this->invoice->setCancelled();
                break;
        }
    }
}