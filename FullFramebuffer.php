<?php

namespace ScrapyardIO\Tubes\Framebuffers;

use ScrapyardIO\Tubes\Contracts\Framebuffers\DamageGranularity;
use ScrapyardIO\Tubes\Contracts\Framebuffers\DumpedBuffer;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\RenderType;
use ScrapyardIO\Tubes\Contracts\Framebuffers\FormatSpec;
use ScrapyardIO\Tubes\Contracts\Framebuffers\PixelStore as PixelStoreContract;

/**
 * Always emits the whole surface. No partial-refresh bookkeeping.
 */
final class FullFramebuffer extends ManagedFramebuffer
{
    public function __construct(PixelStoreContract $pixel_store)
    {
        parent::__construct($pixel_store);
    }

    public function markAllDirty(): static
    {
        return $this;
    }

    public function damageGranularity(): DamageGranularity
    {
        return DamageGranularity::wholeSurface(
            $this->viewportWidth(),
            $this->viewportHeight(),
        );
    }

    /**
     * @return string|array<int, DumpedBuffer|int>
     */
    public function flush(FormatSpec $spec, bool $as_array = false): string|array
    {
        $bytes = $this->bytesForSpec($spec);

        if (! $as_array) {
            return $bytes;
        }

        return [
            new DumpedBuffer(
                RenderType::FULL,
                $spec,
                $bytes,
                width: $this->viewportWidth(),
                height: $this->viewportHeight(),
            ),
        ];
    }
}
