<?php

namespace ScrapyardIO\Tubes\Framebuffers\Support;

use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\BitDepth;
use ScrapyardIO\Tubes\Contracts\Framebuffers\FormatSpec;

/**
 * Convert a logical pixel colour between host FormatSpecs.
 *
 * Draw colours are 0xRRGGBBAA. When the framebuffer host is shallower (RGB565),
 * {@see packDrawColor()} packs on write — never allocate a FormatSpec per pixel.
 * Flush/dump conversion via {@see convert()} is only for host ≠ target FormatSpec.
 */
final class PixelColorPack
{
    /**
     * Pack a sketch draw colour (always 0xRRGGBBAA) into the host encoding.
     *
     * Never treat `$color <= 0xFFFF` as "already native" — black `0x000000FF`
     * is 255 and was mis-written as RGB565 `0x00FF` (blue) on ST77xx.
     * Host-native words use {@see PixelStore::putPacked()}, not this helper.
     */
    public static function packDrawColor(int $color, FormatSpec $host): int
    {
        $depth = $host->bit_depth;

        if ($depth === BitDepth::B32 || $depth === BitDepth::B24) {
            return $color;
        }

        return self::fromRgba(
            ($color >> 24) & 0xFF,
            ($color >> 16) & 0xFF,
            ($color >> 8) & 0xFF,
            $color & 0xFF,
            $host,
        );
    }

    public static function convert(int $color, FormatSpec $from, FormatSpec $to): int
    {
        if (
            $from->bit_depth === $to->bit_depth
            && $from->pixel_format === $to->pixel_format
        ) {
            return $color;
        }

        [$r, $g, $b, $a] = self::toRgba($color, $from);

        return self::fromRgba($r, $g, $b, $a, $to);
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public static function toRgba(int $color, FormatSpec $spec): array
    {
        return match ($spec->bit_depth) {
            BitDepth::B32, BitDepth::B24 => [
                ($color >> 24) & 0xFF,
                ($color >> 16) & 0xFF,
                ($color >> 8) & 0xFF,
                $color & 0xFF,
            ],
            BitDepth::B16 => self::rgb565ToRgba($color),
            BitDepth::B1, BitDepth::B8 => self::monoToRgba($color),
            default => [
                ($color >> 16) & 0xFF,
                ($color >> 8) & 0xFF,
                $color & 0xFF,
                0xFF,
            ],
        };
    }

    public static function fromRgba(int $r, int $g, int $b, int $a, FormatSpec $spec): int
    {
        $r = max(0, min(255, $r));
        $g = max(0, min(255, $g));
        $b = max(0, min(255, $b));
        $a = max(0, min(255, $a));

        return match ($spec->bit_depth) {
            BitDepth::B32, BitDepth::B24 => ($r << 24) | ($g << 16) | ($b << 8) | $a,
            BitDepth::B16 => (($r & 0xF8) << 8) | (($g & 0xFC) << 3) | ($b >> 3),
            BitDepth::B1, BitDepth::B8 => (($r + $g + $b) >= (3 * 128)) ? 1 : 0,
            default => ($r << 16) | ($g << 8) | $b,
        };
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    protected static function rgb565ToRgba(int $color): array
    {
        $packed = $color & 0xFFFF;
        $r = (($packed >> 11) & 0x1F) << 3;
        $g = (($packed >> 5) & 0x3F) << 2;
        $b = ($packed & 0x1F) << 3;

        return [$r, $g, $b, 0xFF];
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    protected static function monoToRgba(int $color): array
    {
        $on = ($color & 1) === 1;
        $v = $on ? 255 : 0;

        return [$v, $v, $v, 0xFF];
    }
}
