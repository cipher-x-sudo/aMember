<?php
/**
 * @table paysystems
 * @id kuickpay-checkout
 * @title Kuickpay Checkout
 * @visible_link https://kuickpay.com
 * @recurring none
 * @logo_url kuickpay.png
 * @am_payment_api 6.0
 */

class Am_Paysystem_KuickpayCheckout extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '6.3.31';

    protected $defaultTitle = 'Kuickpay Checkout';
    protected $defaultDescription = '';

    const API_TEST_URL = 'https://testcheckout.kuickpay.com/api/';
    const API_LIVE_URL = 'https://checkout.kuickpay.com/api/';

    protected $_isDebug = true;

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function getSupportedCurrencies()
    {
        return array_keys(Am_Currency::getFullList());
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_id', ['class' => 'am-el-wide'])
            ->setLabel('Merchant/Institution ID')
            ->addRule('required');
        $form->addSecretText("secured_key", ['class' => 'am-el-wide'])
            ->setLabel("Kuickpay Secured Key")
            ->addRule('required');
        $form->addAdvCheckbox("testing")->setLabel("Test Mode");
    }

	function isConfigured()
    {
		return $this->getConfig('merchant_id')
            && $this->getConfig('secured_key');
	}

	public function _process($invoice, $request, $result)
    {
        if (!$token = $this->Token()) {
            throw new Am_Exception_Configuration('Can not retrieve Access token');
        }

        $a = new Am_Paysystem_Action_Form($this->url('Redirection'));

        $vars = [
            'InstitutionID' => $this->getConfig('merchant_id'),
            'OrderID' => $invoice->public_id,
            'MerchantName' => $this->getDi()->config->get('site_title'),
            'Amount' => $invoice->first_total,
            'TransactionDescription' => $invoice->getLineDescription(),
            'CustomerMobileNumber' => $invoice->getPhone(),
            'CustomerEmail' => $invoice->getEmail(),
            'SuccessUrl' => $this->getPluginUrl('thanks'),
            'FailureUrl' => $this->getCancelUrl(),
            'OrderDate' => sqlDate('now'),
            'CheckoutUrl' => $this->getConfig('testing') ? 'https://testcheckout.kuickpay.com' : 'https://checkout.kuickpay.com',
            'Token' => $token,
            'GrossAmount' => $invoice->first_total,
            'TaxAmount' => 0,
            'Discount' => 0,
        ];
        $vars['Signature'] = md5($vars['InstitutionID'] . $vars['OrderID'] . $vars['Amount'] . $this->getConfig('secured_key'));
        $a->setParams($vars);

        $result->setAction($a);
    }

    function url($slug)
    {
        return ($this->getConfig('testing') ? self::API_TEST_URL : self::API_LIVE_URL) . $slug;
    }

    function Token()
    {
        $req = new Am_HttpRequest($this->url('KPToken'), Am_HttpRequest::METHOD_POST);

        $req->setHeader('accept', 'application/json');
        $req->setHeader('content-type', 'application/json');
        $req->setBody(json_encode([
            'institutionID' => $this->getConfig('merchant_id'),
            'kuickpaySecuredKey' => $this->getConfig('secured_key'),
        ]));

        $res = $req->send();
        $this->log($req, $res, 'Token');

        if ($res->getStatus() == 200 && ($payload = json_decode($res->getBody(), true))) {
            return $payload['auth_token'] ?? null;
        }
    }

    public function createThanksTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_KuickpayCheckout_Thanks($this, $request, $response,$invokeArgs);
    }

    public function createTransaction($request, $response, array $invokeArgs)
    {
        return null;
    }

    function getReadme()
    {
        return <<<CUT
Phone brick is required on signup form.
CUT;

    }

    function log($req, $resp, $title)
    {
        if (!$this->_isDebug)
            return;
        $l = $this->getDi()->invoiceLogRecord;
        $l->paysys_id = $this->getId();
        $l->title = $title;
        $l->add($req);
        $l->add($resp);
    }
}

class Am_Paysystem_Transaction_KuickpayCheckout_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function findInvoiceId()
    {
        return $this->request->getParam('OrderId');
    }

    public function getUniqId()
    {
        return $this->request->getParam('TransactionId');
    }

    public function validateSource()
    {
        $Signature = $this->request->getParam('Signature');

        $hash = md5('OrderId=' . $this->request->getParam('OrderId')
            . '&TransactionId=' . $this->request->getParam('TransactionId')
            . '&KuickpaySecuredKey=' . $this->plugin->getConfig('secured_key')
            . '&ResponseCode=' . $this->request->getParam('ResponseCode'));

        if (!hash_equals($hash, $Signature)) {
            return false;
        }

        return true;
    }

    public function validateStatus()
    {
        return $this->request->getParam('ResponseCode') == '00';
    }

    public function validateTerms()
    {
        return true;
    }
}