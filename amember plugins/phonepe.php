<?php

class Am_Paysystem_Phonepe extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_DEV;
    const PLUGIN_REVISION = '@@VERSION@@';

    protected $defaultTitle = 'Phonepe';
    protected $defaultDescription = 'Pay by credit card/debit card';

    const
        LIVE_URL = 'https://api.phonepe.com/apis/hermes/pg/v1/pay',
        SANDBOX_URL = 'https://api-preprod.phonepe.com/apis/pg-sandbox/pg/v1/pay';

    protected $_canResendPostback = true;

    function supportsCancelPage()
    {
        return false;
    }

    public function canUpgrade(Invoice $invoice, InvoiceItem $item, ProductUpgrade $upgrade)
    {
        return false;
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText("merchant_id")
            ->setLabel("Merchant ID\n" .
                'Unique MerchantID assigned to the merchant by PhonePe')
            ->addRule('required');

        $form->addSecretText("salt_key")
            ->setLabel("Salt KEY")
            ->addRule('required');

        $form->addText("salt_index")
            ->setLabel("Salt INDEX")
            ->addRule('required');

        $form->addAdvCheckbox("testing")
            ->setLabel("Testing\n" .
                'enable/disable payments with test credit cars ask Epoch support for test credit card numbers');

    }

    function isConfigured()
    {
        return strlen($this->getConfig('merchant_id')) &&
            strlen($this->getConfig('salt_key')) &&
            strlen($this->getConfig('salt_index'));
    }

    function getSupportedCurrencies()
    {
        return ['INR'];
    }

    public function _process($invoice, $request, $result)
    {
        $req = new Am_HttpRequest($this->getConfig('testing') ? self::SANDBOX_URL : self::LIVE_URL, Am_HttpRequest::METHOD_POST);
        $vars = [
            'merchantId' => $this->getConfig('merchant_id'),
            'merchantTransactionId' => $invoice->public_id,
            'amount' => $invoice->first_total * 100,
            'merchantUserId' => $invoice->user_id,
            'redirectUrl' => $this->getReturnUrl(),
            'redirectMode' => 'REDIRECT',
            'callbackUrl' => $this->getPluginUrl('ipn'),
            'paymentInstrument' => [
                'type' => 'PAY_PAGE'
            ]
        ];
        $json = json_encode($vars, JSON_NUMERIC_CHECK);
        $key = hash('sha256', base64_encode($json) . "/pg/v1/pay" . $this->getConfig('salt_key')) .
            '###' . $this->getConfig('salt_index');
        $req->setBody(json_encode([
            'request' => base64_encode($json)
        ]))
            ->setHeader('Content-Type', 'application/json')
            ->setHeader('X-VERIFY', $key);
        $res = $req->send();
        $log = Am_Di::getInstance()->invoiceLogRecord;
        $log->setInvoice($invoice);
        $log->paysys_id = $invoice->paysys_id;
        $log->add($req);
        $log->add($res);
        if($res->getStatus() != 200)
            throw new Am_Exception_InputError('Phonepe API error');
        $vars = json_decode($res->getBody(), true);
        if(empty($vars['data']['instrumentResponse']['redirectInfo']['url']))
            throw new Am_Exception_InputError('Phonepe API error');

        $a = new Am_Paysystem_Action_Redirect($vars['data']['instrumentResponse']['redirectInfo']['url']);
        $result->setAction($a);
    }

    public function createTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Phonepe($this, $request, $response, $invokeArgs);
    }

}

class Am_Paysystem_Transaction_Phonepe extends Am_Paysystem_Transaction_Incoming {

    function __construct($plugin, $request, $response, $invokeArgs)
    {
        $vars_ = json_decode($request->getRawBody(), true);
        $this->vars = json_decode(base64_decode($vars_['response']), true);
        parent::__construct($plugin, $request, $response, $invokeArgs);
    }

    public function getUniqId()
    {
        return $this->vars['data']['transactionId'];
    }

    function findInvoiceId()
    {
        return $this->vars['data']['merchantTransactionId'];
    }

    public function validateSource()
    {
        return true;
    }

    public function validateStatus()
    {
        return $this->vars['success'] == 1;
    }

    public function validateTerms()
    {
        return true;
    }

    function processValidated()
    {
        switch ($this->vars['code'])
        {
            case 'PAYMENT_SUCCESS':
                $this->invoice->addPayment($this);
        }
    }
}
