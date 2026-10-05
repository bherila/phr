<?php

// Call only the existing Laravel up/down command, using private bootstrap caches.
// The caller has already verified this immutable config copy with the shared audit.
declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\AliasLoader;

try {
    [, $mode, $private] = $argv;
    if (! in_array($mode, ['up', 'down', 'prove-down', 'prove-up'], true)
        || PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 5) {
        exit(1);
    }
    ob_start();
    foreach (['CONFIG', 'SERVICES', 'PACKAGES', 'ROUTES', 'EVENTS'] as $kind) {
        $path = $private.'/'.strtolower($kind).'.php';
        putenv('APP_'.$kind.'_CACHE='.$path);
        $_ENV['APP_'.$kind.'_CACHE'] = $_SERVER['APP_'.$kind.'_CACHE'] = $path;
    }
    require getcwd().'/vendor/autoload.php';
    // Generated real-time facade files also belong in the private scratch area.
    class ResumeAliasLoader extends AliasLoader
    {
        public function __construct(array $aliases, private string $private)
        {
            $this->aliases = $aliases;
        }

        protected function ensureFacadeExists($alias)
        {
            if (! is_string($alias) || ! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z0-9_]+)*\z/', $alias)) {
                throw new RuntimeException;
            }
            $framework = (new ReflectionClass(AliasLoader::class))->getFileName();
            $stub = file_get_contents(dirname($framework).'/stubs/facade.stub');
            $stub = $this->formatFacadeStub($alias, $stub);
            $path = $this->private.'/facade-'.sha1($alias).'.php';
            if (file_put_contents($path, $stub) !== strlen($stub)) {
                throw new RuntimeException;
            }

            return $path;
        }
    }
    $old = AliasLoader::getInstance();
    foreach (spl_autoload_functions() ?: [] as $loader) {
        if ((is_array($loader) && ($loader[0] ?? null) === $old && ($loader[1] ?? null) === 'load')
            || ($loader instanceof Closure && (new ReflectionFunction($loader))->getClosureThis() === $old)) {
            if (! spl_autoload_unregister($loader)) {
                throw new RuntimeException;
            }
        }
    }
    AliasLoader::setInstance(new ResumeAliasLoader($old->getAliases(), $private));
    $app = require getcwd().'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    // Only file maintenance can be resumed here; never mutate a cache/database driver.
    if ($app['config']->get('app.maintenance.driver', 'file') !== 'file') {
        throw new RuntimeException;
    }
    if (in_array($mode, ['up', 'down'], true) && $kernel->call($mode, ['--no-interaction' => true]) !== 0) {
        throw new RuntimeException;
    }
    $down = $app->isDownForMaintenance();
    if ($down !== in_array($mode, ['down', 'prove-down'], true)) {
        throw new RuntimeException;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    echo 'phr-resume framework='.($down ? 'maintenance' : 'serving')."\n";
} catch (Throwable) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    exit(1);
}
