<?php
namespace Trac2GitLab;

final class Cache
{
    private static $instance = null;
    private static $path = null;

    public static function setPath($path)
    {
        if (file_exists($path) && !is_dir($path)) {
            throw new \Exception("Path {$path} exists and is no folder");
        } elseif (!file_exists($path)) {
            mkdir($path);
        }

        if (!is_writable($path)) {
            throw new \Exception("Path {$path} is not writable");
        }
        self::$path = rtrim($path, '/');
    }

    public static function getInstance()
    {
        if (!isset(self::$path)) {
            throw new \Exception('No path has been defined, use Cache::setPath().');
        }

        if (self::$instance === null) {
            self::$instance = new self(self::$path);
        }
        return self::$instance;
    }

    private $cache_path;
    private $cache;

    private function __construct($path)
    {
        $this->cache = [];
        $this->cache_path = $path;
    }

    public function clear()
    {
        array_map('unlink', glob($this->cache_path . '/*'));
        $this->cache = [];
    }

    public function get($key, $default = null)
    {
        $this->cache[$key] = $default;

        $filename = $this->getCacheFilenameForKey($key);
        if (file_exists($filename)) {
            $this->cache[$key] = json_decode(file_get_contents($filename), true);
        }

        return $this->cache[$key];
    }

    public function set($key, $value)
    {
        $this->cache[$key] = $value;
        file_put_contents($this->getCacheFilenameForKey($key), json_encode($value));
    }

    private function getCacheFilenameForKey($key)
    {
        return $this->cache_path . '/' . md5($key) . '.json';
    }
}
