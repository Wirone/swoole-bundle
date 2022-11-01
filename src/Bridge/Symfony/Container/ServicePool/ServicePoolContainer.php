<?php

declare(strict_types=1);

namespace K911\Swoole\Bridge\Symfony\Container\ServicePool;

final class ServicePoolContainer
{
    /**
     * @var array<ServicePool>
     */
    private array $pools;

    /**
     * @param array<ServicePool> $pools
     */
    public function __construct(array $pools)
    {
        $this->pools = $pools;
    }

    public function addPool(ServicePool $pool): void
    {
        $this->pools[] = $pool;
    }

    public function releaseForCoroutine(int $cId): void
    {
        foreach ($this->pools as $pool) {
            $pool->releaseForCoroutine($cId);
        }
    }
}
