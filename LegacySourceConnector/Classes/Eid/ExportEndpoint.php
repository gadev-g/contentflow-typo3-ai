<?php

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Utility\EidUtility;

EidUtility::initTCA();

header('Content-Type: application/json; charset=utf-8');

try {
    if ('POST' !== strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '')) {
        http_response_code(405);
        throw new RuntimeException('Only POST requests are accepted.');
    }

    $configuration = unserialize(
        isset($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['contentflow_legacy_source'])
            ? $GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['contentflow_legacy_source']
            : ''
    );
    $expectedHash = is_array($configuration) && isset($configuration['migrationTokenHash'])
        ? trim($configuration['migrationTokenHash'])
        : '';
    $authorization = isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    $token = preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) ? trim($matches[1]) : '';

    if (
        '' === $expectedHash
        || '' === $token
        || !hash_equals($expectedHash, hash('sha256', $token))
    ) {
        http_response_code(401);
        throw new RuntimeException('Invalid migration token.');
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    $sourceUrl = is_array($payload) && isset($payload['source_url']) ? trim($payload['source_url']) : '';

    if (false === filter_var($sourceUrl, FILTER_VALIDATE_URL)) {
        http_response_code(422);
        throw new RuntimeException('source_url is required.');
    }

    $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
    $pagesConnection = $connectionPool->getConnectionForTable('pages');
    $pageUid = resolvePageUid($pagesConnection, $sourceUrl);

    if ($pageUid <= 0) {
        http_response_code(404);
        throw new RuntimeException('The source URL could not be resolved to a visible TYPO3 page.');
    }

    $page = $pagesConnection->fetchAssoc(
        'SELECT uid, title, subtitle, nav_title FROM pages WHERE uid = ? AND deleted = 0 AND hidden = 0',
        array($pageUid)
    );

    if (!is_array($page)) {
        http_response_code(404);
        throw new RuntimeException('The TYPO3 page is not readable.');
    }

    $contentConnection = $connectionPool->getConnectionForTable('tt_content');
    $records = $contentConnection->fetchAll(
        'SELECT * FROM tt_content WHERE pid = ? AND deleted = 0 AND hidden = 0'
        . ' AND sys_language_uid IN (0, -1) ORDER BY colPos, sorting',
        array($pageUid)
    );
    $elements = array();

    foreach ($records as $record) {
        $elements[] = exportRecord('tt_content', $record, $connectionPool, 0);
    }

    echo json_encode(array(
        'schema_version' => '1.0',
        'source' => array(
            'url' => $sourceUrl,
            'page_uid' => (int) $page['uid'],
            'title' => (string) $page['title'],
            'mode' => 'typo3-8-connector',
        ),
        'page' => $page,
        'elements' => $elements,
        'media' => array(),
    ));
} catch (Exception $exception) {
    if (http_response_code() < 400) {
        http_response_code(422);
    }

    echo json_encode(array(
        'error' => array(
            'code' => 'legacy_export_failed',
            'message' => $exception->getMessage(),
        ),
    ));
}

function resolvePageUid($connection, $sourceUrl)
{
    $query = parse_url($sourceUrl, PHP_URL_QUERY);
    parse_str($query ? $query : '', $parameters);

    if (!empty($parameters['id']) && ctype_digit((string) $parameters['id'])) {
        return (int) $parameters['id'];
    }

    $path = trim(rawurldecode((string) parse_url($sourceUrl, PHP_URL_PATH)), '/');
    if ('' !== $path) {
        $schemaManager = $connection->getSchemaManager();
        $tableNames = $schemaManager->listTableNames();
        foreach (array('tx_realurl_pathdata', 'tx_realurl_pathcache') as $pathTable) {
            if (!in_array($pathTable, $tableNames, true)) {
                continue;
            }

            $pathCacheColumns = $schemaManager->listTableColumns($pathTable);
            $pathConditions = array('pagepath = ?');
            $pathValues = array($path);
            if (isset($pathCacheColumns['language_id'])) {
                $pathConditions[] = 'language_id IN (0, -1)';
            }

            if (isset($pathCacheColumns['expire'])) {
                $pathConditions[] = '(expire = 0 OR expire > ?)';
                $pathValues[] = time();
            }

            $pathCacheRow = $connection->fetchAssoc('SELECT page_id FROM ' . $pathTable . ' WHERE '
                . implode(' AND ', $pathConditions)
                . ' ORDER BY page_id DESC', $pathValues);
            if (is_array($pathCacheRow) && !empty($pathCacheRow['page_id'])) {
                    return (int) $pathCacheRow['page_id'];
            }
        }
    }

    $segment = basename($path);
    $columns = $connection->getSchemaManager()->listTableColumns('pages');
    $conditions = array();
    $values = array();

    foreach (array('alias', 'tx_realurl_pathsegment') as $field) {
        if (isset($columns[$field])) {
            $conditions[] = $field . ' = ?';
            $values[] = $segment;
        }
    }

    if (empty($conditions)) {
        return resolvePageUidFromHierarchy($connection, $path, $columns);
    }
    $row = $connection->fetchAssoc('SELECT uid FROM pages WHERE deleted = 0 AND hidden = 0 AND (' . implode(' OR ', $conditions) . ')', $values);
    if (is_array($row)) {
        return (int) $row['uid'];
    }

    return resolvePageUidFromHierarchy($connection, $path, $columns);
}

function resolvePageUidFromHierarchy($connection, $path, array $columns)
{
    $segments = array_values(array_filter(explode('/', trim($path, '/'))));
    if (empty($segments)) {
        return 0;
    }

    $selectFields = array('uid', 'pid', 'title', 'nav_title');
    foreach (array('alias', 'tx_realurl_pathsegment') as $field) {
        if (isset($columns[$field])) {
            $selectFields[] = $field;
        }
    }

    $pages = $connection->fetchAll('SELECT ' . implode(', ', $selectFields) . ' FROM pages WHERE deleted = 0 AND hidden = 0');
    $pagesByUid = array();
    foreach ($pages as $page) {
        $pagesByUid[(int) $page['uid']] = $page;
    }
    foreach ($pages as $page) {
        $index = count($segments) - 1;
        $candidate = $page;
        while ($index >= 0 && is_array($candidate)) {
            if (!pageMatchesSpeakingSegment($candidate, $segments[$index])) {
                break;
            }

            --$index;
            if ($index < 0) {
                return (int) $page['uid'];
            }

            $parentUid = isset($candidate['pid']) ? (int) $candidate['pid'] : 0;
            $candidate = isset($pagesByUid[$parentUid]) ? $pagesByUid[$parentUid] : null;
        }
    }

    return 0;
}

function pageMatchesSpeakingSegment(array $page, $segment)
{
    foreach (array('tx_realurl_pathsegment', 'alias', 'nav_title', 'title') as $field) {
        if (!isset($page[$field]) || '' === trim((string) $page[$field])) {
            continue;
        }

        if (speakingUrlSegment((string) $page[$field]) === strtolower((string) $segment)) {
            return true;
        }
    }

    return false;
}

function speakingUrlSegment($value)
{
    $value = str_replace(array('Ä', 'Ö', 'Ü', 'ä', 'ö', 'ü', 'ß'), array('Ae', 'Oe', 'Ue', 'ae', 'oe', 'ue', 'ss'), trim((string) $value));
    $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (false !== $transliterated) {
        $value = $transliterated;
    }

    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    return trim($value, '-');
}
function exportRecord($table, array $record, $connectionPool, $depth)
{
    $fields = array();
    $relations = array();
    $columns = isset($GLOBALS['TCA'][$table]['columns']) ? $GLOBALS['TCA'][$table]['columns'] : array();

    foreach ($columns as $field => $definition) {
        if (!array_key_exists($field, $record)) {
            continue;
        }

        $config = isset($definition['config']) ? $definition['config'] : array();
        $type = isset($config['type']) ? $config['type'] : '';

        if (in_array($type, array('input', 'text'), true) && is_scalar($record[$field])) {
            $fields[$field] = (string) $record[$field];
        }

        if (
            $depth < 4
            && !empty($config['foreign_table'])
            && !empty($config['foreign_field'])
        ) {
            $childTable = $config['foreign_table'];
            $childConnection = $connectionPool->getConnectionForTable($childTable);
            $control = isset($GLOBALS['TCA'][$childTable]['ctrl']) ? $GLOBALS['TCA'][$childTable]['ctrl'] : array();
            $deleteField = !empty($control['delete']) ? $control['delete'] : '';
            $sortField = !empty($control['sortby']) ? $control['sortby'] : 'uid';
            $sql = 'SELECT * FROM ' . $childTable . ' WHERE ' . $config['foreign_field'] . ' = ?';

            if ('' !== $deleteField) {
                $sql .= ' AND ' . $deleteField . ' = 0';
            }

            $children = $childConnection->fetchAll(
                $sql . ' ORDER BY ' . $sortField,
                array((int) $record['uid'])
            );
            $relations[$field] = array();

            foreach ($children as $child) {
                $relations[$field][] = exportRecord($childTable, $child, $connectionPool, $depth + 1);
            }
        }
    }

    return array(
        'uid' => (int) $record['uid'],
        'table' => $table,
        'type' => isset($record['CType']) ? (string) $record['CType'] : $table,
        'fields' => $fields,
        'relations' => $relations,
        'media' => array(),
    );
}
