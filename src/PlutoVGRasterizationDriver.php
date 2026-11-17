<?php

namespace Jovian\Rendering\Pluto;

use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\RasterizeException;
use Surface\Contracts\Rendering\Rasterization\Scanner;
use VelvetGE\Rasterization\ScannerRasterizationDriver;

/**
 * 'plutovg': paths and curved shapes filled by PlutoVG through ext-plutovg, always anti-aliased:
 * PlutoVG has no hard-edged mode, so a request for hard edges is drawn anti-aliased too.
 */
class PlutoVGRasterizationDriver extends ScannerRasterizationDriver
{
    public function __construct()
    {
        if (! extension_loaded('plutovg')) {
            throw RasterizeException::extensionMissing('plutovg', 'ext-plutovg');
        }
    }

    public function driver(): string
    {
        return 'plutovg';
    }

    protected function scanner(Region $clip, Edges $edges): Scanner
    {
        return new PlutoVGScanner($clip);
    }
}
