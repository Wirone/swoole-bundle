<?php

declare(strict_types=1);

namespace K911\Swoole\Bridge\Symfony\Bundle\DependencyInjection;

interface ContainerConstants
{
    public const PARAM_COROUTINES_ENABLED = 'swoole_bundle.coroutines_support.enabled';

    public const PARAM_COROUTINES_STATEFUL_SERVICES = 'swoole_bundle.coroutines_support.stateful_services';

    public const PARAM_COROUTINES_COMPILE_PROCESSORS = 'swoole_bundle.coroutines_support.compile_processors';

    public const PARAM_CACHE_FOLDER = 'swoole_bundle';

    public const TAG_STATEFUL_SERVICE = 'swoole_bundle.stateful_service';

    public const TAG_DECORATED_STATEFUL_SERVICE = 'swoole_bundle.decorated_stateful_service';

    public const TAG_SAFE_STATEFUL_SERVICE = 'swoole_bundle.safe_stateful_service';

    public const TAG_UNMANAGED_FACTORY = 'swoole_bundle.unmanaged_factory';

    public const TAG_STABILITY_CHECKER = 'swoole_bundle.stability_checker';
}
