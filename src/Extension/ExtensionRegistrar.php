<?php

declare(strict_types=1);

namespace DolibarrMcp\Extension;

use DolibarrMcp\Container;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\Builder;

/**
 * Registers the tools of ToolExtension instances on the server builder.
 *
 * A faulty extension must never take the server down: a tool that breaks a
 * rule is skipped and reported in rejected(), the others are registered.
 */
final class ExtensionRegistrar
{
    public const RESERVED_PREFIX = 'dolibarr_';
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{2,63}$/';

    /** @var array<string, string> tool name => extension name */
    private array $registered = [];

    /** @var array<int, string> */
    private array $rejected = [];

    /**
     * The SDK only accepts [ClassName::class, 'method'] handlers and resolves the
     * instance through the container, so each provider object is put there under
     * its class name.
     *
     * @param iterable<mixed> $extensions
     */
    public function register(Builder $builder, Container $container, iterable $extensions): void
    {
        foreach ($extensions as $extension) {
            if (!$extension instanceof ToolExtension) {
                $this->rejected[] = sprintf('%s does not implement %s', get_debug_type($extension), ToolExtension::class);
                continue;
            }
            foreach ($extension->toolProviders() as $provider) {
                if (!is_object($provider)) {
                    $this->rejected[] = sprintf('%s: tool provider %s is not an object', $extension->name(), get_debug_type($provider));
                    continue;
                }
                $this->registerProvider($builder, $container, $extension->name(), $provider);
            }
        }
    }

    /**
     * @return array<string, string> tool name => extension name
     */
    public function registered(): array
    {
        return $this->registered;
    }

    /**
     * @return array<int, string>
     */
    public function rejected(): array
    {
        return $this->rejected;
    }

    private function registerProvider(Builder $builder, Container $container, string $extensionName, object $provider): void
    {
        $class = new \ReflectionObject($provider);
        if ($class->isAnonymous()) {
            $this->rejected[] = sprintf('%s: anonymous classes cannot provide tools', $extensionName);
            return;
        }
        if ($container->has($class->getName())) {
            $this->rejected[] = sprintf('%s: class %s is already registered by another provider', $extensionName, $class->getName());
            return;
        }
        $providerUsed = false;
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }
            foreach ($method->getAttributes(McpTool::class) as $attribute) {
                /** @var McpTool $tool */
                $tool = $attribute->newInstance();
                $name = $tool->name ?? $method->getName();

                $problem = $this->problemWith($name);
                if ($problem !== null) {
                    $this->rejected[] = sprintf('%s: tool "%s" (%s::%s) %s', $extensionName, $name, $class->getName(), $method->getName(), $problem);
                    continue;
                }

                if (!$providerUsed) {
                    $container->set($class->getName(), fn() => $provider);
                    $providerUsed = true;
                }
                $builder->addTool(
                    [$class->getName(), $method->getName()],
                    $name,
                    $tool->title,
                    $tool->description,
                    $tool->annotations,
                    null,
                    $tool->icons,
                    $tool->meta,
                    $tool->outputSchema,
                );
                $this->registered[$name] = $extensionName;
            }
        }
    }

    private function problemWith(string $name): ?string
    {
        if (!preg_match(self::NAME_PATTERN, $name)) {
            return 'has an invalid name (lowercase letters, digits and underscores, 3 to 64 characters)';
        }
        if (str_starts_with($name, self::RESERVED_PREFIX)) {
            return 'uses the reserved prefix "' . self::RESERVED_PREFIX . '"';
        }
        if (isset($this->registered[$name])) {
            return 'is already provided by extension "' . $this->registered[$name] . '"';
        }

        return null;
    }
}
