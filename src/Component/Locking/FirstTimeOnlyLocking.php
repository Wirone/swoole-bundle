<?php

declare(strict_types=1);

namespace K911\Swoole\Component\Locking;

final class FirstTimeOnlyLocking implements Locking
{
    private Store $store;

    private Locking $wrapped;

    private function __construct(Locking $wrapped)
    {
        $this->store = new Store();
        $this->wrapped = $wrapped;
    }

    public function acquire(string $key): Lock
    {
        if (!$this->store->has($key)) {
            $this->store->save($key, FirstTimeOnlyLock::LOCKED);

            return FirstTimeOnlyLock::locked($key, $this->store, $this->wrapped->acquire($key));
        }

        if (FirstTimeOnlyLock::RELEASED === $this->store->get($key)) {
            return FirstTimeOnlyLock::unlocked();
        }

        while (FirstTimeOnlyLock::RELEASED !== $this->store->get($key)) {
            usleep(10);
        }

        /* @phpstan-ignore-next-line */
        return FirstTimeOnlyLock::unlocked();
    }

    public static function init(?Locking $locking = null): Locking
    {
        if (null === $locking) {
            $locking = CoroutineLocking::init();
        }

        if (!$locking instanceof self) {
            $locking = new self($locking);
        }

        return $locking;
    }
}
