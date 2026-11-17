<?php

namespace Jovian\Rendering\Pluto\Images;

use Surface\Contracts\Images\ImageDecoder;
use Surface\Contracts\Images\ImageException;
use Surface\Contracts\Images\ImageFormat;
use Surface\Contracts\Images\ImageInfo;
use Surface\Contracts\Images\ImageSource;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\ScanDirection;

/**
 * PlutoVG's address decoder. stb reads the file into a surface of premultiplied ARGB, and
 * PlutoVG's own plutovg_convert_argb_to_rgba() writes it into the frame as straight R, G, B, A,
 * the conversion PlutoVG uses when it writes a PNG. It divides by integer division,
 * (channel × 255) ÷ alpha, and a clear pixel is 0. PlutoVG has no straight B, G, R, A output, so
 * this decoder writes RGBA frames only. PlutoVG reports no image facts without decoding, so info()
 * reads the PNG or JPEG header and does not load pixels.
 *
 * stb in this build also reads BMP, GIF, TGA, PSD, HDR, PIC and PNM. Images does not name those,
 * so this decoder does not claim them. It does not read TIFF.
 */
final class PlutoImageDecoder implements ImageDecoder
{
    public function driver(): string
    {
        return 'plutovg';
    }

    public function formats(): array
    {
        return [ImageFormat::PNG, ImageFormat::JPEG];
    }

    public function writes(FormatSpec $format): bool
    {
        return $format->pixel_format === PixelFormat::ROW_MAJOR
            && $format->bit_depth === BitDepth::B32
            && $format->scan_direction === ScanDirection::TOP_TO_BOTTOM
            && $format->channel_order === ChannelOrder::RGBA
            && is_null($format->palette);
    }

    public function info(ImageSource $source): ImageInfo
    {
        $bytes = $source->bytes();
        $format = ImageFormat::sniff($bytes) ?? throw ImageException::unknownFormat();

        return match ($format) {
            ImageFormat::PNG => $this->pngInfo($bytes),
            ImageFormat::JPEG => $this->jpegInfo($bytes),
            ImageFormat::TIFF => throw ImageException::corrupt($format, 'PlutoVG does not read TIFF.'),
        };
    }

    public function decodeInto(ImageSource $source, PixelMemory $target, int $frame = 0): Region
    {
        $bytes = $source->bytes();
        $format = ImageFormat::sniff($bytes) ?? throw ImageException::unknownFormat();
        if (! $this->writes($target->format)) {
            throw ImageException::noDecoder($format, $target->format);
        }
        $facts = $this->info($source);
        if ($frame < 0 || $frame >= $facts->frames) {
            throw ImageException::frameOutOfRange($format, $frame, $facts->frames);
        }
        if ($facts->width !== $target->width || $facts->height !== $target->height) {
            throw ImageException::corrupt($format, "it is {$facts->width}x{$facts->height}, and the frame is {$target->width}x{$target->height}.");
        }
        $row = $target->width * 4;
        if ($target->stride < $row) {
            throw ImageException::corrupt($format, "a row is {$row} bytes, and the stride is {$target->stride}.");
        }
        extension_loaded('plutovg') || throw new ImageException('ext-plutovg is not loaded in this PHP.');

        $loaded = plutovg_surface_load_from_image_data($bytes, -1)
            ?? throw ImageException::corrupt($format, 'PlutoVG did not read it.');
        if (plutovg_surface_get_width($loaded) !== $facts->width || plutovg_surface_get_height($loaded) !== $facts->height) {
            throw ImageException::corrupt($format, 'the decoded surface is not the size the header names.');
        }
        $from = plutovg_surface_get_data($loaded);
        $fromStride = plutovg_surface_get_stride($loaded);
        if ($fromStride === $target->stride) {
            $into = $target->address;   // the binding takes its destination by reference
            plutovg_convert_argb_to_rgba($into, $from, $target->width, $target->height, $target->stride);
        } else {
            // PlutoVG's convert walks both buffers with one stride, so rows of different lengths go one at a time.
            for ($y = 0; $y < $target->height; $y++) {
                $into = $target->address + $y * $target->stride;
                plutovg_convert_argb_to_rgba($into, $from + $y * $fromStride, $target->width, 1, $target->width * 4);
            }
        }

        return new Region(0, 0, $target->width, $target->height);
    }

    private function pngInfo(string $bytes): ImageInfo
    {
        if (strlen($bytes) < 29 || ! str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            throw ImageException::corrupt(ImageFormat::PNG, 'the data stops before the image does.');
        }
        $width = unpack('N', $bytes, 16)[1];
        $height = unpack('N', $bytes, 20)[1];
        $colour = ord($bytes[25]);
        $alpha = $colour === 4 || $colour === 6;
        $at = 8;
        while (! $alpha && $at + 12 <= strlen($bytes)) {
            $length = unpack('N', $bytes, $at)[1];
            $type = substr($bytes, $at + 4, 4);
            if ($type === 'tRNS') {
                $alpha = true;
                break;
            }
            if ($type === 'IDAT' || $type === 'IEND' || $at + 12 + $length > strlen($bytes)) {
                break;
            }
            $at += 12 + $length;
        }

        return new ImageInfo(ImageFormat::PNG, $width, $height, $alpha, 1);
    }

    private function jpegInfo(string $bytes): ImageInfo
    {
        $length = strlen($bytes);
        if ($length < 4 || ! str_starts_with($bytes, "\xFF\xD8")) {
            throw ImageException::corrupt(ImageFormat::JPEG, 'the data stops before the image does.');
        }
        $i = 2;
        while ($i + 1 < $length) {
            if (ord($bytes[$i]) !== 0xFF) {
                throw ImageException::corrupt(ImageFormat::JPEG, 'the data stops before the image does.');
            }
            while ($i < $length && ord($bytes[$i]) === 0xFF) {
                $i++;
            }
            if ($i >= $length) {
                break;
            }
            $marker = ord($bytes[$i]);
            $i++;
            if ($marker === 0xD8 || $marker === 0xD9 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }
            if ($i + 1 >= $length) {
                break;
            }
            $segment = unpack('n', $bytes, $i)[1];
            if ($marker >= 0xC0 && $marker <= 0xCF && $marker !== 0xC4 && $marker !== 0xC8 && $marker !== 0xCC) {
                if ($segment < 8 || $i + 6 >= $length) {
                    throw ImageException::corrupt(ImageFormat::JPEG, 'the data stops before the image does.');
                }

                return new ImageInfo(ImageFormat::JPEG, unpack('n', $bytes, $i + 5)[1], unpack('n', $bytes, $i + 3)[1], false, 1);
            }
            if ($segment < 2) {
                throw ImageException::corrupt(ImageFormat::JPEG, 'the data stops before the image does.');
            }
            $i += $segment;
        }

        throw ImageException::corrupt(ImageFormat::JPEG, 'the data stops before the image does.');
    }
}
