<?php

declare(strict_types=1);

require_once __DIR__.'/../../src/Images/PlutoImageDecoder.php';

use Jovian\Rendering\Pluto\Images\PlutoImageDecoder;
use Surface\Contracts\Images\ImageException;
use Surface\Contracts\Images\ImageFormat;
use Surface\Contracts\Images\ImageSource;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;

/*
 * PlutoVG's loader stores premultiplied ARGB, and plutovg_convert_argb_to_rgba() writes it out straight.
 * gd's alpha is 7 bits, widened as 255 − ((a << 1) + (a >> 6)): 0 → 255, 1 → 253, 64 → 126, 127 → 0.
 * The loader premultiplies, (channel × alpha) ÷ 255: pixel 1 red 60 × 253 ÷ 255 = 59, blue 7 × 253 ÷ 255 = 6;
 * pixel 2 red 120 × 126 ÷ 255 = 59, blue 7 × 126 ÷ 255 = 3. The convert divides back, (channel × 255) ÷ alpha:
 * pixel 1 red 59 × 255 ÷ 253 = 59 (0x3B), blue 6 × 255 ÷ 253 = 6; pixel 2 red 59 × 255 ÷ 126 = 119 (0x77),
 * blue 3 × 255 ÷ 126 = 6. Opaque pixel 0 is the file's 0x000007FF. Clear pixel 3 is 0. get() answers 0xRRGGBBAA.
 */

function plutoPng(): string
{
    $image = imagecreatetruecolor(4, 1);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $alphas = [0, 1, 64, 127];
    for ($x = 0; $x < 4; $x++) {
        imagesetpixel($image, $x, 0, ($alphas[$x] << 24) | ($x * 60 << 16) | 7);
    }
    ob_start();
    imagepng($image);

    return ob_get_clean();
}

function plutoMemory(int $width, int $height, int $stride, FormatSpec $format): array
{
    $order = $format->channel_order === \Surface\Contracts\Rendering\Framebuffers\ChannelOrder::RGBA
        ? FB_CHANNELS_RGBA
        : FB_CHANNELS_BGRA;
    $buffer = new FbBuffer(new FbFormat(FB_LAYOUT_RGBA8888, channelOrder: $order), $width, $height);
    $memory = new PixelMemory($buffer->pointer(), $width, $height, $stride, $format, $buffer);

    return [$buffer, $memory];
}

it('writes PlutoVG\'s pixels straight, divided back by its own convert, and an opaque pixel matches the file', function () {
    extension_loaded('plutovg') || throw new RuntimeException('ext-plutovg is not loaded in this PHP.');
    $png = plutoPng();
    $decoder = new PlutoImageDecoder();
    $info = $decoder->info(ImageSource::containing($png));
    [$buffer, $memory] = plutoMemory(4, 1, 16, FormatSpec::rgba8());
    $buffer->rect(0, 0, 4, 1, 0xA5);
    $decoder->decodeInto(ImageSource::containing($png), $memory);

    expect($info->format)->toBe(ImageFormat::PNG)
        ->and($info->width)->toBe(4)
        ->and($info->height)->toBe(1)
        ->and($info->hasAlpha)->toBeTrue()
        ->and($info->frames)->toBe(1)
        ->and($buffer->get(0, 0))->toBe(0x000007FF)
        ->and($buffer->get(1, 0))->toBe(0x3B0006FD)
        ->and($buffer->get(2, 0))->toBe(0x7700067E)
        ->and($buffer->get(3, 0))->toBe(0x00000000);
});

it('writes a flat JPEG as opaque pixels equal to the file', function () {
    extension_loaded('plutovg') || throw new RuntimeException('ext-plutovg is not loaded in this PHP.');
    $image = imagecreatetruecolor(2, 1);
    imagesetpixel($image, 0, 0, 0x102030);
    imagesetpixel($image, 1, 0, 0x405060);
    ob_start();
    imagejpeg($image, null, 100);
    $jpeg = ob_get_clean();
    $decoder = new PlutoImageDecoder();
    $info = $decoder->info(ImageSource::containing($jpeg));
    [$buffer, $memory] = plutoMemory(2, 1, 8, FormatSpec::rgba8());
    $decoder->decodeInto(ImageSource::containing($jpeg), $memory);

    expect($info->format)->toBe(ImageFormat::JPEG)
        ->and($info->width)->toBe(2)
        ->and($info->hasAlpha)->toBeFalse()
        ->and($decoder->formats())->not->toContain(ImageFormat::TIFF)
        ->and($buffer->get(0, 0))->toBe(0x102030FF)
        ->and($buffer->get(1, 0))->toBe(0x405060FF);
});

it('reads a PNG header that ends after IHDR', function () {
    $info = (new PlutoImageDecoder())->info(ImageSource::containing(substr(plutoPng(), 0, 33)));

    expect($info->width)->toBe(4)->and($info->height)->toBe(1)->and($info->hasAlpha)->toBeTrue();
});

function plutoTallPng(): string
{
    $image = imagecreatetruecolor(2, 2);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagesetpixel($image, 0, 0, 7);
    imagesetpixel($image, 1, 0, (127 << 24) | (60 << 16) | 7);
    imagesetpixel($image, 0, 1, 0x102030);
    imagesetpixel($image, 1, 1, 0x405060);
    ob_start();
    imagepng($image);

    return ob_get_clean();
}

it('leaves the stride padding and refuses a short row before writing', function () {
    extension_loaded('plutovg') || throw new RuntimeException('ext-plutovg is not loaded in this PHP.');
    // Two rows. A stride of width×4 would write row 1 into the padding of row 0.
    $owner = new FbBuffer(new FbFormat(FB_LAYOUT_INDEX8), 16, 2);
    $owner->rect(0, 0, 16, 2, 0xA5);
    $memory = new PixelMemory($owner->pointer(), 2, 2, 16, FormatSpec::rgba8(), $owner);
    (new PlutoImageDecoder())->decodeInto(ImageSource::containing(plutoTallPng()), $memory);
    $view = FbBuffer::over(new FbFormat(FB_LAYOUT_RGBA8888, channelOrder: FB_CHANNELS_RGBA), 2, 2, $owner->pointer(), $owner, 16);

    expect($view->get(0, 1))->toBe(0x102030FF);
    for ($byte = 8; $byte < 16; $byte++) {
        expect($owner->get($byte, 0))->toBe(0xA5);
    }

    [$short, $shortMemory] = plutoMemory(4, 1, 8, FormatSpec::rgba8());
    $short->rect(0, 0, 4, 1, 0xA5);
    expect(fn () => (new PlutoImageDecoder())->decodeInto(ImageSource::containing(plutoPng()), $shortMemory))
        ->toThrow(ImageException::class, 'a row is 16 bytes, and the stride is 8.');
    expect($short->get(0, 0))->toBe(0xA5);
});

it('refuses a target of the wrong size, and a BGRA frame PlutoVG has no straight output for', function () {
    extension_loaded('plutovg') || throw new RuntimeException('ext-plutovg is not loaded in this PHP.');
    [$small, $smallMemory] = plutoMemory(2, 1, 8, FormatSpec::rgba8());
    $small->rect(0, 0, 2, 1, 0xA5);
    expect(fn () => (new PlutoImageDecoder())->decodeInto(ImageSource::containing(plutoPng()), $smallMemory))
        ->toThrow(ImageException::class, 'it is 4x1, and the frame is 2x1.');
    expect($small->get(0, 0))->toBe(0xA5);

    [$bgra, $bgraMemory] = plutoMemory(4, 1, 16, FormatSpec::bgra8());
    $bgra->rect(0, 0, 4, 1, 0xA5);
    expect(fn () => (new PlutoImageDecoder())->decodeInto(ImageSource::containing(plutoPng()), $bgraMemory))
        ->toThrow(ImageException::class, 'PNG has no decoder that writes BGRA.');
    expect($bgra->get(0, 0))->toBe(0xA5);
});

it('refuses a PNG whose header is intact and whose pixels PlutoVG does not read', function () {
    extension_loaded('plutovg') || throw new RuntimeException('ext-plutovg is not loaded in this PHP.');
    [$buffer, $memory] = plutoMemory(4, 1, 16, FormatSpec::rgba8());
    $buffer->rect(0, 0, 4, 1, 0xA5);
    $cut = substr(plutoPng(), 0, 40);

    expect(fn () => (new PlutoImageDecoder())->decodeInto(ImageSource::containing($cut), $memory))
        ->toThrow(ImageException::class, 'PlutoVG did not read it.');
    expect($buffer->get(0, 0))->toBe(0xA5);
});
