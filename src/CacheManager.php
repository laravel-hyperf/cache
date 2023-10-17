<?php

declare(strict_types=1);

namespace SwooleTW\Hyperf\Cache;

use Closure;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\RedisFactory;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface as DispatcherContract;
use SwooleTW\Hyperf\Cache\Contracts\Factory as FactoryContract;
use SwooleTW\Hyperf\Cache\Contracts\Repository as RepositoryContract;
use SwooleTW\Hyperf\Cache\Contracts\Store;

use function Hyperf\Support\make;

/**
 * @mixin \SwooleTW\Hyperf\Cache\Contracts\Repository
 * @mixin TaggableStore
 */
class CacheManager implements FactoryContract
{
    /**
     * The array of resolved cache stores.
     */
    protected array $stores = [];

    /**
     * The registered custom driver creators.
     */
    protected array $customCreators = [];

    /**
     * Create a new Cache manager instance.
     */
    public function __construct(
        protected ContainerInterface $app
    ) {}

    /**
     * Dynamically call the default driver instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->store()->{$method}(...$parameters);
    }

    /**
     * Get a cache store instance by name, wrapped in a repository.
     */
    public function store(?string $name = null): RepositoryContract
    {
        $name = $name ?: $this->getDefaultDriver();

        return $this->stores[$name] ??= $this->resolve($name);
    }

    /**
     * Get a cache driver instance.
     */
    public function driver(?string $driver = null): RepositoryContract
    {
        return $this->store($driver);
    }

    /**
     * Create a new cache repository with the given implementation.
     */
    public function repository(Store $store): Repository
    {
        return tap(new Repository($store), function ($repository) {
            $this->setEventDispatcher($repository);
        });
    }

    /**
     * Re-set the event dispatcher on all resolved cache repositories.
     */
    public function refreshEventDispatcher(): void
    {
        array_map([$this, 'setEventDispatcher'], $this->stores);
    }

    /**
     * Get the default cache driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->app->get(ConfigInterface::class)
            ->get('laravel_cache.default', 'file');
    }

    /**
     * Set the default cache driver name.
     */
    public function setDefaultDriver(string $name): void
    {
        $this->app->get(ConfigInterface::class)
            ->set('laravel_cache.default', $name);
    }

    /**
     * Unset the given driver instances.
     */
    public function forgetDriver(null|array|string $name = null): static
    {
        $name ??= $this->getDefaultDriver();

        foreach ((array) $name as $cacheName) {
            if (isset($this->stores[$cacheName])) {
                unset($this->stores[$cacheName]);
            }
        }

        return $this;
    }

    /**
     * Disconnect the given driver and remove from local cache.
     */
    public function purge(?string $name = null): void
    {
        $name ??= $this->getDefaultDriver();

        unset($this->stores[$name]);
    }

    /**
     * Register a custom driver creator Closure.
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customCreators[$driver] = $callback->bindTo($this, $this);

        return $this;
    }

    /**
     * Set the application instance used by the manager.
     */
    public function setApplication(ContainerInterface $app): static
    {
        $this->app = $app;

        return $this;
    }

    /**
     * Attempt to get the store from the local cache.
     */
    protected function get(string $name): RepositoryContract
    {
        return $this->stores[$name] ?? $this->resolve($name);
    }

    /**
     * Resolve the given store.
     *
     * @throws InvalidArgumentException
     */
    protected function resolve(string $name): RepositoryContract
    {
        $config = $this->getConfig($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Cache store [{$name}] is not defined.");
        }

        if (isset($this->customCreators[$config['driver']])) {
            return $this->callCustomCreator($config);
        }

        $driverMethod = 'create' . ucfirst($config['driver']) . 'Driver';

        if (method_exists($this, $driverMethod)) {
            return $this->{$driverMethod}($config);
        }

        throw new InvalidArgumentException("Driver [{$config['driver']}] is not supported.");
    }

    /**
     * Call a custom driver creator.
     */
    protected function callCustomCreator(array $config): RepositoryContract
    {
        return $this->customCreators[$config['driver']]($this->app, $config);
    }

    /**
     * Create an instance of the array cache driver.
     */
    protected function createArrayDriver(array $config): Repository
    {
        return $this->repository(new ArrayStore($config['serialize'] ?? false));
    }

    /**
     * Create an instance of the file cache driver.
     */
    protected function createFileDriver(array $config): Repository
    {
        $store = make(FileStore::class, [
            'directory' => $config['path'],
            'filePermission' => $config['permission'] ?? null,
        ])->setLockDirectory($config['lock_path'] ?? null);

        return $this->repository($store);
    }

    /**
     * Create an instance of the Null cache driver.
     */
    protected function createNullDriver(): Repository
    {
        return $this->repository(new NullStore());
    }

    /**
     * Create an instance of the Redis cache driver.
     */
    protected function createRedisDriver(array $config): Repository
    {
        $redis = $this->app->get(RedisFactory::class);

        $connection = $config['connection'] ?? 'default';

        $store = new RedisStore($redis, $this->getPrefix($config), $connection);

        return $this->repository(
            $store->setLockConnection($config['lock_connection'] ?? $connection)
        );
    }

    /**
     * Create an instance of the Swoole cache driver.
     */
    protected function createSwooleDriver(array $config): Repository
    {
        $cacheTable = $this->app->get(SwooleTableManager::class)->get($config['table']);

        return $this->repository(new SwooleStore($cacheTable));
    }

    /**
     * Set the event dispatcher on the given repository instance.
     */
    protected function setEventDispatcher(Repository $repository): void
    {
        if (! $this->app->has(DispatcherContract::class)) {
            return;
        }

        $repository->setEventDispatcher(
            $this->app->get(DispatcherContract::class)
        );
    }

    /**
     * Get the cache prefix.
     */
    protected function getPrefix(array $config): string
    {
        return $config['prefix'] ?? $this->app->get(ConfigInterface::class)->get('laravel_cache.prefix');
    }

    /**
     * Get the cache connection configuration.
     */
    protected function getConfig(string $name): ?array
    {
        if (! is_null($name) && $name !== 'null') {
            return $this->app->get(ConfigInterface::class)->get("laravel_cache.stores.{$name}");
        }

        return ['driver' => 'null'];
    }
}
