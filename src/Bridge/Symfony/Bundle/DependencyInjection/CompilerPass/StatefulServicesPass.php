<?php

declare(strict_types=1);

namespace K911\Swoole\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass;

use K911\Swoole\Bridge\Doctrine\DoctrineProcessor;
use K911\Swoole\Bridge\Monolog\MonologProcessor;
use K911\Swoole\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\CompileProcessor;
use K911\Swoole\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\FinalClassesProcessor;
use K911\Swoole\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\Proxifier;
use K911\Swoole\Bridge\Symfony\Bundle\DependencyInjection\CompilerPass\StatefulServices\UnmanagedFactoryProxifier;
use K911\Swoole\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use K911\Swoole\Bridge\Symfony\Container\BlockingContainer;
use K911\Swoole\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use K911\Swoole\Bridge\Symfony\Container\StabilityChecker;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

final class StatefulServicesPass implements CompilerPassInterface
{
    private const IGNORED_SERVICES = [
        BlockingContainer::class => true,
    ];

    private const MANDATORRY_SERVICES_TO_PROXIFY = [
        'annotations.reader',
        'logger',
        'profiler_listener',
        'debug.event_dispatcher.inner',
        'debug.stopwatch',
        'request_stack',
    ];

    /**
     * @var array<array{class: class-string<CompileProcessor>, priority: int}>
     */
    private const COMPILE_PROCESSORS = [
        [
            'class' => DoctrineProcessor::class,
            'priority' => 0,
        ],
        [
            'class' => MonologProcessor::class,
            'priority' => 0,
        ],
    ];

    /**
     * {@inheritDoc}
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(ContainerConstants::PARAM_COROUTINES_ENABLED)) {
            return;
        }

        if (!$container->getParameter(ContainerConstants::PARAM_COROUTINES_ENABLED)) {
            return;
        }

        $finalProcessor = new FinalClassesProcessor($container);
        $proxifier = $this->createDefaultProxifier($container, $finalProcessor);
        $this->runCompileProcessors($container, $proxifier);
        $this->proxifyKnownStatefulServices($container, $proxifier);
        $this->proxifyUnmanagedFactories($container, $finalProcessor);
        $this->configureServicePoolContainer($container, $proxifier);
        $this->makeResettableServicesActive($container);
    }

    private function runCompileProcessors(ContainerBuilder $container, Proxifier $proxifier): void
    {
        /** @var array<array{class: class-string<CompileProcessor>, priority: int}> $compileProcessors */
        $compileProcessors = $container->getParameter(ContainerConstants::PARAM_COROUTINES_COMPILE_PROCESSORS);

        if (!is_array($compileProcessors)) {
            throw new \UnexpectedValueException('Invalid compiler processors provided');
        }

        $compileProcessors = array_merge(self::COMPILE_PROCESSORS, $compileProcessors);
        /**
         * @var callable(
         *  array<int, array<class-string<CompileProcessor>>>,
         *  array{class: class-string<CompileProcessor>, priority: int}
         * ): array<int, array<class-string<CompileProcessor>>> $reducer
         */
        $reducer = static function (array $processors, array $processorConfig): array {
            $processors[$processorConfig['priority']][] = $processorConfig['class'];

            return $processors;
        };
        /** @var array<int, array{class: class-string<CompileProcessor>, priority: int}> $compileProcessors */
        $compileProcessors = array_reduce(
            $compileProcessors,
            $reducer,
            []
        );
        $compileProcessors = array_merge(...array_reverse($compileProcessors));

        foreach ($compileProcessors as $processorClass) {
            /** @var CompileProcessor $processor */
            $processor = new $processorClass();
            $processor->process($container, $proxifier);
        }
    }

    private function proxifyKnownStatefulServices(ContainerBuilder $container, Proxifier $proxifier): void
    {
        /** @var array<string, null|array<string, mixed>> $resettableStatefulServices */
        $resettableStatefulServices = $container->findTaggedServiceIds('kernel.reset');
        /** @var array<string, null|array<string, mixed>> $taggedStatefulServices */
        $taggedStatefulServices = $container->findTaggedServiceIds(ContainerConstants::TAG_STATEFUL_SERVICE);
        /** @var array<string> $configuredStatefulServices */
        $configuredStatefulServices = $container->getParameter(ContainerConstants::PARAM_COROUTINES_STATEFUL_SERVICES);
        $servicesToProxify = array_merge(
            array_keys($resettableStatefulServices),
            array_keys($taggedStatefulServices),
            $configuredStatefulServices,
            self::MANDATORRY_SERVICES_TO_PROXIFY
        );
        $servicesToProxify = array_unique($servicesToProxify);

        foreach ($servicesToProxify as $serviceId) {
            if (isset(self::IGNORED_SERVICES[$serviceId])) {
                continue;
            }

            if (!$container->has($serviceId)) {
                continue;
            }

            $proxifier->proxifyService($serviceId);
        }
    }

    private function proxifyUnmanagedFactories(
        ContainerBuilder $container,
        FinalClassesProcessor $finalProcessor
    ): void {
        $factoryProxifier = new UnmanagedFactoryProxifier($container, $finalProcessor);
        /** @var array<string, null|array<string, mixed>> $factoriesToProxify */
        $factoriesToProxify = $container->findTaggedServiceIds(ContainerConstants::TAG_UNMANAGED_FACTORY);
        $factoriesToProxify = array_unique(array_keys($factoriesToProxify));

        foreach ($factoriesToProxify as $serviceId) {
            if (isset(self::IGNORED_SERVICES[$serviceId])) {
                continue;
            }

            if (!$container->has($serviceId)) {
                continue;
            }

            $factoryProxifier->proxifyService($serviceId);
        }
    }

    private function createDefaultProxifier(
        ContainerBuilder $container,
        FinalClassesProcessor $finalProcessor
    ): Proxifier {
        $stabilityCheckerDefs = $container->findTaggedServiceIds(ContainerConstants::TAG_STABILITY_CHECKER);
        /** @var array<class-string, class-string<StabilityChecker>|string> $stabilityCheckers */
        $stabilityCheckers = [];

        foreach (array_keys($stabilityCheckerDefs) as $svcId) {
            $definition = $container->findDefinition($svcId);
            /** @var class-string<StabilityChecker> $svcClass */
            $svcClass = $definition->getClass();
            /** @var class-string $supportedClass */
            $supportedClass = call_user_func([$svcClass, 'getSupportedClass']);
            $stabilityCheckers[$supportedClass] = $svcId;
        }

        if (!is_array($stabilityCheckers)) {
            throw new \UnexpectedValueException('Invalid stability checkers provided.');
        }

        return new Proxifier($container, $finalProcessor, $stabilityCheckers);
    }

    private function configureServicePoolContainer(ContainerBuilder $container, Proxifier $proxifier): void
    {
        $poolContainerDef = $container->findDefinition(ServicePoolContainer::class);
        $poolContainerDef->setArgument(0, $proxifier->getProxifiedServicePoolsRefs());
        $poolContainerDef->setArgument(1, $proxifier->getResetterRefs());
    }

    /**
     * by default ins Symfony, all resettable services are ignored during reset if they haven't been instantiated yet.
     * when using coroutines, all resettable services need to be instantiated on first reset, because otherwise,
     * it would be possible for a coroutine to acquire them not resetted in the scenario described below.
     *
     * 1) coroutine 1 starts, service reset runs but the resettable service is not instantiated yet, so there is no reset
     * 2) coroutine 2 starts in the same manner
     * 3) coroutine 1 needs the service so it instantiates the service pool
     * 4) coroutine 1 uses the stateful service and returns it to the service pool (not resetted)
     * 5) coroutine 2 needs the service, there is already a service pool so it acquires the instance formerly used
     *    in coroutine 1 (which still is not resetted and coroutine 2 is already after the reset phase)
     * 6) coroutine 2 uses the not resetted service with state remembered from the other coroutine
     *
     * the instantiation on first reset is forced by using the RUNTIME_EXCEPTION_ON_INVALID_REFERENCE in service reference
     */
    private function makeResettableServicesActive(ContainerBuilder $container): void
    {
        $resetterDef = $container->findDefinition('services_resetter');

        if ($resetterDef->hasTag('kernel.reset')) {
            return;
        }

        /** @var IteratorArgument $resetters */
        $resetters = $resetterDef->getArgument(0);
        $resetterValues = $resetters->getValues();
        $newReferences = [];

        foreach ($resetterValues as $key => $reference) {
            $newReferences[$key] = new Reference((string) $reference, ContainerInterface::RUNTIME_EXCEPTION_ON_INVALID_REFERENCE);
        }

        $resetters->setValues($newReferences);
    }
}
