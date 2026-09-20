<?php

namespace App\Support;

use App\Models\Setting as SettingModel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Cache\Repository;

class Setting
{
    const CACHE_KEY = 'admin_settings';

    private Repository $cache;
    private ?array $loadedSettings = null; // 请求内缓存

    public function __construct()
    {
        // 原先硬编码 Cache::store('redis')：未启用 redis 的部署每次保存配置都会抛异常，
        // 读取侧则被 catch 成空数组。改用应用配置的默认缓存驱动。
        // 注意：多进程部署（Octane / Horizon / WS server）请使用 redis 等共享驱动，
        // 否则某个进程写入后其它进程的缓存不会失效。
        $this->cache = Cache::store();
    }

    /**
     * 获取配置.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        return Arr::get($this->loadedSettings, strtolower($key), $default);
    }

    /**
     * 设置配置信息.
     */
    public function set(string $key, mixed $value = null): bool
    {
        SettingModel::createOrUpdate(strtolower($key), $value);
        $this->flush();
        return true;
    }

    /**
     * 保存配置到数据库.
     */
    public function save(array $settings): bool
    {
        foreach ($settings as $key => $value) {
            SettingModel::createOrUpdate(strtolower($key), $value);
        }
        $this->flush();
        return true;
    }

    /**
     * 删除配置信息
     */
    public function remove(string $key): bool
    {
        SettingModel::where('name', $key)->delete();
        $this->flush();
        return true;
    }

    /**
     * 更新单个设置项
     */
    public function update(string $key, $value): bool
    {
        return $this->set($key, $value);
    }
    
    /**
     * 批量获取配置项
     */
    public function getBatch(array $keys): array
    {
        $this->load();
        $result = [];
        
        foreach ($keys as $index => $item) {
            $isNumericIndex = is_numeric($index);
            $key = strtolower($isNumericIndex ? $item : $index);
            $default = $isNumericIndex ? config('v2board.' . $item) : (config('v2board.' . $key) ?? $item);
            
            $result[$item] = Arr::get($this->loadedSettings, $key, $default);
        }
        
        return $result;
    }
    
    /**
     * 将所有设置转换为数组
     */
    public function toArray(): array
    {
        $this->load();
        return $this->loadedSettings;
    }

    /**
     * 加载配置到请求内缓存
     */
    private function load(): void
    {
        if ($this->loadedSettings !== null) {
            return;
        }

        try {
            $settings = $this->cache->rememberForever(
                self::CACHE_KEY,
                fn(): array => $this->readFromDatabase()
            );
        } catch (\Throwable $e) {
            // 缓存不可用时必须回退读库，绝不能像以前那样回落成空数组：
            // 那等于 stop_register、captcha_enable、email_whitelist_enable
            // 这类开关在一次 redis 抖动里被静默关闭。
            Log::warning('Setting cache unavailable, reading settings from database: ' . $e->getMessage());

            try {
                $settings = $this->readFromDatabase();
            } catch (\Throwable $dbError) {
                Log::error('Failed to load settings from database: ' . $dbError->getMessage());
                $settings = [];
            }
        }

        $this->loadedSettings = $this->decodeValues($settings);
    }

    /**
     * 直接从数据库读取全部配置（键名统一小写）
     */
    private function readFromDatabase(): array
    {
        return array_change_key_case(
            SettingModel::pluck('value', 'name')->toArray(),
            CASE_LOWER
        );
    }

    /**
     * 还原以 JSON 形式存储的值
     */
    private function decodeValues(array $settings): array
    {
        foreach ($settings as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $settings[$key] = $decoded;
            }
        }

        return $settings;
    }

    /**
     * 清空缓存
     */
    private function flush(): void
    {
        try {
            $this->cache->forget(self::CACHE_KEY);
        } catch (\Throwable $e) {
            // 配置已经落库，这里失败只会导致其它进程短期读到旧值，
            // 不应该把一次成功的保存变成 500，但必须留下痕迹。
            Log::error('Failed to flush setting cache, other processes may serve stale settings: ' . $e->getMessage());
        }

        $this->loadedSettings = null;
    }
}
