<?php

namespace Hassam\DataAccessWrapper;

class CacheLogger extends MongoAbstractDataCollection
{
    /**
     *  constructor.
     */
    public function __construct()
    {
        $this->_context = 'cache';
        parent::__construct($this->_context);
    }

    public static function manage($dataKey, $dataValue)
    {

        $data = self::getCacheByDataKey($dataKey);
        if ($data) {
            $documentId = data_fetch($data, '_id');
            self::updateResponse($dataKey, $dataValue, $documentId);
        } else {
            self::logResponse($dataKey, $dataValue);
        }

        return self::getCacheByDataKey($dataKey);
    }

    public static function getCacheByDataKey($dataKey, $limit = null)
    {
        $filters = [];
        if (!$limit) {
            $limit = config('common.LIMIT_MONGO_LOGGING_FETCH');
        }
        if ($dataKey) {
            $filters['data_key'] = $dataKey;
        }
        $mongoEntries = (new self())->getByQuery($filters);

        return $mongoEntries ? reset($mongoEntries) : null;
    }

    public static function updateResponse($dataKey, $dataValue, $documentId)
    {
        $data = [];
        $data['data_value'] = $dataValue;
        $data['updatedDate'] = time();
        try {
            (new self())->updateBYId($documentId, $data);

            return $data;
        } catch (\Exception $ex) {
        }
    }

    public static function logResponse($dataKey, $dataValue)
    {
        $data = [
            'data_key' => $dataKey,
            'data_value' => $dataValue,
            'createdDate' => time(),
            'updatedDate' => time(),
        ];

        try {
            (new self())->create($data);

            return $data;
        } catch (\Exception $ex) {
        }
    }

    public static function getDataValueByDataKey($dataKey, $default = null)
    {
        $entry = self::getCacheByDataKey($dataKey);

        return data_fetch($entry, 'data_value', $default);
    }

    public static function getLastUpdatedAt($dataKey, $format = 'Y-m-d H:i:s')
    {
        $cacheData = self::getCacheByDataKey($dataKey);

        if ($cacheData && isset($cacheData['updatedDate'])) {
            return date($format, $cacheData['updatedDate']);
        }

        return "";
    }

    public static function getTransactionsTotalAmount($dataKey)
    {
        $cacheData = self::getCacheByDataKey($dataKey);
        if ($cacheData && isset($cacheData['data_value']['total_amount'])) {
            return $cacheData['data_value']['total_amount'];
        }
        return 0;
    }

}
