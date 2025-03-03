<?php

namespace Stancl\Tenancy\Traits;

use Stancl\Tenancy\CacheManager;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Exceptions\PhpRedisNotInstalledException;

trait BootstrapsTenancy
{
    public $originalSettings = [];
    /**
     * Was tenancy initialized/bootstrapped?
     *
     * @var boolean
     */
    public $initialized = false;

    public function bootstrap()
    {
        $this->initialized = true;

        $this->switchDatabaseConnection();
        if ($this->app['config']['tenancy.redis.tenancy']) {
            $this->setPhpRedisPrefix($this->app['config']['tenancy.redis.prefixed_connections']);
        }
        $this->tagCache();
        $this->suffixFilesystemRootPaths();
    }

    public function end()
    {
        $this->initialized = false;

        $this->disconnectDatabase();
        if ($this->app['config']['tenancy.redis.tenancy']) {
            $this->resetPhpRedisPrefix($this->app['config']['tenancy.redis.prefixed_connections']);
        }
        $this->untagCache();
        $this->resetFileSystemRootPaths();
    }

    public function switchDatabaseConnection()
    {
        $this->database->connect($this->getDatabaseName());
    }

    public function setPhpRedisPrefix($connections = ['default'])
    {
        $this->originalSettings['redis'] = $this->originalSettings['redis'] ?? [];

        foreach ($connections as $connection) {
            $prefix = $this->app['config']['tenancy.redis.prefix_base'] . $this->tenant['uuid'];
            $client = Redis::connection($connection)->client();
            
            try {
                $this->originalSettings['redis'][$connection] = $client->getOption($client::OPT_PREFIX);
                $client->setOption($client::OPT_PREFIX, $prefix);
            } catch (\Throwable $t) {
                throw new PhpRedisNotInstalledException();
            }
        }
    }

    public function resetPhpRedisPrefix($connections = ['default'])
    {
        foreach ($connections as $connection) {
            $client = Redis::connection($connection)->client();
            
            try {
                $client->setOption($client::OPT_PREFIX, $this->originalSettings['redis'][$connection]);
            } catch (\Throwable $t) {
                throw new PhpRedisNotInstalledException();
            }
        }
    }

    public function tagCache()
    {
        $this->originalSettings['cache'] = $this->app['cache'];
        $this->app->extend('cache', function () {
            return new CacheManager($this->app);
        });
    }

    public function untagCache()
    {
        $this->app->extend('cache', function () {
            return $this->originalSettings['cache'];
        });
    }

    public function suffixFilesystemRootPaths()
    {
        $old = $this->originalSettings['storage'] ?? [
            'disks' => [],
            'path' => $this->app->storagePath(),
        ];

        $suffix = $this->app['config']['tenancy.filesystem.suffix_base'] . tenant('uuid');

        // storage_path()
        $this->app->useStoragePath($old['path'] . "/{$suffix}");

        // Storage facade
        foreach ($this->app['config']['tenancy.filesystem.disks'] as $disk) {
            $old['disks'][$disk] = Storage::disk($disk)->getAdapter()->getPathPrefix();
            
            if ($root = str_replace('%storage_path%', storage_path(), $this->app['config']["tenancy.filesystem.root_override.{$disk}"])) {
                Storage::disk($disk)->getAdapter()->setPathPrefix($root);
            } else {
                $root = $this->app['config']["filesystems.disks.{$disk}.root"];
    
                Storage::disk($disk)->getAdapter()->setPathPrefix($root . "/{$suffix}");
            }
        }

        $this->originalSettings['storage'] = $old;
    }

    public function resetFilesystemRootPaths()
    {
        // storage_path()
        $this->app->useStoragePath($this->originalSettings['storage']['path']);

        // Storage facade
        foreach ($this->app['config']['tenancy.filesystem.disks'] as $disk) {
            Storage::disk($disk)->getAdapter()->setPathPrefix($this->originalSettings['storage']['disks'][$disk]);
        }
    }
}
