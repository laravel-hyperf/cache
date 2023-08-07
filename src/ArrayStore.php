<?php

declare(strict_types=1);

namespace SwooleTW\Hyperf\Cache;

use Hyperf\Support\Traits\InteractsWithTime;
use SwooleTW\Hyperf\Cache\Contracts\LockProvider;

class ArrayStore extends TaggableStore implements LockProvider
{
    use InteractsWithTime;
    use RetrievesMultipleKeys;

    /**
     * The array of locks.
     *
     * @var array
     */
    public $locks = [];

    /**
     * The array of stored values.
     *
     * @var array
     */
    protected $storage = [];

    /**
     * Indicates if values are serialized within the store.
     *
     * @var bool
     */
    protected $serializesValues;

    /**
     * Create a new Array store.
     *
     * @param bool $serializesValues
     */
    public function __construct($serializesValues = false)
    {
        $this->serializesValues = $serializesValues;
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @param array|string $key
     * @return mixed
     */
    public function get($key)
    {
        if (! isset($this->storage[$key])) {
            return;
        }

        $item = $this->storage[$key];

        $expiresAt = $item['expiresAt'] ?? 0;

        if ($expiresAt !== 0 && $this->currentTime() > $expiresAt) {
            $this->forget($key);

            return;
        }

        return $this->serializesValues ? unserialize($item['value']) : $item['value'];
    }

    /**
     * Store an item in the cache for a given number of seconds.
     *
     * @param string $key
     * @param mixed $value
     * @param int $seconds
     * @return bool
     */
    public function put($key, $value, $seconds)
    {
        $this->storage[$key] = [
            'value' => $this->serializesValues ? serialize($value) : $value,
            'expiresAt' => $this->calculateExpiration($seconds),
        ];

        return true;
    }

    /**
     * Increment the value of an item in the cache.
     *
     * @param string $key
     * @param mixed $value
     * @return int
     */
    public function increment($key, $value = 1)
    {
        if (! is_null($existing = $this->get($key))) {
            return tap(((int) $existing) + $value, function ($incremented) use ($key) {
                $value = $this->serializesValues ? serialize($incremented) : $incremented;

                $this->storage[$key]['value'] = $value;
            });
        }

        $this->forever($key, $value);

        return $value;
    }

    /**
     * Decrement the value of an item in the cache.
     *
     * @param string $key
     * @param mixed $value
     * @return int
     */
    public function decrement($key, $value = 1)
    {
        return $this->increment($key, $value * -1);
    }

    /**
     * Store an item in the cache indefinitely.
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function forever($key, $value)
    {
        return $this->put($key, $value, 0);
    }

    /**
     * Remove an item from the cache.
     *
     * @param string $key
     * @return bool
     */
    public function forget($key)
    {
        if (array_key_exists($key, $this->storage)) {
            unset($this->storage[$key]);

            return true;
        }

        return false;
    }

    /**
     * Remove all items from the cache.
     *
     * @return bool
     */
    public function flush()
    {
        $this->storage = [];

        return true;
    }

    /**
     * Get the cache key prefix.
     *
     * @return string
     */
    public function getPrefix()
    {
        return '';
    }

    /**
     * Get a lock instance.
     *
     * @param string $name
     * @param int $seconds
     * @param null|string $owner
     * @return \SwooleTW\Hyperf\Cache\Contracts\Lock
     */
    public function lock($name, $seconds = 0, $owner = null)
    {
        return new ArrayLock($this, $name, $seconds, $owner);
    }

    /**
     * Restore a lock instance using the owner identifier.
     *
     * @param string $name
     * @param string $owner
     * @return \SwooleTW\Hyperf\Cache\Contracts\Lock
     */
    public function restoreLock($name, $owner)
    {
        return $this->lock($name, 0, $owner);
    }

    /**
     * Get the expiration time of the key.
     *
     * @param int $seconds
     * @return int
     */
    protected function calculateExpiration($seconds)
    {
        return $this->toTimestamp($seconds);
    }

    /**
     * Get the UNIX timestamp for the given number of seconds.
     *
     * @param int $seconds
     * @return int
     */
    protected function toTimestamp($seconds)
    {
        return $seconds > 0 ? $this->availableAt($seconds) : 0;
    }
}
