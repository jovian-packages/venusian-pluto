<?php

namespace Jovian\Rendering\Pluto;

use RasterScanner;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\RasterizeException;
use Surface\Contracts\Rendering\Rasterization\Scanner;
use Surface\VelvetGE\Drivers\Rasterization\ScannerRasterizationDriver;
use Surface\VelvetGE\Rasterize\CLandScanner;
use Surface\VelvetGE\Rasterize\PHPLandScanner;

/**
 * 'plutovg': paths filled by PlutoVG through ext-plutovg. PlutoVG always anti-aliases, so hard
 * edges are VelvetGE's (ext-rasterize's scanner when loaded, else the PHP one).
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
        if ($edges === Edges::HARD) {
            return class_exists(RasterScanner::class) ? new CLandScanner($clip, $edges) : new PHPLandScanner($clip, $edges);
        }

        return new PlutoVGScanner($clip, $edges);
    }
}
