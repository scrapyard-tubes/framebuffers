<?php

namespace ScrapyardIO\Tubes\Framebuffers;

use RuntimeException;
use ScrapyardIO\Tubes\Contracts\Framebuffers\DamageGranularity;
use ScrapyardIO\Tubes\Contracts\Framebuffers\DumpedBuffer;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\PixelFormat;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\RenderType;
use ScrapyardIO\Tubes\Contracts\Framebuffers\FormatSpec;
use ScrapyardIO\Tubes\Contracts\Framebuffers\PixelStore as PixelStoreContract;

/**
 * Tracks dirty vertical pages; flush emits coalesced PARTIAL page runs.
 *
 * Host packing must be MONO_VERTICAL_PAGE (SSD1306-class).
 */
final class PageSegmentBuffer extends ManagedFramebuffer
{
    protected int $page_height = 8;

    /**
     * @var array<int, true>
     */
    protected array $dirty_pages = [];

    public function __construct(PixelStoreContract $pixel_store)
    {
        parent::__construct($pixel_store);
        $this->guardVerticalPage();
    }

    public function setPixel(int $x, int $y, int $value): static
    {
        $this->pixel_store->setPixel($x, $y, $value);

        if (
            ($x >= 0) && ($y >= 0)
            && ($x < $this->viewportWidth())
            && ($y < $this->viewportHeight())
        ) {
            $this->dirty_pages[intdiv($y, $this->page_height)] = true;
        }

        return $this;
    }

    public function setSegment(int $x, int $y, int $width, int $height, int $color): static
    {
        $this->pixel_store->setSegment($x, $y, $width, $height, $color);

        if (($width > 0) && ($height > 0)) {
            $start = intdiv(max(0, $y), $this->page_height);
            $end = intdiv(min($this->viewportHeight(), $y + $height) - 1, $this->page_height);

            for ($page = $start; $page <= $end; $page++) {
                $this->dirty_pages[$page] = true;
            }
        }

        return $this;
    }

    public function clear(): static
    {
        parent::clear();
        $this->markAllDirty();

        return $this;
    }

    public function fill(int $color): static
    {
        parent::fill($color);
        $this->markAllDirty();

        return $this;
    }

    public function markAllDirty(): static
    {
        $pages = intdiv($this->viewportHeight() + ($this->page_height - 1), $this->page_height);

        for ($page = 0; $page < $pages; $page++) {
            $this->dirty_pages[$page] = true;
        }

        return $this;
    }

    public function damageGranularity(): DamageGranularity
    {
        return DamageGranularity::rows(
            $this->page_height,
            $this->viewportWidth(),
            $this->viewportHeight(),
        );
    }

    /**
     * @return array<int, DumpedBuffer>
     */
    public function flush(FormatSpec $spec, bool $as_array = false): string|array
    {
        if ($this->dirty_pages === []) {
            return [];
        }

        if (! $this->formatSpecsMatch($this->hostFormat(), $spec)) {
            throw new RuntimeException(
                'PageSegmentBuffer flush FormatSpec must match the host store (page transcode not implemented).'
            );
        }

        $packed = $this->dump();
        $bytes_per_page = $this->viewportWidth();
        $pages = array_keys($this->dirty_pages);
        sort($pages);

        $updates = [];

        foreach ($this->coalescePageRuns($pages) as [$start_page, $end_page]) {
            $page_count = ($end_page - $start_page) + 1;
            $origin_y = $start_page * $this->page_height;
            $height = min(
                $page_count * $this->page_height,
                $this->viewportHeight() - $origin_y,
            );

            $updates[] = new DumpedBuffer(
                RenderType::PARTIAL,
                $spec,
                substr($packed, $start_page * $bytes_per_page, $page_count * $bytes_per_page),
                origin_x: 0,
                origin_y: $origin_y,
                width: $this->viewportWidth(),
                height: $height,
            );
        }

        $this->dirty_pages = [];

        return $updates;
    }

    /**
     * @param  list<int>  $pages
     * @return list<array{0: int, 1: int}>
     */
    protected function coalescePageRuns(array $pages): array
    {
        if ($pages === []) {
            return [];
        }

        $runs = [];
        $start = $pages[0];
        $end = $pages[0];

        foreach (array_slice($pages, 1) as $page) {
            if ($page === ($end + 1)) {
                $end = $page;
                continue;
            }

            $runs[] = [$start, $end];
            $start = $page;
            $end = $page;
        }

        $runs[] = [$start, $end];

        return $runs;
    }

    protected function guardVerticalPage(): void
    {
        if ($this->hostFormat()->pixel_format !== PixelFormat::MONO_VERTICAL_PAGE) {
            throw new RuntimeException(
                "PageSegmentBuffer only supports MONO_VERTICAL_PAGE hosts, got {$this->hostFormat()->pixel_format->value}."
            );
        }
    }
}
