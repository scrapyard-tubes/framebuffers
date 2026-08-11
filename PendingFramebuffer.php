<?php

namespace ScrapyardIO\Tubes\Framebuffers;

use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\FramebufferKind;
use ScrapyardIO\Tubes\Contracts\Framebuffers\FormatSpec;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Framebuffer;
use ScrapyardIO\Tubes\Contracts\Framebuffers\FramebufferException;

/**
 * Fluent builder for a registered framebuffer driver.
 *
 * Usage:
 *   Framebuffer::driver('full')->size(128, 64)->format($spec)->layers(1)->create()
 */
class PendingFramebuffer
{
    /**
     * @var array<string, mixed>
     */
    protected array $options = [];

    protected ?int $width = null;

    protected ?int $height = null;

    protected ?FormatSpec $host_format = null;

    protected int $z = 1;

    /**
     * @param  non-empty-string  $driver
     */
    public function __construct(
        protected FramebufferManager $manager,
        protected string $driver,
        protected FramebufferKind $kind,
    ) {}

    public function driver(): string
    {
        return $this->driver;
    }

    public function kind(): FramebufferKind
    {
        return $this->kind;
    }

    public function size(int $width, int $height): static
    {
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    public function width(int $width): static
    {
        $this->width = $width;

        return $this;
    }

    public function height(int $height): static
    {
        $this->height = $height;

        return $this;
    }

    public function format(FormatSpec $host_format): static
    {
        return $this->hostFormat($host_format);
    }

    public function hostFormat(FormatSpec $host_format): static
    {
        $this->host_format = $host_format;

        return $this;
    }

    /**
     * Soft compositing layers (PixelStore Z). Deferred drivers may ignore.
     */
    public function layers(int $z): static
    {
        $this->z = $z;

        return $this;
    }

    /**
     * Extra bag for deferred / custom creators (window handle, renderer, …).
     *
     * @param  array<string, mixed>  $options
     */
    public function options(array $options): static
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    public function option(string $key, mixed $value): static
    {
        $this->options[$key] = $value;

        return $this;
    }

    /**
     * @throws FramebufferException
     */
    public function widthValue(): int
    {
        if (is_null($this->width) || ($this->width < 1)) {
            throw new FramebufferException('Framebuffer width is required and must be >= 1.');
        }

        return $this->width;
    }

    /**
     * @throws FramebufferException
     */
    public function heightValue(): int
    {
        if (is_null($this->height) || ($this->height < 1)) {
            throw new FramebufferException('Framebuffer height is required and must be >= 1.');
        }

        return $this->height;
    }

    /**
     * @throws FramebufferException
     */
    public function hostFormatValue(): FormatSpec
    {
        if (is_null($this->host_format)) {
            throw new FramebufferException('A host FormatSpec is required to create a framebuffer.');
        }

        return $this->host_format;
    }

    public function layersValue(): int
    {
        return max(1, $this->z);
    }

    /**
     * @return array<string, mixed>
     */
    public function optionsValue(): array
    {
        return $this->options;
    }

    /**
     * @throws FramebufferException
     */
    public function create(): Framebuffer
    {
        return $this->manager->createFromPending($this);
    }

    /**
     * Alias for {@see create()}.
     *
     * @throws FramebufferException
     */
    public function get(): Framebuffer
    {
        return $this->create();
    }
}
