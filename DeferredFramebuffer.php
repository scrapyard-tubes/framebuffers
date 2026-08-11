<?php

namespace ScrapyardIO\Tubes\Framebuffers;

use ScrapyardIO\Tubes\Contracts\Framebuffers\DeferredFramebuffer as DeferredFramebufferContract;
use ScrapyardIO\Tubes\Contracts\Framebuffers\FormatSpec;

/**
 * Host-backed framebuffer: engine owns pixels (no tubes PixelStore).
 *
 * Companions extend this class and implement pixel ops + {@see present()}.
 */
abstract class DeferredFramebuffer extends Framebuffer implements DeferredFramebufferContract
{
    public function __construct(
        int $width,
        int $height,
        FormatSpec $host_format,
    ) {
        parent::__construct($width, $height, $host_format);
    }

    /**
     * Alias for {@see hostFormat()}.
     */
    public function formatSpec(): FormatSpec
    {
        return $this->hostFormat();
    }

    abstract public function present(): static;

    abstract public function isHeadless(): bool;

    /**
     * Pack a flat list of RGBA8888 (or host int) words into bytes for {@see flush()}.
     *
     * @param  array<int, int>  $words  row-major pixel words
     */
    protected function packWordsToBytes(array $words, FormatSpec $spec): string
    {
        $temp = new PixelStore(
            $this->viewportWidth(),
            $this->viewportHeight(),
            $spec,
            1,
        );

        $i = 0;
        $height = $this->viewportHeight();
        $width = $this->viewportWidth();

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $temp->setPixel($x, $y, $words[$i] ?? 0);
                $i++;
            }
        }

        return $temp->dump();
    }
}
