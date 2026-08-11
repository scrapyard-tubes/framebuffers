<?php

namespace ScrapyardIO\Tubes\Framebuffers;

use ScrapyardIO\Tubes\Contracts\Framebuffers\DamageGranularity;
use ScrapyardIO\Tubes\Contracts\Framebuffers\FormatSpec;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Framebuffer as FramebufferContract;

/**
 * Engine-agnostic draw / blit / damage surface.
 *
 * Does not own a {@see PixelStore} — Managed children do; Deferred children
 * keep pixels in the host library. Batch helpers call abstract pixel ops.
 */
abstract class Framebuffer implements FramebufferContract
{
    public function __construct(
        protected int $width,
        protected int $height,
        protected FormatSpec $host_format,
    ) {}

    public function viewportWidth(): int
    {
        return $this->width;
    }

    public function viewportHeight(): int
    {
        return $this->height;
    }

    public function hostFormat(): FormatSpec
    {
        return $this->host_format;
    }

    abstract public function getPixel(int $x, int $y): int;

    abstract public function setPixel(int $x, int $y, int $value): static;

    abstract public function setSegment(int $x, int $y, int $width, int $height, int $color): static;

    abstract public function dump(?int $layer = null): string;

    abstract public function flush(FormatSpec $spec, bool $as_array = false): string|array;

    public function setPixels(array $pixels): static
    {
        foreach ($pixels as [$x, $y, $value]) {
            $this->setPixel($x, $y, $value);
        }

        return $this;
    }

    public function setRegion(array $coordinates, int $value): static
    {
        foreach ($coordinates as [$x, $y]) {
            $this->setPixel($x, $y, $value);
        }

        return $this;
    }

    public function clear(): static
    {
        return $this->fill(0);
    }

    public function fill(int $color): static
    {
        return $this->setSegment(0, 0, $this->viewportWidth(), $this->viewportHeight(), $color);
    }

    public function blitFrom(FramebufferContract $source, int $offset_x = 0, int $offset_y = 0): FramebufferContract
    {
        for ($y = 0; $y < $source->viewportHeight(); $y++) {
            for ($x = 0; $x < $source->viewportWidth(); $x++) {
                $this->setPixel($offset_x + $x, $offset_y + $y, $source->getPixel($x, $y));
            }
        }

        return $this;
    }

    public function blitTo(FramebufferContract $target, int $offset_x = 0, int $offset_y = 0): FramebufferContract
    {
        return $target->blitFrom($this, $offset_x, $offset_y);
    }

    /**
     * Conservative default: any damage costs the whole surface.
     */
    public function damageGranularity(): DamageGranularity
    {
        return DamageGranularity::wholeSurface(
            $this->viewportWidth(),
            $this->viewportHeight(),
        );
    }

    /**
     * Conservative default: do not trust retained pixels across present.
     */
    public function preservesContentsOnPresent(): bool
    {
        return false;
    }
}
