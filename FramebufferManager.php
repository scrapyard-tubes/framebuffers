<?php

namespace ScrapyardIO\Tubes\Framebuffers;

use ScrapyardIO\Tubes\Contracts\Framebuffers\BufferFactory;
use ScrapyardIO\Tubes\Contracts\Framebuffers\DeferredFramebuffer as DeferredFramebufferContract;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\FramebufferDriver;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\FramebufferKind;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Framebuffer;
use ScrapyardIO\Tubes\Contracts\Framebuffers\FramebufferException;
use ScrapyardIO\Tubes\Contracts\Framebuffers\ManagedFramebuffer as ManagedFramebufferContract;

class FramebufferManager implements BufferFactory
{
    /**
     * @var array<string, callable(PendingFramebuffer): Framebuffer>
     */
    protected array $managed = [];

    /**
     * @var array<string, callable(PendingFramebuffer): Framebuffer>
     */
    protected array $deferred = [];

    /**
     * @var array<string, string> driver => PHP extension name
     */
    protected array $extensions = [];

    /**
     * @var array<string, class-string> driver => concrete class for existence checks
     */
    protected array $classes = [];

    protected string $defaultDriver = 'full';

    /**
     * @param  array<string, mixed>  $config  framebuffers.php shape
     */
    public function __construct(array $config = [])
    {
        $this->defaultDriver = $this->resolveConfiguredDefault('framebuffer', $this->defaultDriver);

        $this->registerBuiltIns();

        if ($config !== []) {
            $this->registerFromConfig($config);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function registerFromConfig(array $config): static
    {
        if (isset($config['default']) && is_string($config['default']) && $config['default'] !== '') {
            $this->defaultDriver = strtolower($config['default']);
        }

        $drivers = $config['drivers'] ?? [];

        if (is_array($drivers)) {
            foreach ($drivers as $name => $definition) {
                if (! is_string($name) || ! is_array($definition)) {
                    continue;
                }

                $this->registerDriverDefinition($name, $definition);
            }
        }

        return $this;
    }

    /**
     * Merge published config/framebuffers/<slug>.php files into the registry.
     */
    public function registerFromConfigDirectory(string $directory): static
    {
        if (! is_dir($directory)) {
            return $this;
        }

        $files = glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.php') ?: [];

        foreach ($files as $file) {
            $slug = strtolower(basename($file, '.php'));
            $definition = require $file;

            if (! is_array($definition)) {
                continue;
            }

            if (! isset($definition['kind']) && isset($definition['driver'])) {
                // Allow wrapping: return ['driver' => 'sdl3', 'kind' => …]
                $slug = strtolower((string) $definition['driver']);
            }

            $this->registerDriverDefinition($slug, $definition);
        }

        return $this;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function registerDriverDefinition(string $name, array $definition): static
    {
        $key = $this->normalize($name);
        $kind = strtolower((string) ($definition['kind'] ?? 'managed'));
        $class = $definition['class'] ?? null;
        $extension = $definition['extension'] ?? null;

        if (is_string($extension) && $extension !== '') {
            $this->extensions[$key] = $extension;
        }

        if (is_string($class) && $class !== '') {
            $this->classes[$key] = $class;

            if (! class_exists($class)) {
                return $this;
            }

            if ($kind === 'deferred') {
                $this->extendDeferred($key, $this->sizedCreator($class));
            } else {
                $this->extendManaged($key, $class);
            }
        }

        return $this;
    }

    public function defaultDriver(): string
    {
        return $this->defaultDriver;
    }

    public function make(?string $driver = null): PendingFramebuffer
    {
        return $this->driver($driver);
    }

    public function driver(FramebufferDriver|string|null $driver = null): PendingFramebuffer
    {
        $name = $this->normalize($driver ?? $this->defaultDriver);
        $kind = $this->kindOf($name);

        if (is_null($kind)) {
            throw new FramebufferException("Framebuffer strategy [{$name}] is not defined.");
        }

        return new PendingFramebuffer($this, $name, $kind);
    }

    public function managed(FramebufferDriver|string $driver): PendingFramebuffer
    {
        $name = $this->normalize($driver);

        if (! isset($this->managed[$name])) {
            throw new FramebufferException("Managed framebuffer strategy [{$name}] is not defined.");
        }

        return new PendingFramebuffer($this, $name, FramebufferKind::MANAGED);
    }

    public function deferred(string $driver): PendingFramebuffer
    {
        $name = $this->normalize($driver);

        if (! isset($this->deferred[$name])) {
            throw new FramebufferException("Deferred framebuffer strategy [{$name}] is not defined.");
        }

        return new PendingFramebuffer($this, $name, FramebufferKind::DEFERRED);
    }

    public function full(): PendingFramebuffer
    {
        return $this->managed(FramebufferDriver::FULL);
    }

    public function dirty(): PendingFramebuffer
    {
        return $this->managed(FramebufferDriver::DIRTY);
    }

    public function page(): PendingFramebuffer
    {
        return $this->managed(FramebufferDriver::PAGE);
    }

    public function extendManaged(string $name, callable|string $creator): static
    {
        $key = $this->normalize($name);

        if (isset($this->deferred[$key])) {
            throw new FramebufferException(
                "Framebuffer strategy [{$key}] is already registered as deferred."
            );
        }

        if (is_string($creator)) {
            if (! is_a($creator, ManagedFramebufferContract::class, true)) {
                throw new FramebufferException(
                    "Managed framebuffer class [{$creator}] must implement ManagedFramebuffer."
                );
            }

            if (! method_exists($creator, 'sized')) {
                throw new FramebufferException(
                    "Managed framebuffer class [{$creator}] must define a static sized() factory."
                );
            }

            $this->classes[$key] = $creator;
            $this->managed[$key] = $this->sizedCreator($creator);

            return $this;
        }

        $this->managed[$key] = $creator;

        return $this;
    }

    public function extendDeferred(string $name, callable|string $creator): static
    {
        $key = $this->normalize($name);

        if (isset($this->managed[$key])) {
            throw new FramebufferException(
                "Framebuffer strategy [{$key}] is already registered as managed."
            );
        }

        if (is_string($creator)) {
            if (! is_a($creator, DeferredFramebufferContract::class, true)) {
                throw new FramebufferException(
                    "Deferred framebuffer class [{$creator}] must implement DeferredFramebuffer."
                );
            }

            if (! method_exists($creator, 'sized')) {
                throw new FramebufferException(
                    "Deferred framebuffer class [{$creator}] must define a static sized() factory."
                );
            }

            $this->classes[$key] = $creator;
            $this->deferred[$key] = $this->sizedCreator($creator);

            return $this;
        }

        $this->deferred[$key] = $creator;

        return $this;
    }

    public function listFramebuffers(?FramebufferKind $kind = null): array
    {
        $names = match ($kind) {
            FramebufferKind::MANAGED => array_keys($this->managed),
            FramebufferKind::DEFERRED => array_keys($this->deferred),
            null => array_merge(array_keys($this->managed), array_keys($this->deferred)),
        };

        sort($names);

        return array_values(array_unique($names));
    }

    public function kindOf(FramebufferDriver|string $driver): ?FramebufferKind
    {
        $name = $this->normalize($driver);

        if (isset($this->managed[$name])) {
            return FramebufferKind::MANAGED;
        }

        if (isset($this->deferred[$name])) {
            return FramebufferKind::DEFERRED;
        }

        return null;
    }

    /**
     * @throws FramebufferException
     */
    public function createFromPending(PendingFramebuffer $pending): Framebuffer
    {
        $name = $pending->driver();

        $this->assertDriverReady($name);

        $creator = match ($pending->kind()) {
            FramebufferKind::MANAGED => $this->managed[$name] ?? null,
            FramebufferKind::DEFERRED => $this->deferred[$name] ?? null,
        };

        if (is_null($creator)) {
            throw new FramebufferException("Framebuffer strategy [{$name}] is not defined.");
        }

        $framebuffer = $creator($pending);

        if (! ($framebuffer instanceof Framebuffer)) {
            throw new FramebufferException(
                "Framebuffer creator [{$name}] must return a Framebuffer instance."
            );
        }

        return $framebuffer;
    }

    /**
     * @throws FramebufferException
     */
    protected function assertDriverReady(string $name): void
    {
        if (isset($this->classes[$name]) && ! class_exists($this->classes[$name])) {
            throw new FramebufferException(
                "Framebuffer class [{$this->classes[$name]}] for strategy [{$name}] is not installed."
            );
        }

        if (isset($this->extensions[$name]) && ! extension_loaded($this->extensions[$name])) {
            throw new FramebufferException(
                "PHP extension [{$this->extensions[$name]}] is required for framebuffer strategy [{$name}]."
            );
        }
    }

    /**
     * @param  class-string  $class
     * @return callable(PendingFramebuffer): Framebuffer
     */
    protected function sizedCreator(string $class): callable
    {
        return static function (PendingFramebuffer $pending) use ($class): Framebuffer {
            $width = $pending->widthValue();
            $height = $pending->heightValue();
            $format = $pending->hostFormatValue();
            $layers = $pending->layersValue();

            $paramCount = (new \ReflectionMethod($class, 'sized'))->getNumberOfParameters();

            if ($paramCount >= 4) {
                return $class::sized($width, $height, $format, $layers);
            }

            return $class::sized($width, $height, $format);
        };
    }

    protected function registerBuiltIns(): void
    {
        $this->extendManaged(FramebufferDriver::FULL->value, FullFramebuffer::class);
        $this->extendManaged(FramebufferDriver::DIRTY->value, DirtyRegionsBuffer::class);
        $this->extendManaged(FramebufferDriver::PAGE->value, PageSegmentBuffer::class);
    }

    protected function resolveConfiguredDefault(string $alias, string $fallback): string
    {
        if (function_exists('config')) {
            $fromTubes = config("tubes.defaults.{$alias}");
            if (is_string($fromTubes) && $fromTubes !== '') {
                return strtolower($fromTubes);
            }
        }

        return strtolower($fallback);
    }

    /**
     * @param  FramebufferDriver|non-empty-string  $driver
     * @return non-empty-string
     */
    protected function normalize(FramebufferDriver|string $driver): string
    {
        $name = $driver instanceof FramebufferDriver ? $driver->value : strtolower($driver);

        if ($name === '') {
            throw new FramebufferException('Framebuffer strategy name must be non-empty.');
        }

        return $name;
    }
}
