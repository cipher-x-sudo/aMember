<?php

/**
 * @table paysystems
 * @id coinbase-commerce
 * @title CoinBase Commerce
 * @visible_link https://commerce.coinbase.com/
 * @recurring none
 * @am_payment_api 6.0
 */
class Am_Paysystem_CoinbaseCommerce extends Am_Paysystem_ManualRebill
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '6.3.31';

    protected $defaultTitle = 'Coinbase Commerce';
    protected $defaultDescription = 'paid by bitcoins';

    const API_VERSION = '2018-03-22';
    const CHECKOUT_URL = 'https://api.commerce.coinbase.com/charges';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addPassword('api_key', ['class' => 'am-el-wide'])
            ->setLabel("API KEY\n" .
                'Get it from your coinbase account');
    }

    function supportsCancelPage()
    {
        return true;
    }

    public function getSupportedCurrencies()
    {
        return ['USD', 'EUR', 'BTC'];
    }

    public function _process($invoice, $request, $result)
    {
        $req = new Am_HttpRequest(self::CHECKOUT_URL, Am_HttpRequest::METHOD_POST);
        $req->setHeader([
            'Content-Type' => 'application/json',
            'X-CC-Api-Key' => $this->getConfig('api_key'),
            'X-CC-Version' => self::API_VERSION
        ]);
        $vars = [
            'name' => substr($invoice->getLineDescription(), 0, 100),
            'description' => $invoice->public_id,
            'pricing_type' => 'fixed_price',
            'local_price' => [
                'amount' => $invoice->isFirstPayment()?$invoice->first_total:$invoice->second_total,
                'currency' => $invoice->currency
            ],
            'requested_info' => [
                'email'
            ],
            'redirect_url' => $this->getReturnUrl(),
            'cancel_url' => $this->getCancelUrl()
        ];
        $req->setBody(json_encode($vars));
        $res = $req->send();
        $log = $this->getDi()->invoiceLogRecord;
        $log->paysys_id = $this->getId();
        $log->setInvoice($invoice);
        $log->add($req);
        $log->add($res);
        if ($res->getStatus() != 201) {
            throw new Am_Exception_InternalError("Coinbase: Can't create charge. Got:" . $res->getBody());
        }
        $body = json_decode($res->getBody(), true);
        if (!($hosted_url = @$body['data']['hosted_url'])) {
            throw new Am_Exception_InternalError("Coinbase: Can't create charge. Got:" . $res->getBody());
        }
        $a = new Am_Paysystem_Action_Redirect($hosted_url);
        $result->setAction($a);
    }

    public function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_CoinbaseCommerce($this, $request, $response, $invokeArgs);
    }
}

class Am_Paysystem_Transaction_CoinbaseCommerce extends Am_Paysystem_Transaction_Incoming
{
    protected $order;

    function __construct(Am_Paysystem_Abstract $plugin, Am_Mvc_Request $request, Am_Mvc_Response $response, $invokeArgs)
    {
        parent::__construct($plugin, $request, $response, $invokeArgs);

        $str = $request->getRawBody();
        if (!($this->vars = @json_decode($str))) {
            throw new Am_Exception_InternalError("Coinbase: Can't decode postback: " . $str);
        }
    }

    public function getUniqId()
    {
        return @$this->vars->event->id;
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return (@$this->vars->event->type == "charge:confirmed" ? true : false);
    }

    public function validateTerms()
    {
        return doubleval(@$this->vars->event->data->pricing->local->amount) == doubleval($this->invoice->first_total);
    }

    public function findInvoiceId()
    {
        return @$this->vars->event->data->description;
    }
}