<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use Zephyrus\Core\Config\RouterConfig;
use Zephyrus\Tests\Unit\Core\Cache\CacheDirectory;

/**
 * A throwaway application root with a config.yml, controllers under app/Controllers and their parents under lib/,
 * in a namespace of its own so that every test can declare classes.
 */
final class ControllerTree
{
    public readonly CacheDirectory $root;
    public readonly string $namespace;
    private readonly string $baseNamespace;
    private readonly \Closure $autoloader;

    public function __construct()
    {
        $this->root = new CacheDirectory();
        $this->baseNamespace = 'RouteTree' . bin2hex(random_bytes(6));
        $this->namespace = $this->baseNamespace . '\Controllers';
        $this->autoloader = function (string $class): void {
            foreach (['Controllers' => 'app/Controllers', 'Base' => 'lib'] as $segment => $directory) {
                $prefix = $this->baseNamespace . '\\' . $segment . '\\';
                if (str_starts_with($class, $prefix)) {
                    $file = $this->root->path . '/' . $directory . '/'
                        . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                    if (is_file($file)) {
                        require $file;
                    }
                }
            }
        };
        spl_autoload_register($this->autoloader);
    }

    /**
     * Write a controller class; its source may name Base\<Class> for a parent written with parent().
     */
    public function controller(string $shortName, string $source): string
    {
        return $this->root->file('app/Controllers/' . $shortName . '.php', $this->php($this->namespace, $source));
    }

    public function parent(string $shortName, string $source): string
    {
        return $this->root->file('lib/' . $shortName . '.php', $this->php($this->baseNamespace . '\Base', $source));
    }

    /**
     * @param array<string, list<string>> $middlewareGroups
     */
    public function configure(array $middlewareGroups = []): RouterConfig
    {
        $groups = '';
        foreach ($middlewareGroups as $name => $middlewares) {
            $groups .= sprintf("    %s: [%s]\n", $name, implode(', ', $middlewares));
        }

        $this->root->file('config.yml', sprintf(
            "router:\n  controllers:\n    %s: app/Controllers\n%s",
            $this->namespace,
            $groups === '' ? '' : "  middleware_groups:\n" . $groups,
        ));

        return RouterConfig::fromArray([
            'controllers' => [$this->namespace => 'app/Controllers'],
            'middleware_groups' => $middlewareGroups,
        ]);
    }

    public function remove(): void
    {
        spl_autoload_unregister($this->autoloader);
        $this->root->remove();
    }

    private function php(string $namespace, string $source): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace " . $namespace . ";\n\n"
            . 'use ' . $this->baseNamespace . "\\Base;\n"
            . "use Zephyrus\\Routing\\Attribute\\Get;\n"
            . "use Zephyrus\\Routing\\Attribute\\MiddlewareGroup;\n"
            . "use Zephyrus\\Routing\\Attribute\\RequiresEnv;\n\n"
            . $source . "\n";
    }
}
