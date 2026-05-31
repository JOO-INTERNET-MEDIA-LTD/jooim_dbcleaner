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
        $this->version = '1.00.02';
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

    public function ensureDatabaseSchema()
    {
        return $this->installSql();
    }

    protected function installSql()
    {
        $sql = array();
        $sql[] = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'jooim_dbcleaner_log` (
            `id_jooim_dbcleaner_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
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
            KEY `id_shop_date_add` (`id_shop`, `date_add`),
            KEY `date_add` (`date_add`),
            KEY `status` (`status`)
        ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4';

        $sql[] = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'jooim_dbcleaner_traffic_daily` (
            `id_jooim_dbcleaner_traffic_daily` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_day` DATE NOT NULL,
            `source_type` VARCHAR(32) NOT NULL,
            `source_domain` VARCHAR(190) NOT NULL DEFAULT "",
            `utm_source` VARCHAR(190) NOT NULL DEFAULT "",
            `utm_medium` VARCHAR(190) NOT NULL DEFAULT "",
            `utm_campaign` VARCHAR(190) NOT NULL DEFAULT "",
            `visits` INT UNSIGNED NOT NULL DEFAULT 0,
            `pageviews` INT UNSIGNED NOT NULL DEFAULT 0,
            `orders` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id_jooim_dbcleaner_traffic_daily`),
            UNIQUE KEY `uniq_daily_source` (`id_shop`, `date_day`, `source_type`, `source_domain`(120), `utm_source`(80), `utm_medium`(80), `utm_campaign`(120)),
            KEY `date_day` (`date_day`),
            KEY `source_type` (`source_type`),
            KEY `id_shop_date` (`id_shop`, `date_day`)
        ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4';

        $sql[] = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'jooim_dbcleaner_order_source_daily` (
            `id_jooim_dbcleaner_order_source_daily` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_day` DATE NOT NULL,
            `country_iso` VARCHAR(8) NOT NULL DEFAULT "",
            `country_name` VARCHAR(128) NOT NULL DEFAULT "",
            `source_type` VARCHAR(32) NOT NULL,
            `source_domain` VARCHAR(190) NOT NULL DEFAULT "",
            `orders` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id_jooim_dbcleaner_order_source_daily`),
            UNIQUE KEY `uniq_order_daily_source` (`id_shop`, `date_day`, `country_iso`, `source_type`, `source_domain`),
            KEY `date_day` (`date_day`),
            KEY `id_shop_date` (`id_shop`, `date_day`),
            KEY `source_type` (`source_type`)
        ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4';

        $sql[] = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'jooim_dbcleaner_order_attribution` (
            `id_order` INT UNSIGNED NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_add` DATETIME NOT NULL,
            `country_iso` VARCHAR(8) NOT NULL DEFAULT "",
            `country_name` VARCHAR(128) NOT NULL DEFAULT "",
            `source_type` VARCHAR(32) NOT NULL,
            `source_domain` VARCHAR(190) NOT NULL DEFAULT "",
            PRIMARY KEY (`id_order`),
            KEY `id_shop_date_add` (`id_shop`, `date_add`),
            KEY `source_type` (`source_type`)
        ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8mb4';

        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        $this->addColumnIfMissing('jooim_dbcleaner_log', 'id_shop', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `id_jooim_dbcleaner_log`');
        $this->addColumnIfMissing('jooim_dbcleaner_traffic_daily', 'id_shop', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `id_jooim_dbcleaner_traffic_daily`');
        $this->addColumnIfMissing('jooim_dbcleaner_traffic_daily', 'orders', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `pageviews`');
        $this->ensureIndex('jooim_dbcleaner_log', 'id_shop_date_add', '`id_shop`, `date_add`');
        $this->ensureIndex('jooim_dbcleaner_traffic_daily', 'id_shop_date', '`id_shop`, `date_day`');
        $this->ensureTrafficDailyUniqueIndex();

        return true;
    }

    protected function addColumnIfMissing($table, $column, $definition)
    {
        if (!JooimDbcleanerTools::tableExists($table) || JooimDbcleanerTools::columnExists($table, $column)) {
            return true;
        }
        return (bool) Db::getInstance()->execute('ALTER TABLE `'._DB_PREFIX_.pSQL($table).'` ADD `'.pSQL($column).'` '.$definition);
    }

    protected function ensureIndex($table, $indexName, $columnsSql)
    {
        if (!JooimDbcleanerTools::tableExists($table)) {
            return true;
        }
        $rows = Db::getInstance()->executeS('SELECT COUNT(*) AS found_index
            FROM information_schema.statistics
            WHERE table_schema = "'.pSQL(_DB_NAME_).'"
              AND table_name = "'.pSQL(_DB_PREFIX_.$table).'"
              AND index_name = "'.pSQL($indexName).'"');
        if (isset($rows[0]['found_index']) && (int) $rows[0]['found_index'] > 0) {
            return true;
        }
        return (bool) Db::getInstance()->execute('ALTER TABLE `'._DB_PREFIX_.pSQL($table).'` ADD KEY `'.pSQL($indexName).'` ('.$columnsSql.')');
    }


    protected function ensureTrafficDailyUniqueIndex()
    {
        if (!JooimDbcleanerTools::tableExists('jooim_dbcleaner_traffic_daily')) {
            return true;
        }
        $rows = Db::getInstance()->executeS('SELECT column_name
            FROM information_schema.statistics
            WHERE table_schema = "'.pSQL(_DB_NAME_).'"
              AND table_name = "'.pSQL(_DB_PREFIX_.'jooim_dbcleaner_traffic_daily').'"
              AND index_name = "uniq_daily_source"
            ORDER BY seq_in_index ASC');
        $firstColumn = isset($rows[0]['column_name']) ? (string) $rows[0]['column_name'] : '';
        if ($firstColumn === 'id_shop') {
            return true;
        }
        if ($rows) {
            Db::getInstance()->execute('ALTER TABLE `'._DB_PREFIX_.'jooim_dbcleaner_traffic_daily` DROP INDEX `uniq_daily_source`');
        }
        return (bool) Db::getInstance()->execute('ALTER TABLE `'._DB_PREFIX_.'jooim_dbcleaner_traffic_daily` ADD UNIQUE KEY `uniq_daily_source` (`id_shop`, `date_day`, `source_type`, `source_domain`(120), `utm_source`(80), `utm_medium`(80), `utm_campaign`(120))');
    }

    protected function uninstallSql()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'jooim_dbcleaner_log`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'jooim_dbcleaner_traffic_daily`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'jooim_dbcleaner_order_source_daily`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'jooim_dbcleaner_order_attribution`');
    }

    public function getContent()
    {
        $this->synchronizeFooterAttributionHook();
        $this->ensureDatabaseSchema();
        $this->processCsvExport();
        $output = '';

        if (Tools::isSubmit('submitJooimDbcleaner')) {
            $this->postProcessConfiguration();
            $output .= $this->displayConfirmation($this->l('Settings updated.'));
        }

        if (Tools::isSubmit('submitJooimDbcleanerRegenerateToken')) {
            $this->updateCronToken(Tools::passwdGen(48));
            Tools::redirectAdmin($this->getAdminBaseUrl().'&jooim_token_regenerated=1');
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

        return $output
            .$this->renderJooboxSupportPanel()
            .$this->renderUpdateCheckPanel()
            .$this->renderTabs()
            .$this->renderActiveTabContent();
    }

    protected function getAdminBaseUrl(array $extra = array())
    {
        $params = array_merge(array(
            'configure' => $this->name,
            'token' => Tools::getAdminTokenLite('AdminModules'),
        ), $extra);
        return AdminController::$currentIndex.'&'.http_build_query($params, '', '&');
    }

    protected function getActiveTab()
    {
        $tab = (string) Tools::getValue('jooim_tab', 'settings');
        $allowed = array('settings', 'info', 'stats');
        return in_array($tab, $allowed, true) ? $tab : 'settings';
    }

    protected function renderTabs()
    {
        $active = $this->getActiveTab();
        $tabs = array(
            'settings' => $this->l('Settings'),
            'info' => $this->l('Important information'),
            'stats' => $this->l('Statistics'),
        );
        $html = '<ul class="nav nav-tabs" style="margin-bottom:15px">';
        foreach ($tabs as $key => $label) {
            $html .= '<li'.($active === $key ? ' class="active"' : '').'><a href="'.htmlspecialchars($this->getAdminBaseUrl(array('jooim_tab' => $key)), ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</a></li>';
        }
        $html .= '</ul>';
        return $html;
    }

    protected function renderActiveTabContent()
    {
        switch ($this->getActiveTab()) {
            case 'info':
                return $this->renderInfoPanel().$this->renderTablesPanel().$this->renderLogsPanel();
            case 'stats':
                return $this->renderTrafficPanel().$this->renderOrdersByCountryPanel();
            case 'settings':
            default:
                return $this->renderForm().$this->renderCronPanel();
        }
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


    protected function getGithubApiUrl()
    {
        return 'https://api.github.com/repos/JOO-INTERNET-MEDIA-LTD/jooim_dbcleaner/releases/latest';
    }

    protected function getGithubReleasesUrl()
    {
        return 'https://github.com/JOO-INTERNET-MEDIA-LTD/jooim_dbcleaner/releases/latest';
    }

    protected function renderUpdateCheckPanel()
    {
        $result = $this->getLatestGithubReleaseVersion((bool) Tools::getValue('jooim_refresh_update'));
        $latest = $result['version'] ? $result['version'] : $this->l('Unknown');
        $statusClass = 'alert alert-info';
        $statusText = $this->l('Unable to verify the latest version from GitHub. Check releases manually.');

        if ($result['version']) {
            if (version_compare($result['version'], $this->version, '>')) {
                $statusClass = 'alert alert-warning';
                $statusText = $this->l('A new module version is available.');
            } else {
                $statusClass = 'alert alert-success';
                $statusText = $this->l('Module is up to date.');
            }
        } elseif (!empty($result['error'])) {
            $statusText = $this->l('GitHub check failed: ').$result['error'];
        }

        $refreshUrl = AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules').'&jooim_refresh_update=1';

        $html = '<div class="panel jooim-dbcleaner-update-panel">';
        $html .= '<h3><i class="icon-refresh"></i> '.$this->l('Update check').'</h3>';
        $html .= '<p><strong>'.$this->l('Current module version').':</strong> '.htmlspecialchars($this->version, ENT_QUOTES, 'UTF-8').'</p>';
        $html .= '<p><strong>'.$this->l('Latest version on GitHub').':</strong> '.htmlspecialchars($latest, ENT_QUOTES, 'UTF-8').'</p>';
        if (!empty($result['checked_at'])) {
            $html .= '<p><strong>'.$this->l('Last checked').':</strong> '.htmlspecialchars($result['checked_at'], ENT_QUOTES, 'UTF-8').'</p>';
        }
        $html .= '<div class="'.$statusClass.'"><strong>'.$this->l('Update status').':</strong> '.htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8').'</div>';
        $html .= '<p>'.$this->l('For PrestaShop installation, do not use GitHub automatic "Source code ZIP". Use only the installation ZIP file from the release assets.').'</p>';
        $html .= '<p><a class="btn btn-primary" href="'.htmlspecialchars($this->getGithubReleasesUrl(), ENT_QUOTES, 'UTF-8').'" target="_blank" rel="noopener">'.$this->l('Open GitHub Releases').'</a> ';
        $html .= '<a class="btn btn-default" href="'.htmlspecialchars($refreshUrl, ENT_QUOTES, 'UTF-8').'">'.$this->l('Refresh update status').'</a></p>';
        $html .= '</div>';

        return $html;
    }

    protected function getLatestGithubReleaseVersion($forceRefresh = false)
    {
        $cacheVersion = 'JOOIM_DBCLEANER_GITHUB_LATEST_VERSION';
        $cacheChecked = 'JOOIM_DBCLEANER_GITHUB_CHECKED_AT';
        $cacheError = 'JOOIM_DBCLEANER_GITHUB_ERROR';
        $ttl = 21600;
        $checkedAt = Configuration::get($cacheChecked);

        if (!$forceRefresh && $checkedAt && (time() - strtotime($checkedAt)) < $ttl) {
            return array(
                'version' => Configuration::get($cacheVersion),
                'checked_at' => $checkedAt,
                'error' => Configuration::get($cacheError),
            );
        }

        $response = $this->downloadGithubJson($this->getGithubApiUrl());
        $version = '';
        $error = '';

        if ($response['success']) {
            $data = json_decode($response['body'], true);
            if (is_array($data) && !empty($data['tag_name'])) {
                $version = preg_replace('/^v/i', '', (string) $data['tag_name']);
            } else {
                $error = 'Invalid GitHub API response.';
            }
        } else {
            $error = $response['error'];
        }

        $now = date('Y-m-d H:i:s');
        Configuration::updateValue($cacheVersion, $version);
        Configuration::updateValue($cacheChecked, $now);
        Configuration::updateValue($cacheError, $error);

        return array('version' => $version, 'checked_at' => $now, 'error' => $error);
    }

    protected function downloadGithubJson($url)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_USERAGENT, 'jooim-dbcleaner-update-checker');
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/vnd.github+json'));
            $body = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body !== false && $httpCode >= 200 && $httpCode < 300) {
                return array('success' => true, 'body' => $body, 'error' => '');
            }

            return array('success' => false, 'body' => '', 'error' => $error ? $error : 'HTTP '.$httpCode);
        }

        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'timeout' => 6,
                'header' => "User-Agent: jooim-dbcleaner-update-checker\r\nAccept: application/vnd.github+json\r\n",
            ),
        ));
        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            return array('success' => false, 'body' => '', 'error' => 'Unable to download GitHub release information.');
        }

        return array('success' => true, 'body' => $body, 'error' => '');
    }


    protected function getCurrentShopIdForStats()
    {
        if (Shop::isFeatureActive() && isset($this->context->shop) && (int) $this->context->shop->id > 0 && Shop::getContext() === Shop::CONTEXT_SHOP) {
            return (int) $this->context->shop->id;
        }
        return 0;
    }

    protected function getShopScopeSql($alias = '')
    {
        $idShop = $this->getCurrentShopIdForStats();
        if ($idShop <= 0) {
            return '';
        }
        $prefix = $alias ? pSQL($alias).'.' : '';
        return ' AND '.$prefix.'id_shop = '.(int) $idShop.' ';
    }

    protected function getPerPage($key, $default = 20)
    {
        $perPage = (int) Tools::getValue($key, $default);
        $allowed = array(10, 20, 50, 100, 200);
        return in_array($perPage, $allowed, true) ? $perPage : $default;
    }

    protected function getPageNumber($key)
    {
        return max(1, (int) Tools::getValue($key, 1));
    }

    protected function getDateWhere($column, $fromKey, $toKey, &$params)
    {
        $where = '';
        $from = trim((string) Tools::getValue($fromKey, ''));
        $to = trim((string) Tools::getValue($toKey, ''));
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where .= ' AND '.$column.' >= "'.pSQL($from).' 00:00:00"';
            $params[$fromKey] = $from;
        }
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where .= ' AND '.$column.' <= "'.pSQL($to).' 23:59:59"';
            $params[$toKey] = $to;
        }
        return $where;
    }

    protected function getDateWhereForDateColumn($column, $fromKey, $toKey, &$params)
    {
        $where = '';
        $from = trim((string) Tools::getValue($fromKey, ''));
        $to = trim((string) Tools::getValue($toKey, ''));
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $where .= ' AND '.$column.' >= "'.pSQL($from).'"';
            $params[$fromKey] = $from;
        }
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $where .= ' AND '.$column.' <= "'.pSQL($to).'"';
            $params[$toKey] = $to;
        }
        return $where;
    }

    protected function getSourceFilterWhere($alias, $prefix, &$params)
    {
        $where = '';
        $fieldPrefix = $alias ? pSQL($alias).'.' : '';
        $typeKey = $prefix.'_source_type';
        $domainKey = $prefix.'_source_domain';
        $sourceType = trim((string) Tools::getValue($typeKey, ''));
        $sourceDomain = trim((string) Tools::getValue($domainKey, ''));

        if ($sourceType !== '') {
            $where .= ' AND '.$fieldPrefix.'source_type = "'.pSQL($sourceType).'"';
            $params[$typeKey] = $sourceType;
        }
        if ($sourceDomain !== '') {
            $where .= ' AND '.$fieldPrefix.'source_domain = "'.pSQL($sourceDomain).'"';
            $params[$domainKey] = $sourceDomain;
        }

        return $where;
    }

    protected function getStatsFilterValues($table, $column, $alias)
    {
        $allowedTables = array('jooim_dbcleaner_traffic_daily', 'jooim_dbcleaner_order_source_daily');
        $allowedColumns = array('source_type', 'source_domain');
        if (!in_array($table, $allowedTables, true) || !in_array($column, $allowedColumns, true)) {
            return array();
        }
        $where = 'WHERE '.$column.' IS NOT NULL AND '.$column.' != "" '.$this->getShopScopeSql($alias);
        $rows = Db::getInstance()->executeS('SELECT DISTINCT '.$column.' AS value FROM `'._DB_PREFIX_.pSQL($table).'` '.pSQL($alias).' '.$where.' ORDER BY '.$column.' ASC');
        $values = array();
        foreach ((array) $rows as $row) {
            if (isset($row['value']) && $row['value'] !== '') {
                $values[] = (string) $row['value'];
            }
        }
        return $values;
    }

    protected function renderFilterForm($tab, $prefix, $exportType = '')
    {
        $fromKey = $prefix.'_from';
        $toKey = $prefix.'_to';
        $perKey = $prefix.'_per_page';
        $typeKey = $prefix.'_source_type';
        $domainKey = $prefix.'_source_domain';
        $from = htmlspecialchars((string) Tools::getValue($fromKey, ''), ENT_QUOTES, 'UTF-8');
        $to = htmlspecialchars((string) Tools::getValue($toKey, ''), ENT_QUOTES, 'UTF-8');
        $selectedType = (string) Tools::getValue($typeKey, '');
        $selectedDomain = (string) Tools::getValue($domainKey, '');
        $perPage = $this->getPerPage($perKey, 20);
        $showSourceFilters = in_array($prefix, array('traffic', 'orders'), true);
        $table = $prefix === 'orders' ? 'jooim_dbcleaner_order_source_daily' : 'jooim_dbcleaner_traffic_daily';
        $alias = $prefix === 'orders' ? 'o' : 't';

        $html = '<style>'
            .'.jooim-dbcleaner-filter{display:flex;flex-wrap:wrap;align-items:center;gap:8px 10px;margin-bottom:15px}'
            .'.jooim-dbcleaner-filter label{margin:0 2px 0 0;font-weight:600;line-height:34px}'
            .'.jooim-dbcleaner-filter .form-control{height:34px;line-height:20px;padding:6px 10px;font-size:13px;color:#333;vertical-align:middle;min-width:120px}'
            .'.jooim-dbcleaner-filter input[type=date]{min-width:155px;line-height:20px;color:#222;background-color:#fff}'
            .'.jooim-dbcleaner-filter input[type=date]::-webkit-date-and-time-value{min-height:20px;line-height:20px;text-align:left}'
            .'.jooim-dbcleaner-filter select.form-control{min-width:150px}'
            .'</style>';
        $html .= '<form method="get" class="form-inline jooim-dbcleaner-filter">';
        $html .= '<input type="hidden" name="controller" value="AdminModules">';
        $html .= '<input type="hidden" name="configure" value="'.htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8').'">';
        $html .= '<input type="hidden" name="token" value="'.htmlspecialchars(Tools::getAdminTokenLite('AdminModules'), ENT_QUOTES, 'UTF-8').'">';
        $html .= '<input type="hidden" name="jooim_tab" value="'.htmlspecialchars($tab, ENT_QUOTES, 'UTF-8').'">';
        $html .= '<label>'.$this->l('Date from').'</label><input class="form-control" type="date" name="'.$fromKey.'" value="'.$from.'">';
        $html .= '<label>'.$this->l('Date to').'</label><input class="form-control" type="date" name="'.$toKey.'" value="'.$to.'">';

        if ($showSourceFilters) {
            $types = $this->getStatsFilterValues($table, 'source_type', $alias);
            $domains = $this->getStatsFilterValues($table, 'source_domain', $alias);
            $html .= '<label>'.$this->l('Type').'</label><select class="form-control" name="'.$typeKey.'">';
            $html .= '<option value="">'.$this->l('All types').'</option>';
            foreach ($types as $value) {
                $safeValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                $html .= '<option value="'.$safeValue.'"'.($selectedType === $value ? ' selected' : '').'>'.$safeValue.'</option>';
            }
            $html .= '</select>';
            $html .= '<label>'.$this->l('Domain').'</label><select class="form-control" name="'.$domainKey.'">';
            $html .= '<option value="">'.$this->l('All domains').'</option>';
            foreach ($domains as $value) {
                $safeValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                $html .= '<option value="'.$safeValue.'"'.($selectedDomain === $value ? ' selected' : '').'>'.$safeValue.'</option>';
            }
            $html .= '</select>';
        }

        $html .= '<label>'.$this->l('Rows per page').'</label><select class="form-control" name="'.$perKey.'">';
        foreach (array(10, 20, 50, 100, 200) as $value) {
            $html .= '<option value="'.$value.'"'.($perPage === $value ? ' selected' : '').'>'.$value.'</option>';
        }
        $html .= '</select>';
        $html .= '<button class="btn btn-default" type="submit"><i class="icon-search"></i> '.$this->l('Filter').'</button>';
        if ($exportType) {
            $html .= '<button class="btn btn-default" type="submit" name="jooim_export" value="'.htmlspecialchars($exportType, ENT_QUOTES, 'UTF-8').'"><i class="icon-download"></i> '.$this->l('Download CSV').'</button>';
        }
        $html .= '</form>';
        return $html;
    }

    protected function renderPagination($tab, $prefix, $page, $perPage, $total)
    {
        $pages = max(1, (int) ceil($total / $perPage));
        if ($pages <= 1) {
            return '';
        }
        $html = '<ul class="pagination">';
        for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++) {
            $params = array('jooim_tab' => $tab, $prefix.'_page' => $i, $prefix.'_per_page' => $perPage);
            foreach (array($prefix.'_from', $prefix.'_to', $prefix.'_source_type', $prefix.'_source_domain') as $key) {
                $value = Tools::getValue($key, '');
                if ($value !== '') {
                    $params[$key] = $value;
                }
            }
            $html .= '<li'.($i === $page ? ' class="active"' : '').'><a href="'.htmlspecialchars($this->getAdminBaseUrl($params), ENT_QUOTES, 'UTF-8').'">'.$i.'</a></li>';
        }
        $html .= '</ul>';
        return $html;
    }

    protected function processCsvExport()
    {
        $type = (string) Tools::getValue('jooim_export', '');
        if ($type !== 'traffic' && $type !== 'orders') {
            return;
        }

        $filename = $type === 'traffic' ? 'jooim_dbcleaner_traffic_stats.csv' : 'jooim_dbcleaner_orders_by_country_source.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        $out = fopen('php://output', 'w');
        if ($type === 'traffic') {
            $params = array();
            $where = 'WHERE 1=1 '.$this->getShopScopeSql('t');
            $where .= $this->getDateWhereForDateColumn('t.date_day', 'traffic_from', 'traffic_to', $params);
            $where .= $this->getSourceFilterWhere('t', 'traffic', $params);
            fputcsv($out, array('date_day', 'id_shop', 'source_type', 'source_domain', 'utm_source', 'utm_medium', 'utm_campaign', 'visits', 'pageviews', 'orders'));
            $rows = Db::getInstance()->executeS('SELECT date_day, id_shop, source_type, source_domain, utm_source, utm_medium, utm_campaign, visits, pageviews, orders
                FROM `'._DB_PREFIX_.'jooim_dbcleaner_traffic_daily` t '.$where.' ORDER BY date_day DESC, visits DESC');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
        } else {
            $params = array();
            $where = 'WHERE 1=1 '.$this->getShopScopeSql('o');
            $where .= $this->getDateWhereForDateColumn('o.date_day', 'orders_from', 'orders_to', $params);
            $where .= $this->getSourceFilterWhere('o', 'orders', $params);
            fputcsv($out, array('date_day', 'id_shop', 'country_iso', 'country_name', 'source_type', 'source_domain', 'orders'));
            $rows = Db::getInstance()->executeS('SELECT date_day, id_shop, country_iso, country_name, source_type, source_domain, orders
                FROM `'._DB_PREFIX_.'jooim_dbcleaner_order_source_daily` o '.$where.' ORDER BY date_day DESC, orders DESC');
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
        }
        fclose($out);
        exit;
    }

    protected function renderInfoPanel()
    {
        $size = JooimDbcleanerTools::getDatabaseSizeMb();
        $lastRun = Configuration::get('JOOIM_DBCLEANER_LAST_RUN');
        $lastStatus = Configuration::get('JOOIM_DBCLEANER_LAST_STATUS');
        $lastMessage = Configuration::get('JOOIM_DBCLEANER_LAST_MESSAGE');

        $html = '<div class="panel"><h3>'.$this->l('Database overview').'</h3>';
        $html .= '<p><strong>'.$this->l('Multishop scope').':</strong> '.htmlspecialchars($this->getCurrentShopIdForStats() ? $this->l('Current shop') : $this->l('All shops / global database'), ENT_QUOTES, 'UTF-8').'</p>';
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
        $perPage = $this->getPerPage('logs_per_page', 20);
        $page = $this->getPageNumber('logs_page');
        $offset = ($page - 1) * $perPage;
        $params = array();
        $where = 'WHERE 1=1 '.$this->getShopScopeSql('l');
        $where .= $this->getDateWhere('l.date_add', 'logs_from', 'logs_to', $params);
        $countRows = Db::getInstance()->executeS('SELECT COUNT(*) AS total FROM `'._DB_PREFIX_.'jooim_dbcleaner_log` l '.$where);
        $total = isset($countRows[0]['total']) ? (int) $countRows[0]['total'] : 0;
        $rows = Db::getInstance()->executeS('SELECT * FROM `'._DB_PREFIX_.'jooim_dbcleaner_log` l '.$where.' ORDER BY id_jooim_dbcleaner_log DESC LIMIT '.(int) $offset.', '.(int) $perPage);
        $html = '<div class="panel"><h3>'.$this->l('Recent cleanup runs').'</h3>';
        $html .= $this->renderFilterForm('info', 'logs');
        $html .= '<table class="table"><thead><tr><th>ID</th><th>'.$this->l('Shop ID').'</th><th>'.$this->l('Date').'</th><th>'.$this->l('Type').'</th><th>'.$this->l('Status').'</th><th>'.$this->l('Deleted connections').'</th><th>'.$this->l('Source').'</th><th>'.$this->l('Pages').'</th><th>'.$this->l('Layered cache').'</th><th>'.$this->l('DB before/after MB').'</th><th>'.$this->l('Runtime').'</th><th>'.$this->l('Message').'</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>'.(int) $row['id_jooim_dbcleaner_log'].'</td><td>'.(int) (isset($row['id_shop']) ? $row['id_shop'] : 0).'</td><td>'.htmlspecialchars($row['date_add'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['run_type'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8').'</td><td>'.(int) $row['deleted_connections'].'</td><td>'.(int) $row['deleted_connections_source'].'</td><td>'.(int) $row['deleted_connections_page'].'</td><td>'.((int) $row['layered_filter_cleared'] ? $this->l('yes') : $this->l('no')).'</td><td>'.htmlspecialchars($row['database_size_mb_before'], ENT_QUOTES, 'UTF-8').' / '.htmlspecialchars($row['database_size_mb_after'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['runtime_seconds'], ENT_QUOTES, 'UTF-8').'s</td><td>'.htmlspecialchars($row['message'], ENT_QUOTES, 'UTF-8').'</td></tr>';
        }
        if (!$rows) {
            $html .= '<tr><td colspan="12">'.$this->l('No cleanup runs yet.').'</td></tr>';
        }
        $html .= '</tbody></table>'.$this->renderPagination('info', 'logs', $page, $perPage, $total).'</div>';
        return $html;
    }

    protected function renderTrafficPanel()
    {
        $perPage = $this->getPerPage('traffic_per_page', 20);
        $page = $this->getPageNumber('traffic_page');
        $offset = ($page - 1) * $perPage;
        $params = array();
        $where = 'WHERE 1=1 '.$this->getShopScopeSql('t');
        $where .= $this->getDateWhereForDateColumn('t.date_day', 'traffic_from', 'traffic_to', $params);
        $where .= $this->getSourceFilterWhere('t', 'traffic', $params);
        $countRows = Db::getInstance()->executeS('SELECT COUNT(*) AS total FROM `'._DB_PREFIX_.'jooim_dbcleaner_traffic_daily` t '.$where);
        $total = isset($countRows[0]['total']) ? (int) $countRows[0]['total'] : 0;
        $rows = Db::getInstance()->executeS('SELECT date_day, id_shop, source_type, source_domain, utm_source, utm_medium, utm_campaign, visits, pageviews, orders
            FROM `'._DB_PREFIX_.'jooim_dbcleaner_traffic_daily` t '.$where.'
            ORDER BY date_day DESC, visits DESC
            LIMIT '.(int) $offset.', '.(int) $perPage);
        $html = '<div class="panel"><h3>'.$this->l('Aggregated traffic source stats').'</h3>';
        $html .= '<p>'.$this->l('Only aggregated data is stored. No IP addresses, full referrer URLs or user-agent strings are saved by this module.').'</p>';
        $html .= $this->renderFilterForm('stats', 'traffic', 'traffic');
        $html .= '<table class="table"><thead><tr><th>'.$this->l('Date').'</th><th>'.$this->l('Shop ID').'</th><th>'.$this->l('Type').'</th><th>'.$this->l('Domain').'</th><th>UTM Source</th><th>UTM Medium</th><th>UTM Campaign</th><th>'.$this->l('Visits').'</th><th>'.$this->l('Pageviews').'</th><th>'.$this->l('Orders').'</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>'.htmlspecialchars($row['date_day'], ENT_QUOTES, 'UTF-8').'</td><td>'.(int) $row['id_shop'].'</td><td>'.htmlspecialchars($row['source_type'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['source_domain'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['utm_source'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['utm_medium'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['utm_campaign'], ENT_QUOTES, 'UTF-8').'</td><td>'.(int) $row['visits'].'</td><td>'.(int) $row['pageviews'].'</td><td>'.(int) $row['orders'].'</td></tr>';
        }
        if (!$rows) {
            $html .= '<tr><td colspan="10">'.$this->l('No aggregated traffic data yet.').'</td></tr>';
        }
        $html .= '</tbody></table>'.$this->renderPagination('stats', 'traffic', $page, $perPage, $total).'</div>';
        return $html;
    }

    protected function renderOrdersByCountryPanel()
    {
        $perPage = $this->getPerPage('orders_per_page', 20);
        $page = $this->getPageNumber('orders_page');
        $offset = ($page - 1) * $perPage;
        $params = array();
        $where = 'WHERE 1=1 '.$this->getShopScopeSql('o');
        $where .= $this->getDateWhereForDateColumn('o.date_day', 'orders_from', 'orders_to', $params);
        $where .= $this->getSourceFilterWhere('o', 'orders', $params);
        $countRows = Db::getInstance()->executeS('SELECT COUNT(*) AS total FROM `'._DB_PREFIX_.'jooim_dbcleaner_order_source_daily` o '.$where);
        $total = isset($countRows[0]['total']) ? (int) $countRows[0]['total'] : 0;
        $rows = Db::getInstance()->executeS('SELECT date_day, id_shop, country_iso, country_name, source_type, source_domain, orders
            FROM `'._DB_PREFIX_.'jooim_dbcleaner_order_source_daily` o '.$where.'
            ORDER BY date_day DESC, orders DESC
            LIMIT '.(int) $offset.', '.(int) $perPage);
        $html = '<div class="panel"><h3>'.$this->l('Orders by country, source type and domain').'</h3>';
        $html .= '<p>'.$this->l('This table shows how many orders were attributed to a country, source type and domain for each day. Attribution is created from the visitor source data available before cleanup.').'</p>';
        $html .= $this->renderFilterForm('stats', 'orders', 'orders');
        $html .= '<table class="table"><thead><tr><th>'.$this->l('Date').'</th><th>'.$this->l('Shop ID').'</th><th>'.$this->l('Country').'</th><th>'.$this->l('Country ISO').'</th><th>'.$this->l('Type').'</th><th>'.$this->l('Domain').'</th><th>'.$this->l('Orders').'</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr><td>'.htmlspecialchars($row['date_day'], ENT_QUOTES, 'UTF-8').'</td><td>'.(int) $row['id_shop'].'</td><td>'.htmlspecialchars($row['country_name'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['country_iso'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['source_type'], ENT_QUOTES, 'UTF-8').'</td><td>'.htmlspecialchars($row['source_domain'], ENT_QUOTES, 'UTF-8').'</td><td>'.(int) $row['orders'].'</td></tr>';
        }
        if (!$rows) {
            $html .= '<tr><td colspan="7">'.$this->l('No order source statistics yet.').'</td></tr>';
        }
        $html .= '</tbody></table>'.$this->renderPagination('stats', 'orders', $page, $perPage, $total).'</div>';
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
