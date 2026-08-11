<?php

namespace ScrapyardIO\Tubes\Framebuffers;

use Fabricate\NutsAndBolts\Concerns\Splices4Bits;
use InvalidArgumentException;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\BitDepth;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\BitOrder;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\Endianness;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\PixelFormat;
use ScrapyardIO\Tubes\Contracts\Framebuffers\Enums\ScanDirection;
use ScrapyardIO\Tubes\Contracts\Framebuffers\FormatSpec;
use ScrapyardIO\Tubes\Contracts\Framebuffers\PixelStore as PixelStoreContract;
use ScrapyardIO\Tubes\Framebuffers\Support\PixelColorPack;

/**
 * Packed binary pixel blob sized from a host FormatSpec.
 *
 * Init allocates zeroed bytes for width × height × z under the host packing.
 * Mutations write directly into that packing. Dirty policy and flush-to-foreign
 * FormatSpec conversion stay on Framebuffer.
 */
class PixelStore implements PixelStoreContract
{
    use Splices4Bits;

    private string $pixels;

    private int $layer_byte_length;

    public function __construct(
        protected int $width,
        protected int $height,
        protected FormatSpec $host_format,
        protected int $z = 1,
    ) {
        if (($this->width < 1) || ($this->height < 1)) {
            throw new InvalidArgumentException(
                "PixelStore dimensions must be positive, got {$this->width}x{$this->height}."
            );
        }

        if ($this->z < 1) {
            throw new InvalidArgumentException(
                "PixelStore z (layer count) must be at least 1, got {$this->z}."
            );
        }

        $this->layer_byte_length = $this->host_format->bytesForSurface(
            $this->width,
            $this->height,
        );

        $this->pixels = str_repeat("\0", $this->layer_byte_length * $this->z);
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function z(): int
    {
        return $this->z;
    }

    public function hostFormat(): FormatSpec
    {
        return $this->host_format;
    }

    public function layerByteLength(): int
    {
        return $this->layer_byte_length;
    }

    public function byteLength(): int
    {
        return $this->layer_byte_length * $this->z;
    }

    public function dump(?int $layer = null): string
    {
        if (is_null($layer)) {
            return $this->pixels;
        }

        return substr(
            $this->pixels,
            $this->layerOffset($layer),
            $this->layer_byte_length,
        );
    }

    /**
     * Fast ROW_MAJOR region extract (memcpy rows). Empty string when unsupported.
     */
    public function dumpRowMajorRegion(int $x, int $y, int $width, int $height, int $layer = 0): ?string
    {
        if (
            $this->host_format->pixel_format !== PixelFormat::ROW_MAJOR
            || $this->host_format->bit_depth === BitDepth::B12
            || $width < 1
            || $height < 1
            || $x < 0
            || $y < 0
            || ($x + $width) > $this->width
            || ($y + $height) > $this->height
        ) {
            return null;
        }

        $bytesPerPixel = intdiv($this->host_format->bit_depth->value + 7, 8);
        $stride = $this->width * $bytesPerPixel;
        $rowBytes = $width * $bytesPerPixel;
        $base = $this->layerOffset($layer);
        $out = '';

        for ($row = 0; $row < $height; $row++) {
            $offset = $base + (($y + $row) * $stride) + ($x * $bytesPerPixel);
            $out .= substr($this->pixels, $offset, $rowBytes);
        }

        return $out;
    }

    public function clear(?int $layer = null): static
    {
        if (is_null($layer)) {
            $this->pixels = str_repeat("\0", $this->byteLength());

            return $this;
        }

        $this->pixels = substr_replace(
            $this->pixels,
            str_repeat("\0", $this->layer_byte_length),
            $this->layerOffset($layer),
            $this->layer_byte_length,
        );

        return $this;
    }

    public function fill(int $color, ?int $layer = null): static
    {
        if (is_null($layer)) {
            for ($z = 0; $z < $this->z; $z++) {
                $this->fillLayer($color, $z);
            }

            return $this;
        }

        $this->fillLayer($color, $layer);

        return $this;
    }

    public function getPixel(int $x, int $y, int $layer = 0): int
    {
        if (! $this->contains($x, $y)) {
            return 0;
        }

        $base = $this->layerOffset($layer);
        $yx = $this->mapLogical($x, $y);

        return match ($this->host_format->pixel_format) {
            PixelFormat::MONO_VERTICAL_PAGE => $this->getMonoVerticalPage($base, $yx[0], $yx[1]),
            PixelFormat::MONO_HORIZONTAL => $this->getMonoHorizontal($base, $yx[0], $yx[1]),
            PixelFormat::PLANAR => $this->getPlanar($base, $yx[0], $yx[1]),
            PixelFormat::ROW_MAJOR => $this->getRowMajor($base, $yx[0], $yx[1]),
        };
    }

    public function setPixel(int $x, int $y, int $color, int $layer = 0): static
    {
        return $this->writePixel(
            $x,
            $y,
            PixelColorPack::packDrawColor($color, $this->host_format),
            $layer,
        );
    }

    /**
     * Write a host-native packed colour (RGB565 etc.) — no 0xRRGGBBAA conversion.
     */
    public function putPacked(int $x, int $y, int $packed, int $layer = 0): static
    {
        return $this->writePixel($x, $y, $packed, $layer);
    }

    protected function writePixel(int $x, int $y, int $packed, int $layer = 0): static
    {
        if (! $this->contains($x, $y)) {
            return $this;
        }

        $base = $this->layerOffset($layer);
        [$mx, $my] = $this->mapLogical($x, $y);

        match ($this->host_format->pixel_format) {
            PixelFormat::MONO_VERTICAL_PAGE => $this->setMonoVerticalPage($base, $mx, $my, $packed),
            PixelFormat::MONO_HORIZONTAL => $this->setMonoHorizontal($base, $mx, $my, $packed),
            PixelFormat::PLANAR => $this->setPlanar($base, $mx, $my, $packed),
            PixelFormat::ROW_MAJOR => $this->setRowMajor($base, $mx, $my, $packed),
        };

        return $this;
    }

    public function setPixels(array $pixels, int $layer = 0): static
    {
        foreach ($pixels as [$x, $y, $color]) {
            $this->setPixel($x, $y, $color, $layer);
        }

        return $this;
    }

    public function setSegment(int $x, int $y, int $width, int $height, int $color, int $layer = 0): static
    {
        if (($width <= 0) || ($height <= 0)) {
            return $this;
        }

        if ($this->fillRowMajorSolid($x, $y, $width, $height, $color, $layer)) {
            return $this;
        }

        for ($row = 0; $row < $height; $row++) {
            for ($col = 0; $col < $width; $col++) {
                $this->setPixel($x + $col, $y + $row, $color, $layer);
            }
        }

        return $this;
    }

    protected function fillLayer(int $color, int $layer): void
    {
        $this->layerOffset($layer);

        $format = $this->host_format->pixel_format;

        if (
            ($format === PixelFormat::MONO_VERTICAL_PAGE || $format === PixelFormat::MONO_HORIZONTAL)
            && (($color & 1) === 1)
        ) {
            $this->pixels = substr_replace(
                $this->pixels,
                str_repeat("\xFF", $this->layer_byte_length),
                $this->layerOffset($layer),
                $this->layer_byte_length,
            );

            return;
        }

        if ($this->fillRowMajorSolid(0, 0, $this->width, $this->height, $color, $layer)) {
            return;
        }

        if ($color === 0) {
            $this->clear($layer);

            return;
        }

        $this->setSegment(0, 0, $this->width, $this->height, $color, $layer);
    }

    /**
     * ROW_MAJOR solid fill: pack draw colour once, stamp pixel bytes (no per-pixel convert).
     */
    protected function fillRowMajorSolid(
        int $x,
        int $y,
        int $width,
        int $height,
        int $color,
        int $layer,
    ): bool {
        if (
            $this->host_format->pixel_format !== PixelFormat::ROW_MAJOR
            || $this->host_format->bit_depth === BitDepth::B12
            || $this->host_format->scan_direction !== ScanDirection::TOP_TO_BOTTOM
        ) {
            return false;
        }

        $x = max(0, $x);
        $y = max(0, $y);
        $right = min($this->width, $x + $width);
        $bottom = min($this->height, $y + $height);
        $width = $right - $x;
        $height = $bottom - $y;

        if (($width < 1) || ($height < 1)) {
            return true;
        }

        $native = PixelColorPack::packDrawColor($color, $this->host_format);
        $pixel = $this->rowMajorPixelBytes($native);
        $bytesPerPixel = strlen($pixel);
        $stride = $this->width * $bytesPerPixel;
        $rowBytes = $width * $bytesPerPixel;
        $base = $this->layerOffset($layer);

        // Whole layer: one allocation. Partial: in-place byte writes — never loop
        // substr_replace (each rewrite copies the entire store and tanks FPS).
        if ($x === 0 && $y === 0 && $width === $this->width && $height === $this->height) {
            $this->pixels = substr_replace(
                $this->pixels,
                str_repeat($pixel, $this->width * $this->height),
                $base,
                $this->layer_byte_length,
            );

            return true;
        }

        $rowPattern = str_repeat($pixel, $width);

        // Force string separation once, then mutate in place.
        if ($this->pixels !== '') {
            $this->pixels[0] = $this->pixels[0];
        }

        for ($row = 0; $row < $height; $row++) {
            $offset = $base + (($y + $row) * $stride) + ($x * $bytesPerPixel);

            for ($i = 0; $i < $rowBytes; $i++) {
                $this->pixels[$offset + $i] = $rowPattern[$i];
            }
        }

        return true;
    }

    protected function rowMajorPixelBytes(int $nativeColor): string
    {
        $bytesPerPixel = intdiv($this->host_format->bit_depth->value + 7, 8);
        $msbFirst = $this->host_format->endianness !== Endianness::LSB;
        $out = '';

        for ($i = 0; $i < $bytesPerPixel; $i++) {
            $shift = $msbFirst ? (($bytesPerPixel - 1 - $i) * 8) : ($i * 8);
            $out .= chr(($nativeColor >> $shift) & 0xFF);
        }

        return $out;
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function mapLogical(int $x, int $y): array
    {
        if ($this->host_format->scan_direction === ScanDirection::BOTTOM_TO_TOP) {
            return [$x, $this->height - 1 - $y];
        }

        return [$x, $y];
    }

    protected function contains(int $x, int $y): bool
    {
        return ($x >= 0) && ($y >= 0) && ($x < $this->width) && ($y < $this->height);
    }

    protected function layerOffset(int $layer): int
    {
        if (($layer < 0) || ($layer >= $this->z)) {
            throw new InvalidArgumentException(
                "PixelStore layer {$layer} is out of range for z={$this->z}."
            );
        }

        return $layer * $this->layer_byte_length;
    }

    protected function msbFirstBits(): bool
    {
        return $this->host_format->bit_order === BitOrder::MSB_FIRST;
    }

    protected function readByte(int $offset): int
    {
        return ord($this->pixels[$offset]);
    }

    protected function writeByte(int $offset, int $byte): void
    {
        $this->pixels[$offset] = chr($byte & 0xFF);
    }

    protected function setMonoBit(int $byte_offset, int $bit_index, bool $on): void
    {
        $byte = $this->readByte($byte_offset);

        if ($on) {
            $byte |= (1 << $bit_index);
        } else {
            $byte &= ~(1 << $bit_index);
        }

        $this->writeByte($byte_offset, $byte);
    }

    protected function getMonoBit(int $byte_offset, int $bit_index): int
    {
        return (($this->readByte($byte_offset) >> $bit_index) & 1);
    }

    protected function setMonoVerticalPage(int $base, int $x, int $y, int $color): void
    {
        $page = intdiv($y, 8);
        $row_in_page = $y % 8;
        $bit = $this->msbFirstBits() ? (7 - $row_in_page) : $row_in_page;
        $offset = $base + ($page * $this->width) + $x;

        $this->setMonoBit($offset, $bit, ($color & 1) === 1);
    }

    protected function getMonoVerticalPage(int $base, int $x, int $y): int
    {
        $page = intdiv($y, 8);
        $row_in_page = $y % 8;
        $bit = $this->msbFirstBits() ? (7 - $row_in_page) : $row_in_page;
        $offset = $base + ($page * $this->width) + $x;

        return $this->getMonoBit($offset, $bit);
    }

    protected function setMonoHorizontal(int $base, int $x, int $y, int $color): void
    {
        $bytes_per_row = intdiv($this->width + 7, 8);
        $col_byte = intdiv($x, 8);
        $bit_in_byte = $x % 8;
        $bit = $this->msbFirstBits() ? (7 - $bit_in_byte) : $bit_in_byte;
        $offset = $base + ($y * $bytes_per_row) + $col_byte;

        $this->setMonoBit($offset, $bit, ($color & 1) === 1);
    }

    protected function getMonoHorizontal(int $base, int $x, int $y): int
    {
        $bytes_per_row = intdiv($this->width + 7, 8);
        $col_byte = intdiv($x, 8);
        $bit_in_byte = $x % 8;
        $bit = $this->msbFirstBits() ? (7 - $bit_in_byte) : $bit_in_byte;
        $offset = $base + ($y * $bytes_per_row) + $col_byte;

        return $this->getMonoBit($offset, $bit);
    }

    protected function setPlanar(int $base, int $x, int $y, int $color): void
    {
        $palette = $this->host_format->palette;

        if (is_null($palette)) {
            throw new InvalidArgumentException('PLANAR host FormatSpec requires a ChannelPalette.');
        }

        $plane_bytes = $this->height * intdiv($this->width + 7, 8);
        $bytes_per_row = intdiv($this->width + 7, 8);
        $col_byte = intdiv($x, 8);
        $bit_in_byte = $x % 8;
        $bit = $this->msbFirstBits() ? (7 - $bit_in_byte) : $bit_in_byte;

        foreach ($palette->channels as $index => $channel) {
            $matches = ($color === $channel->color);
            $on = $channel->inverted ? ! $matches : $matches;
            $offset = $base + ($index * $plane_bytes) + ($y * $bytes_per_row) + $col_byte;
            $this->setMonoBit($offset, $bit, $on);
        }
    }

    protected function getPlanar(int $base, int $x, int $y): int
    {
        $palette = $this->host_format->palette;

        if (is_null($palette)) {
            throw new InvalidArgumentException('PLANAR host FormatSpec requires a ChannelPalette.');
        }

        $plane_bytes = $this->height * intdiv($this->width + 7, 8);
        $bytes_per_row = intdiv($this->width + 7, 8);
        $col_byte = intdiv($x, 8);
        $bit_in_byte = $x % 8;
        $bit = $this->msbFirstBits() ? (7 - $bit_in_byte) : $bit_in_byte;

        foreach ($palette->channels as $index => $channel) {
            $offset = $base + ($index * $plane_bytes) + ($y * $bytes_per_row) + $col_byte;
            $bit_on = $this->getMonoBit($offset, $bit) === 1;
            $present = $channel->inverted ? ! $bit_on : $bit_on;

            if ($present) {
                return $channel->color;
            }
        }

        return 0;
    }

    protected function setRowMajor(int $base, int $x, int $y, int $color): void
    {
        if ($this->host_format->bit_depth === BitDepth::B12) {
            $this->setRowMajorB12($base, $x, $y, $color);

            return;
        }

        // $color is host-native (caller packs 0xRRGGBBAA via setPixel / fillRowMajorSolid).
        $bytes_per_pixel = intdiv($this->host_format->bit_depth->value + 7, 8);
        $offset = $base + ((($y * $this->width) + $x) * $bytes_per_pixel);
        $msb_first = $this->host_format->endianness !== Endianness::LSB;

        for ($i = 0; $i < $bytes_per_pixel; $i++) {
            $shift = $msb_first ? (($bytes_per_pixel - 1 - $i) * 8) : ($i * 8);
            $this->writeByte($offset + $i, ($color >> $shift) & 0xFF);
        }
    }

    protected function getRowMajor(int $base, int $x, int $y): int
    {
        if ($this->host_format->bit_depth === BitDepth::B12) {
            return $this->getRowMajorB12($base, $x, $y);
        }

        $bytes_per_pixel = intdiv($this->host_format->bit_depth->value + 7, 8);
        $offset = $base + ((($y * $this->width) + $x) * $bytes_per_pixel);
        $msb_first = $this->host_format->endianness !== Endianness::LSB;
        $color = 0;

        for ($i = 0; $i < $bytes_per_pixel; $i++) {
            $shift = $msb_first ? (($bytes_per_pixel - 1 - $i) * 8) : ($i * 8);
            $color |= ($this->readByte($offset + $i) << $shift);
        }

        return $color;
    }

    protected function setRowMajorB12(int $base, int $x, int $y, int $color): void
    {
        $index = ($y * $this->width) + $x;
        $pair_index = intdiv($index, 2);
        $offset = $base + ($pair_index * 3);
        $even = ($index % 2) === 0;

        if ($even) {
            $left = $color & 0x0FFF;
            $right = ($x + 1 < $this->width) ? $this->getRowMajorB12($base, $x + 1, $y) : 0;
        } else {
            $left = $this->getRowMajorB12($base, $x - 1, $y);
            $right = $color & 0x0FFF;
        }

        $left_channels = $this->splitRgb444($left);
        $right_channels = $this->splitRgb444($right);

        $this->writeByte($offset, $this->packNibbles($left_channels['r'], $left_channels['g']));
        $this->writeByte($offset + 1, $this->packNibbles($left_channels['b'], $right_channels['r']));
        $this->writeByte($offset + 2, $this->packNibbles($right_channels['g'], $right_channels['b']));
    }

    protected function getRowMajorB12(int $base, int $x, int $y): int
    {
        $index = ($y * $this->width) + $x;
        $pair_index = intdiv($index, 2);
        $offset = $base + ($pair_index * 3);
        $even = ($index % 2) === 0;

        $b0 = $this->splitNibbles($this->readByte($offset));
        $b1 = $this->splitNibbles($this->readByte($offset + 1));
        $b2 = $this->splitNibbles($this->readByte($offset + 2));

        if ($even) {
            return $this->packRgb444($b0['high'], $b0['low'], $b1['high']);
        }

        return $this->packRgb444($b1['low'], $b2['high'], $b2['low']);
    }
}
