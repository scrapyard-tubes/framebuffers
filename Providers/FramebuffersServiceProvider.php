<?php

namespace ScrapyardIO\Tubes\Framebuffers\Providers;

use Fabricate\NutsAndBolts\Contracts\DeferrableProvider;
use Fabricate\NutsAndBolts\ServiceProvider;
use ScrapyardIO\Tubes\Contracts\Framebuffers\BufferFactory;
use ScrapyardIO\Tubes\Framebuffers\FramebufferManager;

class FramebuffersServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/framebuffers.php',
            'framebuffers',
        );

        $this->container->singleton('framebuffer', function ($app) {
            $manager = new FramebufferManager(
                $app->bound('config') ? $app->make('config')->get('framebuffers', []) : [],
            );

            if (method_exists($app, 'configPath')) {
                $manager->registerFromConfigDirectory($app->configPath('framebuffers'));
            }

            return $manager;
        });

        $this->container->singleton(FramebufferManager::class, fn ($app) => $app->make('framebuffer'));

        $this->container->singleton(BufferFactory::class, fn ($app) => $app->make('framebuffer'));
    }

    public function boot(): void
    {
        if ($this->container->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/framebuffers.php' => $this->container->configPath('framebuffers.php'),
            ], 'tubes-framebuffers-config');
        }

        // Companions may also callAfterResolving('framebuffer', …) with extendDeferred().
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            'framebuffer',
            FramebufferManager::class,
            BufferFactory::class,
        ];
    }
}
