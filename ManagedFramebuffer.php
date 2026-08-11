<?php

namespace ScrapyardIO\Tubes\Framebuffers;

use ScrapyardIO\Tubes\Contracts\Framebuffers\FormatSpec;
use ScrapyardIO\Tubes\Contracts\Framebuffers\ManagedFramebuffer as ManagedFramebufferContract;
use ScrapyardIO\Tubes\Contracts\Framebuffers\PixelStore as PixelStoreContract;
use ScrapyardIO\Tubes\Framebuffers\Support\PixelColorPack;

/**
 * Software-owned framebuffer: PixelStore + dirty/flush policy.
 */
abstract class ManagedFramebuffer extends Framebuffer implements ManagedFramebufferContract
{
    public function __construct(
        protected PixelStoreContract $pixel_store,
    ) {
        parent::__construct(
            $pixel_store->width(),
            $pixel_store->height(),
            $pixel_store->hostFormat(),
        );
    }

    public function pixelStore(): PixelStoreContract
    {
        return $this->pixel_store;
    }

    /**
     * Build a managed buffer over a freshly allocated host store.
     */
    public static function sized(
        int $width,
        int $height,
        FormatSpec $host_format,
        int $z = 1,
    ): static {
        return new static(new PixelStore($width, $height, $host_format, $z));
    }

    public function getPixel(int $x, int $y): int
    {
        return $this->pixel_store->getPixel($x, $y);
    }

    public function setPixel(int $x, int $y, int $value): static
    {
        $this->pixel_store->setPixel($x, $y, $value);

        return $this;
    }

    public function setSegment(int $x, int $y, int $width, int $height, int $color): static
    {
        $this->pixel_store->setSegment($x, $y, $width, $height, $color);

        return $this;
    }

    public function clear(): static
    {
        $this->pixel_store->clear();

        return $this;
    }

    public function fill(int $color): static
    {
        $this->pixel_store->fill($color);

        return $this;
    }

    public function dump(?int $layer = null): string
    {
        return $this->pixel_store->dump($layer);
    }

    /**
     * Alias for {@see hostFormat()} — 0.6 FormatSpecFramebuffer naming.
     */
    public function formatSpec(): FormatSpec
    {
        return $this->hostFormat();
    }

    abstract public function markAllDirty(): static;

    /**
     * Managed software canvases retain pixels across flush by default.
     */
    public function preservesContentsOnPresent(): bool
    {
        return true;
    }

    /**
     * Canvas dump contract: host FormatSpec == flush target → return host bytes
     * unchanged (Window or PanelIC). Convert only when specs differ.
     */
    protected function bytesForSpec(FormatSpec $spec): string
    {
        if ($this->formatSpecsMatch($this->hostFormat(), $spec)) {
            return $this->dump();
        }

        return $this->transcodeEntireSurface($spec);
    }

    protected function formatSpecsMatch(FormatSpec $left, FormatSpec $right): bool
    {
        return ($left->pixel_format === $right->pixel_format)
            && ($left->bit_depth === $right->bit_depth)
            && ($left->scan_direction === $right->scan_direction)
            && ($left->bit_order === $right->bit_order)
            && ($left->endianness === $right->endianness)
            && ($left->page_axis === $right->page_axis)
            && ($left->palette === $right->palette);
    }

    /**
     * Foreign flush only — never used when Canvas FormatSpec matches the FB host.
     */
    protected function transcodeEntireSurface(FormatSpec $target): string
    {
        $width = $this->viewportWidth();
        $height = $this->viewportHeight();
        $host = $this->hostFormat();
        $temp = new PixelStore($width, $height, $target, 1);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $temp->putPacked(
                    $x,
                    $y,
                    PixelColorPack::convert($this->getPixel($x, $y), $host, $target),
                );
            }
        }

        return $temp->dump();
    }

    /**
     * Copy a rectangular region into a new store (same host format) and dump it.
     */
    protected function dumpRegion(int $x, int $y, int $width, int $height): string
    {
        return $this->dumpRegionForSpec($x, $y, $width, $height, $this->hostFormat());
    }

    /**
     * Region dump for flush.
     *
     * Matching FormatSpec → host bytes only (ROW_MAJOR memcpy when possible).
     * Mismatch → PixelColorPack (engine / foreign flush only).
     */
    protected function dumpRegionForSpec(
        int $x,
        int $y,
        int $width,
        int $height,
        FormatSpec $target,
    ): string {
        if (($width < 1) || ($height < 1)) {
            return '';
        }

        $host = $this->hostFormat();

        if ($this->formatSpecsMatch($host, $target)) {
            if ($this->pixel_store instanceof PixelStore) {
                $fast = $this->pixel_store->dumpRowMajorRegion($x, $y, $width, $height);
                if (is_string($fast)) {
                    return $fast;
                }
            }

            // Match but non-ROW_MAJOR / unsupported fast path: still no colour convert —
            // copy host-native pixel values into a same-spec temp store.
            $temp = new PixelStore($width, $height, $host, 1);

            for ($row = 0; $row < $height; $row++) {
                for ($col = 0; $col < $width; $col++) {
                    $temp->putPacked($col, $row, $this->getPixel($x + $col, $y + $row));
                }
            }

            return $temp->dump();
        }

        $temp = new PixelStore($width, $height, $target, 1);

        for ($row = 0; $row < $height; $row++) {
            for ($col = 0; $col < $width; $col++) {
                $temp->putPacked(
                    $col,
                    $row,
                    PixelColorPack::convert($this->getPixel($x + $col, $y + $row), $host, $target),
                );
            }
        }

        return $temp->dump();
    }
}
