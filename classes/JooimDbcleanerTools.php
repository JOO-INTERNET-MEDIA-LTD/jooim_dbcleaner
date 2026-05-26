<?php
/**
 * JooIM DB Cleaner helper tools.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class JooimDbcleanerTools
{
    public static function tableExists($tableName)
    {
        // Do not use SHOW TABLES or Db::getValue() here. Some PrestaShop/MariaDB
        // combinations append LIMIT 1 to SHOW queries and produce invalid SQL.
        $sql = 'SELECT COUNT(*) AS found_table
                FROM information_schema.tables
                WHERE table_schema = "'.pSQL(_DB_NAME_).'"
                  AND table_name = "'.pSQL(_DB_PREFIX_.$tableName).'"';

        $rows = Db::getInstance()->executeS($sql);
        return isset($rows[0]['found_table']) && (int) $rows[0]['found_table'] > 0;
    }

    public static function columnExists($tableName, $columnName)
    {
        // Same reason as tableExists(): use information_schema and executeS(),
        // never SHOW COLUMNS through getValue().
        $sql = 'SELECT COUNT(*) AS found_column
                FROM information_schema.columns
                WHERE table_schema = "'.pSQL(_DB_NAME_).'"
                  AND table_name = "'.pSQL(_DB_PREFIX_.$tableName).'"
                  AND column_name = "'.pSQL($columnName).'"';

        $rows = Db::getInstance()->executeS($sql);
        return isset($rows[0]['found_column']) && (int) $rows[0]['found_column'] > 0;
    }

    public static function getDatabaseSizeMb()
    {
        // Keep this off Db::getValue() as well, so the cron path has no automatic
        // LIMIT injection on information_schema queries.
        $sql = 'SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS database_size_mb
                FROM information_schema.tables
                WHERE table_schema = "'.pSQL(_DB_NAME_).'"';
        $rows = Db::getInstance()->executeS($sql);
        if (!isset($rows[0]['database_size_mb']) || $rows[0]['database_size_mb'] === null) {
            return null;
        }
        return (float) $rows[0]['database_size_mb'];
    }

    public static function getLargestTables($limit = 20)
    {
        $limit = max(1, min(100, (int) $limit));
        $sql = 'SELECT table_name,
                       ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb,
                       ROUND(data_length / 1024 / 1024, 2) AS data_mb,
                       ROUND(index_length / 1024 / 1024, 2) AS index_mb,
                       table_rows
                FROM information_schema.tables
                WHERE table_schema = "'.pSQL(_DB_NAME_).'"
                ORDER BY data_length + index_length DESC
                LIMIT '.(int) $limit;
        return Db::getInstance()->executeS($sql);
    }

    public static function normalizeDomain($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        if (strpos($url, '://') === false) {
            $url = 'http://'.$url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return '';
        }

        $host = Tools::strtolower($host);
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        return Tools::substr($host, 0, 190);
    }

    public static function detectSourceType($domain, $requestUri, $referrer)
    {
        $domain = Tools::strtolower((string) $domain);
        $requestUri = (string) $requestUri;
        $referrer = (string) $referrer;
        $combined = Tools::strtolower($requestUri.' '.$referrer);

        if (preg_match('/(^|[?&])(gclid|gbraid|wbraid)=/i', $requestUri)) {
            return 'paid_search';
        }
        if (preg_match('/(^|[?&])msclkid=/i', $requestUri)) {
            return 'paid_search';
        }
        if (preg_match('/(^|[?&])fbclid=/i', $requestUri)) {
            return 'paid_social';
        }
        if (strpos($combined, 'utm_medium=cpc') !== false || strpos($combined, 'utm_medium=ppc') !== false || strpos($combined, 'utm_medium=paidsearch') !== false || strpos($combined, 'utm_medium=paid_search') !== false) {
            return 'paid_search';
        }
        if (strpos($combined, 'utm_medium=paid_social') !== false || strpos($combined, 'utm_medium=social_paid') !== false) {
            return 'paid_social';
        }
        if ($domain === '') {
            return 'direct';
        }

        $searchDomains = array('google.', 'bing.', 'yahoo.', 'duckduckgo.', 'seznam.', 'yandex.');
        foreach ($searchDomains as $needle) {
            if (strpos($domain, $needle) !== false) {
                return 'organic_search';
            }
        }

        $socialDomains = array('facebook.com', 'instagram.com', 't.co', 'twitter.com', 'x.com', 'linkedin.com', 'pinterest.', 'youtube.com', 'tiktok.com');
        foreach ($socialDomains as $needle) {
            if (strpos($domain, $needle) !== false) {
                return 'organic_social';
            }
        }

        return 'referral';
    }

    public static function extractUtm($requestUri, $key)
    {
        $value = '';
        $parts = parse_url((string) $requestUri);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            if (isset($query[$key]) && !is_array($query[$key])) {
                $value = trim((string) $query[$key]);
            }
        }
        return Tools::substr($value, 0, 190);
    }
}
