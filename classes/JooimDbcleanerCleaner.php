<?php
/**
 * JooIM DB Cleaner cleaning service.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__).'/JooimDbcleanerTools.php';

class JooimDbcleanerCleaner
{
    protected $db;
    protected $module;

    public function __construct(Module $module)
    {
        $this->db = Db::getInstance();
        $this->module = $module;
    }

    public function run($runType = 'cron')
    {
        $started = microtime(true);
        if (method_exists($this->module, 'ensureDatabaseSchema')) {
            $this->module->ensureDatabaseSchema();
        }
        $result = array(
            'status' => 'success',
            'message' => '',
            'deleted_connections' => 0,
            'deleted_connections_source' => 0,
            'deleted_connections_page' => 0,
            'layered_filter_cleared' => 0,
            'database_size_mb_before' => JooimDbcleanerTools::getDatabaseSizeMb(),
            'database_size_mb_after' => null,
            'runtime_seconds' => 0,
        );

        if (!Configuration::get('JOOIM_DBCLEANER_ENABLE')) {
            $result['status'] = 'skipped';
            $result['message'] = 'Cleanup is disabled.';
            $this->finishLog($runType, $result, $started);
            return $result;
        }

        if (!$this->acquireLock()) {
            $result['status'] = 'skipped';
            $result['message'] = 'Another cleanup process is already running.';
            $this->finishLog($runType, $result, $started);
            return $result;
        }

        try {
            $retentionDays = max(1, (int) Configuration::get('JOOIM_DBCLEANER_RETENTION_DAYS'));
            $batchSize = max(100, (int) Configuration::get('JOOIM_DBCLEANER_BATCH_SIZE'));
            $maxBatches = max(1, (int) Configuration::get('JOOIM_DBCLEANER_MAX_BATCHES'));

            if (Configuration::get('JOOIM_DBCLEANER_AGGREGATE_STATS')) {
                $this->aggregateStatsForOldConnections($retentionDays, $batchSize, $maxBatches);
            }

            $deleteResult = $this->deleteOldConnectionStats($retentionDays, $batchSize, $maxBatches);
            $result['deleted_connections'] = $deleteResult['deleted_connections'];
            $result['deleted_connections_source'] = $deleteResult['deleted_connections_source'];
            $result['deleted_connections_page'] = $deleteResult['deleted_connections_page'];

            if (Configuration::get('JOOIM_DBCLEANER_CLEAR_LAYERED_FILTER') && JooimDbcleanerTools::tableExists('layered_filter_block')) {
                $this->db->execute('TRUNCATE TABLE `'._DB_PREFIX_.'layered_filter_block`');
                $result['layered_filter_cleared'] = 1;
            }

            $this->purgeOldAggregates();
        } catch (Exception $e) {
            $result['status'] = 'error';
            $result['message'] = $e->getMessage();
        }

        $this->releaseLock();
        $this->finishLog($runType, $result, $started);
        return $result;
    }

    protected function finishLog($runType, array &$result, $started)
    {
        $result['database_size_mb_after'] = JooimDbcleanerTools::getDatabaseSizeMb();
        $result['runtime_seconds'] = round(microtime(true) - $started, 3);
        $this->insertLog($runType, $result);
        Configuration::updateValue('JOOIM_DBCLEANER_LAST_RUN', date('Y-m-d H:i:s'));
        Configuration::updateValue('JOOIM_DBCLEANER_LAST_STATUS', $result['status']);
        Configuration::updateValue('JOOIM_DBCLEANER_LAST_MESSAGE', Tools::substr($result['message'], 0, 255));
    }

    protected function acquireLock()
    {
        $timeout = max(300, (int) Configuration::get('JOOIM_DBCLEANER_STALE_LOCK_TIMEOUT'));
        $runningSince = (int) Configuration::get('JOOIM_DBCLEANER_RUNNING_SINCE');
        $now = time();

        if ($runningSince > 0 && ($now - $runningSince) < $timeout) {
            return false;
        }

        Configuration::updateValue('JOOIM_DBCLEANER_RUNNING_SINCE', (string) $now);
        return true;
    }

    protected function releaseLock()
    {
        Configuration::updateValue('JOOIM_DBCLEANER_RUNNING_SINCE', '0');
    }

    protected function getOldConnectionIds($retentionDays, $batchSize)
    {
        if (!JooimDbcleanerTools::tableExists('connections')) {
            return array();
        }

        $sql = 'SELECT id_connections
                FROM `'._DB_PREFIX_.'connections`
                WHERE date_add < DATE_SUB(NOW(), INTERVAL '.(int) $retentionDays.' DAY)
                ORDER BY id_connections ASC
                LIMIT '.(int) $batchSize;
        $rows = $this->db->executeS($sql);
        $ids = array();
        foreach ($rows as $row) {
            $ids[] = (int) $row['id_connections'];
        }
        return $ids;
    }

    protected function deleteOldConnectionStats($retentionDays, $batchSize, $maxBatches)
    {
        $totals = array(
            'deleted_connections' => 0,
            'deleted_connections_source' => 0,
            'deleted_connections_page' => 0,
        );

        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $ids = $this->getOldConnectionIds($retentionDays, $batchSize);
            if (!$ids) {
                break;
            }

            $idsSql = implode(',', array_map('intval', $ids));

            if (JooimDbcleanerTools::tableExists('connections_page')) {
                $this->db->execute('DELETE FROM `'._DB_PREFIX_.'connections_page` WHERE id_connections IN ('.$idsSql.')');
                $totals['deleted_connections_page'] += $this->db->Affected_Rows();
            }

            if (JooimDbcleanerTools::tableExists('connections_source')) {
                $this->db->execute('DELETE FROM `'._DB_PREFIX_.'connections_source` WHERE id_connections IN ('.$idsSql.')');
                $totals['deleted_connections_source'] += $this->db->Affected_Rows();
            }

            $this->db->execute('DELETE FROM `'._DB_PREFIX_.'connections` WHERE id_connections IN ('.$idsSql.')');
            $totals['deleted_connections'] += $this->db->Affected_Rows();
        }

        return $totals;
    }

    protected function aggregateStatsForOldConnections($retentionDays, $batchSize, $maxBatches)
    {
        if (!JooimDbcleanerTools::tableExists('connections')) {
            return;
        }

        $limit = $batchSize * $maxBatches;
        $hasSource = JooimDbcleanerTools::tableExists('connections_source');
        $hasPage = JooimDbcleanerTools::tableExists('connections_page');
        $hasShop = JooimDbcleanerTools::columnExists('connections', 'id_shop');
        $hasGuest = JooimDbcleanerTools::columnExists('connections', 'id_guest');

        $sourceSelect = $hasSource ? 'cs.http_referer, cs.request_uri' : 'c.http_referer, "" AS request_uri';
        $sourceJoin = $hasSource ? 'LEFT JOIN `'._DB_PREFIX_.'connections_source` cs ON cs.id_connections = c.id_connections' : '';
        $pageSelect = $hasPage ? '(SELECT COUNT(*) FROM `'._DB_PREFIX_.'connections_page` cp WHERE cp.id_connections = c.id_connections) AS pageviews' : '1 AS pageviews';
        $shopSelect = $hasShop ? 'c.id_shop' : '0 AS id_shop';
        $guestSelect = $hasGuest ? 'c.id_guest' : '0 AS id_guest';

        $sql = 'SELECT c.id_connections, '.$shopSelect.', '.$guestSelect.', c.date_add, DATE(c.date_add) AS date_day, '.$sourceSelect.', '.$pageSelect.'
                FROM `'._DB_PREFIX_.'connections` c
                '.$sourceJoin.'
                WHERE c.date_add < DATE_SUB(NOW(), INTERVAL '.(int) $retentionDays.' DAY)
                ORDER BY c.id_connections ASC
                LIMIT '.(int) $limit;

        $rows = $this->db->executeS($sql);
        if (!$rows) {
            return;
        }

        $aggregates = array();
        $sourceByConnection = array();
        foreach ($rows as $row) {
            $referrer = isset($row['http_referer']) ? (string) $row['http_referer'] : '';
            $requestUri = isset($row['request_uri']) ? (string) $row['request_uri'] : '';
            $domain = JooimDbcleanerTools::normalizeDomain($referrer);
            $sourceType = JooimDbcleanerTools::detectSourceType($domain, $requestUri, $referrer);
            $utmSource = JooimDbcleanerTools::extractUtm($requestUri, 'utm_source');
            $utmMedium = JooimDbcleanerTools::extractUtm($requestUri, 'utm_medium');
            $utmCampaign = JooimDbcleanerTools::extractUtm($requestUri, 'utm_campaign');
            $dateDay = pSQL($row['date_day']);
            $idShop = (int) $row['id_shop'];
            $pageviews = max(1, (int) $row['pageviews']);

            $key = implode('|', array($idShop, $dateDay, $sourceType, $domain, $utmSource, $utmMedium, $utmCampaign));
            if (!isset($aggregates[$key])) {
                $aggregates[$key] = array(
                    'id_shop' => $idShop,
                    'date_day' => $dateDay,
                    'source_type' => $sourceType,
                    'source_domain' => $domain,
                    'utm_source' => $utmSource,
                    'utm_medium' => $utmMedium,
                    'utm_campaign' => $utmCampaign,
                    'visits' => 0,
                    'pageviews' => 0,
                );
            }
            $aggregates[$key]['visits']++;
            $aggregates[$key]['pageviews'] += $pageviews;

            $sourceByConnection[(int) $row['id_connections']] = array(
                'id_shop' => $idShop,
                'source_type' => $sourceType,
                'source_domain' => $domain,
                'utm_source' => $utmSource,
                'utm_medium' => $utmMedium,
                'utm_campaign' => $utmCampaign,
            );
        }

        foreach ($aggregates as $data) {
            $this->upsertTrafficDaily($data);
        }

        $this->aggregateOrdersForConnections(array_keys($sourceByConnection), $sourceByConnection);
    }

    protected function aggregateOrdersForConnections(array $connectionIds, array $sourceByConnection)
    {
        if (!$connectionIds || !JooimDbcleanerTools::columnExists('connections', 'id_guest') || !JooimDbcleanerTools::tableExists('cart') || !JooimDbcleanerTools::tableExists('orders')) {
            return;
        }

        $idsSql = implode(',', array_map('intval', $connectionIds));
        $countryLangId = (int) Configuration::get('PS_LANG_DEFAULT');
        $hasCartGuest = JooimDbcleanerTools::columnExists('cart', 'id_guest');
        if (!$hasCartGuest) {
            return;
        }

        $sql = 'SELECT o.id_order, o.id_shop, o.date_add AS order_date_add, DATE(o.date_add) AS date_day,
                       x.id_connections,
                       IFNULL(co.iso_code, "") AS country_iso,
                       IFNULL(cl.name, "") AS country_name
                FROM `'._DB_PREFIX_.'orders` o
                INNER JOIN `'._DB_PREFIX_.'cart` ca ON ca.id_cart = o.id_cart
                INNER JOIN (
                    SELECT o2.id_order, MAX(c2.id_connections) AS id_connections
                    FROM `'._DB_PREFIX_.'connections` c2
                    INNER JOIN `'._DB_PREFIX_.'cart` ca2 ON ca2.id_guest = c2.id_guest
                    INNER JOIN `'._DB_PREFIX_.'orders` o2 ON o2.id_cart = ca2.id_cart
                    WHERE c2.id_connections IN ('.$idsSql.')
                      AND o2.date_add >= c2.date_add
                    GROUP BY o2.id_order
                ) x ON x.id_order = o.id_order
                LEFT JOIN `'._DB_PREFIX_.'address` a ON a.id_address = o.id_address_delivery
                LEFT JOIN `'._DB_PREFIX_.'country` co ON co.id_country = a.id_country
                LEFT JOIN `'._DB_PREFIX_.'country_lang` cl ON cl.id_country = co.id_country AND cl.id_lang = '.(int) $countryLangId;

        $rows = $this->db->executeS($sql);
        if (!$rows) {
            return;
        }

        $trafficOrders = array();
        $countryOrders = array();
        foreach ($rows as $row) {
            $idConnection = (int) $row['id_connections'];
            if (!isset($sourceByConnection[$idConnection])) {
                continue;
            }
            $source = $sourceByConnection[$idConnection];
            $idShop = (int) $row['id_shop'];
            if ($idShop <= 0) {
                $idShop = (int) $source['id_shop'];
            }
            $countryIso = Tools::substr((string) $row['country_iso'], 0, 8);
            $countryName = Tools::substr((string) $row['country_name'], 0, 128);

            $inserted = $this->db->insert('jooim_dbcleaner_order_attribution', array(
                'id_order' => (int) $row['id_order'],
                'id_shop' => $idShop,
                'date_add' => pSQL($row['order_date_add']),
                'country_iso' => pSQL($countryIso),
                'country_name' => pSQL($countryName),
                'source_type' => pSQL($source['source_type']),
                'source_domain' => pSQL($source['source_domain']),
            ), false, true, Db::INSERT_IGNORE);

            if (!$inserted || $this->db->Affected_Rows() <= 0) {
                continue;
            }

            $trafficKey = implode('|', array($idShop, $row['date_day'], $source['source_type'], $source['source_domain'], $source['utm_source'], $source['utm_medium'], $source['utm_campaign']));
            if (!isset($trafficOrders[$trafficKey])) {
                $trafficOrders[$trafficKey] = array(
                    'id_shop' => $idShop,
                    'date_day' => $row['date_day'],
                    'source_type' => $source['source_type'],
                    'source_domain' => $source['source_domain'],
                    'utm_source' => $source['utm_source'],
                    'utm_medium' => $source['utm_medium'],
                    'utm_campaign' => $source['utm_campaign'],
                    'orders' => 0,
                );
            }
            $trafficOrders[$trafficKey]['orders']++;

            $countryKey = implode('|', array($idShop, $row['date_day'], $countryIso, $source['source_type'], $source['source_domain']));
            if (!isset($countryOrders[$countryKey])) {
                $countryOrders[$countryKey] = array(
                    'id_shop' => $idShop,
                    'date_day' => $row['date_day'],
                    'country_iso' => $countryIso,
                    'country_name' => $countryName,
                    'source_type' => $source['source_type'],
                    'source_domain' => $source['source_domain'],
                    'orders' => 0,
                );
            }
            $countryOrders[$countryKey]['orders']++;
        }

        foreach ($trafficOrders as $data) {
            $this->upsertTrafficOrders($data);
        }
        foreach ($countryOrders as $data) {
            $this->upsertOrderSourceDaily($data);
        }
    }

    protected function upsertTrafficDaily(array $data)
    {
        $sql = 'INSERT INTO `'._DB_PREFIX_.'jooim_dbcleaner_traffic_daily`
                (`id_shop`, `date_day`, `source_type`, `source_domain`, `utm_source`, `utm_medium`, `utm_campaign`, `visits`, `pageviews`, `orders`)
                VALUES (
                    '.(int) $data['id_shop'].',
                    "'.pSQL($data['date_day']).'",
                    "'.pSQL($data['source_type']).'",
                    "'.pSQL($data['source_domain']).'",
                    "'.pSQL($data['utm_source']).'",
                    "'.pSQL($data['utm_medium']).'",
                    "'.pSQL($data['utm_campaign']).'",
                    '.(int) $data['visits'].',
                    '.(int) $data['pageviews'].',
                    0
                )
                ON DUPLICATE KEY UPDATE
                    visits = visits + VALUES(visits),
                    pageviews = pageviews + VALUES(pageviews)';
        $this->db->execute($sql);
    }

    protected function upsertTrafficOrders(array $data)
    {
        $sql = 'INSERT INTO `'._DB_PREFIX_.'jooim_dbcleaner_traffic_daily`
                (`id_shop`, `date_day`, `source_type`, `source_domain`, `utm_source`, `utm_medium`, `utm_campaign`, `visits`, `pageviews`, `orders`)
                VALUES (
                    '.(int) $data['id_shop'].',
                    "'.pSQL($data['date_day']).'",
                    "'.pSQL($data['source_type']).'",
                    "'.pSQL($data['source_domain']).'",
                    "'.pSQL($data['utm_source']).'",
                    "'.pSQL($data['utm_medium']).'",
                    "'.pSQL($data['utm_campaign']).'",
                    0,
                    0,
                    '.(int) $data['orders'].'
                )
                ON DUPLICATE KEY UPDATE
                    orders = orders + VALUES(orders)';
        $this->db->execute($sql);
    }

    protected function upsertOrderSourceDaily(array $data)
    {
        $sql = 'INSERT INTO `'._DB_PREFIX_.'jooim_dbcleaner_order_source_daily`
                (`id_shop`, `date_day`, `country_iso`, `country_name`, `source_type`, `source_domain`, `orders`)
                VALUES (
                    '.(int) $data['id_shop'].',
                    "'.pSQL($data['date_day']).'",
                    "'.pSQL($data['country_iso']).'",
                    "'.pSQL($data['country_name']).'",
                    "'.pSQL($data['source_type']).'",
                    "'.pSQL($data['source_domain']).'",
                    '.(int) $data['orders'].'
                )
                ON DUPLICATE KEY UPDATE
                    orders = orders + VALUES(orders),
                    country_name = VALUES(country_name)';
        $this->db->execute($sql);
    }

    protected function purgeOldAggregates()
    {
        $days = max(30, (int) Configuration::get('JOOIM_DBCLEANER_STATS_RETENTION_DAYS'));
        $this->db->execute('DELETE FROM `'._DB_PREFIX_.'jooim_dbcleaner_traffic_daily` WHERE date_day < DATE_SUB(CURDATE(), INTERVAL '.(int) $days.' DAY)');
        $this->db->execute('DELETE FROM `'._DB_PREFIX_.'jooim_dbcleaner_log` WHERE date_add < DATE_SUB(NOW(), INTERVAL 365 DAY)');
        $this->db->execute('DELETE FROM `'._DB_PREFIX_.'jooim_dbcleaner_order_source_daily` WHERE date_day < DATE_SUB(CURDATE(), INTERVAL '.(int) $days.' DAY)');
        $this->db->execute('DELETE FROM `'._DB_PREFIX_.'jooim_dbcleaner_order_attribution` WHERE date_add < DATE_SUB(NOW(), INTERVAL '.(int) $days.' DAY)');
    }

    protected function insertLog($runType, array $result)
    {
        $this->db->insert('jooim_dbcleaner_log', array(
            'id_shop' => (int) (Context::getContext()->shop ? Context::getContext()->shop->id : 0),
            'date_add' => date('Y-m-d H:i:s'),
            'run_type' => pSQL($runType),
            'status' => pSQL($result['status']),
            'deleted_connections' => (int) $result['deleted_connections'],
            'deleted_connections_source' => (int) $result['deleted_connections_source'],
            'deleted_connections_page' => (int) $result['deleted_connections_page'],
            'layered_filter_cleared' => (int) $result['layered_filter_cleared'],
            'database_size_mb_before' => $result['database_size_mb_before'] === null ? null : (float) $result['database_size_mb_before'],
            'database_size_mb_after' => $result['database_size_mb_after'] === null ? null : (float) $result['database_size_mb_after'],
            'runtime_seconds' => (float) $result['runtime_seconds'],
            'message' => pSQL($result['message'], true),
        ));
    }
}
