# MUTEX
Simple PHP Mutex implementation.

Probably better suited for async applications where heavy-or-time-sensitive processes are executed in parallel with no intercommunication, and you need to implement some kind of semaphore.

Uses any PSR-16 (SimpleCache) implementation as the storage mechanism, just be sure to use something that all processes can access (in the likes of APC, memcached, Redis, plain files, etc...).

# EXPLANATION
Mutexes are based on named `events` and `timeouts`.
You `lock` an `event` for a certain period of time (or forever, if you wish), and then you can check elsewhere if that same event is locked or not, and act accordingly.
Usually, your first process will `lock` the event just before you start doing some important operation, and `unlock` the event when you are finished.
And then other processes that should wait for the first process to finish will check whether that event is `locked` and wait until it is clear to proceed.

# BASIC EXAMPLE
```
<?php

//PROCESS #1
$cache = new MyCache();
$mutex = new Mutex($cache);
$event = 'MyEvent';

$mutex->lock(event: $event);
sleep(5);
$mutex->unlock(event: $event);

------------

//PROCESS #2
$cache = new MyCache();
$mutex = new Mutex($cache);
$event = 'MyEvent';

while ($mutex->isLocked(event: $event)) {
	echo "Mutex in place... Waiting\n";
	sleep(1);
}
echo "Mutex not in place... Proceeding...\n";
```

Process #1 will start and put a lock in place for the event `MyEvent`, and unlock it after five seconds.
Process #2 will check if there is a lock in place for the event. If there is, it will wait one second and check again, until process #1 unlocks it.

# EXPLICIT TIMEOUT
```
<?php

//PROCESS #1
$cache = new MyCache();
$mutex = new Mutex($cache);
$event = 'MyEvent';

$lockID = $mutex->lock(event: $event, timeout: 5);
sleep(10);
$mutex->unlock($event, $lockID);

------------

//PROCESS #2
$cache = new MyCache();
$mutex = new Mutex($cache);
$event = 'MyEvent';

while ($mutex->isLocked($event)) {
	echo "Mutex in place... Waiting\n";
	sleep(1);
}
echo "Mutex not in place... Proceeding...\n";
```

Now process #1 will start and put a lock in place for the event `MyEvent` for a maximum time of 5 seconds, but will sleep for 10 seconds before trying to unlock it explicitly.
Process #2 will check if there is a lock in place for the event. Every second it will check again. After the explicit 5 seconds timeout expires, the event will unlock automatically, event though Process #1 never released it explicitly.

**IMPORTANT**
>The `lock` method returns a string ("lockID").
>If the `unlock` method is called with a single parameter (`event`), it will unlock that event without any further checking.
>If the `unlock` method is called with a second parameter (`lockID`), it will unlock that event ONLY IF the lock was put in place by that same "lockID".
>This prevents the event from being unlocked by someone who did not put the lock in place.

# SYNTACT SUGAR
```
<?php

$cache = new MyCache();
$mutex = new Mutex($cache);
$event = 'MyEvent';

try {
	$mutex->waitUntilUnlocked(event: $event, maxWait: 5, preDelay: 1, checkPeriod: 2_000_000);
} catch (MutexTimeoutException) {
	echo "Mutex did not unlock...\n";
}
```

The `waitUntilUnlocked` method handles the "waiting loop" for you, and some things more:
- `maxWait` (default = 0) means the maximum time you will wait (0 = forever). If the lock is still in place after this time, a `MutexTimeoutException` will be thrown.
- `preDelay` (default = 0) means the time (in microseconds) you will wait BEFORE checking for the lock the first time. Equivalent to running a `usleep(time)` just before calling this method. May be useful to avoid flooding in some situations.
- `checkPeriod` (default = 1_000_000) means the period (in microseconds) in which you will check if the lock is in place.

# IMPORTANT CONSIDERATIONS
- if you try to lock something but that event is already locked, a `MutexDoubleLockException` will be thrown.
- if you are firing several processes at the same time and will use the Mutex to "put them in line", I recommend using the `preDelay` parameter of the `waitUntilLocked` method, and probably with a random value to mitigate chances of being unlucky.
- always consider the possibility of using "named locks/unlocks", to avoid the Mutex from being unlocked by anyone who is not the process which set the lock in the first place.
- the Mutex constructor accepts a second parameter `cachePrefix`. If used, all keys written-to/read-from the cache will be prefixed with this string. In some applications, the cache uses key prefixes to handle special items.
- the `getLockerPID` method return this PID of the process which put the lock in place. This information may be useful in some situations, since you might be able to track (and maybe terminate) a process that is taking way too long to perform a task.
