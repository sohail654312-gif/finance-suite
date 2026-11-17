<?php

declare(strict_types=1);

use App\Models\Module;
use App\Modules\Contracts\ModuleInterface;
use App\Modules\ModuleManager;
use App\Modules\ModuleServiceProvider;
use App\Modules\Support\ExternalModuleLoader;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function moduleSafetyFixture(string $directory, string $namespace, string $name, array $config = []): string
{
    File::ensureDirectoryExists($directory);
    File::put($directory.'/module.json', json_encode(['name' => $name, 'version' => '2.3.4', 'description' => 'Synthetic safety fixture', 'config' => $config], JSON_THROW_ON_ERROR));
    $class = $namespace.'\\'.$name.'Module';
    $source = <<<'PHP'
<?php
namespace %s;
final class %sModule extends \App\Modules\BaseModule
{
    public array $operations = [];
    protected function onEnable(): void
    {
        $this->operations[] = 'enable';
        if ($this->config['fail_enable'] ?? false) { throw new \RuntimeException('synthetic-enable-failure'); }
    }
    protected function onDisable(): void
    {
        $this->operations[] = 'disable';
        if ($this->config['fail_disable'] ?? false) { throw new \RuntimeException('synthetic-disable-failure'); }
    }
    protected function onInstall(): void { $this->operations[] = 'install'; }
    protected function onUninstall(): void { $this->operations[] = 'uninstall'; }
}
PHP;
    File::put($directory.'/'.$name.'Module.php', sprintf($source, $namespace, $name));
    require_once $directory.'/'.$name.'Module.php';

    return $class;
}

beforeEach(function (): void {
    $this->moduleSafetyAppPath = app_path();
    $this->moduleSafetyPublicPath = public_path();
    $this->moduleSafetyRoot = storage_path('framework/testing/module-safety-'.Str::uuid());
    File::ensureDirectoryExists($this->moduleSafetyRoot.'/Modules');
    File::ensureDirectoryExists($this->moduleSafetyRoot.'/public');
    $this->app->useAppPath($this->moduleSafetyRoot);
    $this->app->usePublicPath($this->moduleSafetyRoot.'/public');
    config(['modules.path' => $this->moduleSafetyRoot.'/Modules', 'modules.cache' => false,
        'modules.load_composer_modules' => false, 'modular.modules_directory' => 'storage/framework/testing/absent-safety-modules']);
    $this->moduleSafetyManager = new ModuleManager();
    $this->moduleSafetyNamespace = 'ModuleSafetyFixtures\\N'.str_replace('-', '', (string) Str::uuid());
});

afterEach(function (): void {
    $this->app->useAppPath($this->moduleSafetyAppPath);
    $this->app->usePublicPath($this->moduleSafetyPublicPath);
    if (dirname((string) realpath($this->moduleSafetyRoot)) === realpath(storage_path('framework/testing'))) {
        File::deleteDirectory($this->moduleSafetyRoot);
    }
});

it('loads actual external module classes only from their declared source directories and canonicalizes roots', function (): void {
    $root = $this->moduleSafetyRoot.'/external';
    $class = moduleSafetyFixture($root.'/Safe', $this->moduleSafetyNamespace.'\\Safe', 'Safe');
    File::ensureDirectoryExists($root.'/NotAModule');
    File::ensureDirectoryExists($root.'/NoClass');
    File::put($root.'/NoClass/module.json', '{}');
    $loader = new ExternalModuleLoader($this->moduleSafetyManager);
    $loader->loadFromPath($this->moduleSafetyRoot.'/absent');
    $loader->loadFromPath($root, $this->moduleSafetyNamespace);
    $loader->loadFromPath($root.'/.', $this->moduleSafetyNamespace);
    expect($loader->getLoadedPaths())->toBe([realpath($root)])
        ->and($this->moduleSafetyManager->all())->toHaveCount(1)
        ->and($this->moduleSafetyManager->get('Safe'))->toBeInstanceOf($class);
    expect($loader->registerCustomModule($this->moduleSafetyRoot.'/absent', $class))->toBeFalse()
        ->and($loader->registerCustomModule($root.'/Safe', 'NonexistentSafetyModule'))->toBeFalse()
        ->and($loader->registerCustomModule($root.'/NoClass', $class))->toBeFalse()
        ->and($loader->registerCustomModule($root.'/Safe', $class))->toBeTrue();
});

it('rejects an external class outside the declared module even when its namespace looks correct', function (): void {
    $root = $this->moduleSafetyRoot.'/external';
    File::ensureDirectoryExists($root.'/Impostor');
    File::put($root.'/Impostor/module.json', '{}');
    moduleSafetyFixture($this->moduleSafetyRoot.'/outside', $this->moduleSafetyNamespace.'\\Impostor', 'Impostor');
    $loader = new ExternalModuleLoader($this->moduleSafetyManager);
    $loader->loadFromPath($root, $this->moduleSafetyNamespace);
    expect($this->moduleSafetyManager->all())->toBeEmpty();
    expect(fn () => $loader->loadFromPath($root, '../Untrusted'))->toThrow(InvalidArgumentException::class);
});

it('does not allow symlinked module directories to escape the configured root', function (): void {
    $root = $this->moduleSafetyRoot.'/external';
    File::ensureDirectoryExists($root);
    moduleSafetyFixture($this->moduleSafetyRoot.'/outside', $this->moduleSafetyNamespace.'\\Escaped', 'Escaped');
    if (! @symlink($this->moduleSafetyRoot.'/outside', $root.'/Escaped')) {
        $this->markTestSkipped('The platform does not permit synthetic symlinks.');
    }
    try {
        (new ExternalModuleLoader($this->moduleSafetyManager))->loadFromPath($root, $this->moduleSafetyNamespace);
        expect($this->moduleSafetyManager->all())->toBeEmpty();
    } finally {
        unlink($root.'/Escaped');
    }
});

it('handles unusable external classes without registering a partially constructed module', function (): void {
    $root = $this->moduleSafetyRoot.'/external';
    foreach (['Wrong', 'Throws'] as $name) {
        $path = $root.'/'.$name;
        File::ensureDirectoryExists($path);
        File::put($path.'/module.json', '{}');
        $body = $name === 'Wrong' ? '{}' : '{ public function __construct() { throw new \\RuntimeException("synthetic-constructor-failure"); } }';
        $file = $path.'/'.$name.'Module.php';
        File::put($file, '<?php namespace '.$this->moduleSafetyNamespace.'\\'.$name.'; class '.$name.'Module '.$body);
        require $file;
    }
    $loader = new ExternalModuleLoader($this->moduleSafetyManager);
    $loader->loadFromPath($root, $this->moduleSafetyNamespace);
    expect($this->moduleSafetyManager->all())->toBeEmpty()
        ->and($loader->registerCustomModule($root.'/Wrong', $this->moduleSafetyNamespace.'\\Wrong\\WrongModule'))->toBeFalse()
        ->and($loader->registerCustomModule($root.'/Throws', $this->moduleSafetyNamespace.'\\Throws\\ThrowsModule'))->toBeFalse();
});

it('discovers composer-shipped module roots from real package manifests without using the installed vendor tree', function (): void {
    $originalBasePath = base_path();
    $root = $this->moduleSafetyRoot.'/composer-fixture';
    $loader = new ExternalModuleLoader($this->moduleSafetyManager);
    $this->app->setBasePath($root);
    try {
        $loader->loadFromComposer();
        foreach (['valid', 'missing', 'invalid', 'empty'] as $package) {
            $directory = $root.'/vendor/synthetic/'.$package;
            $namespace = $package === 'valid' ? $this->moduleSafetyNamespace : 'Modules';
            $name = 'Package'.ucfirst($package);
            moduleSafetyFixture($directory.'/modules/'.$name, $namespace.'\\'.$name, $name);
            if ($package !== 'missing') {
                File::put($directory.'/composer.json', match ($package) {
                    'valid' => json_encode(['autoload' => ['psr-4' => [$namespace.'\\' => 'modules/']]], JSON_THROW_ON_ERROR),
                    'invalid' => 'invalid-json', default => '{}',
                });
            }
        }
        $loader->loadFromComposer();
        expect($this->moduleSafetyManager->all())->toHaveCount(4)
            ->and($this->moduleSafetyManager->has('PackageValid'))->toBeTrue()
            ->and($loader->getLoadedPaths())->toHaveCount(4);
    } finally {
        $this->app->setBasePath($originalBasePath);
        $this->app->useAppPath($this->moduleSafetyRoot);
        $this->app->usePublicPath($this->moduleSafetyRoot.'/public');
    }
});

it('rejects cycles and transitive disabled dependencies before running module enable hooks', function (): void {
    $factory = function (string $name, array $dependencies, bool $enabled): ModuleInterface {
        return new class($name, $dependencies, $enabled) implements ModuleInterface
        {
            public int $calls = 0;

            public function __construct(private string $name, private array $dependencies, private bool $enabled) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getVersion(): string
            {
                return '1';
            }

            public function getDescription(): string
            {
                return 'Synthetic dependency graph';
            }

            public function getDependencies(): array
            {
                return $this->dependencies;
            }

            public function isEnabled(): bool
            {
                return $this->enabled;
            }

            public function getConfig(): array
            {
                return [];
            }

            public function enable(): void
            {
                $this->calls++;
                $this->enabled = true;
            }

            public function disable(): void
            {
                $this->calls++;
                $this->enabled = false;
            }

            public function install(): void
            {
                $this->calls++;
            }

            public function uninstall(): void
            {
                $this->calls++;
            }
        };
    };
    $a = $factory('CycleA', ['CycleB'], true);
    $b = $factory('CycleB', ['CycleA'], true);
    $this->moduleSafetyManager->register($a);
    $this->moduleSafetyManager->register($b);
    expect(fn () => $this->moduleSafetyManager->enable('CycleA'))->toThrow(RuntimeException::class, 'unmet dependencies')
        ->and($a->calls)->toBe(0);
    $this->moduleSafetyManager->register($factory('Leaf', [], false));
    $this->moduleSafetyManager->register($factory('Middle', ['Leaf'], true));
    $this->moduleSafetyManager->register($factory('Top', ['Middle'], false));
    expect(fn () => $this->moduleSafetyManager->install('Top'))->toThrow(RuntimeException::class, 'unmet dependencies');
    expect($this->moduleSafetyManager->checkModuleHealth('Middle')['healthy'])->toBeFalse();
});

it('discards poisoned module cache entries instead of trusting nonmodule objects', function (): void {
    config(['modules.cache' => true, 'modules.cache_key' => 'synthetic-module-safety-cache', 'modules.development' => false]);
    Cache::put('synthetic-module-safety-cache', ['poisoned' => new stdClass()]);
    expect((new ModuleManager())->all())->toBeEmpty()->and(Cache::get('synthetic-module-safety-cache'))->toBe([]);
});

it('does not accept a failed migration or rollback as successful module installation or removal', function (string $operation, string $command): void {
    $name = 'Safety'.str_replace('-', '', (string) Str::uuid());
    $path = $this->moduleSafetyRoot.'/Modules/'.$name;
    $class = moduleSafetyFixture($path, 'App\\Modules\\'.$name, $name);
    File::ensureDirectoryExists($path.'/database/migrations');
    $module = new $class();
    $this->moduleSafetyManager->register($module);
    Artisan::shouldReceive('call')->once()->with($command, ['--path' => 'app/Modules/'.$name.'/database/migrations', '--force' => true])->andReturn(1);
    expect(fn () => $this->moduleSafetyManager->{$operation}($name))->toThrow(RuntimeException::class, 'did not complete successfully');
    expect(Module::where('name', $name)->exists())->toBeFalse()
        ->and($module->operations)->not->toContain($operation);
})->with([['install', 'migrate'], ['uninstall', 'migrate:rollback']]);

it('refuses migrations from a sibling whose path merely starts with the permitted module name', function (): void {
    $name = 'Safety'.str_replace('-', '', (string) Str::uuid());
    File::ensureDirectoryExists($this->moduleSafetyRoot.'/Modules/'.$name);
    $outside = $this->moduleSafetyRoot.'/Modules/'.$name.'Outside';
    $class = moduleSafetyFixture($outside, $this->moduleSafetyNamespace, $name);
    File::ensureDirectoryExists($outside.'/database/migrations');
    Artisan::shouldReceive('call')->never();
    $module = new $class();
    expect(fn () => $module->install())->toThrow(RuntimeException::class, 'Invalid module path')
        ->and(fn () => $module->uninstall())->toThrow(RuntimeException::class, 'Invalid module path');
});

it('keeps a failed enable from persisting enabled state and still publishes guarded module lifecycle hooks', function (): void {
    $name = 'Safety'.str_replace('-', '', (string) Str::uuid());
    $class = moduleSafetyFixture($this->moduleSafetyRoot.'/Modules/'.$name, 'App\\Modules\\'.$name, $name, ['fail_enable' => true]);
    $module = new $class();
    $this->moduleSafetyManager->register($module);
    expect(fn () => $this->moduleSafetyManager->enable($name))->toThrow(RuntimeException::class, 'synthetic-enable-failure')
        ->and($module->operations)->toBe(['enable'])->and($module->isEnabled())->toBeFalse();
    expect(Module::where('name', $name)->exists())->toBeFalse();
});

it('registers enabled routes views translations configuration and publishing while keeping disabled routes absent', function (): void {
    $enabled = 'Safety'.str_replace('-', '', (string) Str::uuid());
    $disabled = 'Disabled'.str_replace('-', '', (string) Str::uuid());
    foreach ([$enabled, $disabled] as $name) {
        $root = $this->moduleSafetyRoot.'/Modules/'.$name;
        foreach (['routes', 'config', 'resources/views', 'resources/lang/en', 'resources/assets', 'database/migrations'] as $directory) {
            File::ensureDirectoryExists($root.'/'.$directory);
        }
        File::put($root.'/config/safety.php', '<?php return ["flag" => "synthetic-value"];');
        File::put($root.'/routes/web.php', '<?php \\Illuminate\\Support\\Facades\\Route::get("/synthetic-safety/'.$name.'", fn () => "synthetic-route");');
        File::put($root.'/resources/views/safety.blade.php', 'Synthetic module view');
        File::put($root.'/resources/lang/en/messages.php', '<?php return ["safe" => "Synthetic translation"];');
        Module::create(['name' => $name, 'enabled' => $name === $enabled]);
    }
    $provider = new ModuleServiceProvider($this->app);
    $provider->register();
    $provider->boot();
    expect(Route::getRoutes()->match(Request::create('/synthetic-safety/'.$enabled))->uri())->toBe('synthetic-safety/'.$enabled)
        ->and(config(Str::snake($enabled).'.safety.flag'))->toBe('synthetic-value')
        ->and(view(Str::snake($enabled).'::safety')->render())->toBe('Synthetic module view')
        ->and(trans(Str::snake($enabled).'::messages.safe'))->toBe('Synthetic translation');
    expect(fn () => Route::getRoutes()->match(Request::create('/synthetic-safety/'.$disabled)))
        ->toThrow(NotFoundHttpException::class);
    expect(ModuleServiceProvider::pathsToPublish(ModuleServiceProvider::class, Str::snake($enabled).'-assets'))
        ->toHaveKey($this->moduleSafetyRoot.'/Modules/'.$enabled.'/resources/assets');
});
