<?php

namespace Jovian\Rendering\Pluto\Stroking;

use Surface\Contracts\Rendering\Rasterization\StrokeGeometry;
use Surface\Rendering\Stroking\StrokingDriver;

/** 'plutovg': PlutoVG's stroker, with PlutoVG's dashes. */
class PlutoVGStrokingDriver extends StrokingDriver
{
    private PlutoVGStroker $stroker;

    public function __construct()
    {
        $this->stroker = new PlutoVGStroker();
    }

    public function driver(): string
    {
        return 'plutovg';
    }

    public function stroker(): StrokeGeometry
    {
        return $this->stroker;
    }
}
