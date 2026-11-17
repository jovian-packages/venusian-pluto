<?php

namespace Jovian\Rendering\Pluto\Images;

use Surface\Contracts\Images\ImageDecoder;
use Surface\Contracts\Images\ImageException;
use Surface\Images\ImageDecoderDriver;

/** Registers PlutoVG's address decoder where ext-plutovg is loaded. */
final class PlutoImageDecoderDriver extends ImageDecoderDriver
{
    /**
     * @throws ImageException When ext-plutovg is not loaded. Images skips a decoder that throws this.
     */
    public function __construct()
    {
        extension_loaded('plutovg') || throw new ImageException('ext-plutovg is not loaded in this PHP.');
    }

    public function driver(): string
    {
        return 'plutovg';
    }

    public function decoder(): ImageDecoder
    {
        return new PlutoImageDecoder();
    }
}
