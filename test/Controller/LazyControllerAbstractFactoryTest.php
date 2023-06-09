<?php

namespace LaminasTest\Mvc\Controller;

use Prophecy\Prophecy\ObjectProphecy;
use LaminasTest\Mvc\Controller\TestAsset\SampleController;
use LaminasTest\Mvc\Controller\TestAsset\ControllerWithEmptyConstructor;
use LaminasTest\Mvc\Controller\TestAsset\SampleInterface;
use LaminasTest\Mvc\Controller\TestAsset\ControllerWithTypeHintedConstructorParameter;
use LaminasTest\Mvc\Controller\TestAsset\ControllerWithUnionTypeHintedConstructorParameter;
use LaminasTest\Mvc\Controller\TestAsset\ControllerWithScalarParameters;
use LaminasTest\Mvc\Controller\TestAsset\ControllerAcceptingConfigToConstructor;
use LaminasTest\Mvc\Controller\TestAsset\ControllerAcceptingWellKnownServicesAsConstructorParameters;
use LaminasTest\Mvc\Controller\TestAsset\ControllerWithMixedConstructorParameters;
use Interop\Container\ContainerInterface;
use Laminas\Mvc\Controller\LazyControllerAbstractFactory;
use Laminas\Mvc\Exception\DomainException;
use Laminas\ServiceManager\Exception\ServiceNotFoundException;
use Laminas\Validator\ValidatorPluginManager;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;

class LazyControllerAbstractFactoryTest extends TestCase
{
    use ProphecyTrait;

    private ContainerInterface|ObjectProphecy $container;

    public function setUp(): void
    {
        $this->container = $this->prophesize(ContainerInterface::class);
    }

    public function nonClassRequestedNames()
    {
        return [
            'non-class-string' => ['non-class-string'],
        ];
    }

    /**
     * @dataProvider nonClassRequestedNames
     */
    public function testCanCreateReturnsFalseForNonClassRequestedNames($requestedName)
    {
        $factory = new LazyControllerAbstractFactory();
        $this->assertFalse($factory->canCreate($this->container->reveal(), $requestedName));
    }

    public function testCanCreateReturnsFalseForClassesThatDoNotImplementDispatchableInterface()
    {
        $factory = new LazyControllerAbstractFactory();
        $this->assertFalse($factory->canCreate($this->container->reveal(), self::class));
    }

    public function testFactoryInstantiatesClassDirectlyIfItHasNoConstructor()
    {
        $factory = new LazyControllerAbstractFactory();
        $controller = $factory($this->container->reveal(), SampleController::class);
        $this->assertInstanceOf(SampleController::class, $controller);
    }

    public function testFactoryInstantiatesClassDirectlyIfConstructorHasNoArguments()
    {
        $factory = new LazyControllerAbstractFactory();
        $controller = $factory($this->container->reveal(), ControllerWithEmptyConstructor::class);
        $this->assertInstanceOf(ControllerWithEmptyConstructor::class, $controller);
    }

    public function testFactoryRaisesExceptionWhenUnableToResolveATypeHintedService()
    {
        $this->container->has(SampleInterface::class)->willReturn(false);
        $this->container->has(\ZendTest\Mvc\Controller\TestAsset\SampleInterface::class)->willReturn(false);
        $factory = new LazyControllerAbstractFactory();
        $this->expectException(ServiceNotFoundException::class);
        $this->expectExceptionMessage(
            sprintf(
                'Unable to create controller "%s"; unable to resolve parameter "sample" using type hint "%s"',
                ControllerWithTypeHintedConstructorParameter::class,
                SampleInterface::class
            )
        );
        $factory($this->container->reveal(), ControllerWithTypeHintedConstructorParameter::class);
    }

    /**
     * @requires PHP >= 8.0
     */
    public function testFactoryRaisesExceptionWhenResolvingUnionTypeHintedService(): void
    {
        $this->container->has(SampleInterface::class)->willReturn(false);
        $factory = new LazyControllerAbstractFactory();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(
            sprintf(
                'Unable to create controller "%s"; unable to resolve parameter "sample" with union type hint',
                ControllerWithUnionTypeHintedConstructorParameter::class
            )
        );
        $factory($this->container->reveal(), ControllerWithUnionTypeHintedConstructorParameter::class);
    }

    public function testFactoryPassesNullForScalarParameters()
    {
        $factory = new LazyControllerAbstractFactory();
        $controller = $factory($this->container->reveal(), ControllerWithScalarParameters::class);
        $this->assertInstanceOf(ControllerWithScalarParameters::class, $controller);
        $this->assertNull($controller->foo);
        $this->assertNull($controller->bar);
    }

    public function testFactoryInjectsConfigServiceForConfigArgumentsTypeHintedAsArray()
    {
        $config = ['foo' => 'bar'];
        $this->container->has('config')->willReturn(true);
        $this->container->get('config')->willReturn($config);

        $factory = new LazyControllerAbstractFactory();
        $controller = $factory($this->container->reveal(), ControllerAcceptingConfigToConstructor::class);
        $this->assertInstanceOf(ControllerAcceptingConfigToConstructor::class, $controller);
        $this->assertEquals($config, $controller->config);
    }

    public function testFactoryCanInjectKnownTypeHintedServices()
    {
        $sample = $this->prophesize(SampleInterface::class)->reveal();
        $this->container->has(SampleInterface::class)->willReturn(true);
        $this->container->get(SampleInterface::class)->willReturn($sample);

        $factory = new LazyControllerAbstractFactory();
        $controller = $factory(
            $this->container->reveal(),
            ControllerWithTypeHintedConstructorParameter::class
        );
        $this->assertInstanceOf(ControllerWithTypeHintedConstructorParameter::class, $controller);
        $this->assertSame($sample, $controller->sample);
    }

    public function testFactoryResolvesTypeHintsForServicesToWellKnownServiceNames()
    {
        $validators = $this->prophesize(ValidatorPluginManager::class)->reveal();
        $this->container->has('ValidatorManager')->willReturn(true);
        $this->container->get('ValidatorManager')->willReturn($validators);

        $factory = new LazyControllerAbstractFactory();
        $controller = $factory(
            $this->container->reveal(),
            ControllerAcceptingWellKnownServicesAsConstructorParameters::class
        );
        $this->assertInstanceOf(
            ControllerAcceptingWellKnownServicesAsConstructorParameters::class,
            $controller
        );
        $this->assertSame($validators, $controller->validators);
    }

    public function testFactoryCanSupplyAMixOfParameterTypes()
    {
        $validators = $this->prophesize(ValidatorPluginManager::class)->reveal();
        $this->container->has('ValidatorManager')->willReturn(true);
        $this->container->get('ValidatorManager')->willReturn($validators);

        $sample = $this->prophesize(SampleInterface::class)->reveal();
        $this->container->has(SampleInterface::class)->willReturn(true);
        $this->container->get(SampleInterface::class)->willReturn($sample);

        $config = ['foo' => 'bar'];
        $this->container->has('config')->willReturn(true);
        $this->container->get('config')->willReturn($config);

        $factory = new LazyControllerAbstractFactory();
        $controller = $factory($this->container->reveal(), ControllerWithMixedConstructorParameters::class);
        $this->assertInstanceOf(ControllerWithMixedConstructorParameters::class, $controller);

        $this->assertEquals($config, $controller->config);
        $this->assertNull($controller->foo);
        $this->assertEquals([], $controller->options);
        $this->assertSame($sample, $controller->sample);
        $this->assertSame($validators, $controller->validators);
    }
}
