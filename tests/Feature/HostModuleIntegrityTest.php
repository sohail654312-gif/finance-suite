<?php

declare(strict_types=1);

use App\Console\Commands\ModuleCommand;
use App\Models\Module;
use App\Modules\Contracts\ModuleInterface;
use App\Modules\Events\ModuleDisabled;
use App\Modules\Events\ModuleEnabled;
use App\Modules\Events\ModuleInstalled;
use App\Modules\Events\ModuleUninstalled;
use App\Modules\ModuleManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Tester\CommandTester;

function hostIntegrityModule(string $name, array $dependencies = [], bool $enabled = false): ModuleInterface
{
    return new class($name, $dependencies, $enabled) implements ModuleInterface
    {
        public array $calls = [];

        public function __construct(private string $name, private array $dependencies, private bool $enabled) {}

        public function getName(): string
        {
            return $this->name;
        }

        public function getVersion(): string
        {
            return '1.2.3';
        }

        public function getDescription(): string
        {
            return 'Synthetic module contract';
        }

        public function getDependencies(): array
        {
            return $this->dependencies;
        }

        public function getConfig(): array
        {
            return ['synthetic' => true];
        }

        public function isEnabled(): bool
        {
            return $this->enabled;
        }

        public function enable(): void
        {
            $this->calls[] = 'enable';
            $this->enabled = true;
        }

        public function disable(): void
        {
            $this->calls[] = 'disable';
            $this->enabled = false;
        }

        public function install(): void
        {
            $this->calls[] = 'install';
            $this->enabled = true;
        }

        public function uninstall(): void
        {
            $this->calls[] = 'uninstall';
            $this->enabled = false;
        }
    };
}

function hostIntegrityCommand(ModuleManager $manager, array $arguments, array $answers = []): CommandTester
{
    $command = new ModuleCommand($manager);
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $tester->setInputs($answers);
    $tester->execute($arguments, ['interactive' => $answers !== []]);

    return $tester;
}

beforeEach(function (): void {
    $this->originalHostAppPath = app_path();
    $this->originalHostPublicPath = public_path();
    $this->hostModuleRoot = storage_path('framework/testing/host-module-'.Str::uuid());
    File::makeDirectory($this->hostModuleRoot.'/Modules', 0755, true);
    $this->app->useAppPath($this->hostModuleRoot);
    $this->app->usePublicPath($this->hostModuleRoot.'/public');
    config(['modules.path' => $this->hostModuleRoot.'/Modules', 'modules.cache' => false, 'modular.modules_directory' => 'storage/framework/testing/absent-external-modules']);
    $this->hostManager = new ModuleManager();
});

afterEach(function (): void {
    $this->app->useAppPath($this->originalHostAppPath);
    $this->app->usePublicPath($this->originalHostPublicPath);
    if (str_starts_with($this->hostModuleRoot, storage_path('framework/testing/host-module-'))) {
        File::deleteDirectory($this->hostModuleRoot);
    }
});

it('persists module lifecycle state, metadata and cache invalidation', function (string $action, bool $expected): void {
    $module = hostIntegrityModule('Synthetic', [], ! $expected);
    $this->hostManager->register($module);
    Cache::put(config('modules.cache_key'), ['old-state']);
    expect($this->hostManager->{$action}('Synthetic'))->toBeTrue();
    $record = Module::where('name', 'Synthetic')->sole();
    expect($record->enabled)->toBe($expected)->and($record->version)->toBe('1.2.3')
        ->and($record->config)->toBe(['synthetic' => true])
        ->and($module->calls)->toBe([$action])
        ->and(Cache::has(config('modules.cache_key')))->toBeFalse();
})->with(['enable' => ['enable', true], 'install' => ['install', true], 'disable' => ['disable', false], 'uninstall' => ['uninstall', false]]);

it('requires enabled dependencies and protects enabled dependents from removal', function (): void {
    $base = hostIntegrityModule('Base');
    $dependent = hostIntegrityModule('Dependent', ['Base']);
    $this->hostManager->register($base);
    $this->hostManager->register($dependent);
    expect(fn () => $this->hostManager->enable('Dependent'))->toThrow(RuntimeException::class, 'unmet dependencies')
        ->and(fn () => $this->hostManager->install('Dependent'))->toThrow(RuntimeException::class, 'unmet dependencies');
    expect($dependent->calls)->toBe([]);
    $this->hostManager->enable('Base');
    $this->hostManager->enable('Dependent');
    expect(fn () => $this->hostManager->disable('Base'))->toThrow(RuntimeException::class, 'depend on it')
        ->and(fn () => $this->hostManager->uninstall('Base'))->toThrow(RuntimeException::class, 'depend on it');
    $this->hostManager->disable('Dependent');
    expect($this->hostManager->disable('Base'))->toBeTrue();
});

it('reports missing and disabled dependencies and lists actual registered metadata', function (): void {
    $this->hostManager->register(hostIntegrityModule('Disabled'));
    $this->hostManager->register(hostIntegrityModule('Broken', ['Missing', 'Disabled'], true));
    expect($this->hostManager->enabled()->keys()->all())->toBe(['Broken'])
        ->and($this->hostManager->disabled()->keys()->all())->toBe(['Disabled'])
        ->and($this->hostManager->has('Broken'))->toBeTrue()
        ->and($this->hostManager->checkModuleHealth('Broken'))->toBe(['healthy' => false, 'errors' => ['Dependency Missing not found', 'Module is enabled but has unmet dependencies'], 'warnings' => ['Dependency Disabled is disabled']])
        ->and($this->hostManager->healthCheck()['Disabled']['healthy'])->toBeTrue()
        ->and($this->hostManager->getAllModulesInfo())->toHaveCount(2)
        ->and($this->hostManager->getModuleInfo('Broken')['dependencies'])->toBe(['Missing', 'Disabled'])
        ->and($this->hostManager->getModuleInfo('Unknown'))->toBe([])
        ->and($this->hostManager->checkModuleHealth('Unknown')['healthy'])->toBeFalse()
        ->and($this->hostManager->install('Unknown'))->toBeFalse()
        ->and($this->hostManager->uninstall('Unknown'))->toBeFalse();
});

it('does not report success when durable module state cannot be saved', function (): void {
    Module::create(['name' => 'Synthetic', 'enabled' => false]);
    $this->hostManager->register(hostIntegrityModule('Synthetic'));
    Module::saving(fn () => throw new RuntimeException('synthetic-persistence-failure'));
    try {
        expect(fn () => $this->hostManager->enable('Synthetic'))->toThrow(RuntimeException::class, 'could not be persisted');
        expect(Module::where('name', 'Synthetic')->sole()->enabled)->toBeFalse();
    } finally {
        Module::flushEventListeners();
    }
});

it('uses valid module caches and replaces malformed cache entries', function (): void {
    config(['modules.cache' => true, 'modules.cache_key' => 'host-integrity.module-cache', 'modules.development' => false]);
    Cache::put('host-integrity.module-cache', ['Synthetic' => hostIntegrityModule('Synthetic', [], true)]);
    $manager = new ModuleManager();
    expect($manager->enabled()->keys()->all())->toBe(['Synthetic']);
    $manager->clearCache();
    expect(Cache::has('host-integrity.module-cache'))->toBeFalse();
    Cache::put('host-integrity.module-cache', 'malformed');
    expect((new ModuleManager())->all())->toBeEmpty()
        ->and(Cache::get('host-integrity.module-cache'))->toBe([]);
});

it('reports CLI missing and unknown module names as failures', function (string $action): void {
    $missing = hostIntegrityCommand($this->hostManager, ['action' => $action]);
    expect($missing->getStatusCode())->toBe(1)->and($missing->getDisplay())->toContain('Module name is required');
    if ($action !== 'create') {
        $unknown = hostIntegrityCommand($this->hostManager, ['action' => $action, 'name' => 'Unknown', '--force' => true]);
        expect($unknown->getStatusCode())->toBe(1)->and($unknown->getDisplay())->toContain('not found');
    }
})->with(['enable', 'disable', 'install', 'uninstall', 'create', 'info']);

it('returns a nonzero CLI result when module actions throw', function (string $action): void {
    $manager = Mockery::mock(ModuleManager::class);
    $manager->shouldReceive($action)->once()->with('Synthetic')->andThrow(new RuntimeException('synthetic-operation-failure'));
    $tester = hostIntegrityCommand($manager, ['action' => $action, 'name' => 'Synthetic', '--force' => true]);
    expect($tester->getStatusCode())->toBe(1)->and($tester->getDisplay())->toContain('Failed to '.$action);
})->with(['enable', 'disable', 'install', 'uninstall']);

it('executes CLI lifecycle operations and presents actual module information', function (): void {
    $this->hostManager->register(hostIntegrityModule('Base', [], true));
    $this->hostManager->register(hostIntegrityModule('Synthetic', ['Base']));
    foreach (['enable', 'disable', 'install', 'uninstall'] as $action) {
        $tester = hostIntegrityCommand($this->hostManager, ['action' => $action, 'name' => 'Synthetic', '--force' => true]);
        expect($tester->getStatusCode())->toBe(0);
    }
    $info = hostIntegrityCommand($this->hostManager, ['action' => 'info', 'name' => 'Synthetic']);
    expect($info->getDisplay())->toContain('Version: 1.2.3', 'Dependencies: Base', 'Status: Disabled');
    $list = hostIntegrityCommand($this->hostManager, ['action' => 'list']);
    expect($list->getDisplay())->toContain('Synthetic', 'Base', 'Enabled', 'Disabled');
});

it('shows help and empty modules without inventing installed modules', function (): void {
    expect(hostIntegrityCommand($this->hostManager, ['action' => 'help'])->getDisplay())->toContain('Available actions:')
        ->and(hostIntegrityCommand($this->hostManager, ['action' => 'list'])->getDisplay())->toContain('No modules found.');
});

it('does not uninstall when the operator declines confirmation', function (): void {
    $module = hostIntegrityModule('Synthetic', [], true);
    $this->hostManager->register($module);
    $tester = hostIntegrityCommand($this->hostManager, ['action' => 'uninstall', 'name' => 'Synthetic'], ['no']);
    expect($tester->getStatusCode())->toBe(0)->and($tester->getDisplay())->toContain('Operation cancelled.')
        ->and($module->calls)->toBe([])->and($module->isEnabled())->toBeTrue();
});

it('rejects path traversal and invalid PHP module identifiers before writing files', function (string $name): void {
    $tester = hostIntegrityCommand($this->hostManager, ['action' => 'create', 'name' => $name]);
    expect($tester->getStatusCode())->toBe(1)->and($tester->getDisplay())->toContain('PascalCase PHP identifier')
        ->and(File::directories($this->hostModuleRoot.'/Modules'))->toBe([]);
})->with(['../Outside', 'nested/Module', 'lowercase', 'Bad-Name', 'Bad;Name', '1Bad']);

it('creates and loads a real synthetic module and runs its guarded lifecycle and hooks', function (): void {
    $name = 'Synthetic'.str_replace('-', '', (string) Str::uuid());
    $created = hostIntegrityCommand($this->hostManager, ['action' => 'create', 'name' => $name]);
    expect($created->getStatusCode())->toBe(0);
    $path = $this->hostModuleRoot.'/Modules/'.$name;
    expect(json_decode(File::get($path.'/module.json'), true)['name'])->toBe($name)
        ->and(File::exists($path.'/Providers/'.$name.'ServiceProvider.php'))->toBeTrue()
        ->and(hostIntegrityCommand($this->hostManager, ['action' => 'create', 'name' => $name])->getStatusCode())->toBe(1);
    $manager = new ModuleManager();
    $module = $manager->get($name);
    expect($module)->toBeInstanceOf(ModuleInterface::class)->and($module->isEnabled())->toBeFalse();
    $order = [];
    $module->registerHook('before_enable', function () use (&$order): void {
        $order[] = 'second';
    }, 20);
    $module->registerHook('before_enable', function () use (&$order): void {
        $order[] = 'first';
    }, 10);
    expect($module->hasHook('before_enable'))->toBeTrue()->and($module->getHooks())->toBe(['before_enable']);
    Event::fake([ModuleEnabled::class, ModuleDisabled::class, ModuleInstalled::class, ModuleUninstalled::class]);
    Artisan::shouldReceive('call')->once()->with('migrate', ['--path' => 'app/Modules/'.$name.'/database/migrations', '--force' => true])->andReturn(0);
    Artisan::shouldReceive('call')->once()->with('vendor:publish', ['--tag' => strtolower($name).'-assets', '--force' => true])->andReturn(0);
    Artisan::shouldReceive('call')->once()->with('migrate:rollback', ['--path' => 'app/Modules/'.$name.'/database/migrations', '--force' => true])->andReturn(0);
    $manager->install($name);
    $manager->enable($name);
    expect($order)->toBe(['first', 'second'])->and($module->isEnabled())->toBeTrue();
    $module->setConfig('synthetic.setting', 'value');
    $module->mergeConfig(['another' => true]);
    expect($module->config('synthetic.setting'))->toBe('value')->and($module->hasConfig('synthetic.setting'))->toBeTrue()->and($module->getAllConfig()['another'])->toBeTrue();
    $module->clearHook('before_enable');
    expect($module->executeHook('absent'))->toBeNull()->and($module->hasHook('before_enable'))->toBeFalse();
    File::makeDirectory(public_path('modules/'.$name), 0755, true);
    File::put(public_path('modules/'.$name.'/synthetic.txt'), 'synthetic');
    $manager->uninstall($name);
    expect($module->isEnabled())->toBeFalse()->and(File::exists(public_path('modules/'.$name)))->toBeFalse();
    Event::assertDispatchedTimes(ModuleEnabled::class, 1);
    Event::assertDispatchedTimes(ModuleDisabled::class, 1);
    Event::assertDispatchedTimes(ModuleInstalled::class, 1);
    Event::assertDispatchedTimes(ModuleUninstalled::class, 1);
});
