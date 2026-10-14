<?php
// Standalone regression tests. No shop bootstrap, network, credentials or production DB.
define('_PS_VERSION_', '9.0.0');
define('_DB_PREFIX_', 'custom_');
define('_DB_NAME_', 'test');
define('_MYSQL_ENGINE_', 'InnoDB');
function pSQL($value, $html = false) { return str_replace("'", "''", $value); }
class Module { public $version = '1.00.03'; }
class Configuration {
    public static $values = array();
    public static $failKey = '';
    public static function get($key) { return isset(self::$values[$key]) ? self::$values[$key] : false; }
    public static function updateValue($key, $value) {
        if ($key === self::$failKey) { return false; }
        self::$values[$key] = $value;
        return true;
    }
}
class Tools {
    public static $request = array();
    public static function substr($s, $start, $length) { return substr($s, $start, $length); }
    public static function getValue($key, $default = false) { return isset(self::$request[$key]) ? self::$request[$key] : $default; }
}
class Context {
    public $shop;
    public static function getContext() { $c = new self; $c->shop = (object) array('id' => 1); return $c; }
}
class Db {
    public static $instance;
    public $pdo;
    public $queries = array();
    public $lock = 1;
    public $released = 0;
    public $failSelect = false;
    public $failDelete = false;
    public $afterDelete;
    public $logs = array();
    private $affected = 0;
    public function __construct() { $this->pdo = new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
    public static function getInstance() { return self::$instance; }
    public function executeS($sql, $array = true, $cache = true) {
        $this->queries[] = $sql;
        if (strpos($sql, 'GET_LOCK(') !== false) {
            check(!$cache, 'lock query bypasses cache');
            return array(array('acquired_lock' => $this->lock));
        }
        if (strpos($sql, 'RELEASE_LOCK(') !== false) { check(!$cache, 'unlock bypasses cache'); ++$this->released; return array(array('released_lock' => 1)); }
        if (strpos($sql, 'information_schema.tables') !== false) {
            if (strpos($sql, 'SUM(') !== false) { return array(array('database_size_mb' => 1)); }
            preg_match('/table_name = "([^"]+)"/', $sql, $m);
            $q = $this->pdo->prepare("SELECT COUNT(*) AS found_table FROM sqlite_master WHERE type='table' AND name=?");
            $q->execute(array($m[1])); return $q->fetchAll(PDO::FETCH_ASSOC);
        }
        if (strpos($sql, 'information_schema.columns') !== false) {
            preg_match('/table_name = "([^"]+)"/', $sql, $m);
            preg_match('/column_name = "([^"]+)"/', $sql, $c);
            $columns = $this->pdo->query('PRAGMA table_info('.$m[1].')')->fetchAll(PDO::FETCH_ASSOC);
            return array(array('found_column' => count(array_filter($columns, function ($r) use ($c) { return $r['name'] === $c[1]; }))));
        }
        if ($this->failSelect === 'throwable') { throw new TypeError('Simulated driver type error'); }
        if ($this->failSelect) { return false; }
        if (strpos($sql, 'custom_jooim_dbcleaner_') === false) {
            check(!$cache, 'cleanup SELECT bypasses cache');
        }
        return $this->pdo->query($this->translate($sql))->fetchAll(PDO::FETCH_ASSOC);
    }
    private function translate($sql) {
        $sql = preg_replace('/DATE_SUB\(NOW\(\), INTERVAL (\d+) DAY\)/', "datetime('now', '-$1 days')", $sql);
        $sql = preg_replace('/DATE_SUB\(CURDATE\(\), INTERVAL (\d+) DAY\)/', "date('now', '-$1 days')", $sql);
        // SQLite builds without DELETE LIMIT: preserve the row limit using rowid.
        if (preg_match('/^DELETE FROM (`[^`]+`)\s+WHERE (.*)\s+LIMIT (\d+)$/s', trim($sql), $m)) {
            $sql = 'DELETE FROM '.$m[1].' WHERE rowid IN (SELECT rowid FROM '.$m[1].' WHERE '.$m[2].' LIMIT '.$m[3].')';
        }
        return $sql;
    }
    public function execute($sql) {
        $this->queries[] = $sql;
        if ($this->failDelete) { return false; }
        $this->affected = $this->pdo->exec($this->translate($sql));
        if ($this->afterDelete) { call_user_func($this->afterDelete, $sql); }
        return true;
    }
    public function Affected_Rows() { return $this->affected; }
    public function getMsgError() { return 'simulated database failure'; }
    public function insert($table, $data) { $this->logs[] = $data; return true; }
}
function check($value, $message) { if (!$value) { throw new RuntimeException('FAILED: '.$message); } }
require dirname(__DIR__).'/jooim_dbcleaner.php';
class TestModule extends Module {
    public $schemaOk = true;
    public function ensureDatabaseSchema() { return $this->schemaOk; }
}
class CleanerProbe extends JooimDbcleanerCleaner {
    public function start() { $this->startedAt = microtime(true); $this->maxRuntimeSeconds = 120; }
    public function expire() { $this->startedAt = microtime(true) - 200; }
    public function guests($ids, $batch) { return $this->deleteOldGuests($ids, $batch); }
    public function pages($batch) { return $this->deleteOrphanConnectionPages($batch, 1); }
}
function fixture() {
    Db::$instance = new Db;
    $db = Db::$instance;
    foreach (array(
        'guest' => 'id_guest INTEGER PRIMARY KEY, id_customer INTEGER',
        'cart' => 'id_cart INTEGER PRIMARY KEY, id_guest INTEGER',
        'connections' => 'id_connections INTEGER PRIMARY KEY, id_guest INTEGER, date_add TEXT',
        'connections_source' => 'id_connections_source INTEGER PRIMARY KEY, id_connections INTEGER, date_add TEXT',
        'connections_page' => 'id_connections INTEGER, id_page INTEGER, time_start TEXT',
        'jooim_dbcleaner_log' => 'date_add TEXT',
    ) as $table => $columns) { $db->pdo->exec('CREATE TABLE custom_'.$table.' ('.$columns.')'); }
    Configuration::$failKey = '';
    Configuration::$values = array('JOOIM_DBCLEANER_ENABLE' => 1, 'JOOIM_DBCLEANER_RETENTION_DAYS' => 30,
        'JOOIM_DBCLEANER_SOURCE_RETENTION_DAYS' => 7, 'JOOIM_DBCLEANER_BATCH_SIZE' => 100,
        'JOOIM_DBCLEANER_MAX_BATCHES' => 1, 'JOOIM_DBCLEANER_MAX_RUNTIME' => 120,
        'JOOIM_DBCLEANER_CLEAN_ORPHANS' => 1);
    return $db;
}
$db = fixture();
$db->pdo->exec("INSERT INTO custom_guest VALUES (1,0),(2,0),(3,9),(4,0),(5,0),(6,NULL)");
$db->pdo->exec("INSERT INTO custom_cart VALUES (1,2)");
$db->pdo->exec("INSERT INTO custom_connections VALUES (1,1,'2000-01-01'),(2,2,'2000-01-01'),(3,3,'2000-01-01'),(4,4,'2000-01-01'),(5,4,datetime('now')),(6,6,'2000-01-01')");
$db->pdo->exec("INSERT INTO custom_connections_source VALUES (1,1,'2000-01-01'),(2,5,datetime('now')),(3,99,'2000-01-01')");
$db->pdo->exec("INSERT INTO custom_connections_page VALUES (1,1,'2000-01-01'),(99,1,'2000-01-01'),(5,1,'2000-01-01')");
$result = (new CleanerProbe(new TestModule))->run();
check($result['status'] === 'success', 'successful full cleanup');
check($result['deleted_guests'] === 2, 'expired anonymous guests removed');
check($db->pdo->query('SELECT group_concat(id_guest) FROM custom_guest')->fetchColumn() === '2,3,4,5', 'cart, customer, recent and unknown-age guests preserved');
check($result['deleted_connections'] === 5 && $result['deleted_connections_source'] === 2, 'expired connections and sources removed');
check($db->pdo->query('SELECT COUNT(*) FROM custom_connections_page')->fetchColumn() == 1, 'recent connection page preserved');
check($db->released === 1, 'lock released after success');
$db = fixture();
for ($i = 0; $i < 12; $i++) { $db->pdo->exec("INSERT INTO custom_connections_page VALUES (99,$i,'2000-01-01')"); }
$p = new CleanerProbe(new TestModule); $p->start();
check($p->pages(3) === 3, 'orphan page batch limits actual rows');
$db = fixture();
$db->pdo->exec("INSERT INTO custom_connections VALUES (1,1,'2000-01-01')");
for ($i = 0; $i < 205; $i++) { $db->pdo->exec("INSERT INTO custom_connections_page VALUES (1,$i,'2000-01-01')"); }
$result = (new CleanerProbe(new TestModule))->run();
check($result['deleted_connections_page'] === 205 && $result['deleted_connections'] === 1, 'all bounded child batches complete before parent deletion');
foreach ($db->queries as $query) {
    if (strpos($query, 'DELETE FROM `custom_connections_page`') === 0) { check(strpos($query, 'LIMIT 100') !== false, 'child DELETE limits actual rows'); }
}
$db = fixture(); $db->failSelect = true;
$result = (new CleanerProbe(new TestModule))->run();
check($result['status'] === 'error' && $db->released === 1, 'failed SELECT handled and lock released');
$db = fixture(); $db->failSelect = 'throwable';
$result = (new CleanerProbe(new TestModule))->run();
check($result['status'] === 'error' && $db->released === 1, 'PHP TypeError handled and lock released');
$db = fixture(); $db->failDelete = true;
$result = (new CleanerProbe(new TestModule))->run();
check($result['status'] === 'error' && $db->released === 1, 'failed DELETE handled and lock released');
foreach (array(null, 0) as $lock) {
    $db = fixture(); $db->lock = $lock;
    $result = (new CleanerProbe(new TestModule))->run();
    check($result['status'] === ($lock === null ? 'error' : 'skipped'), 'unavailable/busy lock fails closed');
    check(count(array_filter($db->queries, function ($q) { return strpos($q, 'DELETE') === 0; })) === 0, 'no deletion without lock');
    check($db->released === 0, 'never release another process lock');
}
$db = fixture(); $m = new TestModule; $m->schemaOk = false;
$result = (new CleanerProbe($m))->run();
check($result['status'] === 'error' && $db->released === 1, 'schema failure prevents cleanup');
$db = fixture();
$db->pdo->exec("INSERT INTO custom_connections VALUES (1,1,'2000-01-01')");
$db->pdo->exec("INSERT INTO custom_connections_page VALUES (1,1,'2000-01-01')");
$p = new CleanerProbe(new TestModule);
$db->afterDelete = function ($q) use ($p) { if (strpos($q, 'custom_connections_page') !== false) { $p->expire(); } };
$result = $p->run();
check($result['status'] === 'partial', 'runtime deadline reports partial');
check($db->pdo->query('SELECT COUNT(*) FROM custom_connections')->fetchColumn() == 1, 'parent kept when runtime ends during child cleanup');
class SchemaProbe extends Jooim_dbcleaner {
    public $ok = false;
    protected function installSql() { return $this->ok; }
}
$m = (new ReflectionClass('SchemaProbe'))->newInstanceWithoutConstructor();
Configuration::$values = array();
check(!$m->ensureDatabaseSchema() && !Configuration::get('JOOIM_DBCLEANER_SCHEMA_VERSION'), 'failed schema never marked upgraded');
$m->ok = true; Configuration::$failKey = Jooim_dbcleaner::CONFIG_CLEAN_ORPHANS;
check(!$m->ensureDatabaseSchema() && !Configuration::get('JOOIM_DBCLEANER_SCHEMA_VERSION'), 'failed defaults never marked upgraded');
Configuration::$failKey = '';
check($m->ensureDatabaseSchema() && Configuration::get('JOOIM_DBCLEANER_SCHEMA_VERSION') === '1.00.03', 'successful upgrade version recorded');


class StatisticsProbe extends Jooim_dbcleaner {
    public $shopId = 1;
    public function l($text) { return $text; }
    public function activeTab() { return $this->getActiveTab(); }
    public function tabs() { return $this->renderTabs(); }
    public function content() { return $this->renderActiveTabContent(); }
    protected function getAdminBaseUrl(array $extra = array()) { return 'index.php?'.http_build_query($extra); }
    protected function getShopScopeSql($alias = '') { return $this->shopId ? ' AND '.$alias.'.id_shop = '.$this->shopId : ''; }
    protected function renderFilterForm($tab, $prefix, $exportType = '') {
        check($exportType === '', 'statistics do not expose inactive CSV export');
        return '<div>filter-'.$prefix.'</div>';
    }
}
$db = fixture();
$m = (new ReflectionClass('StatisticsProbe'))->newInstanceWithoutConstructor();
Tools::$request = array('jooim_tab' => 'stats');
check($m->activeTab() === 'stats', 'Statistics tab accepted');
check(strpos($m->tabs(), 'Statistics') !== false && strpos($m->tabs(), 'class="active"') !== false, 'Statistics navigation visible and active');
$html = $m->content();
check(substr_count($html, 'No historical statistics are available') === 2, 'fresh installation without legacy tables renders empty panels');
check(strpos($html, 'does not collect new statistics') !== false, 'historical-only behavior explained');
$db->pdo->exec('CREATE TABLE custom_jooim_dbcleaner_traffic_daily (date_day TEXT, id_shop INTEGER, source_type TEXT, source_domain TEXT, utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, visits INTEGER, pageviews INTEGER, orders INTEGER)');
$db->pdo->exec('CREATE TABLE custom_jooim_dbcleaner_order_source_daily (date_day TEXT, id_shop INTEGER, country_iso TEXT, country_name TEXT, source_type TEXT, source_domain TEXT, orders INTEGER)');
for ($i = 0; $i < 25; $i++) {
    $db->pdo->exec("INSERT INTO custom_jooim_dbcleaner_traffic_daily VALUES ('2026-10-01',1,'organic_search','example.com','','','',3,4,1)");
}
$db->pdo->exec("INSERT INTO custom_jooim_dbcleaner_traffic_daily VALUES ('2026-10-01',2,'organic_search','other-shop.example','','','',3,4,1)");
$db->pdo->exec("INSERT INTO custom_jooim_dbcleaner_order_source_daily VALUES ('2026-10-01',1,'SK','Slovakia','organic_search','<script>',2)");
$html = $m->content();
check(strpos($html, 'example.com') !== false && strpos($html, 'Slovakia') !== false, 'legacy traffic and order summaries displayed');
check(strpos($html, 'other-shop.example') === false, 'legacy statistics respect shop scope');
check(strpos($html, '&lt;script&gt;') !== false && strpos($html, '<script>') === false, 'historical source values escaped');
check(strpos($html, 'pagination') !== false && substr_count($html, 'example.com') === 20, 'legacy traffic pagination limits visible rows');
Tools::$request['traffic_source_domain'] = 'absent.example';
$html = $m->content();
check(strpos($html, 'No aggregated traffic data yet.') !== false, 'statistics filters applied');
check(!count(array_filter($db->queries, function ($q) { return strpos($q, 'DELETE') === 0; })), 'statistics rendering does not delete data');
Tools::$request = array('jooim_tab' => 'invalid');
check($m->activeTab() === 'settings', 'unknown tab falls back to settings');
echo "All cleaner, upgrade and Statistics tab regression tests passed.\n";
