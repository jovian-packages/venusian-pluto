<?php

namespace Jovian\Rendering\Pluto\Framebuffers;

use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Rendering\Framebuffers\FramebufferDriver;

/** PlutoVG as a Velvet framebuffer module: every make() is one PlutoVGSurface (BGRA8). */
class PlutoVGFramebufferDriver extends FramebufferDriver
{
    /** @throws FramebufferException When ext-plutovg is not loaded. */
    public function __construct()
    {
        if (! extension_loaded('plutovg')) {
            throw FramebufferException::extensionMissing('plutovg', '0.11.0');
        }
    }

    public function driver(): string
    {
        return 'plutovg';
    }

    /** @throws FramebufferException For any format but BGRA8. */
    public function make(FormatSpec $format, int $width, int $height): PlutoVGSurface
    {
        return new PlutoVGSurface($format, $width, $height);
    }
}
