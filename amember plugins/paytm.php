<?php
/**
 * @table paysystems
 * @id paytm
 * @title Paytm
 * @desc Paytm Checkout for your website provides a secure, PCI-compliant way to accept Debit/Credit card, Net-Banking, UPI and Paytm wallet payments from your customers
 * @visible_link https://paytm.com/
 * @recurring none
 * @logo_url paytm.png
 * @am_payment_api 6.0
 */
class Am_Paysystem_Paytm extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_REVISION = '@@VERSION@@';

    const IV = "@@@@&&&&####$$$$";

    const URL_PROD = "https://securegw.paytm.in/";
    const URL_STAGE = "https://securegw-stage.paytm.in/";

    protected $defaultTitle = 'Paytm';
    protected $defaultDescription = 'purchase using Wallet, Net Banking, or Credit Card';

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    function getSupportedCurrencies()
    {
        return ['INR'];
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('MID')
            ->setLabel('Merchant MID')
            ->addRule('required');
        $form->addSecretText('KEY')
            ->setLabel('Merchant Key')
            ->addRule('required');
        $form->addText('WEBSITE')
            ->setLabel("WEBSITE\nfor staging environment: WEBSTAGING")
            ->addRule('required');
        $form->addText('INDUSTRY_TYPE_ID')
            ->setLabel("INDUSTRY_TYPE_ID\nFor staging environment: Retail")
            ->addRule('required');
        $form->addAdvRadio('env')
            ->loadOptions([
                'prod' => 'Production',
                'stage' => 'Staging',
            ])
            ->setLabel('Environment')
            ->addRule('required');
    }

    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $a = new Am_Paysystem_Action_Form($this->url('order/process'));

        $data = [
            'MID' => $this->getConfig('MID'),
            'ORDER_ID' => $invoice->public_id,
            'CUST_ID' => $invoice->getUser()->pk(),
            'TXN_AMOUNT' => $invoice->first_total,
            'CHANNEL_ID' => 'WEB',
            'WEBSITE' => $this->getConfig('WEBSITE'),
            'EMAIL' => $invoice->getUser()->email,
            'INDUSTRY_TYPE_ID' => $this->getConfig('INDUSTRY_TYPE_ID'),
            'CALLBACK_URL' => $this->getPluginUrl('thanks'),
        ];

        $data['CHECKSUMHASH'] = $this->checksumhash($data);

        $a->setParams($data);

        $result->setAction($a);
    }

    function url($slug)
    {
        return ($this->getConfig('env') == 'prod' ? self::URL_PROD : self::URL_STAGE) . $slug;
    }

    function checksumhash($data, $salt = null)
    {
        ksort($data);

        $data = array_filter($data, function($_) { return stripos($_, '|') === false && stripos($_, 'REFUND') === false;});
        $data = array_map(function($_) {return $_ == 'null' ? '' : $_;}, $data);

        $data[] = $salt = ($salt ?: $this->getDi()->security->randomString(4));

        $_ = implode("|", $data);

        return openssl_encrypt(
            hash("sha256", $_) . $salt,
            "AES-128-CBC",
            $this->getConfig('KEY'),
            0,
            self::IV
        );
    }

    function verifychecksumhash($data, $hash)
    {
        $_hash = openssl_decrypt(
            $hash,
            "AES-128-CBC",
            $this->getConfig('KEY'),
            0,
            self::IV
        );
        $salt = substr($_hash, -4);

        return $this->checksumhash($data, $salt) == $hash;
    }

    public function getReadme()
    {
        //nop
    }

    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        //nop
    }

    public function createThanksTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Paytm($this, $request, $response, $invokeArgs);
    }
}

class Am_Paysystem_Transaction_Paytm extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function findInvoiceId()
    {
        return $this->request->get('ORDERID');
    }

    function getUniqId()
    {
        return $this->request->get('TXNID');
    }

    public function validateSource()
    {
        $data = $this->request->getRequestOnlyParams();
        $hash = $data['CHECKSUMHASH'];
        unset($data['CHECKSUMHASH']);

        return $this->plugin->verifychecksumhash($data, $hash);
    }

    public function validateStatus()
    {
        //@see self::processValidated()
        return true;
    }

    public function validateTerms()
    {
        return $this->request->get('TXNAMOUNT') == $this->invoice->first_total
            && $this->request->get('CURRENCY') == $this->invoice->currency;
    }

    function processValidated()
    {
        if ($this->request->get('STATUS') == 'TXN_SUCCESS') {
            parent::processValidated();
        } else {
            $url = $this->plugin->getDi()->surl("cancel", ['id'=>$this->getInvoice()->getSecureId("CANCEL")], false);
            Am_Mvc_Response::redirectLocation($url);
        }
    }
}

