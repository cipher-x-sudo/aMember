<?php
class SetupController
{
	protected $title = null;
	protected $vars = [];
	protected $errors = [];
	protected $pageTemplate = '<html><head><title><!--TITLE--></title></head><body><!--CONTENT--></body></html>';
	protected $db = null;
	protected $setup = null;

	public function get($varName, $default = NULL)
	{
		return $this->vars[$varName] ?? $default;
	}

	public function e($varName, $default = NULL)
	{
		return htmlentities($this->get($varName, $default) ?? '');
	}

	public function setPageTemplate($pageTemplate)
	{
		$this->pageTemplate = $pageTemplate;
	}

	public function _set_input_vars()
	{
		$REQUEST_METHOD = $_SERVER['REQUEST_METHOD'];
		$vars = ($REQUEST_METHOD == 'POST' ? $_POST : $_GET);

		foreach ($vars as $k => $v) {
			if (is_array($v)) {
				continue;
			}

			$vars[$k] = trim($v);
		}

		$this->vars = $vars;
	}

	public function make_password($length = 16)
	{
		$vowels = 'aeiouy';
		$consonants = 'bdghjlmnpqrstvwxz';
		$password = '';
		$alt = time() % 2;

		for ($i = 0; $i < $length; ++$i) {
			if ($alt == 1) {
				$password .= $consonants[rand() % 17];
				$alt = 0;
			}
			else {
				$password .= $vowels[rand() % 6];
				$alt = 1;
			}
		}

		return $password;
	}

	public function render_errors()
	{
		if (!$this->errors) {
			return '';
		}

		$out = '<ul class="am-errors">';

		foreach ((array) $this->errors as $e) {
			$out .= '<li>' . $e . '</li>' . "\n";
		}

		$out .= '</ul>';
		return $out;
	}

	public function fatal($errs = [])
	{
		if ($errs && !is_array($errs)) {
			$errs = [$errs];
		}

		$this->errors = array_merge($this->errors, $errs);
		print('<br><br><br>');
		$this->display();
		exit();
	}

	public function check_for_existance()
	{
		$root_dir = ROOT_DIR;
		$cf = $root_dir . '/application/configs/config.php';
		if (file_exists($cf) && filesize($cf)) {
			$this->addError('File \'config.php\' in amember folder is already exists and non-empty. Please remove it or delete all content if you want to do full reconfiguration');
		}

		return !$this->errors;
	}

	public function addError($err)
	{
		$this->errors[] = $err;
	}

	public function check_for_extensions()
	{
		$ext = ['pdo', 'pdo_mysql', 'gd', 'openssl', 'mbstring', 'iconv', 'xml', 'xmlwriter', 'xmlreader', 'ctype'];

		foreach ($ext as $e) {
			if (!extension_loaded($e)) {
				$this->addError('aMember require <b>' . $e . '</b> extension to be installed in php. Please check <a href=\'http://www.php.net/manual/en/' . $e . '.installation.php\'>installation instructions</a>');
			}
		}

		return !$this->errors;
	}

	public function check_for_writeable()
	{
		$root_dir = ROOT_DIR;

		foreach ([$root_dir . '/data/', $root_dir . '/data/cache', $root_dir . '/data/new-rewrite/', $root_dir . '/data/public/'] as $d) {
			if (!is_writeable($d)) {
				$this->addError('Directory \'' . $d . '\' is not writable. Please <a href=\'https://docs.amember.com/Installation/Setting_Permission_for_a_File_or_a_Folder/\' target=\'_blank\'>fix it</a>');
			}
		}

		return !$this->errors;
	}

	public function getRewriteCheckJs()
	{
		return '<script type="text/javascript">' . "\r\n" . 'jQuery(function(){' . "\r\n" . '    var func = function(resp){' . "\r\n" . '        if (!resp.responseText.match(/aMember is not configured yet/, resp))' . "\r\n" . '        {' . "\r\n" . '            jQuery(\'#rewrite-error\').show();' . "\r\n" . '        };' . "\r\n" . '    }' . "\r\n" . '    var url = window.location.href;' . "\r\n" . '    url = url.replace(/\\/setup.*/, \'/test-rewrite/test-xx\');' . "\r\n" . '    $.get(url)' . "\r\n" . '        .error(func);' . "\r\n" . '});' . "\r\n" . '</script>' . "\r\n" . '<ul id="rewrite-error" style="display:none;" class="am-error">' . "\r\n" . '    <li>Seems your webhosting does not support mod_rewrite rules required by aMember. There may be several reasons:' . "\r\n" . '    <ul>' . "\r\n" . '        <li>You have not uploaded file amember/.htaccess (it might be hidden and invisible with default settings)</li>' . "\r\n" . '        <li>Your webhosting has no <b>mod_rewrite</b> module enabled. Contact tech support to get it enabled</li>' . "\r\n" . '        <li>Your webhosting uses software different from Apache webserver. It requires to convert rewrite rules' . "\r\n" . '            located in <i>amember/.htaccess</i> file into the webserver native format. Contact webhosting tech' . "\r\n" . '            for details.</li>' . "\r\n" . '    </ul>' . "\r\n" . '    You may continue aMember installation, but aMember will not work correctly until <i>mod_rewrite</i> issues are resolved.' . "\r\n" . '    </li>' . "\r\n" . '</ul>';
	}

	public function checkHtaccess()
	{
		$htaccess = ROOT_DIR . '/.htaccess';
		$cnt = @file_get_contents($htaccess);

		if (!$cnt) {
			$this->fatal('File [' . $htaccess . '] is not uploaded');
			exit();
		}

		$base = preg_replace('|/setup/.*$|', '', $_SERVER['REQUEST_URI']);

		if (!$base) {
			$base = '/';
		}

		if (!preg_match_all('|^()(\\s*)RewriteBase\\s+([\\\\/a-zA-Z0-9_-]+)\\s*$|m', $cnt, $regs)) {
			preg_match_all('|^(#*)(\\s*)RewriteBase\\s+([\\\\/a-zA-Z0-9_-]+)\\s*$|m', $cnt, $regs);
		}

		if ($regs[0]) {
			foreach ($regs[3] as $i => $r) {
				if ($regs[1][$i]) {
					continue;
				}

				if ($r == $base) {
					return true;
				}
			}

			foreach ($regs[0] as $i => $r) {
				$cnt = preg_replace('|^' . preg_quote($regs[0][$i]) . '$|m', $regs[2][$i] . 'RewriteBase ' . $base, $cnt);
				break;
			}
		}
		else {
			$cnt = str_replace('RewriteEngine on', 'RewriteEngine on' . "\n" . '    RewriteBase ' . $base, $cnt);
		}

		if (!is_writable($htaccess)) {
			$cnt = htmlentities($cnt);
			$this->fatal('File [' . $htaccess . '] is not writeable. Please use your FTP client ' . 'or Web-hosting control panel file manager to update this file ' . "\n" . ('that is the file named .htaccess inside ' . $base . ' folder ' . "\n") . 'edit the file and replace file content to the following (copy&paste) ' . "\n" . ('<pre style=\'border: solid 2px black; background-color: white;\'>' . $cnt . '</pre>') . '<br /><br />' . 'Once .htaccess file is updated, click <a href=\'index.php\'>this link to continue setup</a>');
			exit();
		}

		return file_put_contents($htaccess, $cnt);
	}

	public function step1()
	{
		$root_dir = ROOT_DIR;
		$SERVER_ADMIN = (array_key_exists('SERVER_ADMIN', $_SERVER) ? $_SERVER['SERVER_ADMIN'] : '');
		$this->checkHtaccess();
		$myurl = preg_replace('|/setup/.*$|', '', $this->getSelfUrl());
		$root_url = $this->e('@ROOT_URL@', $myurl);
		$root_surl = $this->e('@ROOT_SURL@', $myurl);
		$admin_email = $this->e('@ADMIN_EMAIL@', $SERVER_ADMIN);
		$admin_login = $this->e('@ADMIN_LOGIN@', 'admin');
		$admin_pass = $this->e('@ADMIN_PASS@', '');
		$admin_pass_c = $this->e('@ADMIN_PASS_C@', '');
		$license = $this->e('@LICENSE@', '');
		$site_title = $this->e('@SITE_TITLE@', 'aMember Pro');
		$i_agree = ($this->e('@i_agree@') ? 'checked' : '');
		print($this->getRewriteCheckJs());
		print('<h1>Enter configuration parameters</h1>' . "\r\n" . '                <a href="https://begpl.com" style="' . "\r\n" . '    position: absolute;' . "\r\n" . '    top: 0px;' . "\r\n" . '    right: 0px;' . "\r\n" . '"><img src="https://begpl.com/wp-content/uploads/2023/05/BeGPL-Logo.png" style="height: 31px;float: right;"/></a>' . "\r\n" . '                <div class="sb-title">' . "\r\n" . '                    <?php sb_e(\'Installation\') ?>' . "\r\n" . '                    <p style="background: green;color: #fff;padding: 10px;text-align: center;font-size: 17px;">Activated by BeGPL using LicenseDash Licensing Server!</p>' . "\r\n\r\n" . '<div class="am-info">' . "\r\n" . '    You may modify these values later via the aMember Control Panel' . "\r\n" . '</div>' . "\r\n" . '<div class="am-form">' . "\r\n" . '    <form method=post>' . "\r\n" . '        <div class="am-row">' . "\r\n" . '            <div class="am-element-title"><label>Site Title</label></div>' . "\r\n" . '            <div class="am-element">' . "\r\n" . '                <input type=text name="@SITE_TITLE@" value="' . $site_title . '" size=50>' . "\r\n" . '            </div>' . "\r\n" . '        </div>' . "\r\n" . '        <div class="am-row">' . "\r\n" . '            <div class="am-element-title"><label>Root URL of script</label></div>' . "\r\n" . '            <div class="am-element">' . "\r\n" . '                <input type=text name="@ROOT_URL@" value="' . $root_url . '" size=50>' . "\r\n" . '                <div class="comment">do not place a trailing slash ( <b>/</b> ) at the end! Please note that url must match your license.</div>' . "\r\n" . '            </div>' . "\r\n" . '        </div>' . "\r\n" . '        <div class="am-row">' . "\r\n" . '            <div class="am-element-title"><label>Secure (HTTPS) Root URL of script</label></div>' . "\r\n" . '            <div class="am-element"><input type=text name="@ROOT_SURL@" value="' . $root_surl . '" size=50>' . "\r\n" . '                <div class="comment">' . "\r\n" . '                    please keep default (not-secure) value if you are unsure. No trailing slash ( <b>/</b> ) please!' . "\r\n" . '                    That url must match your license.' . "\r\n" . '                </div>' . "\r\n" . '            </div>' . "\r\n" . '        </div>' . "\r\n" . '        <div class="am-row">' . "\r\n" . '            <div class="am-element-title"><label>Admin Email</lable></div>' . "\r\n" . '            <div class="am-element">' . "\r\n" . '                <input type=text name="@ADMIN_EMAIL@" value=\'' . $admin_email . '\' size=50>' . "\r\n" . '                <div class="comment">the address that alerts and other email should be sent to</div>' . "\r\n" . '            </div>' . "\r\n" . '        </div>' . "\r\n" . '        <div class="am-row">' . "\r\n" . '            <div class="am-element-title"><label>Admin Login</label></div>' . "\r\n" . '            <div class="am-element">' . "\r\n" . '                <div><i>admin</i></div>' . "\r\n" . '                <input type=hidden name="@ADMIN_LOGIN@" value=\'admin\' size=30>' . "\r\n" . '                <div class="comment">username for login to the Admin interface</div>' . "\r\n" . '            </div>' . "\r\n" . '        </div>' . "\r\n" . '        <div class="am-row">' . "\r\n" . '            <div class="am-element-title"><label>Admin Password</label>' . "\r\n" . '            </div>' . "\r\n" . '            <div class="am-element">' . "\r\n" . '                <div>' . "\r\n" . '                    <input type=password name="@ADMIN_PASS@" value=\'' . $admin_pass . '\' size=30>' . "\r\n" . '                    <div style="margin-top:0.4em">Confirm Admin Password</div>' . "\r\n" . '                    <input type=password name="@ADMIN_PASS_C@" value=\'' . $admin_pass_c . '\' size=30>' . "\r\n" . '                    <div class="comment">password for login to the Admin interface</div>' . "\r\n" . '                </div>' . "\r\n" . '            </div>' . "\r\n" . '        </div>' . "\r\n" . '        ');

		if (false) {
			print('<input type=\'hidden\' name=\'@LICENSE@\' value=\'LTRIALX\' />');
		}
		else {
			print('        <div class="am-row">' . "\r\n" . '            <div class="am-element-title"><label>License</label></div>' . "\r\n" . '            <div class="am-element">' . "\r\n" . '                <input type="text" style=\'font-family: Helvetica, sans-serif; width:95%\'' . "\r\n" . '                    name=\'@LICENSE@\' size="50" value="LBEGPL324X">' . "\r\n" . '                <div class="comment">enter the license key</div>' . "\r\n" . '            </div>' . "\r\n" . '        </div>');
		}

		print('    <div class="am-row">' . "\r\n" . '            <div class="am-element-title"></div>' . "\r\n" . '            <div class="am-element">' . "\r\n" . '                <label><input id="i_agree" type="checkbox" name="@i_agree@" value="1" ' . $i_agree . '> I accept <a href="https://www.amember.com/license/" target="_blank">License Agreement</a></label>' . "\r\n" . '            </div>' . "\r\n" . '        </div>' . "\r\n" . '        <div class="am-row">' . "\r\n" . '            <div class="am-element-title"></div>' . "\r\n" . '            <div class="am-element">' . "\r\n" . '                <input id="input_next" type=submit value="Next &gt;&gt;">' . "\r\n" . '            </div>' . "\r\n" . '        </div>' . "\r\n" . '        <input type=hidden name=step value=1>' . "\r\n" . '    </form>' . "\r\n" . '    ' . "\r\n" . '</div>' . "\r\n" . '<script>' . "\r\n" . 'window.onload = function(){' . "\r\n" . '    var c = document.getElementById(\'i_agree\');' . "\r\n" . '    var n = document.getElementById(\'input_next\');' . "\r\n" . '    (c.onchange = function() {' . "\r\n" . '        n.disabled = !c.checked;' . "\r\n" . '    })();' . "\r\n" . '};' . "\r\n" . '</script>');
	}

	public function check_step1()
	{
		$vars = $this->vars;

		if (empty($vars['@i_agree@'])) {
			$this->errors[] = 'Please accept License Agreement to continue';
		}

		if (!strlen($vars['@SITE_TITLE@'])) {
			$this->errors[] = 'Please enter Site Title';
		}

		if (!strlen($vars['@ROOT_URL@'])) {
			$this->errors[] = 'Please enter root url of script';
		}

		if (!strlen($vars['@ROOT_SURL@'])) {
			$this->errors[] = 'Please enter secure root url of script (or keep DEFAULT VALUE - set it equal to Not-secure root URL - it will work anyway)';
		}

		if (!strlen($vars['@ADMIN_EMAIL@'])) {
			$this->errors[] = 'Please enter admin email';
		}

		if (!strlen($vars['@ADMIN_LOGIN@'])) {
			$this->errors[] = 'Please enter admin login';
		}

		if (!strlen($vars['@ADMIN_PASS@'])) {
			$this->errors[] = 'Please enter admin password';
		}

		if (strlen($vars['@ADMIN_PASS@']) < 6) {
			$this->errors[] = 'Admin password cannot be shorter than 6 characters';
		}

		if ($vars['@ADMIN_PASS_C@'] != $vars['@ADMIN_PASS@']) {
			$this->errors[] = 'Admin password and password confirmation do not match';
		}

		if (true) {
			if (!strlen($vars['@LICENSE@'])) {
				$this->errors[] = 'Please enter license code';
			}
			else {
				if (!preg_match('/^L[A-Za-z0-9\\/=+]+X$/', $vars['@LICENSE@'])) {
					$this->errors[] = 'Please enter full license code (it should start with L and ends with X)';
				}
			}
		}

		return !$this->errors;
	}

	public function get_hidden_vars()
	{
		$res = '';

		foreach ($this->vars as $k => $v) {
			if ($k[0] == '@') {
				if (is_array($v)) {
					foreach ($v as $kk => $vv) {
						$res .= sprintf('<input type=hidden name="%s[]" value="%s">' . "\n", htmlspecialchars($k), htmlspecialchars($vv));
					}
				}
				else {
					$res .= sprintf('<input type=hidden name="%s" value="%s">' . "\n", htmlspecialchars($k), htmlspecialchars($v));
				}
			}
		}

		return $res;
	}

	public function step2()
	{
		$hidden = $this->get_hidden_vars();
		$host = $this->e('@DB_MYSQL_HOST@', 'localhost');
		$db = $this->e('@DB_MYSQL_DB@', '');
		$user = $this->e('@DB_MYSQL_USER@', '');
		$pass = $this->e('@DB_MYSQL_PASS@', '');
		$port = $this->e('@DB_MYSQL_PORT@', '');
		$prefix = $this->e('@DB_MYSQL_PREFIX@', 'am_');
		print('<h1>Enter MySQL configuration parameters</h1>' . "\r\n" . '<div class="am-form">' . "\r\n" . '    <form method=post>' . "\r\n" . '        ' . $hidden . "\r\n" . '        <div class="am-row">' . "\r\n" . '            <div class="am-element-title"><label>MySQL Host</label></div>' . "\r\n" . '            <div class="am-element">' . "\r\n" . '                <input type=text name=\'@DB_MYSQL_HOST@\' value=\'' . $host . '\' size=30>' . "\r\n" . '                <div class="comment">very often \'localhost\'</div>' . "\r\n" . '            </div>' . "\r\n" . '        </div>' . "\r\n" . '        <div class="am-row">' . "\r\n" . '            <div class="am-element-title" style=\'color: gray\'><label style=\'color: gray\'>MySQL Port</label></div>' . "\r\n" . '            <div class="am-element">' . "\r\n" . '                <input type=text name=\'@DB_MYSQL_PORT@\' value=\'' . $port . '\' size=10 placeholder="3306">' . "\r\n" . '                <div class="comment">normally you do not need to enter anything into this field. Keep default value</div>' . "\r\n" . '            </div>' . "\r\n" . '        </div>' . "\r\n" . '        <div class="am-row">' . "\r\n" . '            <div class="am-element-title"><label>MySQL Username</label></div>' . "\r\n" . '            <div class="am-element"><input type=text name=\'@DB_MYSQL_USER@\' value=\'' . $user . '\' size=30></div>' . "\r\n" . '        </div>' . "\r\n" . '        <div class="am-row">' . "\r\n" . '            <div class="am-element-title"><label>MySQL Password</label></div>' . "\r\n" . '            <div class="am-element"><input type=text name=\'@DB_MYSQL_PASS@\' value=\'' . $pass . '\' size=30></div>' . "\r\n" . '        </div>' . "\r\n" . '        <div class="am-row">' . "\r\n" . '            <div class="am-element-title"><label>MySQL Database</label></div>' . "\r\n" . '            <div class="am-element">' . "\r\n" . '                <input type=text name=\'@DB_MYSQL_DB@\' value=\'' . $db . '\' size=30>' . "\r\n" . '                <div class="comment">note: setup does not create the database for you. Use the default database created by your host or create a new database, for example \'amember\'</div>' . "\r\n" . '            </div>' . "\r\n" . '        </div>' . "\r\n" . '        <div class="am-row"></div>' . "\r\n" . '        <div class="am-row">' . "\r\n" . '            <div class="am-element-title"><label>MySQL Tables Prefix</label></div>' . "\r\n" . '            <div class="am-element">' . "\r\n" . '                <input type=text name=\'@DB_MYSQL_PREFIX@\' value=\'' . $prefix . '\' size=30>' . "\r\n" . '                <div class="comment">If not sure, keep the default value \'<i>am_</i>\'</div>' . "\r\n" . '            </div>' . "\r\n" . '        </div>' . "\r\n" . '        <div class="am-row">' . "\r\n" . '            <div class="am-element-title"></div>' . "\r\n" . '            <div class="am-element">' . "\r\n" . '                <input type=submit value="Next &gt;&gt;">' . "\r\n" . '            </div>' . "\r\n" . '        </div>' . "\r\n" . '        <input type=hidden name=step value=2>' . "\r\n" . '    </form>' . "\r\n" . '</div>');
	}

	public function check_step2()
	{
		$vars = $this->vars;

		if ($this->errors) {
			return false;
		}

		try {
			set_error_handler(function() {
			});
			$this->getSetup()->connectDb();
			restore_error_handler();
		}
		catch (Am_Setup_Exception_Db $e) {
			switch ($e->getCode()) {
			case 1045:
				$this->errors[] = 'MySQL user access denied - check username, password and hostname';
				break;
			case 1049:
				if ($this->getSetup()->tryCreateDbAndConnnect()) {
					return true;
				}

				$this->errors[] = 'Unknown MySQL database - check database name';
				break;
			case 2002:
				$this->errors[] = 'Can\'t connect to local MySQL server through socket.' . "\r\n" . '                                        Try to use 127.0.0.1 for MySQL Host setting.' . "\r\n" . '                                        If this will not help contact hosting support and ask to provide correct MySQL Host';
			default:
				$this->errors[] = $e->getMessage();
			}

			return false;
		}

		return true;
	}

	public function step3()
	{
		$hidden = $this->get_hidden_vars();
		print('<h1>Continue installation?</h1>' . "\r\n" . '<div class="am-info">' . "\r\n" . '    aMember Setup Wizard is now ready to finish' . "\r\n" . '        installation and create database tables. If database tables are' . "\r\n" . '        already created, aMember will intelligently modify its structure' . "\r\n" . '        to match latest aMember version. Your existing configuration and' . "\r\n" . '        database records will not be removed.</div>' . "\r\n" . '<div class="am-form">' . "\r\n" . '    <form method=post>' . "\r\n" . '        <div class="am-row">' . "\r\n" . '            <div class="am-element-title">' . "\r\n" . '            </div>' . "\r\n" . '            <div class="am-element">' . "\r\n" . '                <input type=submit value="Next &gt;&gt;">' . "\r\n" . '            </div>' . "\r\n" . '        </div>' . "\r\n" . '        <input type=hidden name=step value=3>' . "\r\n" . '        ' . $hidden . "\r\n" . '    </form>' . "\r\n" . '</div>');
	}

	public function getSetup()
	{
		if (!$this->setup) {
			$this->setup = new Am_Setup(dirname(__FILE__), ROOT_DIR . '/application/configs', [CODE_ROOT_DIR . '/application/default/db.xml'], $this->vars);
		}

		return $this->setup;
	}

	public function display_error(Exception $e)
	{
		$this->title = 'aMember Setup: Internal Error';
		$msg = $e->getMessage();
		print('<ul class="am-errors">' . "\r\n" . '    <li>' . $msg . '</li>' . "\r\n" . '</ul>' . "\r\n");
	}

	public function display_send_files_form()
	{
		$this->title = 'aMember Setup: could not save config file';
		$hidden = $this->get_hidden_vars();
		$configFn = $this->getSetup()->getConfigFileFn();
		$content = $this->getSetup()->getConfigFileContent();
		print('<br /><br />' . "\r\n" . '<ul class="am-errors">' . "\r\n" . '    <li>Installation script is unable to save file <i>' . $configFn . '</i></b>.' . "\r\n" . '        For complete setup you may download new config files to your computer and upload' . "\r\n" . '        it back to your server.</li>' . "\r\n" . '</ul>' . "\r\n\r\n" . '<p>File <i>config.php</i>. Upload it to your FTP: <br><i>' . $configFn . '</i></p>' . "\r\n" . '<form name=f1 method=post>' . "\r\n" . '    <input type=submit value="Download config.php">' . "\r\n" . '    <input type=hidden name=step value=9>' . "\r\n" . '    <input type=hidden  name=file value=0>' . "\r\n" . '    ' . $hidden . "\r\n" . '</form>' . "\r\n" . '</p>' . "\r\n\r\n" . '<p>Internet Explorer sometimes rename files when save it. For example, it may rename <i>config.php</i> to <i>config[1].php</i>. Don\'t forget to fix it before uploading!' . "\r\n" . '<p>' . "\r\n" . '<script>' . "\r\n" . '    function copyc(){' . "\r\n" . '        var holdtext = document.getElementById(\'conf\');' . "\r\n" . '        navigator.clipboard.writeText(holdtext.value);' . "\r\n" . '    }' . "\r\n" . '</script>' . "\r\n\r\n" . '<h1>Or, alternatively, you may copy&paste this text to application/configs/config.php file.</h1>' . "\r\n" . '<textarea rows="10" style="width:95%" readonly name="conf" id="conf">' . $content . '</textarea>' . "\r\n" . '<br>' . "\r\n" . '<a href="javascript:;" onclick="copyc()">Copy to clipboard</a>' . "\r\n" . '<br /><br /><br />' . "\r\n\r\n" . '<h1>When the file is copied or created,' . "\r\n" . '    <a href="../?a=cce">click this link to continue</a></h1>');
	}

	public function send_config_file()
	{
		header('Content-Disposition: attachment; filename="config.php"');
		header('Content-Type: application/php');
		echo $this->getSetup()->getConfigFileContent();
		exit();
	}

	public function step4()
	{
		try {
			$this->getSetup()->process();
		}
		catch (Am_Setup_Exception_WriteConfigFiles $e) {
			return $this->display_send_files_form();
		}
		catch (Exception $e) {
			return $this->display_error($e);
		}

		$link = $this->getSelfUrl() . '?step=5';
		print('<br /><br /><h1>Installation finished. Please <a href=\'' . $link . '\'>click this link to continue</a>. Activated by BeGPL.com, Enjoy!</h1>');
	}

	public function getSelfUrl()
	{
		$ssl = !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) != 'off';
		return ($ssl ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
	}

	public function step5()
	{
		print('<h1>Thank you for choosing aMember Pro</h1>' . "\r\n" . '<p>You can find  the aMember Pro User\'s Guide <a href=\'https://docs.amember.com\'>here</a>.' . "\r\n" . '    Feel free to <a href=\'https://www.amember.com/support/\'>contact CGI-Central</a> any time' . "\r\n" . '    if you have any issues with the script.</p>' . "\r\n\r\n" . '<h2>Please review the following (You may want to bookmark this page):</h2>' . "\r\n" . '<ul class=\'am-list\'>' . "\r\n" . '    <li><a href=\'../admin/\' target=_blank>Admin page (aMember Control Panel)</a></li>' . "\r\n" . '    <li><a href=\'../signup\' target=_blank>Signup page</a></li>' . "\r\n" . '    <li><a href=\'../member\' target=_blank>Registered Member page</a></li>' . "\r\n" . '    <li><a href=\'../login\' target=_blank>Login page (redirect to protected area)</a></li>' . "\r\n" . '</ul>' . "\r\n" . '<div class="am-info" bis_skin_checked="1">' . "\r\n" . '                <h1>Thank You for Your Purchase from BeGPL! <br>Activated by <a href="http://begpl.com/" target="_blank" rel="noreferrer">BeGPL</a> using <a href="http://LicenseDash.com/" target="_blank" rel="noreferrer">LicenseDash</a> Licensing Servers</h1>' . "\r\n" . '        <img src="https://begpl.com/wp-content/uploads/2023/05/BeGPL-Logo.png" alt="BeGPL Logo" width="250">' . "\r\n" . '        <p>Your transaction was successful. Welcome to BeGPL,<br> your trusted source for GPL WordPress themes and plugins.</p>' . "\r\n" . '            <h2>Why Choose BeGPL?</h2>' . "\r\n" . '            <ul>' . "\r\n" . '                <li>✅ Lifetime Free Updates</li>' . "\r\n" . '                <li>✅ Unlimited Downloads Membership</li>' . "\r\n" . '                <li>✅ Access to 45,000+ Premium Themes &amp; Plugins</li>' . "\r\n" . '                <li>✅ Instant Downloads After Purchase</li>' . "\r\n" . '                <li>✅ Secure Checkout and Payment</li>' . "\r\n" . '                <li>✅ 100% Original &amp; Unmodified Files</li>' . "\r\n" . '                <li>✅ Money-Back Guarantee</li>' . "\r\n" . '                <li>✅ Priority Support</li>' . "\r\n" . '            </ul>' . "\r\n" . '            <p>Explore 45,000+ GPL WordPress Plugins and Themes</p>' . "\r\n" . '            <p>If you don\'t find what you need, we can help you get it!</p>' . "\r\n" . '            <p>Save money on renewals! We offer pre-activated<br> themes, plugins, or scripts at a one-time cost.</p>' . "\r\n" . '            <p>Need Help? Contact Us:</p>' . "\r\n" . '            <p>WhatsApp: <a href="https://wa.me/447445505361">+44 7 4455 0 5361</a></p>' . "\r\n" . '</div>' . "\r\n\r\n" . '<h2>Before aMember is ready to use you will also need to do the following:</h2>' . "\r\n" . '<p>Go to the <a href=\'../admin-setup\' target=_blank>Admin Setup/Configuration page</a>' . "\r\n" . '    Enable any additional payment plugins you need and \'Save\'. Then configuration pages for plugins' . "\r\n" . '    will appear in the top of page. Visit them and configure enabled plugins.' . "\r\n" . '<p>Go to the <a href=\'../admin-products\' target=_blank>Admin Products page</a> and' . "\r\n" . '    add your products or subscription types.</p>' . "\r\n" . '<p>You may prefer to refer to them as \'Products\' or \'Subscription Types\' depending upon the type' . "\r\n" . '    of business you are in. For example, you might choose to refer to a newsletter as a' . "\r\n" . '    \'Subscription\', while you might call computer software or hardware a \'Product\'. It\'s up to' . "\r\n" . '    you what you choose to call these aMember database records.</p>' . "\r\n" . '<p>Remember, a \'Product\' or \'Subscription Type\' is just a different way to refer to the same thing,' . "\r\n" . '    which is an aMember database record.</p>' . "\r\n" . '<p>You may specify the Subscription Type (free or paid signup, etc.) as you enter each product.</p>' . "\r\n\r\n" . '<h2>It is important to set up at least one product!</h2>' . "\r\n" . '<p>Determine whether your payment system(s) require any special configuration. If' . "\r\n" . '    so then you can refer to the' . "\r\n" . '    <a href=\'https://www.amember.com/docs/Installation\' target=_blank>Installation Manual</a>' . "\r\n" . '    for more information, or contact CGI-Central for script customization services.</p>' . "\r\n" . '<p>Visit <a href=\'../admin-content\'></a> Setup your protection for protected areas or upload files for customers.</p>' . "\r\n" . '<p>Check your installation by testing your' . "\r\n" . '    <a href=\'../signup\' target=_blank>Signup Page</a>.</p>' . "\r\n\r\n" . '<p><strong>Feel free to contact <a href=\'https://www.amember.com/support/\' target=_blank>CGI-Central Support</a> if you need any customization of the script.</strong></p>' . "\r\n\r\n" . '<p>You can also find a lot of useful info in the <a href=\'https://www.amember.com/forum/?from=setup\' target=_blank>aMember Forum</a>.</p>');
	}

	public function display()
	{
		$out = $this->render_errors() . "\n\n" . ob_get_clean();
		$tpl = $this->pageTemplate;
		$tpl = str_replace('<!--TITLE-->', $this->title, $tpl);
		$tpl = str_replace('<!--CONTENT-->', $out, $tpl);
		echo $tpl;
	}

	public function run()
	{
		$this->_set_input_vars();
		ob_start();
		$step = (int) @$_REQUEST['step'];
		if (($step != 5) && !$this->check_for_existance()) {
			$this->title = 'Amember Setup : is already installed';
			return $this->display();
		}

		if (!$this->check_for_writeable()) {
			$this->title = 'Amember Setup : folders permissions must be fixed';
			return $this->display();
		}

		if (!$this->check_for_extensions()) {
			$this->title = 'Amember Setup : Extensions required';
			return $this->display();
		}

		$this->title = 'aMember Setup: Step ' . ($step + 1) . ' of 4';

		switch ($step) {
		case 0:
		case '0':
			$this->step1();
			break;
		case 1:
		case '1':
			if (!$this->check_step1()) {
				$this->step1();
			}
			else {
				$this->step2();
			}

			break;
		case 2:
		case '2':
			if (!$this->check_step2()) {
				$this->step2();
			}
			else {
				$this->step3();
			}

			break;
		case 3:
		case '3':
			if (!$this->check_step1()) {
				$this->step1();
			}
			else if (!$this->check_step2()) {
				$this->step2();
			}
			else {
				$this->step4();
			}

			break;
		case 5:
		case '5':
			$this->title = 'aMember Setup: Step ' . ($step - 1) . ' of 4';
			$this->step5();
			break;
		case 9:
		case '9':
			return $this->send_config_file();
			break;
		default:
			exit('Unknown step: ' . $step);
		}

		return $this->display();
	}
}

function check_versions()
{
	if (version_compare(phpversion(), '7.4') < 0) {
		exit('PHP version 7.4 or greater is required to run aMember. Your PHP-Version is : ' . phpversion() . '<br>Please upgrade or ask your hosting to upgrade.');
	}

	if (!extension_loaded('PDO')) {
		exit('PHP on your webhosting has no [pdo] extension enabled. Please ask the webhosting support to install it');
	}

	if (!extension_loaded('pdo_mysql')) {
		exit('PHP on your webhosting has no [pdo_mysql] extension enabled. Please ask the webhosting support to install it');
	}
}

date_default_timezone_set(@date_default_timezone_get());
check_versions();
define('ROOT_DIR', realpath(dirname(__FILE__) . '/..'));
$_amAutoloader = require_once __DIR__ . '/../library/vendor/autoload.php';
define('CODE_ROOT_DIR', ROOT_DIR);
error_reporting(32767);
@ini_set('display_errors', 1);
$controller = new SetupController();
$year = date('Y');
$controller->setPageTemplate('<!DOCTYPE html>' . "\r\n" . '<html xmlns="http://www.w3.org/1999/xhtml">' . "\r\n" . '    <head>' . "\r\n" . '        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />' . "\r\n" . '        <title><!--TITLE--></title>' . "\r\n" . '        <link href="../application/default/views/public/css/reset.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="../application/default/views/public/css/amember.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.6.4/jquery.min.js" ></script>' . "\r\n" . '        <style>' . "\r\n" . '            div.am-row-wide div.am-element-title{' . "\r\n" . '                float: none;' . "\r\n" . '                width: 100%;' . "\r\n" . '                text-align: center;' . "\r\n" . '                padding: 1em 0 0 1em;' . "\r\n" . '            }' . "\r\n\r\n" . '            div.am-row-wide div.am-element {' . "\r\n" . '                margin:0;' . "\r\n" . '                padding:1em;' . "\r\n" . '            }' . "\r\n" . '            div.am-row-wide textarea {' . "\r\n" . '                margin:0;' . "\r\n" . '                width: 95%;' . "\r\n" . '            }' . "\r\n" . '            div.am-row div.comment {' . "\r\n" . '                font-style: italic;' . "\r\n" . '                margin-top: 0.2em;' . "\r\n" . '                color:#aaa;' . "\r\n" . '            }' . "\r\n" . '        </style>' . "\r\n" . '    </head>' . "\r\n" . '    <body>' . "\r\n" . '        <div class="am-layout am-common">' . "\r\n" . '            <a id="top"></a>' . "\r\n" . '            <div class="am-header">' . "\r\n" . '                <div class="am-header-content-wrapper am-main">' . "\r\n" . '                    <div class="am-header-content">' . "\r\n" . '                        <img src="../application/default/views/public/img/header-logo.png" alt="aMember Pro" />' . "\r\n" . '                    </div>' . "\r\n" . '                </div>' . "\r\n" . '            </div>' . "\r\n" . '            <div class="am-header-line">' . "\r\n\r\n" . '            </div>' . "\r\n" . '            <div class="am-body">' . "\r\n" . '                <div class="am-body-content-wrapper am-main">' . "\r\n" . '                    <div class="am-body-content" style=" position: relative; ">' . "\r\n" . '                        <!-- content starts here -->' . "\r\n" . '                        <!--CONTENT-->' . "\r\n" . '                    </div>' . "\r\n" . '                </div>' . "\r\n" . '            </div>' . "\r\n" . '        </div>' . "\r\n" . '        <div class="am-footer">' . "\r\n" . '            <div class="am-footer-content-wrapper am-main">' . "\r\n" . '                <div class="am-footer-content">' . "\r\n" . '                    aMember Pro&trade; 6.3.6 by <a href="https://www.amember.com">aMember.com</a>  &copy; 2002&ndash;' . $year . ' BeGPL' . "\r\n" . '                </div>' . "\r\n" . '            </div>' . "\r\n" . '        </div>' . "\r\n" . '    </body>' . "\r\n" . '</html>');
$controller->run();

?>