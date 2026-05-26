<?php
/**
 * Prestashop DB Cleaner
 *
 * Safe PrestaShop database housekeeping module.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__).'/classes/JooimDbcleanerTools.php';
require_once dirname(__FILE__).'/classes/JooimDbcleanerCleaner.php';

class Jooim_dbcleaner extends Module
{
    const CONFIG_ENABLE = 'JOOIM_DBCLEANER_ENABLE';
    const CONFIG_RETENTION = 'JOOIM_DBCLEANER_RETENTION_DAYS';
    const CONFIG_BATCH_SIZE = 'JOOIM_DBCLEANER_BATCH_SIZE';
    const CONFIG_MAX_BATCHES = 'JOOIM_DBCLEANER_MAX_BATCHES';
    const CONFIG_CLEAR_LAYERED = 'JOOIM_DBCLEANER_CLEAR_LAYERED_FILTER';
    const CONFIG_AGGREGATE = 'JOOIM_DBCLEANER_AGGREGATE_STATS';
    const CONFIG_STATS_RETENTION = 'JOOIM_DBCLEANER_STATS_RETENTION_DAYS';
    const CONFIG_TOKEN = 'JOOIM_DBCLEANER_CRON_TOKEN';
    const CONFIG_TOKEN_GLOBAL = 'JOOIM_DBCLEANER_CRON_TOKEN_GLOBAL';
    const CONFIG_STALE_LOCK = 'JOOIM_DBCLEANER_STALE_LOCK_TIMEOUT';

    public function __construct()
    {
        $this->name = 'jooim_dbcleaner';
        $this->tab = 'administration';
        $this->version = '1.00.00';
        $this->author = 'JOO INTERNET MEDIA LTD';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName = $this->l('Prestashop DB Cleaner');
        $this->description = $this->l('Safely cleans old PrestaShop database statistics, clears selected cache tables and stores aggregated traffic source summaries.');
        $this->confirmUninstall = $this->l('Are you sure? Cleanup logs and aggregated statistics created by this module will be removed.');
    }

    public function install()
    {
        return parent::install()
            && $this->registerFooterAttributionHook()
            && $this->installSql()
            && $this->installDefaults();
    }

    public function uninstall()
    {
        return $this->uninstallSql()
            && $this->deleteConfiguration()
            && parent::uninstall();
    }

    protected function installDefaults()
    {
        $token = Tools::passwdGen(48);
        $this->updateCronToken($token);
        return Configuration::updateValue(self::CONFIG_ENABLE, 1)
            && Configuration::updateValue(self::CONFIG_RETENTION, 60)
            && Configuration::updateValue(self::CONFIG_BATCH_SIZE, 10000)
            && Configuration::updateValue(self::CONFIG_MAX_BATCHES, 10)
            && Configuration::updateValue(self::CONFIG_CLEAR_LAYERED, 1)
            && Configuration::updateValue(self::CONFIG_AGGREGATE, 1)
            && Configuration::updateValue(self::CONFIG_STATS_RETENTION, 730)
            && Configuration::updateValue(self::CONFIG_TOKEN, $token)
            && Configuration::updateValue(self::CONFIG_TOKEN_GLOBAL, $token)
            && Configuration::updateValue(self::CONFIG_STALE_LOCK, 7200)
            && Configuration::updateValue('JOOIM_DBCLEANER_RUNNING_SINCE', '0')
            && Configuration::updateValue('JOOIM_DBCLEANER_LAST_RUN', '')
            && Configuration::updateValue('JOOIM_DBCLEANER_LAST_STATUS', '')
            && Configuration::updateValue('JOOIM_DBCLEANER_LAST_MESSAGE', '');
    }

    protected function deleteConfiguration()
    {
        $keys = array(
            self::CONFIG_ENABLE,
            self::CONFIG_RETENTION,
            self::CONFIG_BATCH_SIZE,
            self::CONFIG_MAX_BATCHES,
            self::CONFIG_CLEAR_LAYERED,
            self::CONFIG_AGGREGATE,
            self::CONFIG_STATS_RETENTION,
            self::CONFIG_TOKEN,
            self::CONFIG_TOKEN_GLOBAL,
            self::CONFIG_STALE_LOCK,
            'JOOIM_DBCLEANER_RUNNING_SINCE',
            'JOOIM_DBCLEANER_LAST_RUN',
            'JOOIM_DBCLEANER_LAST_STATUS',
            'JOOIM_DBCLEANER_LAST_MESSAGE',
        );
        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }
        return true;
    }

    protected function installSql()
    {
        $sql = array();
        $sql[] = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'jooim_dbcleaner_log` (
            `id_jooim_dbcleaner_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `date_add` DATETIME NOT NULL,
            `run_type` VARCHAR(32) NOT NULL,
            `status` VARCHAR(32) NOT NULL,
            `deleted_connections` INT UNSIGNED NOT NULL DEFAULT 0,
            `deleted_connections_source` INT UNSIGNED NOT NULL DEFAULT 0,
            `deleted_connections_page` INT UNSIGNED NOT NULL DEFAULT 0,
            `layered_filter_cleared` TINYINT(1) NOT NULL DEFAULT 0,
            `database_size_mb_before` DECIMAL(12,2) NULL,
            `database_size_mb_after` DECIMAL(12,2) NULL,
            `runtime_seconds` DECIMAL(10,3) NULL,
            `message` TEXT NULL,
            PRIMARY KEY (`id_jooim_dbcleaner_log`),
            KEY `date_add` (`date_add`),
            KEY `status` (`status`)
        ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4';

        $sql[] = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'jooim_dbcleaner_traffic_daily` (
            `id_jooim_dbcleaner_traffic_daily` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `date_day` DATE NOT NULL,
            `source_type` VARCHAR(32) NOT NULL,
            `source_domain` VARCHAR(190) NOT NULL DEFAULT "",
            `utm_source` VARCHAR(190) NOT NULL DEFAULT "",
            `utm_medium` VARCHAR(190) NOT NULL DEFAULT "",
            `utm_campaign` VARCHAR(190) NOT NULL DEFAULT "",
            `visits` INT UNSIGNED NOT NULL DEFAULT 0,
            `pageviews` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id_jooim_dbcleaner_traffic_daily`),
            UNIQUE KEY `uniq_daily_source` (`date_day`, `source_type`, `source_domain`, `utm_source`, `utm_medium`, `utm_campaign`),
            KEY `date_day` (`date_day`),
            KEY `source_type` (`source_type`)
        ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4';

        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }
        return true;
    }

    protected function uninstallSql()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'jooim_dbcleaner_log`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'jooim_dbcleaner_traffic_daily`');
    }

    public function getContent()
    {
        $this->synchronizeFooterAttributionHook();
        $output = '';

        if (Tools::isSubmit('submitJooimDbcleaner')) {
            $this->postProcessConfiguration();
            $output .= $this->displayConfirmation($this->l('Settings updated.'));
        }

        if (Tools::isSubmit('submitJooimDbcleanerRegenerateToken')) {
            $this->updateCronToken(Tools::passwdGen(48));
            Tools::redirectAdmin(AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules').'&jooim_token_regenerated=1');
        }

        if (Tools::getValue('jooim_token_regenerated')) {
            $output .= $this->displayConfirmation($this->l('Cron token regenerated.'));
        }

        if (Tools::isSubmit('submitJooimDbcleanerRunNow')) {
            $cleaner = new JooimDbcleanerCleaner($this);
            $result = $cleaner->run('manual');
            if ($result['status'] === 'error') {
                $output .= $this->displayError($result['message']);
            } else {
                $output .= $this->displayConfirmation($this->l('Manual cleanup finished with status: ').$result['status']);
            }
        }

        return $output.$this->renderJooboxSupportPanel().$this->renderInfoPanel().$this->renderForm().$this->renderCronPanel().$this->renderTablesPanel().$this->renderLogsPanel().$this->renderTrafficPanel();
    }

    protected function postProcessConfiguration()
    {
        Configuration::updateValue(self::CONFIG_ENABLE, (int) Tools::getValue(self::CONFIG_ENABLE));
        Configuration::updateValue(self::CONFIG_RETENTION, max(1, (int) Tools::getValue(self::CONFIG_RETENTION)));
        Configuration::updateValue(self::CONFIG_BATCH_SIZE, max(100, (int) Tools::getValue(self::CONFIG_BATCH_SIZE)));
        Configuration::updateValue(self::CONFIG_MAX_BATCHES, max(1, (int) Tools::getValue(self::CONFIG_MAX_BATCHES)));
        Configuration::updateValue(self::CONFIG_CLEAR_LAYERED, (int) Tools::getValue(self::CONFIG_CLEAR_LAYERED));
        Configuration::updateValue(self::CONFIG_AGGREGATE, (int) Tools::getValue(self::CONFIG_AGGREGATE));
        Configuration::updateValue(self::CONFIG_STATS_RETENTION, max(30, (int) Tools::getValue(self::CONFIG_STATS_RETENTION)));
        Configuration::updateValue(self::CONFIG_STALE_LOCK, max(300, (int) Tools::getValue(self::CONFIG_STALE_LOCK)));
    }

    protected function registerFooterAttributionHook()
    {
        return $this->registerHookIfNeeded('displayFooter');
    }

    protected function synchronizeFooterAttributionHook()
    {
        if (method_exists($this, 'isRegisteredInHook') && $this->isRegisteredInHook('displayFooterAfter')) {
            $this->unregisterHook('displayFooterAfter');
        }

        return $this->registerHookIfNeeded('displayFooter');
    }

    protected function registerHookIfNeeded($hookName)
    {
        if (method_exists($this, 'isRegisteredInHook') && $this->isRegisteredInHook($hookName)) {
            return true;
        }

        return (bool) $this->registerHook($hookName);
    }

    protected function updateCronToken($token)
    {
        $token = (string) $token;
        Configuration::updateValue(self::CONFIG_TOKEN, $token);
        Configuration::updateValue(self::CONFIG_TOKEN_GLOBAL, $token);

        if (method_exists('Configuration', 'updateGlobalValue')) {
            Configuration::updateGlobalValue(self::CONFIG_TOKEN, $token);
            Configuration::updateGlobalValue(self::CONFIG_TOKEN_GLOBAL, $token);
        }

        return true;
    }

    public function getCronToken()
    {
        $token = (string) Configuration::get(self::CONFIG_TOKEN);
        if ($token !== '') {
            return $token;
        }

        $globalToken = (string) Configuration::get(self::CONFIG_TOKEN_GLOBAL);
        if ($globalToken !== '') {
            return $globalToken;
        }

        if (method_exists('Configuration', 'getGlobalValue')) {
            $globalValue = (string) Configuration::getGlobalValue(self::CONFIG_TOKEN);
            if ($globalValue !== '') {
                return $globalValue;
            }
        }

        return '';
    }

    public function isValidCronToken($token)
    {
        $token = (string) $token;
        if ($token === '') {
            return false;
        }

        $candidates = array(
            (string) Configuration::get(self::CONFIG_TOKEN),
            (string) Configuration::get(self::CONFIG_TOKEN_GLOBAL),
        );

        if (method_exists('Configuration', 'getGlobalValue')) {
            $candidates[] = (string) Configuration::getGlobalValue(self::CONFIG_TOKEN);
            $candidates[] = (string) Configuration::getGlobalValue(self::CONFIG_TOKEN_GLOBAL);
        }

        foreach (array_unique($candidates) as $expected) {
            if ($expected !== '' && hash_equals($expected, $token)) {
                return true;
            }
        }

        return false;
    }

    protected function getJooboxSupportUrl()
    {
        $language = isset($this->context->language) ? $this->context->language : null;
        $iso = $language && isset($language->iso_code) ? Tools::strtolower((string) $language->iso_code) : 'en';
        $locale = '';

        if ($language) {
            if (isset($language->locale) && $language->locale) {
                $locale = (string) $language->locale;
            } elseif (isset($language->language_code) && $language->language_code) {
                $locale = (string) $language->language_code;
            }
        }

        $locale = Tools::strtolower(str_replace('_', '-', $locale));

        if ($locale === 'de-at') {
            return 'https://joobox.eu/de-at/unterstutzung';
        }
        if ($locale === 'de-ch') {
            return 'https://joobox.eu/de-ch/unterstutzung';
        }
        if ($locale === 'en-ca') {
            return 'https://joobox.eu/en-ca/support';
        }
        if ($locale === 'fr-ca') {
            return 'https://joobox.eu/fr-ca/support';
        }

        switch ($iso) {
            case 'sk':
                return 'https://joobox.eu/sk-sk/podpora';
            case 'cs':
                return 'https://joobox.eu/cs-cz/podpora';
            case 'de':
                return 'https://joobox.eu/de-de/unterstutzung';
            case 'fr':
                return 'https://joobox.eu/fr-fr/soutien';
            case 'it':
                return 'https://joobox.eu/it-it/supporto';
            case 'pl':
                return 'https://joobox.eu/pl-pl/wsparcie';
            case 'en':
            default:
                return 'https://joobox.eu/en-gb/support';
        }
    }

    protected function renderJooboxSupportPanel()
    {
        $url = $this->getJooboxSupportUrl();
        $github = 'https://github.com/JOO-INTERNET-MEDIA-LTD/jooim_dbcleaner';

        return '<div class="panel jooim-dbcleaner-support-panel">'
            .'<h3><i class="icon-life-ring"></i> '.$this->l('Joobox support for this module').'</h3>'
            .'<p>'.$this->l('This free GitHub module is provided without individual technical support. Installation, configuration, production testing, updates and troubleshooting are available for customers using Joobox PrestaShop Cloud or another paid service explicitly agreed with JOO INTERNET MEDIA LTD.').'</p>'
            .'<p><a class="btn btn-primary" href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'" target="_blank" rel="noopener">'.$this->l('Get Joobox support').'</a> '
            .'<a class="btn btn-default" href="'.htmlspecialchars($github, ENT_QUOTES, 'UTF-8').'" target="_blank" rel="noopener">GitHub</a></p>'
            .'</div>';
    }

    protected function renderInfoPanel()
    {
        $size = JooimDbcleanerTools::getDatabaseSizeMb();
        $lastRun = Configuration::get('JOOIM_DBCLEANER_LAST_RUN');
        $lastStatus = Configuration::get('JOOIM_DBCLEANER_LAST_STATUS');
        $lastMessage = Configuration::get('JOOIM_DBCLEANER_LAST_MESSAGE');

        $html = '<div class="panel"><h3>'.$this->l('Database overview').'</h3>';
        $html .= '<p><strong>'.$this->l('Current PrestaShop database').':</strong> '.htmlspecialchars(_DB_NAME_, ENT_QUOTES, 'UTF-8').'</p>';
        $html .= '<p><strong>'.$this->l('Approximate database size').':</strong> '.($size === null ? '-' : number_format($size, 2, '.', ' ').' MB').'</p>';
        $html .= '<p><strong>'.$this->l('Last run').':</strong> '.($lastRun ? htmlspecialchars($lastRun, ENT_QUOTES, 'UTF-8') : '-').'</p>';
        $html .= '<p><strong>'.$this->l('Last status').':</strong> '.($lastStatus ? htmlspecialchars($lastStatus, ENT_QUOTES, 'UTF-8') : '-').'</p>';
        if ($lastMessage) {
            $html .= '<p><strong>'.$this->l('Last message').':</strong> '.htmlspecialchars($lastMessage, ENT_QUOTES, 'UTF-8').'</p>';
        }
        $html .= '</div>';
        return $html;
    }

    protected function renderForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitJooimDbcleaner';
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->fields_value = array(
            self::CONFIG_ENABLE => (int) Configuration::get(self::CONFIG_ENABLE),
            self::CONFIG_RETENTION => (int) Configuration::get(self::CONFIG_RETENTION),
            self::CONFIG_BATCH_SIZE => (int) Configuration::get(self::CONFIG_BATCH_SIZE),
            self::CONFIG_MAX_BATCHES => (int) Configuration::get(self::CONFIG_MAX_BATCHES),
            self::CONFIG_CLEAR_LAYERED => (int) Configuration::get(self::CONFIG_CLEAR_LAYERED),
            self::CONFIG_AGGREGATE => (int) Configuration::get(self::CONFIG_AGGREGATE),
            self::CONFIG_STATS_RETENTION => (int) Configuration::get(self::CONFIG_STATS_RETENTION),
            self::CONFIG_STALE_LOCK => (int) Configuration::get(self::CONFIG_STALE_LOCK),
        );

        $fields = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Cleanup settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Enable cleanup'),
                        'name' => self::CONFIG_ENABLE,
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'active_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'active_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                        'hint' => $this->l('Turns the cleaner on or off. When disabled, the cron URL and the manual run button will not delete old statistics. Use this switch when you want to keep the module installed but temporarily stop all cleanup activity.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Retention days for connection statistics'),
                        'name' => self::CONFIG_RETENTION,
                        'desc' => $this->l('Rows older than this will be removed from connections, connections_source and connections_page.'),
                        'hint' => $this->l('Enter the number of days for which raw PrestaShop visitor statistics should be kept. Example: 60 means the module keeps the last 60 days and deletes older records from ps_connections, ps_connections_source and ps_connections_page. Do not set this to 1 unless you are sure you do not need recent visitor statistics.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Batch size'),
                        'name' => self::CONFIG_BATCH_SIZE,
                        'desc' => $this->l('Maximum number of connections processed in one batch.'),
                        'hint' => $this->l('Enter how many main connection records may be processed in one internal batch. Smaller values are safer on weak hosting but require more cron runs. Larger values clean faster but can hold database locks for longer. Recommended starting value: 1000 to 5000; increase only after testing runtime.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Maximum batches per run'),
                        'name' => self::CONFIG_MAX_BATCHES,
                        'desc' => $this->l('Limits how much work one cron execution can do.'),
                        'hint' => $this->l('Enter how many batches may run during one cron execution. Total possible deleted connection records per run are roughly Batch size multiplied by Maximum batches per run. Example: 5000 and 10 means up to about 50000 main connection records in one cron run. Lower this if the shop slows down during cleanup.'),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Clear ps_layered_filter_block'),
                        'name' => self::CONFIG_CLEAR_LAYERED,
                        'desc' => $this->l('Clears faceted/layered search block cache using TRUNCATE TABLE. The table is recreated empty by MySQL/MariaDB.'),
                        'hint' => $this->l('Enable this only if ps_layered_filter_block grows too much or contains stale faceted search cache. This does not delete products, categories or the layered price index. After clearing, PrestaShop or the faceted search module can rebuild this cache when needed.'),
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'layered_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'layered_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Aggregate traffic source stats before deletion'),
                        'name' => self::CONFIG_AGGREGATE,
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'aggregate_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'aggregate_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                        'desc' => $this->l('Stores daily summarized traffic source statistics before deleting raw rows.'),
                        'hint' => $this->l('When enabled, the module saves a small daily summary before removing raw PrestaShop statistics. It keeps counts such as visits, pageviews, source domain and UTM values. It does not store IP addresses, full referrer URLs or user-agent strings. Disable it if you only want deletion and do not need historical source summaries.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Aggregated stats retention days'),
                        'name' => self::CONFIG_STATS_RETENTION,
                        'desc' => $this->l('How long the small daily summary table should be kept.'),
                        'hint' => $this->l('Enter how many days aggregated traffic summaries should stay in the module table. Example: 730 keeps about two years of daily source summaries. This setting affects only the module table ps_jooim_dbcleaner_traffic_daily, not the original PrestaShop statistics tables.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Stale lock timeout in seconds'),
                        'name' => self::CONFIG_STALE_LOCK,
                        'desc' => $this->l('Protection against two cleanup processes running at the same time.'),
                        'hint' => $this->l('Enter the number of seconds after which an unfinished cleanup lock is considered abandoned. This protects the database from two cron executions running at the same time. Recommended value: 7200. Lower it only if your cron is guaranteed to finish quickly.'),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'class' => 'btn btn-default pull-right',
                ),
            ),
        );

        return $helper->generateForm(array($fields));
    }

    protected function renderCronPanel()
    {
        $token = $this->getCronToken();
        $httpUrl = $this->context->link->getModuleLink($this->name, 'cron', array('token' => $token), true);
        $cliPath = _PS_MODULE_DIR_.$this->name.'/cron.php';

        $html = '<div class="panel"><h3>'.$this->l('Cron').'</h3>';
        $html .= '<p>'.$this->l('Recommended CLI cron command:').'</p>';
        $html .= '<pre>php '.htmlspecialchars($cliPath, ENT_QUOTES, 'UTF-8').' --token='.htmlspecialchars($token, ENT_QUOTES, 'UTF-8').'</pre>';
        $html .= '<p>'.$this->l('HTTP fallback cron URL:').'</p>';
        $html .= '<pre>'.htmlspecialchars($httpUrl, ENT_QUOTES, 'UTF-8').'</pre>';
        $html .= '<form method="post" style="display:inline-block;margin-right:10px"><button name="submitJooimDbcleanerRegenerateToken" class="btn btn-warning" type="submit">'.$this->l('Regenerate token').'</button></form>';
        $html .= '<form method="post" style="display:inline-block"><button name="submitJooimDbcleanerRunNow" class="btn btn-default" type="submit" onclick="return confirm(\''.htmlspecialchars($this->l('Run cleanup now?'), ENT_QUOTES, 'UTF-8').'\')">'.$this->l('Run cleanup now').'</button></form>';
        $html .= '</div>';
        return $html;
    }

    protected function renderTablesPanel()
    {
        $rows = JooimDbcleanerTools::getLargestTables(20);
        $html = '<div class="panel"><h3>'.$this->l('Largest tables in current PrestaShop database').'</h3>';
        $html .= '<table class="table"><thead><tr><th>'.$this->l('Table').'</th><th>'.$this->l('Size MB').'</th><th>'.$this->l('Data MB').'</th><th>'.$this->l('Index MB').'</th><th>'.$this->l('Rows estimate').'</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>'.htmlspecialchars($row['table_name'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['size_mb'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['data_mb'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['index_mb'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['table_rows'], ENT_QUOTES, 'UTF-8').'</td></tr>';
        }
        if (!$rows) {
            $html .= '<tr><td colspan="5">'.$this->l('No data available.').'</td></tr>';
        }
        $html .= '</tbody></table></div>';
        return $html;
    }

    protected function renderLogsPanel()
    {
        $rows = Db::getInstance()->executeS('SELECT * FROM `'._DB_PREFIX_.'jooim_dbcleaner_log` ORDER BY id_jooim_dbcleaner_log DESC LIMIT 20');
        $html = '<div class="panel"><h3>'.$this->l('Recent cleanup runs').'</h3>';
        $html .= '<table class="table"><thead><tr><th>ID</th><th>'.$this->l('Date').'</th><th>'.$this->l('Type').'</th><th>'.$this->l('Status').'</th><th>'.$this->l('Deleted connections').'</th><th>'.$this->l('Source').'</th><th>'.$this->l('Pages').'</th><th>'.$this->l('Layered cache').'</th><th>'.$this->l('DB before/after MB').'</th><th>'.$this->l('Runtime').'</th><th>'.$this->l('Message').'</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>'.(int) $row['id_jooim_dbcleaner_log'].'</td><td>'.htmlspecialchars($row['date_add'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['run_type'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8').'</td><td>'.(int) $row['deleted_connections'].'</td><td>'.(int) $row['deleted_connections_source'].'</td><td>'.(int) $row['deleted_connections_page'].'</td><td>'.((int) $row['layered_filter_cleared'] ? $this->l('yes') : $this->l('no')).'</td><td>'.htmlspecialchars($row['database_size_mb_before'], ENT_QUOTES, 'UTF-8').' / '.htmlspecialchars($row['database_size_mb_after'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['runtime_seconds'], ENT_QUOTES, 'UTF-8').'s</td><td>'.htmlspecialchars($row['message'], ENT_QUOTES, 'UTF-8').'</td></tr>';
        }
        if (!$rows) {
            $html .= '<tr><td colspan="11">'.$this->l('No cleanup runs yet.').'</td></tr>';
        }
        $html .= '</tbody></table></div>';
        return $html;
    }

    protected function renderTrafficPanel()
    {
        $rows = Db::getInstance()->executeS('SELECT date_day, source_type, source_domain, utm_source, utm_medium, utm_campaign, visits, pageviews
            FROM `'._DB_PREFIX_.'jooim_dbcleaner_traffic_daily`
            ORDER BY date_day DESC, visits DESC
            LIMIT 50');
        $html = '<div class="panel"><h3>'.$this->l('Aggregated traffic source stats').'</h3>';
        $html .= '<p>'.$this->l('Only aggregated data is stored. No IP addresses, full referrer URLs or user-agent strings are saved by this module.').'</p>';
        $html .= '<table class="table"><thead><tr><th>'.$this->l('Date').'</th><th>'.$this->l('Type').'</th><th>'.$this->l('Domain').'</th><th>UTM Source</th><th>UTM Medium</th><th>UTM Campaign</th><th>'.$this->l('Visits').'</th><th>'.$this->l('Pageviews').'</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>'.htmlspecialchars($row['date_day'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['source_type'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['source_domain'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['utm_source'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['utm_medium'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['utm_campaign'], ENT_QUOTES, 'UTF-8').'</td><td>'.(int) $row['visits'].'</td><td>'.(int) $row['pageviews'].'</td></tr>';
        }
        if (!$rows) {
            $html .= '<tr><td colspan="8">'.$this->l('No aggregated traffic data yet.').'</td></tr>';
        }
        $html .= '</tbody></table></div>';
        return $html;
    }

    public function hookDisplayFooter($params)
    {
        return $this->renderFooterAttribution($params);
    }

    public function hookDisplayFooterAfter($params)
    {
        return '';
    }

    protected function renderFooterAttribution($params)
    {
        unset($params);

        if (!$this->active) {
            return '';
        }

        $this->context->smarty->assign(array(
            'joobox_home_url' => 'https://joobox.eu',
        ));

        return $this->display(__FILE__, 'views/templates/hook/footer_attribution.tpl');
    }
}
