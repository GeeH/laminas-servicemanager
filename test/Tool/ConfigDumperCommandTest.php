<?php

declare(strict_types=1);

namespace LaminasTest\ServiceManager\Tool;

use Laminas\ServiceManager\AbstractFactory\ConfigAbstractFactory;
use Laminas\ServiceManager\Tool\ConfigDumperCommand;
use Laminas\Stdlib\ConsoleHelper;
use LaminasTest\ServiceManager\TestAsset\InvokableObject;
use LaminasTest\ServiceManager\TestAsset\ObjectWithObjectScalarDependency;
use LaminasTest\ServiceManager\TestAsset\ObjectWithScalarDependency;
use LaminasTest\ServiceManager\TestAsset\SimpleDependencyObject;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

use function file_get_contents;
use function PHPUnit\Framework\assertIsArray;
use function realpath;
use function sprintf;

class ConfigDumperCommandTest extends TestCase
{
    use ProphecyTrait;

    public function setUp(): void
    {
        $this->configDir = vfsStream::setup('project');
        $this->helper = $this->prophesize(ConsoleHelper::class);
        $this->command = new ConfigDumperCommand(ConfigDumperCommand::class, $this->helper->reveal());
    }

    public function assertHelp($stream = STDOUT)
    {
        $this->helper->writeLine(
            Argument::containingString('<info>Usage:</info>'),
            true,
            $stream
        )->shouldBeCalled();
    }

    public function assertErrorRaised($message)
    {
        $this->helper->writeErrorMessage(
            Argument::containingString($message)
        )->shouldBeCalled();
    }

    public function testEmitsHelpWhenNoArgumentsProvided()
    {
        $command = $this->command;
        $this->assertHelp();
        $this->assertEquals(0, $command([]));
    }

    public function helpArguments()
    {
        return [
            'short'   => ['-h'],
            'long'    => ['--help'],
            'literal' => ['help'],
        ];
    }

    public function ignoreUnresolvedArguments()
    {
        return [
            'short' => ['-i'],
            'long'  => ['--ignore-unresolved'],
        ];
    }

    /**
     * @dataProvider helpArguments
     */
    public function testEmitsHelpWhenHelpArgumentProvidedAsFirstArgument($argument)
    {
        $command = $this->command;
        $this->assertHelp();
        $this->assertEquals(0, $command([$argument]));
    }

    public function testEmitsErrorWhenTooFewArgumentsPresent()
    {
        $command = $this->command;
        $this->assertErrorRaised('Missing class name');
        $this->assertHelp(STDERR);
        $this->assertEquals(1, $command(['foo']));
    }

    public function testRaisesExceptionIfConfigFileNotFoundAndDirectoryNotWritable()
    {
        $command = $this->command;
        vfsStream::newDirectory('config', 0550)
            ->at($this->configDir);
        $config = vfsStream::url('project/config/test.config.php');
        $this->assertErrorRaised(sprintf('Cannot create configuration at path "%s"; not writable.', $config));
        $this->assertHelp(STDERR);
        $this->assertEquals(1, $command([$config, 'Not\A\Real\Class']));
    }

    public function testGeneratesConfigFileWhenProvidedConfigurationFileNotFound()
    {
        $command = $this->command;
        vfsStream::newDirectory('config', 0775)
            ->at($this->configDir);
        $config = vfsStream::url('project/config/test.config.php');

        $this->helper->writeLine('<info>[DONE]</info> Changes written to ' . $config)->shouldBeCalled();

        $this->assertEquals(0, $command([$config, SimpleDependencyObject::class]));

        $generated = include $config;
        $this->assertIsArray($generated);
        $this->assertArrayHasKey(ConfigAbstractFactory::class, $generated);
        $factoryConfig = $generated[ConfigAbstractFactory::class];
        $this->assertIsArray($factoryConfig);
        $this->assertArrayHasKey(SimpleDependencyObject::class, $factoryConfig);
        $this->assertArrayHasKey(InvokableObject::class, $factoryConfig);
        $this->assertContains(InvokableObject::class, $factoryConfig[SimpleDependencyObject::class]);
        $this->assertEquals([], $factoryConfig[InvokableObject::class]);
    }

    /**
     * @dataProvider ignoreUnresolvedArguments
     */
    public function testGeneratesConfigFileIgnoringUnresolved($argument)
    {
        $command = $this->command;
        vfsStream::newDirectory('config', 0775)
            ->at($this->configDir);
        $config = vfsStream::url('project/config/test.config.php');

        $this->helper->writeLine('<info>[DONE]</info> Changes written to ' . $config)->shouldBeCalled();

        $this->assertEquals(0, $command([$argument, $config, ObjectWithObjectScalarDependency::class]));

        $generated = include $config;
        $this->assertIsArray($generated);
        $this->assertArrayHasKey(ConfigAbstractFactory::class, $generated);
        $factoryConfig = $generated[ConfigAbstractFactory::class];
        $this->assertIsArray($factoryConfig);
        $this->assertArrayHasKey(SimpleDependencyObject::class, $factoryConfig);
        $this->assertArrayHasKey(InvokableObject::class, $factoryConfig);
        $this->assertContains(InvokableObject::class, $factoryConfig[SimpleDependencyObject::class]);
        $this->assertEquals([], $factoryConfig[InvokableObject::class]);

        $this->assertArrayHasKey(ObjectWithObjectScalarDependency::class, $factoryConfig);
        $this->assertContains(
            SimpleDependencyObject::class,
            $factoryConfig[ObjectWithObjectScalarDependency::class]
        );
        $this->assertContains(
            ObjectWithScalarDependency::class,
            $factoryConfig[ObjectWithObjectScalarDependency::class]
        );
    }

    public function testEmitsErrorWhenConfigurationFileDoesNotReturnArray()
    {
        $command = $this->command;
        vfsStream::newFile('config/invalid.config.php')
            ->at($this->configDir)
            ->setContent(file_get_contents(realpath(__DIR__ . '/../TestAsset/config/invalid.config.php')));
        $config = vfsStream::url('project/config/invalid.config.php');
        $this->assertErrorRaised('Configuration at path "' . $config . '" does not return an array.');
        $this->assertHelp(STDERR);
        $this->assertEquals(1, $command([$config, 'Not\A\Real\Class']));
    }

    public function testEmitsErrorWhenClassDoesNotExist()
    {
        $command = $this->command;
        vfsStream::newFile('config/test.config.php')
            ->at($this->configDir)
            ->setContent(file_get_contents(realpath(__DIR__ . '/../TestAsset/config/test.config.php')));
        $config = vfsStream::url('project/config/test.config.php');
        $this->assertErrorRaised('Class "Not\\A\\Real\\Class" does not exist or could not be autoloaded.');
        $this->assertHelp(STDERR);
        $this->assertEquals(1, $command([$config, 'Not\A\Real\Class']));
    }

    public function testEmitsErrorWhenUnableToCreateConfiguration()
    {
        $command = $this->command;
        vfsStream::newFile('config/test.config.php')
            ->at($this->configDir)
            ->setContent(file_get_contents(realpath(__DIR__ . '/../TestAsset/config/test.config.php')));
        $config = vfsStream::url('project/config/test.config.php');
        $this->assertErrorRaised('Unable to create config for "' . ObjectWithScalarDependency::class . '":');
        $this->assertHelp(STDERR);
        $this->assertEquals(1, $command([$config, ObjectWithScalarDependency::class]));
    }

    public function testEmitsConfigFileToStdoutWhenSuccessful()
    {
        $command = $this->command;
        vfsStream::newFile('config/test.config.php')
            ->at($this->configDir)
            ->setContent(file_get_contents(realpath(__DIR__ . '/../TestAsset/config/test.config.php')));
        $config = vfsStream::url('project/config/test.config.php');

        $this->helper->writeLine('<info>[DONE]</info> Changes written to ' . $config)->shouldBeCalled();

        $this->assertEquals(0, $command([$config, SimpleDependencyObject::class]));

        $generated = include $config;
        $this->assertIsArray($generated);
        $this->assertArrayHasKey(ConfigAbstractFactory::class, $generated);
        $factoryConfig = $generated[ConfigAbstractFactory::class];
        $this->assertIsArray($factoryConfig);
        $this->assertArrayHasKey(SimpleDependencyObject::class, $factoryConfig);
        $this->assertArrayHasKey(InvokableObject::class, $factoryConfig);
        $this->assertContains(InvokableObject::class, $factoryConfig[SimpleDependencyObject::class]);
        $this->assertEquals([], $factoryConfig[InvokableObject::class]);
    }
}
