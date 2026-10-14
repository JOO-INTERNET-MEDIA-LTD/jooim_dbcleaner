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
    protected $startedAt = 0.0;
    protected $maxRuntimeSeconds = 120;
    protected $mysqlLockName = '';
    protected $mysqlLockAcquired = false;

    public function __construct(Module $module)
    {
        $this->db = Db::getInstance();
        $this->module = $module;
        $this->mysqlLockName = 'jooim_dbcleaner_'.sha1(_DB_NAME_._DB_PREFIX_);
    }

    public function run($runType = 'cron')
    {
        $this->startedAt = microtime(true);
        $this->maxRuntimeSeconds = max(15, (int) Configuration::get('JOOIM_DBCLEANER_MAX_RUNTIME'));

        $result = array(
            'status' => 'success',
            'message' => '',
            'deleted_guests' => 0,
            'deleted_connections' => 0,
            'deleted_connections_source' => 0,
            'deleted_connections_page' => 0,
            'deleted_orphan_connections_source' => 0,
            'deleted_orphan_connections_page' => 0,
            'layered_filter_cleared' => 0,
            'database_size_mb_before' => JooimDbcleanerTools::getDatabaseSizeMb(),
            'database_size_mb_after' => null,
            'runtime_seconds' => 0,
        );

        if (!Configuration::get('JOOIM_DBCLEANER_ENABLE')) {
            $result['status'] = 'skipped';
            $result['message'] = 'Cleanup is disabled.';
            $this->finishLog($runType, $result);
            return $result;
        }

        try {
            if (!$this->acquireLock()) {
                $result['status'] = 'skipped';
                $result['message'] = 'Another cleanup process is already running.';
                $this->finishLog($runType, $result);
                return $result;
            }
            if (method_exists($this->module, 'ensureDatabaseSchema') && !$this->module->ensureDatabaseSchema()) {
                throw new RuntimeException('Unable to update the module database schema.');
            }
            $this->maxRuntimeSeconds = max(15, (int) Configuration::get('JOOIM_DBCLEANER_MAX_RUNTIME'));
            $connectionsRetention = max(1, (int) Configuration::get('JOOIM_DBCLEANER_RETENTION_DAYS'));
            $sourceRetention = max(1, (int) Configuration::get('JOOIM_DBCLEANER_SOURCE_RETENTION_DAYS'));
            $batchSize = max(100, min(100000, (int) Configuration::get('JOOIM_DBCLEANER_BATCH_SIZE')));
            $maxBatches = max(1, min(1000, (int) Configuration::get('JOOIM_DBCLEANER_MAX_BATCHES')));

            // Clean connections_source by its own date_add. This is intentionally
            // independent of ps_connections and remains effective even when source
            // rows are orphaned or statsdata produced far more source rows than visits.
            $result['deleted_connections_source'] += $this->deleteOldConnectionSources(
                $sourceRetention,
                $batchSize,
                $maxBatches
            );

            if (!$this->runtimeExceeded()) {
                $deleteResult = $this->deleteOldConnectionStats(
                    $connectionsRetention,
                    $batchSize,
                    $maxBatches
                );
                $result['deleted_guests'] += $deleteResult['deleted_guests'];
                $result['deleted_connections'] += $deleteResult['deleted_connections'];
                $result['deleted_connections_source'] += $deleteResult['deleted_connections_source'];
                $result['deleted_connections_page'] += $deleteResult['deleted_connections_page'];
            }

            if (Configuration::get('JOOIM_DBCLEANER_CLEAN_ORPHANS') && !$this->runtimeExceeded()) {
                $result['deleted_orphan_connections_source'] = $this->deleteOrphanConnectionSources(
                    $batchSize,
                    $maxBatches
                );
                $result['deleted_connections_source'] += $result['deleted_orphan_connections_source'];
            }

            if (Configuration::get('JOOIM_DBCLEANER_CLEAN_ORPHANS') && !$this->runtimeExceeded()) {
                $result['deleted_orphan_connections_page'] = $this->deleteOrphanConnectionPages(
                    $batchSize,
                    $maxBatches
                );
                $result['deleted_connections_page'] += $result['deleted_orphan_connections_page'];
            }

            if (Configuration::get('JOOIM_DBCLEANER_CLEAR_LAYERED_FILTER')
                && JooimDbcleanerTools::tableExists('layered_filter_block')
                && !$this->runtimeExceeded()
            ) {
                if (!$this->db->execute('TRUNCATE TABLE `'._DB_PREFIX_.'layered_filter_block`')) {
                    throw new RuntimeException('Unable to truncate layered_filter_block: '.$this->db->getMsgError());
                }
                $result['layered_filter_cleared'] = 1;
            }

            $this->purgeOldModuleData();

            if ($this->runtimeExceeded()) {
                $result['status'] = 'partial';
                $result['message'] = 'The configured maximum runtime was reached. Remaining rows will be processed by the next cron run.';
            } elseif ($result['message'] === '') {
                $result['message'] = 'Cleanup completed.';
            }
        } catch (Throwable $e) {
            $result['status'] = 'error';
            $result['message'] = 'Cleanup failed. Check database permissions and the module schema.';
        } finally {
            try {
                $this->releaseLock();
            } catch (Throwable $e) {
                $result['status'] = 'error';
                $result['message'] = 'Unable to release the cleanup lock. It will be released when the database connection closes.';
            }
        }

        $this->finishLog($runType, $result);
        return $result;
    }

    protected function finishLog($runType, array &$result)
    {
        $result['database_size_mb_after'] = JooimDbcleanerTools::getDatabaseSizeMb();
        $result['runtime_seconds'] = round(microtime(true) - $this->startedAt, 3);
        try {
            $this->insertLog($runType, $result);
        } catch (Throwable $e) {
            $result['status'] = 'error';
            $result['message'] = 'Unable to save the cleanup log. Check the module database schema.';
        }
        Configuration::updateValue('JOOIM_DBCLEANER_LAST_RUN', date('Y-m-d H:i:s'));
        Configuration::updateValue('JOOIM_DBCLEANER_LAST_STATUS', $result['status']);
        Configuration::updateValue('JOOIM_DBCLEANER_LAST_MESSAGE', Tools::substr($result['message'], 0, 255));
    }

    protected function runtimeExceeded()
    {
        return (microtime(true) - $this->startedAt) >= $this->maxRuntimeSeconds;
    }

    protected function acquireLock()
    {
        // Fail closed when named locks are unavailable; a configuration flag is not atomic.
        $rows = $this->db->executeS('SELECT GET_LOCK("'.pSQL($this->mysqlLockName).'", 0) AS acquired_lock', true, false);
        if (!is_array($rows) || !isset($rows[0]['acquired_lock'])) {
            throw new RuntimeException('An atomic MySQL cleanup lock is unavailable. Cleanup was not started.');
        }
        $this->mysqlLockAcquired = (int) $rows[0]['acquired_lock'] === 1;
        return $this->mysqlLockAcquired;
    }

    protected function releaseLock()
    {
        if ($this->mysqlLockAcquired) {
            $rows = $this->db->executeS('SELECT RELEASE_LOCK("'.pSQL($this->mysqlLockName).'") AS released_lock', true, false);
            $this->mysqlLockAcquired = false;
            if (!isset($rows[0]['released_lock']) || (int) $rows[0]['released_lock'] !== 1) {
                throw new RuntimeException('Unable to release the cleanup lock.');
            }
        }
    }

    protected function deleteOldConnectionSources($retentionDays, $batchSize, $maxBatches)
    {
        if (!JooimDbcleanerTools::tableExists('connections_source')
            || !JooimDbcleanerTools::columnExists('connections_source', 'date_add')
        ) {
            return 0;
        }

        $deleted = 0;
        for ($batch = 0; $batch < $maxBatches && !$this->runtimeExceeded(); $batch++) {
            // The core table has an index on date_add. A bounded DELETE ordered by
            // that column avoids scanning or materialising a huge ID list.
            $sql = 'DELETE FROM `'._DB_PREFIX_.'connections_source`
                    WHERE `date_add` < DATE_SUB(NOW(), INTERVAL '.(int) $retentionDays.' DAY)
                    ORDER BY `date_add` ASC
                    LIMIT '.(int) $batchSize;
            if (!$this->db->execute($sql)) {
                throw new RuntimeException('Delete failed for '._DB_PREFIX_.'connections_source: '.$this->db->getMsgError());
            }

            $affected = (int) $this->db->Affected_Rows();
            $deleted += $affected;
            if ($affected < $batchSize) {
                break;
            }
        }

        return $deleted;
    }

    protected function getOldConnectionIds($retentionDays, $batchSize)
    {
        if (!JooimDbcleanerTools::tableExists('connections')) {
            return array();
        }

        $rows = $this->db->executeS(
            'SELECT `id_connections`
             FROM `'._DB_PREFIX_.'connections`
             WHERE `date_add` < DATE_SUB(NOW(), INTERVAL '.(int) $retentionDays.' DAY)
             ORDER BY `date_add` ASC, `id_connections` ASC
             LIMIT '.(int) $batchSize, true, false
        );

        return $this->extractIds($rows, 'id_connections');
    }

    protected function deleteOldConnectionStats($retentionDays, $batchSize, $maxBatches)
    {
        $totals = array(
            'deleted_guests' => 0,
            'deleted_connections' => 0,
            'deleted_connections_source' => 0,
            'deleted_connections_page' => 0,
        );

        for ($batch = 0; $batch < $maxBatches && !$this->runtimeExceeded(); $batch++) {
            $ids = $this->getOldConnectionIds($retentionDays, $batchSize);
            if (!$ids) {
                break;
            }

            $guestRows = $this->db->executeS('SELECT DISTINCT `id_guest` FROM `'._DB_PREFIX_.'connections`
                WHERE `id_connections` IN ('.implode(',', array_map('intval', $ids)).')', true, false);
            $guestIds = $this->extractIds($guestRows, 'id_guest');

            if (JooimDbcleanerTools::tableExists('connections_page')) {
                $totals['deleted_connections_page'] += $this->deleteChildren('connections_page', $ids, $batchSize);
            }

            if (JooimDbcleanerTools::tableExists('connections_source')) {
                $totals['deleted_connections_source'] += $this->deleteChildren('connections_source', $ids, $batchSize);
            }

            if ($this->runtimeExceeded()) {
                break;
            }
            $this->executeDeleteByIds('connections', 'id_connections', $ids);
            $totals['deleted_connections'] += $this->db->Affected_Rows();
            if (!$this->runtimeExceeded()) {
                $totals['deleted_guests'] += $this->deleteOldGuests($guestIds, $batchSize);
            }
        }

        return $totals;
    }

    protected function deleteOrphanConnectionSources($batchSize, $maxBatches)
    {
        if (!JooimDbcleanerTools::tableExists('connections_source')
            || !JooimDbcleanerTools::tableExists('connections')
        ) {
            return 0;
        }

        $deleted = 0;
        for ($batch = 0; $batch < $maxBatches && !$this->runtimeExceeded(); $batch++) {
            $rows = $this->db->executeS(
                'SELECT cs.`id_connections_source`
                 FROM `'._DB_PREFIX_.'connections_source` cs
                 LEFT JOIN `'._DB_PREFIX_.'connections` c
                   ON c.`id_connections` = cs.`id_connections`
                 WHERE c.`id_connections` IS NULL
                 ORDER BY cs.`id_connections_source` ASC
                 LIMIT '.(int) $batchSize, true, false
            );
            $ids = $this->extractIds($rows, 'id_connections_source');
            if (!$ids) {
                break;
            }

            $this->executeDeleteByIds('connections_source', 'id_connections_source', $ids);
            $deleted += $this->db->Affected_Rows();
        }

        return $deleted;
    }

    protected function deleteOrphanConnectionPages($batchSize, $maxBatches)
    {
        if (!JooimDbcleanerTools::tableExists('connections_page') || !JooimDbcleanerTools::tableExists('connections')) {
            return 0;
        }
        $deleted = 0;
        for ($batch = 0; $batch < $maxBatches && !$this->runtimeExceeded(); $batch++) {
            $sql = 'DELETE FROM `'._DB_PREFIX_.'connections_page`
                WHERE NOT EXISTS (SELECT 1 FROM `'._DB_PREFIX_.'connections` c
                    WHERE c.`id_connections` = `'._DB_PREFIX_.'connections_page`.`id_connections`)
                LIMIT '.(int) $batchSize;
            if (!$this->db->execute($sql)) {
                throw new RuntimeException('Unable to delete orphan connection pages.');
            }
            $affected = (int) $this->db->Affected_Rows();
            $deleted += $affected;
            if ($affected < $batchSize) {
                break;
            }
        }
        return $deleted;
    }

    protected function deleteOldGuests(array $ids, $batchSize)
    {
        // guest has no date_add: only IDs from expired connections are eligible.
        // Preserve every customer, cart and remaining connection, across all shops.
        if (!$ids || !JooimDbcleanerTools::tableExists('guest')
            || !JooimDbcleanerTools::tableExists('cart')) {
            return 0;
        }
        $sql = 'DELETE FROM `'._DB_PREFIX_.'guest`
            WHERE `id_guest` IN ('.implode(',', array_map('intval', $ids)).')
              AND COALESCE(`id_customer`, 0) = 0
              AND NOT EXISTS (SELECT 1 FROM `'._DB_PREFIX_.'cart` cart
                  WHERE cart.`id_guest` = `'._DB_PREFIX_.'guest`.`id_guest`)
              AND NOT EXISTS (SELECT 1 FROM `'._DB_PREFIX_.'connections` c
                  WHERE c.`id_guest` = `'._DB_PREFIX_.'guest`.`id_guest`)
            LIMIT '.(int) $batchSize;
        if (!$this->db->execute($sql)) {
            throw new RuntimeException('Unable to delete expired guests.');
        }
        return (int) $this->db->Affected_Rows();
    }

    protected function extractIds($rows, $column)
    {
        if (!is_array($rows)) {
            throw new RuntimeException('Unable to select cleanup records.');
        }
        $ids = array();
        foreach ($rows as $row) {
            if (isset($row[$column])) {
                $id = (int) $row[$column];
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    protected function deleteChildren($table, array $ids, $batchSize)
    {
        $deleted = 0;
        while (!$this->runtimeExceeded()) {
            $sql = 'DELETE FROM `'._DB_PREFIX_.pSQL($table).'`
                WHERE `id_connections` IN ('.implode(',', array_map('intval', $ids)).')
                LIMIT '.(int) $batchSize;
            if (!$this->db->execute($sql)) {
                throw new RuntimeException('Unable to delete related statistics.');
            }
            $affected = (int) $this->db->Affected_Rows();
            $deleted += $affected;
            if ($affected < $batchSize) {
                break;
            }
        }
        return $deleted;
    }

    protected function executeDeleteByIds($table, $column, array $ids)
    {
        if (!$ids) {
            return true;
        }

        $idsSql = implode(',', array_map('intval', $ids));
        $sql = 'DELETE FROM `'._DB_PREFIX_.pSQL($table).'`
                WHERE `'.pSQL($column).'` IN ('.$idsSql.')';
        if (!$this->db->execute($sql)) {
            throw new RuntimeException('Delete failed for '._DB_PREFIX_.$table.': '.$this->db->getMsgError());
        }
        return true;
    }

    protected function purgeOldModuleData()
    {
        $days = max(30, (int) Configuration::get('JOOIM_DBCLEANER_STATS_RETENTION_DAYS'));
        $batchSize = max(100, min(100000, (int) Configuration::get('JOOIM_DBCLEANER_BATCH_SIZE')));
        $tables = array(
            'jooim_dbcleaner_log' => '`date_add` < DATE_SUB(NOW(), INTERVAL 365 DAY)',
            'jooim_dbcleaner_traffic_daily' => '`date_day` < DATE_SUB(CURDATE(), INTERVAL '.$days.' DAY)',
            'jooim_dbcleaner_order_source_daily' => '`date_day` < DATE_SUB(CURDATE(), INTERVAL '.$days.' DAY)',
            'jooim_dbcleaner_order_attribution' => '`date_add` < DATE_SUB(NOW(), INTERVAL '.$days.' DAY)',
        );
        foreach ($tables as $table => $where) {
            if ($this->runtimeExceeded()) {
                break;
            }
            if (JooimDbcleanerTools::tableExists($table)
                && !$this->db->execute('DELETE FROM `'._DB_PREFIX_.$table.'` WHERE '.$where.' LIMIT '.$batchSize)) {
                throw new RuntimeException('Unable to purge old module data.');
            }
        }
    }

    protected function insertLog($runType, array $result)
    {
        if (!JooimDbcleanerTools::tableExists('jooim_dbcleaner_log')) {
            return;
        }

        if (!$this->db->insert('jooim_dbcleaner_log', array(
            'id_shop' => (int) (Context::getContext()->shop ? Context::getContext()->shop->id : 0),
            'date_add' => date('Y-m-d H:i:s'),
            'run_type' => pSQL($runType),
            'status' => pSQL($result['status']),
            'deleted_guests' => (int) $result['deleted_guests'],
            'deleted_connections' => (int) $result['deleted_connections'],
            'deleted_connections_source' => (int) $result['deleted_connections_source'],
            'deleted_connections_page' => (int) $result['deleted_connections_page'],
            'deleted_orphan_connections_source' => (int) $result['deleted_orphan_connections_source'],
            'deleted_orphan_connections_page' => (int) $result['deleted_orphan_connections_page'],
            'layered_filter_cleared' => (int) $result['layered_filter_cleared'],
            'database_size_mb_before' => $result['database_size_mb_before'] === null ? null : (float) $result['database_size_mb_before'],
            'database_size_mb_after' => $result['database_size_mb_after'] === null ? null : (float) $result['database_size_mb_after'],
            'runtime_seconds' => (float) $result['runtime_seconds'],
            'message' => pSQL($result['message'], true),
        ))) {
            throw new RuntimeException('Unable to save the cleanup log.');
        }
    }
}
