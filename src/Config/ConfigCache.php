<?php

declare(strict_types=1);

namespace SubticketManager\Config;

/**
 * ConfigCache - Singleton for caching plugin configuration
 *
 * osTicket's Signal callbacks receive new plugin instances without proper config.
 * This class caches config values statically to work around this limitation.
 *
 * Usage:
 * - In bootstrap(): ConfigCache::getInstance()->populate($values)
 * - In signal handlers: ConfigCache::getInstance()->get('key')
 *
 * @package SubticketManager
 */
final class ConfigCache
{
    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private array $cache = [];

    private function __construct()
    {
        // Private constructor for singleton
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Reset instance (for testing)
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Populate cache with values
     *
     * @param array<string, mixed> $values Key-value pairs to cache
     */
    public function populate(array $values): void
    {
        $this->cache = array_merge($this->cache, $values);
    }

    /**
     * Get a cached value
     *
     * @param string $key Config key
     * @param mixed $default Default value if not set
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->cache[$key] ?? $default;
    }

    /**
     * Set a single cached value
     *
     * @param string $key Config key
     * @param mixed $value Value to cache
     */
    public function set(string $key, $value): void
    {
        $this->cache[$key] = $value;
    }

    /**
     * Check if key exists in cache
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->cache);
    }

    /**
     * Get all cached values
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->cache;
    }

    /**
     * Clear the cache
     */
    public function clear(): void
    {
        $this->cache = [];
    }
}
