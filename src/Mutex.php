<?php
namespace Alesinicio\Mutex;

use Alesinicio\Mutex\Exceptions\MutexDoubleLockException;
use Alesinicio\Mutex\Exceptions\MutexTimeoutException;
use Psr\SimpleCache\CacheInterface;

class Mutex {
	public function __construct(
		private readonly CacheInterface $cache,
		private readonly ?string        $cacheKeyPrefix = null,
	) {}
	/**
	 * @throws MutexDoubleLockException
	 */
	public function lock(string $event, ?int $timeout = null) : string {
		$cacheKey = $this->getEventCacheKey($event);
		if ($this->isLocked($event)) throw new MutexDoubleLockException();

		$pid = posix_getpid();
		$this->cache->set(
			key  : $cacheKey,
			value: ['pid' => $pid, 'status' => true],
			ttl  : $timeout,
		);
		return $pid;
	}
	public function unlock(string $event, string $pid = null) : bool {
		$cacheKey = $this->getEventCacheKey($event);
		if ($pid) {
			$item = $this->cache->get($cacheKey);
			if (!$item) return true;
			if ($item['pid'] !== $pid) return false;
		}

		return $this->cache->delete(
			key: $cacheKey,
		);
	}
	/**
	 * @throws MutexTimeoutException
	 */
	public function waitUntilUnlocked(string $event, int $maxWait = 0, int $preDelay = 0, int $checkPeriod = 1_000_000) : void {
		if ($preDelay) usleep($preDelay);

		$waitUntil = date('U', strtotime('+' . $maxWait . ' seconds') ?: 0);
		while (true) {
			if (!$this->isLocked($event)) return;

			usleep($checkPeriod);
			if (($maxWait > 0) && (date('U') > $waitUntil)) throw new MutexTimeoutException();
		}
	}
	public function isLocked(string $event) : bool {
		return (bool)$this->cache->get(
			key    : $this->getEventCacheKey($event),
			default: false,
		);
	}
	public function getLockerPID(string $event) : ?int {
		$cacheKey = $this->getEventCacheKey($event);
		$item     = $this->cache->get($cacheKey);
		if (!$item) return null;
		return $item['pid'] ?? null;
	}
	private function getEventCacheKey(string $event) : string {
		return implode(':', array_filter([$this->cacheKeyPrefix, 'mutex', $event]));
	}
}