<?php

/**
 * @title Credits
 * @desc
 *       Plugin allows to sell "credits" - count of credits will be configured
 *       in product billing plan settings, and added to user account after purchase
 *
 *       Code to deduct "credits" must be implemented by your system
 *
 *       Credits are not expiring with subscription (!)
 *       aMember does not take account of subscription dates at all when accounting credits
 *       Once accounted, credit records are not updated if product setting changed
 *
 * @example
 *
 *      // with comment
 *      Am_Di::getInstance()->plugins_misc->loadGet('credits')->debit(120, "Used 120 credits");
 *      // debit 120 credits from user# 1234
 *      Am_Di::getInstance()->plugins_misc->loadGet('credits')->debit(120, "Used 120 credits", 1234);
 *      // balance - get current number of credits
 *      Am_Di::getInstance()->plugins_misc->loadGet('credits')->balance();
 *
 * @am_plugin_api 6.0
 */

class Am_Plugin_Credits extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_COMM = self::COMM_COMMERCIAL;
    const PLUGIN_REVISION = '6.3.31';
    const ADMIN_PERM_ID = 'credits';

    const DEFAULT_LOW_LEVEL_THRESHOLD = 10;

    protected $_table;

    ////////////////////////// PUBLIC API FUNCTIONS ////////////////////
    /**
     * Debit (-) credits from user account
     * @param int $credits required, count of credits to deduct (positive)
     * @param string $comment required, description of transaction
     * @param int $user_id optional, uses user_id from session by default
     * @param string $reference_id optional, id of transaction in third-party database
     * @return int credit_id value of inserted record
     */
    function debit($credits, $comment, $user_id=null, $reference_id = null)
    {
        if ($credits <= 0)
            throw new Am_Exception_InternalError("\$credits must be not-zero in " . __METHOD__);
        $this->_insert(-$credits, $comment, $user_id, $reference_id);
    }

    /**
     * Credit (+) credits to user account
     * @param int $credits required, count of credits to deduct
     * @param string $comment required, description of transaction
     * @param int $user_id optional, uses user_id from session by default
     * @param string $reference_id optional, id of transaction in third-party database
     * @return int credit_id value of inserted record
     */
    function credit($credits, $comment, $user_id=null, $reference_id = null)
    {
        if ($credits <= 0)
            throw new Am_Exception_InternalError("\$credits must be not-zero in " . __METHOD__);
        return $this->_insert($credits, $comment, $user_id, $reference_id);
    }

    /**
     * Return customer balance to today or to specified date
     * @return int credits balance to date (default to current date)
     */
    function balance($user_id=null, $date=null)
    {
        if (!$user_id)
        {
            $user_id = $this->getDi()->auth->getUserId();
            if (!$user_id)
                throw new Am_Exception_InternalError("user_id must be specified or user must be logged-in to run " . __METHOD__);
        }
        return (int) $this->getDi()->db->selectCell("SELECT SUM(`value`) FROM ?_credit
            WHERE user_id=?d
                { AND dattm <= ?}", $user_id, $date !== null ? sqlDate($date) : DBSIMPLE_SKIP);
    }

    ////////////////////////// PLUGIN FUNCTIONS ////////////////////

    static function getDbXml()
    {
        return <<<CUT
<schema version="4.0.0">
    <table name="credit">
        <field name="credit_id" type="int" notnull="1" extra="auto_increment"/>
        <field name="dattm" type="datetime"/>
        <field name="user_id" type="int"/>
        <field name="value" type="int" comment="positive value: credit, negavive value: debit"/>
        <field name="comment" type="varchar" len="255" comment="useful for debit comments"/>
        <field name="access_id" type="int" notnull="0" comment='will be set to related access_id for credit records'/>
        <field name="reference_id" type="varchar" len="255" notnull="0" comment="you can set it to your internal operation reference# to link, is not used in aMember"/>
        <index name="PRIMARY" unique="1">
            <field name="credit_id" />
        </index>
        <index name="user-dattm">
            <field name="user_id" />
            <field name="dattm" />
        </index>
    </table>
</schema>
CUT;
    }

    static function getEtXml()
    {
        return <<<CUT
<table_data name="email_template">
    <row type="email_template">
        <field name="name">credits.notify_low_level_user</field>
        <field name="email_template_layout_id">1</field>
        <field name="lang">en</field>
        <field name="format">text</field>
        <field name="subject">%site_title%: Low level of Credits Balance</field>
        <field name="txt">
Low level of Credits Balance
        </field>
    </row>
</table_data>
CUT;
    }

    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('credits.title')
            ->setLabel(___("Credits Title
            Used as caption of tabs and links displayed on user or admin side"));

        $form->addAdvCheckbox('credits.hide_credit_balance_link')
            ->setLabel(___("Do not show 'Credits Balance' link at Useful Links"));

        $form->addInteger('credits.count_row_page', ['placeholder' => $this->getDi()->config->get('admin.records-on-page', 10)])
            ->setLabel(___("Rows Count per Page at Credits History page\n" .
                "empty - it uses global settings"));
        $form->setDefault('credits.title', ___('Credits'));

        $form->addElement('email_checkbox', 'credits.notify_low_level_user')
            ->setLabel(___('Low Level User Notification'));
        $form->addText('credits.low_level_threshold',
            ['size'=>3, 'placeholder' => self::DEFAULT_LOW_LEVEL_THRESHOLD, 'id' => 'll-threshold'])
            ->setLabel(___('Low Level Threshold'));
        $form->addScript()
            ->setScript(<<<CUT
jQuery(function(){
    jQuery('[name$=notify_low_level_user]').change(function(){
        jQuery('#ll-threshold').closest('.am-row').toggle(this.checked);
    }).change();
});
CUT
            );

        $form->addText('credits.payout_rate', ['size'=>4])
            ->setLabel(___("Affiliate Payout Conversion Rate\n".
                "how many credits affiliate should receive for %s",  Am_Currency::render(1)));

        $form->setDefault('credits.payout_rate', 1);
    }

    public function init()
    {
        $this->getDi()->productTable->customFields()->add(new Am_CustomFieldText('credit', 'Number of Credits', "count of credits that would be added to user's balance when user order this product"));
    }

    function onGetPermissionsList(Am_Event $e)
    {
        $e->addReturn(___('Credits'), self::ADMIN_PERM_ID);
    }

    function onAffPayout(Am_Event $e)
    {
        foreach ($e->getPayouts() as $payout) {
            if ($payout->type == 'credit') {
                foreach ($this->getDi()->affPayoutDetailTable->findByPayoutId($payout->pk()) as $d) {
                    $this->credit(
                        floor($d->amount * $this->getConfig('payout_rate', 1)),
                        ___('Commission %s, %s', Am_Currency::render($d->amount), $payout->thresehold_date),
                        $d->aff_id,
                        "aff_payout_detail-{$d->pk()}"
                    );
                    $d->updateQuick('is_paid', 1);
                }
            }
        }
    }

    function onAffGetPayoutOptions(Am_Event $e)
    {
        $e->addReturn('Credits', 'credit');
    }

    function onAccessAfterInsert(Am_Event $event)
    {
        $this->_update($event->getAccess()->user_id);
    }

    function onAccessAfterDelete(Am_Event $event)
    {
        $this->_update($event->getAccess()->user_id);
    }

    function onAccessAfterUpdate(Am_Event $event)
    {
        $this->_update($event->getAccess()->user_id);
    }

    function _update($user_id)
    {
        $before = $this->balance($user_id);

        // delete credits with removed "access"
        $ids = $this->getDi()->db->selectCol("
                SELECT credit_id
                FROM ?_credit c LEFT JOIN ?_access a USING (access_id)
                WHERE c.user_id=?d AND c.access_id > 0 AND a.access_id is NULL", $user_id);
        if ($ids)
            $this->getDi()->db->query("DELETE FROM ?_credit WHERE credit_id IN (?a)", $ids);

        // insert records with no related "credit"
        $this->getDi()->db->select("
            INSERT INTO ?_credit
            SELECT
                null as credit_id,
                IFNULL(p.dattm, a.begin_date) as dattm,
                a.user_id,
                d.`value`*a.qty as `value`,
                pr.title as comment,
                a.access_id,
                null as reference_id
            FROM ?_access a
                LEFT JOIN ?_credit c ON a.access_id = c.access_id
                INNER JOIN ?_data d ON d.`table`='product'
                    AND d.`id`=a.product_id AND d.`key`='credit' AND d.`value` > 0
                LEFT JOIN ?_invoice_payment p ON a.invoice_payment_id=p.invoice_payment_id
                LEFT JOIN ?_invoice_item it ON it.invoice_id = a.invoice_id and it.item_id=a.product_id
                LEFT JOIN ?_product pr ON a.product_id = pr.product_id
            WHERE a.user_id=?d AND c.credit_id IS NULL
        ", $user_id);

        $after = $this->balance($user_id);
        $this->notifyIfNecessary($user_id, $before, $after);
    }

    function notifyIfNecessary($user_id, $before, $after)
    {
        if (
            $this->getConfig('notify_low_level_user')
            && $before > $this->getConfig('low_level_threshold', self::DEFAULT_LOW_LEVEL_THRESHOLD)
            && $after <= $this->getConfig('low_level_threshold', self::DEFAULT_LOW_LEVEL_THRESHOLD)
        ) {
            $user = $this->getDi()->userTable->load($user_id);
            $et = Am_Mail_Template::load('credits.notify_low_level_user', $user->lang);
            $et->setUser($user);
            $et->send($user);
        }
    }

    /**
     * @return CreditTable
     */
    function getTable()
    {
        if (!$this->_table) {
            $this->_table = $this->getDi()->creditTable;
        }
        return $this->_table;
    }

    protected function _insert($credits, $comment, $user_id=null, $reference_id = null)
    {
        if (!$user_id) {
            $user_id = $this->getDi()->auth->getUserId();
            if (!$user_id)
                throw new Am_Exception_InternalError("user_id must be specified or user must be logged-in to run " . __METHOD__);
        }

        $d = $this->getTable()->createRecord();
        $d->user_id = $user_id;
        $d->dattm = $this->getDi()->sqlDateTime;
        $d->value = (int) $credits;
        $d->comment = $comment;
        $d->reference_id = $reference_id;
        $d->insert();
        return $d->pk();
    }

    function onUserTabs(Am_Event_UserTabs $e)
    {
        if ($e->getUserId() > 0) {
            $e->getTabs()->addPage([
                'id' => 'credits',
                'controller' => 'admin-credits',
                'action' => 'index',
                'params' => [
                    'user_id' => $e->getUserId(),
                ],
                'label' => $this->getTitle(___('Credits')),
                'order' => 1000,
                'resource' => self::ADMIN_PERM_ID
            ]);
        }
    }

    function onUserMenuItems(Am_Event $e)
    {
        $e->addReturn([$this, 'buildMenu'], 'credits');
    }

    function buildMenu(Am_Navigation_Container $nav, /* ?User */ $user, $order, $config)
    {
        if ($user) {
            return $nav->addPage([
                'id' => 'credits',
                'controller' => 'credits',
                'action' => 'index',
                'label' => ___('%s History', $this->getTitle(___('Credits'))),
                'order' => $order
            ]);
        }
    }

    function onGetMemberLinks(Am_Event $e)
    {
        if (!$this->getConfig('hide_credit_balance_link'))
            $e->addReturn(
                ___("Your %s Balance: ", $this->getTitle(___('Credits'))) . $this->getDi()->plugins_misc->loadGet('credits')->balance(),
                $this->getDi()->url('credits', false)
            );
    }

    function getTitle($default = null)
    {
        return $this->getConfig('title', $default);
    }

    function getConfig($key = null, $default = null)
    {
        return $this->getDi()->config->get("credits.{$key}", $default);
    }
}

class Credit extends Am_Record
{
    protected $_key = 'credit_id';
    protected $_table = '?_credit';

    function insert($reload = true)
    {
        $pl = $this->getPlugin();

        $before = $pl->balance($this->user_id);
        $_ = parent::insert($reload);
        $after = $pl->balance($this->user_id);
        $pl->notifyIfNecessary($this->user_id, $before, $after);
        return $_;
    }

    /**
     * @return Am_Plugin_Credits
     * @throws Am_Exception_InternalError
     */
    function getPlugin()
    {
        return $this->getDi()->plugins_misc->loadGet('credits');
    }
}

class CreditTable extends Am_Table
{
    protected $_key = 'credit_id';
    protected $_table = '?_credit';
    protected $_recordClass = 'Credit';
}

class AdminCreditsController extends Am_Mvc_Controller_Grid
{
    protected $layout = 'admin/user-layout.phtml';

    public function checkAdminPermissions(Admin $admin)
    {
        return true;
    }

    /**
     * @return Am_Plugin_Credits
     * @throws Am_Exception_InternalError
     */
    function getPlugin()
    {
        return $this->getDi()->plugins_misc->loadGet('credits');
    }

    function createGrid()
    {
        $ds = new Am_Query($this->getDi()->creditTable);
        $ds->addWhere('user_id=?', $this->user_id)
            ->setOrder('dattm', true);
        $grid = new Am_Grid_Editable('_credits', $this->getPlugin()->getTitle(___('Credits')), $ds, $this->getRequest(), $this->getView(), $this->getDi());
        $grid->setPermissionId(Am_Plugin_Credits::ADMIN_PERM_ID);
        $grid->addField(new Am_Grid_Field_Date('dattm', ___('Date')));
        $grid->addField('value', ___('Value'));
        $grid->addField('comment', ___('Comment'));
        $grid->setForm([$this, 'createForm']);
        $grid->addCallback(Am_Grid_Editable::CB_VALUES_FROM_FORM, [$this, 'valuesFromForm']);
        $grid->addCallback(Am_Grid_ReadOnly::CB_RENDER_STATIC, [$this, 'renderStatic']);
        $grid->addCallback(Am_Grid_ReadOnly::CB_TR_ATTRIBS, [$this, 'cbGetTrAttribs']);
        $grid->actionsClear();
        $grid->actionAdd(new Am_Grid_Action_Insert());
        return $grid;
    }

    public function cbGetTrAttribs(& $ret, $record)
    {
        if ($record->value < 0)
        {
            $ret['class'] = isset($ret['class']) ? $ret['class'] . ' debit' : 'debit';
        }
    }

    function renderStatic(& $out)
    {
        $out .= <<<CUT
    <style>
    <!--
    tr.debit {
       color: #C43D33;
    }
    -->
    </style>
CUT;
        $out .= "<h1 id='credit-balance'>".___('%s Balance', $this->getPlugin()->getTitle(___('Credits'))) ." : ". $this->getPlugin()->balance($this->user_id) . "</h1>";
        $out .= "<script type=\"text/javascript\">jQuery(function($){ jQuery('#credit-balance').insertBefore(jQuery('#grid-credits')); });</script>";
    }

    function valuesFromForm(& $values)
    {
        if ($values['_type'] == 'debit')
        {
            $values['value'] = -1 * $values['value'];
        }

        $values['user_id'] = $this->user_id;
    }

    function createForm()
    {
        $form = new Am_Form_Admin('credits');

        $form->addAdvRadio('_type')
            ->setLabel('Type')
            ->loadOptions([
                'credit' => '+ Credit',
                'debit' => '− Debit'
            ]);

        $value = $form->addText('value')
                ->setLabel('Value');

        $value->setValue(0);
        $value->addRule('required');

        $dattm = $form->addDate('dattm')
                ->setLabel('Date');

        $dattm->setValue(date('Y-m-d'));
        $dattm->addRule('required');

        $form->addTextarea('comment')
            ->setLabel('Comment');

        return $form;
    }

    function preDispatch()
    {
        $this->setActiveMenu('users-browse');

        $this->user_id = $this->getInt('user_id');
        if (!$this->user_id)
            throw new Am_Exception_InputError("Wrong URL specified: no member# passed");

        parent::preDispatch();
    }
}

class CreditsController extends Am_Mvc_Controller_Grid
{
    protected $layout = 'layout.phtml';

    function preDispatch()
    {
        $this->getDi()->auth->requireLogin($this->getUrl());
        $this->user_id = $this->getDi()->auth->getUserId();
        if ($this->getPlugin()->getConfig('hide_credit_history_tab')
            && $this->getPlugin()->getConfig('hide_credit_balance_link')) {
            throw new Am_Exception_AccessDenied("You have no enough permissions for this operation");
        }

        parent::preDispatch();
    }

    /**
     * @return Am_Plugin_Credits
     * @throws Am_Exception_InternalError
     */
    function getPlugin()
    {
        return $this->getDi()->plugins_misc->loadGet('credits');
    }

    function createGrid()
    {
        $ds = new Am_Query($this->getDi()->creditTable);
        $ds->addWhere('user_id=?', $this->user_id)
            ->setOrder('dattm', true);
        $grid = new Am_Grid_ReadOnly('_credits', $this->getPlugin()->getTitle(___('Credits')), $ds, $this->getRequest(), $this->getView(), $this->getDi());
        $grid->setCountPerPage(
            $this->getPlugin()->getConfig('count_row_page', $this->getDi()->config->get('admin.records-on-page', 10))
        );
        $grid->addField(new Am_Grid_Field_Date('dattm', ___('Date')));
        $grid->addField('value', ___('Value'));
        $grid->addField('comment', ___('Comment'));
        $grid->addCallback(Am_Grid_ReadOnly::CB_RENDER_STATIC, [$this, 'renderStatic']);
        $grid->addCallback(Am_Grid_ReadOnly::CB_TR_ATTRIBS, [$this, 'cbGetTrAttribs']);
        return $grid;
    }

    public function cbGetTrAttribs(& $ret, $record)
    {
        if ($record->value < 0)
            $ret['class'] = isset($ret['class']) ? $ret['class'] . ' debit' : 'debit';
    }

    function renderStatic(& $out, Am_Grid_ReadOnly $grid)
    {
        $out .= <<<CUT
    <style>
    <!--
    tr.debit {
       color: #C43D33;
    }
    -->
    </style>
CUT;
        $out .= "<h1 id='credit-balance'>".___('%s Balance', $this->getPlugin()->getTitle(___('Credits'))).": " . $this->getDi()->plugins_misc->loadGet('credits')->balance() . "</h1>";
        $out .= "<script type=\"text/javascript\">jQuery(function($){ jQuery('#credit-balance').insertBefore(jQuery('#grid-credits')); });</script>";
    }
}

Am_Di::getInstance()->hook->add(Am_Event::USER_SEARCH_CONDITIONS, function (Am_Event $e) {

    if (!class_exists('Am_Query_User_Condition_CreditsBalance', false)) :

        class Am_Query_User_Condition_CreditsBalance
            extends Am_Query_Condition
            implements Am_Query_Renderable_Condition
        {
            protected $val, $op;

            public function getId()
            {
                return 'cb';
            }

            function _getJoin(Am_Query $q)
            {
                return "LEFT JOIN ?_credit credit ON u.user_id=credit.user_id";
            }

            function getHaving(Am_Query $db)
            {
                return "SUM(IFNULL(credit.`value`, 0)) $this->op $this->val";
            }

            public function getDescription()
            {
                return htmlentities(___('Credits Balance %s %d', $this->op, $this->val));
            }

            public function renderElement(HTML_QuickForm2_Container $form)
            {
                $title = ___('Credits Balance');

                $form->options[___('Misc')][$this->getId()] = $title;
                $group = $form->addGroup($this->getId())
                    ->setLabel($title)
                    ->setAttribute('id', $this->getId())
                    ->setAttribute('class', 'searchField empty');
                $group->addSelect('op')
                    ->loadOptions([
                        '=' => '=',
                        '>' => '>',
                        '<' => '<',
                    ]);
                $group->addInteger('val');
            }

            public function setFromRequest(array $input)
            {
                $id = $this->getId();
                if (!empty($input[$id]['op'])) {
                    $this->val = (int)$input[$id]['val'];
                    $this->op = in_array($input[$id]['op'], ['=', '>', '<']) ? $input[$id]['op'] : '=';
                    return true;
                }

                return false;
            }

            public function isEmpty()
            {
                return empty($this->op);
            }
        }

    endif;

    $e->addReturn(new Am_Query_User_Condition_CreditsBalance);
});

if (Am_Di::getInstance()->modules->isEnabled('aff')) :

class Am_Aff_PayoutMethod_Credit extends Am_Aff_PayoutMethod
{
    public function export(AffPayout $payout, Am_Query $details, Am_Mvc_Response $response)
    {
        $q = $details->query();
        while ($d = $payout->getDi()->db->fetchRow($q))
        {
            $d = $payout->getDi()->affPayoutDetailTable->createRecord($d);
            /* @var $d AffPayoutDetail */
            $aff = $d->getAff();
            $rows[] = [
                $aff->name_f,
                $aff->name_l,
                $aff->email,
                moneyRound($d->amount),
                Am_Currency::getDefault(),
                $aff->user_id,
                "Affiliate commission to " . amDate($payout->thresehold_date),
            ];
        }
        $this->sendCsv("credits-commission-".$payout->payout_id.".txt", $rows, $response);
    }

    public function addFields(Am_CustomFieldsManager $m) {}
}

endif;