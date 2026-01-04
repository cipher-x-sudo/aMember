<?php

class Am_Paysystem_Jazzcash extends Am_Paysystem_Abstract
{
    const PLUGIN_STATUS = self::STATUS_DEV;
    const PLUGIN_REVISION = '6.3.31';

    const SANDBOX_URL = "https://sandbox.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform";
    const LIVE_URL = "https://payments.jazzcash.com.pk/CustomerPortal/transactionmanagement/merchantform";

    protected $defaultTitle = 'JazzCash';
    protected $defaultDescription = 'purchase using Jazzcash';

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('merchant_id', ['class' => 'am-el-wide'])
            ->setLabel('Merchant ID')
            ->addRule('required');
        $form->addSecretText('pass', ['class' => 'am-el-wide'])
            ->setLabel('Validation Password')
            ->addRule('required');
        $form->addSecretText('hash_key', ['class' => 'am-el-wide'])
            ->setLabel('Integerity Salt')
            ->addRule('required');
        $form->addAdvCheckbox('sandbox')
            ->setLabel('Sandbox Mode');
    }

    function isConfigured()
    {
        return $this->getConfig('merchant_id') &&
            $this->getConfig('pass') &&
            $this->getConfig('hash_key');
    }

    function getSupportedCurrencies()
    {
        return ['PKR'];
    }

    function supportsCancelPage()
    {
        return false;
    }

    function _process($invoice, $request, $result)
    {
        date_default_timezone_set("Asia/karachi");
        $TxnDateTime = date('YmdHis');
        $TxnExpiryDateTime = date('YmdHis', strtotime('+1 Days'));
        $TxnRefNo = $invoice->public_id . '' . $this->getDi()->security->randomString(5);
        $HashArray = [
            $this->getConfig('hash_key'),
            $invoice->first_total * 100,
            $invoice->public_id,
            $invoice->getLineDescription(),
            'EN',
            $this->getConfig('merchant_id'),
            $this->getConfig('pass'),
            $this->getPluginUrl('thanks'),
            $this->getPluginUrl('ipn'),
            $invoice->currency,
            $TxnDateTime,
            $TxnExpiryDateTime,
            $TxnRefNo,
            '1.1'
        ];
        $Securehash = hash_hmac('sha256', implode('&', $HashArray), $this->getConfig('hash_key'));

        $a = new Am_Paysystem_Action_Form($this->getConfig('sandbox') ? self::SANDBOX_URL : self::LIVE_URL);
        $a->pp_Version = '1.1';
        $a->pp_TxnType = '';
        $a->pp_Language = 'EN';
        $a->pp_MerchantID = $this->getConfig('merchant_id');
        $a->pp_Password = $this->getConfig('pass');
        $a->pp_TxnRefNo = $TxnRefNo;
        $a->pp_Amount = $invoice->first_total * 100;
        $a->pp_TxnCurrency = $invoice->currency;
        $a->pp_TxnDateTime = $TxnDateTime;
        $a->pp_BillReference = $invoice->public_id;
        $a->pp_Description = $invoice->getLineDescription();
        $a->pp_DiscountedAmount = '';
        $a->pp_DiscountBank = '';
        $a->pp_TxnExpiryDateTime = $TxnExpiryDateTime;
        $a->pp_ReturnURL = $this->getPluginUrl('thanks');
        $a->pp_SecureHash = $Securehash;
        $a->pp_notify_url = $this->getPluginUrl('ipn');
        $a->pp_SubMerchantID = '';
        $a->ppmpf_1 = '';
        $a->ppmpf_2= '';
        $a->ppmpf_3 = '';
        $a->ppmpf_4 = '';
        $a->ppmpf_5 = '';
        $result->setAction($a);
    }

    public function getRecurringType()
    {
        return self::REPORTS_NOT_RECURRING;
    }

    public function createTransaction($request, $response, array $invokeArgs)
    {
    }

    public function createThanksTransaction($request, $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_Jazzcash_Thanks($this, $request, $response, $invokeArgs);
    }

    function thanksAction($request, $response, array $invokeArgs)
    {
        try {
            parent::thanksAction($request, $response, $invokeArgs);
        }
        catch (Am_Exception_Paysystem_TransactionSource $e)
        {
            $this->getDi()->errorLogTable->logException($e);
            throw $e;
        }
        catch (Am_Exception_Paysystem $e)
        {
            $this->getDi()->errorLogTable->logException($e);
            $response->setRedirect($this->getCancelUrl());
        }
    }

}


class Am_Paysystem_Transaction_Jazzcash_Thanks extends Am_Paysystem_Transaction_Incoming_Thanks
{
    public function validateSource()
    {
        $HashArray = array_filter($this->request->getParams());
        $hash = $HashArray['pp_SecureHash'];
        unset($HashArray['pp_SecureHash']);
        $HashArray = array_filter($HashArray, function($v, $k) {
            return (strpos($k, 'pp') === 0) && (strlen($k) > 0);
        }, ARRAY_FILTER_USE_BOTH);
        ksort($HashArray);
        array_unshift($HashArray, $this->plugin->getConfig('hash_key'));
        return strtolower($hash) == strtolower(hash_hmac('sha256', implode('&', $HashArray), $this->plugin->getConfig('hash_key')));
    }

    public function validateStatus()
    {
        return $this->request->getFiltered('pp_ResponseCode') == '000';
    }

    public function findInvoiceId()
    {
        return $this->request->getFiltered('pp_BillReference');
    }

    public function getUniqId()
    {
        return $this->request->get('pp_RetreivalReferenceNo');
    }

    public function validateTerms()
    {
        return $this->request->get('pp_Amount') == $this->invoice->first_total * 100;
    }

    function loadInvoice($invoiceId)
    {
        $invoice = parent::loadInvoice($invoiceId);
        $this->plugin->_setInvoice($invoice);
        return $invoice;
    }

}