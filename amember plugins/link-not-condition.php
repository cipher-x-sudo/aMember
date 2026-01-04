<?php

/**
 * @am_plugin_api 6.0
*/
class Am_Plugin_LinkNotCondition extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_PRODUCTION;
    const PLUGIN_COMM = self::COMM_COMMERCIAL;
    const PLUGIN_REVISION = '6.3.31';

    protected $_configPrefix = 'misc.';

    static function getDbXml()
    {
        return <<<CUT
<schema version="4.0.0">
    <table name="link">
        <field name="not_conditions" type="varchar" len="255" notnull="0" />
        <field name="not_conditions_expired" type="tinyint" notnull="0" />
        <field name="not_conditions_future" type="tinyint" notnull="0" />
    </table>
</schema>
CUT;
    }

    function onGridContentLinksInitGrid(Am_Event_Grid $e)
    {
        $e->getGrid()->setFormValueCallback('not_conditions',
            array('RECORD', 'unserializeList'),
            array('RECORD', 'serializeList'));
    }

    function onGridContentLinksInitForm(Am_Event_Grid $e)
    {
        $form = $e->getGrid()->getForm();

        $group = $form->addGroup()
                ->setLabel(___('Show Link only if customer has no subscription (optional)'));
        $group->setSeparator('<br />');

        $select = $group->addMagicSelect('not_conditions', array('class'=>'am-combobox'));
        $this->addCategoriesProductsList($select);
        $group->addAdvCheckbox('not_conditions_expired')
            ->setContent(___('check expired subscriptions too'));
        $group->addAdvCheckbox('not_conditions_future')
            ->setContent(___('check future subscriptions too'));

    }

    function onGetAllowedResources(Am_Event $e)
    {
        $user = $e->getUser();
        $res = $e->getReturn();

        foreach ($res as $k => $v) {
            if ($v->getAccessType() == ResourceAccess::LINK
                && !$this->hasAccess($user, $v)) {

                unset($res[$k]);
            }
        }

        $e->setReturn($res);
    }

    function onUserHasAccess(Am_Event $e)
    {
        $type = $e->getResource_type();
        $id = $e->getResource_id();
        if ($type == ResourceAccess::LINK) {
            $r = $this->getDi()->linkTable->load($id);
            $e->setReturn($e->getReturn() && $this->hasAccess($e->getUser(), $r));
        }
    }

    function hasAccess(User $user, ResourceAbstract $r)
    {
        return !$r->not_conditions || $this->checkNotConditions($user, $r);
    }

    public function checkNotConditions(User $user, ResourceAbstract $r)
    {
        $pids = array();
        $catp = null;
        foreach (array_filter(explode(',', $r->not_conditions)) as $s)
        {
            if ($s == 'c-1')
            {
                if ($r->not_conditions_future && $user->getFutureProductIds())
                    return false;
                if (!$r->not_conditions_expired)
                    return $user->status != User::STATUS_ACTIVE;
                else
                    return $user->status == User::STATUS_PENDING;
            } elseif ($s[0] == 'p') {
                $pids[] = substr($s, 1);
            } elseif ($s[0] == 'c') {
                if (!$catp)
                    $catp = $this->getDi()->productCategoryTable->getCategoryProducts(true);
                if (!empty($catp[substr($s, 1)]))
                    $pids = array_merge($pids, $catp[substr($s, 1)]);
            }
        }
        if (!$pids) return true;
        $userPids = $user->getActiveProductIds();
        if ($r->not_conditions_expired)
            $userPids = array_merge($userPids, $user->getExpiredProductIds());
        if ($r->not_conditions_future)
            $userPids = array_merge($userPids, $user->getFutureProductIds());

        return !$pids || !array_intersect($pids, $userPids);
    }

    function addCategoriesProductsList(HTML_QuickForm2_Element_Select $select)
    {
        $g = $select->addOptgroup(___('Product Categories'),
            array('class' => 'product_category_id', 'data-text' => ___("Category")));
        $g->addOption(___('Any Product'), 'c-1', array('style' => 'font-weight: bold'));
        foreach ($this->getDi()->productCategoryTable->getAdminSelectOptions() as $k => $v) {
            $g->addOption($v, 'c' . $k);
        }
        $g = $select->addOptgroup(___('Products'), array('class' => 'product_id', 'data-text' => ___("Product")));
        foreach ($this->getDi()->productTable->getOptions() as $k => $v) {
            $g->addOption($v, 'p' . $k);
        }
    }
}