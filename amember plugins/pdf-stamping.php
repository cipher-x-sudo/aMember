<?php

/**
 * @am_plugin_api 6.0
 *
 * Convert PDF to 1.4 version (no Cross Reference Streams) on Ubuntu
 * apt-get install  ghostscript
 * gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/screen -dNOPAUSE -dQUIET -dBATCH -sOutputFile=output.pdf input.pdf
 *
 * Reference:
 * http://manpages.ubuntu.com/manpages/trusty/man1/gs.1.html
 * http://milan.kupcevic.net/ghostscript-ps-pdf/
 */
class Am_Plugin_PdfStamping extends Am_Plugin
{
    const PLUGIN_STATUS = self::STATUS_BETA;
    const PLUGIN_COMM = self::COMM_COMMERCIAL;
    const PLUGIN_REVISION = '6.3.31';

    const DEFAULT_TEXT = 'document downloaded by %user.name_f% %user.name_l% <%user.email%> from %site_title%';

    protected $_configPrefix = 'misc.';

    static function getDbXml()
    {
        return <<<CUT
<schema version="4.0.0">
    <table name="file">
        <field name="add_stamp" type="tinyint" notnull="0" />
    </table>
</schema>
CUT;
    }

    function getTitle()
    {
        return 'Pdf Stamping';
    }

    function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('text', ['class' => 'am-el-wide', 'placeholder' => self::DEFAULT_TEXT])
            ->setLabel(___("Text to Add to each PDF file\n" .
                "This text will be included at bottom to PDF file. " .
                "You can use all user specific placeholders here ".
                "eg. %user.login%, %user.name_f%, %user.name_l% etc."));
        $fs = $form->addAdvFieldset(null, ['id'=>$this->getId()])
            ->setLabel(___('Stamp Style'));
        $fs->addSelect('font')
            ->setLabel('Font Family')
            ->loadOptions($this->getFontOptions());
        $fs->addText('size', ['size'=>3, 'placeholder' => 10])
            ->setLabel('Font Size');
        $fs->addText('x', ['size'=>3, 'placeholder' => 10])
            ->setLabel("Horizontal Position\n" .
                "A4: 0&ndash;595, Latter: 0&ndash;612");
        $fs->addText('y', ['size'=>3, 'placeholder' => 10])
            ->setLabel("Vertical Position\n" .
                "A4: 0&ndash;842, Latter: 0&ndash;792");
        $fs->addAdvRadio('align')
            ->setLabel("Align\n" .
                "align in regards to position defined above")
            ->loadOptions([
                'left' => 'Left',
                'right' => 'Right',
                'center' => 'Center'
            ]);
        $fs->addText('r', ['size'=>3, 'placeholder' => 0])
            ->setLabel("Rotate (degree)\n");

        $fs->addText('color', ['size' => 7, 'placeholder' => '#000000'])
            ->setLabel("Color\n" .
                'you can use any valid <a href="http://www.w3schools.com/html/html_colors.asp" class="link" target="_blank" rel="noreferrer">HTML color</a>, you can find useful color palette <a href="http://www.w3schools.com/TAGS/ref_colornames.asp" class="link" target="_blank" rel="noreferrer">here</a>');
        $fs->addText('transparency', ['size'=>3, 'placeholder' => 1])
            ->setLabel("Transparency\n" .
            "From 0 to 1\n" .
            "0 - transparent\n" .
            "1 - opaque");

        $form->setDefault('align', 'left');
    }

    function getFontOptions()
    {
        return [
            'Helvetica' => 'Helvetica',
            'Helvetica-Bold' => 'Helvetica (Bold)',
            'Helvetica-Oblique' => 'Helvetica (Oblique)',
            'Helvetica-BoldOblique' => 'Helvetica (Bold Oblique)',
            'Courier-Bold' => 'Courier (Bold)',
            'Courier-Oblique' => 'Courier (Oblique)',
            'Courier-BoldOblique' => 'Courier (Bold Oblique)',
            'Symbol' => 'Symbol',
            'Times-Roman' => 'Times Roman',
            'Times-Bold' => 'Times (Bold)',
            'Times-Italic' => 'Times (Italic)',
            'Times-BoldItalic' => 'Times (Bold Italic)'
        ];
    }

    function init()
    {
        if ($user = $this->getDi()->auth->getUser()) {
            $tmpl = new Am_SimpleTemplate();
            $tmpl->assignStdVars();
            $tmpl->user = $user;
            $h = new Am_PdfStamping_SendFile($tmpl->render($this->getConfig('text', self::DEFAULT_TEXT)), $this);

            $stack = Zend_Controller_Action_HelperBroker::getStack();
            $stack['SendFile'] = $h;
        }
    }

    function onGridContentFilesInitForm(Am_Event $event)
    {
        $form = $event->getGrid()->getForm();
        $form->addAdvCheckbox('add_stamp')
            ->setLabel(___("Add User Stamp\n" .
                "this option applicalable only to PDF files"));
    }

}

class Am_PdfStamping_SendFile extends Am_Mvc_Controller_Action_Helper_SendFile {

    protected $text, $plugin;

    public function __construct($text, $plugin)
    {
        $this->text = $text;
        $this->plugin = $plugin;
    }

    public function sendFile($path, $type, $options = [])
    {
        if (isset($options['file_id']) && ($type == 'application/pdf') && ($file = Am_Di::getInstance()->fileTable->load($options['file_id'], false)) && $file->add_stamp)
        {
            if (!@class_exists('Zend_Pdf_Page', true)) {
                include_once 'Zend/Pdf_Pack.php';
            }

            $pdf = Zend_Pdf::load($path);
            foreach($pdf->pages as $page) {
                $this->addStamp($page);
            }

            try {
                return parent::sendData($pdf->render(), $type, $options['filename']);
            } catch (Zend_Pdf_Exception $ex) {
                Am_Di::getInstance()->logger->error("Error in pdf-stamping plugin", ["exception" => $ex]);
                return parent::sendFile($path, $type, $options);
            }
        }
        return parent::sendFile($path, $type, $options);
    }

    protected function addStamp(Zend_Pdf_Page $page)
    {
        $page = new Am_Pdf_Page_Decorator($page);
        $page->setFillColor(new Zend_Pdf_Color_Html($this->plugin->getConfig('color', '#000000')));
        $page->setFont(Zend_Pdf_Font::fontWithName($this->plugin->getConfig('font', Zend_Pdf_Font::FONT_HELVETICA)),
            $this->plugin->getConfig('size', 10));

        $rotate = $this->plugin->getConfig('r', 0);
        if ($rotate) {
            $page->rotate($this->plugin->getConfig('x', 10), $this->plugin->getConfig('y', 10), deg2rad($rotate));
        }
        $page->setAlpha($this->plugin->getConfig('transparency', 1));
        $page->drawText($this->text, $this->plugin->getConfig('x', 10), $this->plugin->getConfig('y', 10), 'UTF-8', $this->plugin->getConfig('align', 'left'));
        if ($rotate) {
            $page->rotate($this->plugin->getConfig('x', 10), $this->plugin->getConfig('y', 10), deg2rad(-1 * $rotate));
        }
    }
}