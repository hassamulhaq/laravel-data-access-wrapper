<?php

namespace Hassam\DataAccessWrapper;

class GeneralCache extends BaseCache
{
    public static function make(): self
    {
        return new self('-general_cache-', 'v1');
    }

    public static function setData($key, $value, $seconds = 600)
    {
        return self::make()->putWithoutPrefix($key, $value, $seconds);
    }

    public static function getData($key)
    {
        return self::make()->getWithoutPrefix($key);
    }

    public static function getApiToken($keyPostfix)
    {
        return self::make()->getWithoutPrefix('user-api-token-'.$keyPostfix);
    }

    public static function putApiToken($keyPostfix, $data, $ttl = 600): void
    {
        self::make()->putWithoutPrefix('user-api-token-'.$keyPostfix, $data, $ttl);
    }

    public static function getOpenStackSwiftAuthTokenCacheKey(): ?string
    {
        $tenantId = config('common.swift.tenant_id');
        return self::make()->getWithoutPrefix($tenantId).'-open-stack-swift-auth-token';
    }

    public static function getOpenStackSwiftAuthToken()
    {
        return self::make()->getWithoutPrefix(self::getOpenStackSwiftAuthTokenCacheKey());
    }

    public static function setOpenStackSwiftAuthToken($authToken, $seconds = 36000): void
    {
        // 10 hrs
        self::make()->putWithoutPrefix(self::getOpenStackSwiftAuthTokenCacheKey(), $authToken, $seconds);
    }

    public static function forgetApiToken($keyPostfix): bool
    {
        return self::make()->forgetWithoutPrefix('user-api-token-'.$keyPostfix);
    }

    public static function putMetaData($data, $prefix): void
    {
        self::make()->putWithoutPrefix("{$prefix}-metadata-app", $data, 86400); //2 Days
    }

    public static function getMetaData($prefix)
    {
        return self::make()->getWithoutPrefix("{$prefix}-metadata-app");
    }

    public static function getStaticSiteData()
    {
        return self::make()->get('1static-site-dashboard');
    }
}
