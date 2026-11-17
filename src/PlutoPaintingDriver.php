<?php

namespace Jovian\Rendering\Pluto;

use Surface\Contracts\Rendering\Painting\Brush;
use Surface\Contracts\Rendering\Rasterization\RasterizeDriver;
use Surface\Contracts\Rendering\Recording\RecordDriver;
use Surface\Contracts\Rendering\RenderingException;
use VelvetGE\Painting\BrushPaintingDriver;

/**
 * 'plutovg' painting: VelvetGE's painter with PlutoBrush. What the brush does not take is
 * rasterized by PlutoVG too, unless another rasterizer is given. Without ext-plutovg (and its
 * plutovg_surface_blend_colour() addition) the constructor throws, so 'auto' passes over it.
 */
class PlutoPaintingDriver extends BrushPaintingDriver
{
    /** As BrushPaintingDriver takes them: what VelvetGE's painter rasterizes the rest with (PlutoVG's own when null), direct fills, and what frames are recorded with. */
    public function __construct(?RasterizeDriver $rasterize = null, bool $directFill = true, ?RecordDriver $record = null)
    {
        function_exists('plutovg_surface_blend_colour') || throw new RenderingException('ext-plutovg with plutovg_surface_blend_colour() is not loaded.');
        parent::__construct($rasterize ?? new PlutoVGRasterizationDriver(), $directFill, $record);
    }

    public function driver(): string
    {
        return 'plutovg';
    }

    public function brush(): Brush
    {
        return new PlutoBrush();
    }
}
