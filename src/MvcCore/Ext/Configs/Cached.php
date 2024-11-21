<?php

/**
 * MvcCore
 *
 * This source file is subject to the BSD 3 License
 * For the full copyright and license information, please view
 * the LICENSE.md file that are distributed with this source code.
 *
 * @copyright	Copyright (c) 2016 Tom Flidr (https://github.com/mvccore)
 * @license		https://mvccore.github.io/docs/mvccore/5.0.0/LICENSE.md
 */

namespace MvcCore\Ext\Configs;

class Cached extends \MvcCore\Config {
	
	/**
	 * MvcCore Extension - Config - Cached - version:
	 * Comparison by PHP function version_compare();
	 * @see http://php.net/manual/en/function.version-compare.php
	 */
	const VERSION = '5.3.0';

	/**
	 * Cache instance pointer.
	 * @var \MvcCore\Ext\ICache
	 */
	protected static $cache = NULL;

	/**
	 * Cache ttl used to cache config files,
	 * `NULL` means unlimited time, by default.
	 * @var int|NULL
	 */
	protected static $ttl = NULL;

	/**
	 * Cache tags used to cache config files,
	 * `['config']` as default.
	 * @var \string[]
	 */
	protected static $tags = ['config'];

	/**
	 * Array with each key as environment name
	 * and values as another environment names necessary
	 * to also keep in cached config.
	 * @var array
	 */
	protected static $environmentGroups = [];

	/**
	 * Set cache ttl used to cache config files,
	 * `NULL` means unlimited time, by default.
	 * @param int|NULL $ttl
	 * @return void
	 */
	public static function SetTtl ($ttl) {
		static::$ttl = $ttl;
	}

	/**
	 * Get cache ttl used to cache config files,
	 * `NULL` means unlimited time, by default.
	 * @return int|NULL
	 */
	public static function GetTtl () {
		return static::$ttl;
	}

	/**
	 * Set cache tags used to cache config files,
	 * `['config']` as default.
	 * @param \string[] $tags
	 * @return void
	 */
	public static function SetTags ($tags) {
		static::$tags = $tags;
	}

	/**
	 * Get cache tags used to cache config files,
	 * `['config']` as default.
	 * @return \string[]
	 */
	public static function GetTags () {
		return static::$tags;
	}

	/**
	 * Set array with each key as environment name
	 * and values as another environment names necessary
	 * to also keep in cached config.
	 * @param array $environmentGroups
	 * @return void
	 */
	public static function SetEnvironmentGroups (array $environmentGroups = []) {
		static::$environmentGroups = $environmentGroups;
	}

	/**
	 * Get array with each key as environment name
	 * and values as another environment names necessary
	 * to also keep in cached config.
	 * @return array
	 */
	public static function GetEnvironmentGroups () {
		return static::$environmentGroups;
	}

	/**
	 * Try to load from cache or from hdd and parse config file by app root relative path.
	 * If config contains system data, try to detect environment.
	 * @param string $configFullPath
	 * @param string $systemConfigClass
	 * @param int    $configType
	 * @return \MvcCore\Config|bool
	 */
	public static function LoadConfig ($configFullPath, $systemConfigClass, $configType = \MvcCore\IConfig::TYPE_COMMON) {
		/** @var $cache \MvcCore\Ext\ICache|NULL */
		$cache = static::getCache();
		if ($cache === NULL)
			return parent::LoadConfig($configFullPath, $systemConfigClass, $configType);
		$cacheKey = static::getCacheKey($configFullPath);

		/** @var $config \MvcCore\Config|NULL */
		$config = $cache->Load($cacheKey);

		if (!$config) {
			$config = parent::LoadConfig($configFullPath, $systemConfigClass, $configType);
			if ($config !== NULL) {
				$environment = static::getSysCfgDetectedEnv($config, $configType);
				static::computeEnvironmentData($config, $environment->GetName());
			}
			static::cacheConfig($cache, $cacheKey, $config);

		} else {
			$environment = static::getSysCfgDetectedEnv($config, $configType);
			if ($environment->IsDevelopment()) {
				clearstatcache(TRUE, $configFullPath);
				$lastModTime = filemtime($configFullPath);
				if ($lastModTime > $config->GetLastChanged()) {
					$config = parent::LoadConfig($configFullPath, $systemConfigClass, $configType);
					if ($config !== NULL) {
						$environment = static::getSysCfgDetectedEnv($config, $configType);
						static::computeEnvironmentData($config, $environment->GetName());
					}
					static::cacheConfig($cache, $cacheKey, $config);
				}
			}
		}

		return $config;
	}

	/**
	 * If config is system, get environment instance by environment section data,
	 * if config is not system, get environment from application instance.
	 * @param  \MvcCore\Config $config 
	 * @param  int             $configType 
	 * @return \MvcCore\Environment
	 */
	protected static function getSysCfgDetectedEnv (\MvcCore\Config $config, $configType = \MvcCore\IConfig::TYPE_COMMON) {
		$sysCfg = ($configType & \MvcCore\IConfig::TYPE_SYSTEM) != 0;
		$envCfg = ($configType & \MvcCore\IConfig::TYPE_ENVIRONMENT) != 0;
		if ($sysCfg || $envCfg) {
			$environment = static::detectEnvironment($config, FALSE);
		} else {
			$app = self::$app ?: (self::$app = \MvcCore\Application::GetInstance());
			$environment = $app->GetEnvironment();
		}
		return $environment;
	}

	/**
	 * Complete config merged data collection records for all necessary environments.
	 * @param \MvcCore\Config $config
	 * @param string|NULL $envName
	 */
	protected static function computeEnvironmentData (\MvcCore\IConfig $config, $envName) {
		if ($envName === NULL) return;
		$envNamesToCache = [$envName];
		if (isset(static::$environmentGroups[$envName]))
			$envNamesToCache = array_unique(array_merge(
				$envNamesToCache, static::$environmentGroups[$envName]
			));
		foreach ($envNamesToCache as $envNameToCache)
			$config->GetData($envNameToCache);
	}

	/**
	 * Cache completed configuration file.
	 * @param \MvcCore\Ext\Cache   $cache
	 * @param string               $cacheKey
	 * @param \MvcCore\Config|NULL $config
	 * @throws \Exception Config to cache it doesn't implement `\MvcCore\IConfig` interface.
	 * @return bool
	 */
	protected static function cacheConfig (\MvcCore\Ext\ICache $cache, $cacheKey, $config = NULL) {
		if ($config !== NULL && !($config instanceof \MvcCore\IConfig))
			throw new \Exception("[" . get_class($this) . "] Config to cache it doesn't implement `\MvcCore\IConfig` interface.");
		return $cache->Save($cacheKey, $config, static::$ttl, static::$tags);
	}

	/**
	 * Detect environment if necessary and returns it's name.
	 * @param \MvcCore\Config|NULL $config
	 * @param bool			       $force
	 * @throws \Exception Config to detect environment doesn't implement `\MvcCore\IConfig` interface.
	 * @return \MvcCore\Environment
	 */
	protected static function detectEnvironment ($config = NULL, $force = FALSE) {
		if ($config !== NULL && !($config instanceof \MvcCore\IConfig))
			throw new \Exception("[" . get_class($this) . "] Config to detect environment doesn't implement `\MvcCore\IConfig` interface.");
		$app = parent::$app ?: parent::$app = \MvcCore\Application::GetInstance();
		$environment = $app->GetEnvironment();
		$envClass = $app->GetEnvironmentClass();
		$isDetected = $environment->IsDetected();
		if ($config && (!$isDetected || $force)) {
			$configClass = $app->GetConfigClass();
			$envDetectionData = & $configClass::GetEnvironmentDetectionData($config);
			$envName = $envClass::DetectBySystemConfig((array) $envDetectionData);
			$environment->SetName($envName);
		}
		return $environment;
	}

	/**
	 * Get cache key by config fullpath.
	 * @param string $configFullPath
	 * @return string
	 */
	protected static function getCacheKey ($configFullPath) {
		$app = parent::$app ?: parent::$app = \MvcCore\Application::GetInstance();
		$appRoot = $app->GetPathAppRoot();
		if (mb_strpos($configFullPath, $appRoot) === 0) {
			$cacheKey = mb_substr($configFullPath, mb_strlen($appRoot));
		} else {
			$cacheKey = str_replace([':', '/'], ['', '_'], $configFullPath);
		}
		return $cacheKey;
	}

	/**
	 * Get cache store registered as default.
	 * @param string|NULL $storeName
	 * @return \MvcCore\Ext\Cache|NULL
	 */
	protected static function getCache ($storeName = NULL) {
		if (static::$cache === NULL) {
			$cacheClass = '\\MvcCore\\Ext\\Cache';
			static::$cache = $cacheClass::GetStore($storeName);
		}
		return static::$cache;
	}
}