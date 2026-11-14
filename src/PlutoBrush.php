<?php

namespace Jovian\Rendering\Pluto;

use plutovg_path;
use plutovg_rect;
use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\ScanDirection;
use Surface\Contracts\Rendering\Painting\Brush;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\Scanner;
use Surface\Contracts\Rendering\RenderingException;
use Surface\NutsAndBolts\GradientSpread;
use Surface\Rendering\Rasterization\Rasterizer;
use Surface\VelvetGE\Drivers\Rasterization\PHPLandRasterizationDriver;
use Surface\VelvetGE\Framebuffers\Layout;
use ValueError;

/**
 * PlutoVG's brush. On B G R A memory, PlutoVG's own layout, a solid shape is drawn in place and
 * PlutoVG blends it (within PlutoVGScanner::BLEND of VelvetGE's blend). Otherwise, and for every
 * gradient, PlutoVG draws the shape's anti-aliased coverage and ext-plutovg blends it into straight
 * 32-bit memory with VelvetGE's arithmetic (plutovg_surface_blend_colour / _ramp), so those pixels
 * differ from VelvetGE's by PlutoVG's edge coverage alone.
 *
 * Shapes are prepared exactly as the rasterizer prepares them (Rasterizer::lower()): radii held,
 * a stroke turned into its outline. The brush takes anti-aliased path, gradpath, polyline, ellipse,
 * ring, roundrect and roundring commands; PlutoVG has no hard-edge mode, so hard edges, and clear,
 * shadow, glass, layer, image, glyphs and spans, are VelvetGE's painter's.
 */
final class PlutoBrush implements Brush
{
    /**
     * Worst per-byte distance from VelvetGE's native painter, one anti-aliased shape at a time
     * (measured 2026-11-13 over 25 seeds a kind, four channel orders): rects 8, lines 3, ellipses
     * 27, rings 40, round rects 32. VelvetGE samples sub-scanlines where PlutoVG takes exact area,
     * so diagonal edges differ most; areas agree within 0.6%. Where a path's own contours cross
     * inside a pixel (self-crossing fills, stroke joins, polylines) PlutoVG sums their coverage and
     * a pixel can differ by up to 101: under 1% of the pixels that differ at all.
     */
    public const int TOLERANCE = 40;

    /** The commands painted, and where each holds its clip. */
    private const array SHAPES = ['path' => 4, 'gradpath' => 4, 'polyline' => 5, 'ellipse' => 6, 'ring' => 7, 'roundrect' => 7, 'roundring' => 8];

    /** A cubic's control distance for a quarter circle of radius 1. */
    private const float KAPPA = 0.5522847498307936;

    /** @var array<string, Rasterizer> by clip: what lowers a command's shape */
    private array $lowering = [];

    private plutovg_path $path;

    private plutovg_rect $extents;

    public function __construct()
    {
        function_exists('plutovg_surface_blend_colour') || throw new RenderingException('ext-plutovg with plutovg_surface_blend_colour() is not loaded in this PHP.');
        $this->path = plutovg_path_create();
        $this->extents = new plutovg_rect();
    }

    /** Four bytes a pixel in any of the four channel orders, top row first. */
    public function drawsInto(PixelMemory $memory): bool
    {
        try {
            return Layout::of($memory->format) === Layout::RGBA8888
                && $memory->format->scan_direction === ScanDirection::TOP_TO_BOTTOM
                && $memory->stride >= $memory->width * 4;
        } catch (FramebufferException) {
            return false;    // not a layout at all
        }
    }

    public function takes(array $command, Edges $edges): bool
    {
        return $edges === Edges::ANTIALIASED && isset(self::SHAPES[$command[0] ?? null]);
    }

    public function paint(array $commands, Region $rows, PixelMemory $memory, Edges $edges): array
    {
        $order = match (Layout::channelOrder($memory->format)) {
            ChannelOrder::BGRA => PLUTOVG_MEMORY_BGRA,
            ChannelOrder::ARGB => PLUTOVG_MEMORY_ARGB,
            ChannelOrder::ABGR => PLUTOVG_MEMORY_ABGR,
            default => PLUTOVG_MEMORY_RGBA,
        };

        // on B G R A memory solid shapes are drawn in place; one canvas over it, clipped per command
        $canvas = PlutoVGScanner::inPlace($memory) ? PlutoVGScanner::canvasOver($memory, new Region(0, 0, $memory->width, $memory->height)) : null;
        $touched = [];
        foreach ($commands as $command) {
            $cut = $command[self::SHAPES[$command[0]]]->intersect($rows);
            $touched[] = is_null($cut) ? null : $this->command($command, $cut, $edges, $memory, $order, $canvas);
        }

        return $touched;
    }

    /** One command inside $cut: its shape's coverage from PlutoVG, blended into the memory. */
    private function command(array $command, Region $cut, Edges $edges, PixelMemory $memory, int $order, ?\plutovg_canvas $canvas): ?Region
    {
        $rasterizer = $this->lowering["{$cut->x},{$cut->y},{$cut->width},{$cut->height}"] ??= (new PHPLandRasterizationDriver())->rasterizer($cut, $edges);
        $gradient = $command[0] === 'gradpath';
        [$ops, $colours] = $rasterizer->lower([$gradient ? ['path', $command[1], $command[2], 0, $cut] : $command]);
        if ($ops === []) {
            return null;
        }

        $rule = $this->shape($ops[0]);
        if (! is_null($canvas) && ! $gradient) {
            // in place: PlutoVG blends the shape itself, inside $cut
            $area = ($colours[0] & 0xFF) === 0 ? null : PlutoVGScanner::extents($this->path)?->intersect($cut);
            if (is_null($area) || $area->isEmpty()) {
                return null;
            }
            plutovg_canvas_save($canvas);
            plutovg_canvas_clip_rect($canvas, $cut->x, $cut->y, $cut->width, $cut->height);
            PlutoVGScanner::paint($canvas, $this->path, $rule, $colours[0]);
            plutovg_canvas_restore($canvas);

            return $area;
        }
        $coverage = $this->coverage($cut, $rule);
        if (is_null($coverage)) {
            return null;
        }
        [$surface, $x, $y] = $coverage;

        try {
            if ($gradient) {
                $g = $command[3];
                $m = $g['inverse'];
                $box = plutovg_surface_blend_ramp($surface, $memory->address, $memory->width, $memory->height, $memory->stride, $order, $x, $y,
                    $g['ramp'], $g['kind'] === 'radial', match ($g['spread']) {
                        GradientSpread::PAD => PLUTOVG_SPREAD_METHOD_PAD,
                        GradientSpread::REPEAT => PLUTOVG_SPREAD_METHOD_REPEAT,
                        GradientSpread::REFLECT => PLUTOVG_SPREAD_METHOD_REFLECT,
                    }, [$m->a, $m->b, $m->c, $m->d, $m->e, $m->f]);
            } else {
                $box = plutovg_surface_blend_colour($surface, $memory->address, $memory->width, $memory->height, $memory->stride, $order, $x, $y, $colours[0]);
            }
        } catch (ValueError $e) {
            throw new RenderingException($e->getMessage(), previous: $e);
        }

        return is_null($box) ? null : new Region(...$box);
    }

    /** The scanner op as PlutoVG's path in $this->path; answers the fill rule it is filled with. */
    private function shape(array $op): int
    {
        plutovg_path_reset($this->path);

        return match ($op[0]) {
            Scanner::PATH => $this->contours($op[1], $op[2]),
            Scanner::ELLIPSE => $this->ellipse($op[1], $op[2], $op[3], $op[4]),
            Scanner::RING => $this->ring($op[1], $op[2], $op[3], $op[4], $op[5]),
            Scanner::ROUND_RECT => $this->roundRect($op[1], $op[2], $op[3], $op[4], $op[5]),
            Scanner::ROUND_RING => $this->roundRing($op[1], $op[2], $op[3], $op[4], $op[5], $op[6]),
            default => throw new RenderingException("PlutoBrush has no shape for scanner op {$op[0]}."),
        };
    }

    /** @param list<list<float>> $contours flat x, y lists, each closed */
    private function contours(array $contours, int $evenOdd): int
    {
        foreach ($contours as $flat) {
            plutovg_path_move_to($this->path, $flat[0], $flat[1]);
            for ($i = 2, $n = count($flat); $i < $n; $i += 2) {
                plutovg_path_line_to($this->path, $flat[$i], $flat[$i + 1]);
            }
            plutovg_path_close($this->path);
        }

        return $evenOdd === 1 ? PLUTOVG_FILL_RULE_EVEN_ODD : PLUTOVG_FILL_RULE_NON_ZERO;
    }

    private function ellipse(float $cx, float $cy, float $rx, float $ry): int
    {
        plutovg_path_add_ellipse($this->path, $cx, $cy, $rx, $ry);

        return PLUTOVG_FILL_RULE_NON_ZERO;
    }

    /** The band between radii r ± stroke / 2; all of the outer ellipse when the inner one vanishes. */
    private function ring(float $cx, float $cy, float $rx, float $ry, float $stroke): int
    {
        $half = $stroke / 2;
        plutovg_path_add_ellipse($this->path, $cx, $cy, $rx + $half, $ry + $half);
        if ($rx - $half > 0 && $ry - $half > 0) {
            plutovg_path_add_ellipse($this->path, $cx, $cy, $rx - $half, $ry - $half);
        }

        return PLUTOVG_FILL_RULE_EVEN_ODD;
    }

    /** @param array{float, float, float, float} $radii top-left, top-right, bottom-right, bottom-left, already held to the box */
    private function roundRect(float $x0, float $y0, float $x1, float $y1, array $radii): int
    {
        $this->addRoundRect($x0, $y0, $x1, $y1, array_values($radii));

        return PLUTOVG_FILL_RULE_NON_ZERO;
    }

    /** The band inside the box, $inset wide: the inner box's radii are max(0, r − inset). */
    private function roundRing(float $x0, float $y0, float $x1, float $y1, array $radii, float $inset): int
    {
        $r = array_values($radii);
        $this->addRoundRect($x0, $y0, $x1, $y1, $r);
        [$ix0, $iy0, $ix1, $iy1] = [$x0 + $inset, $y0 + $inset, $x1 - $inset, $y1 - $inset];
        if ($ix1 > $ix0 && $iy1 > $iy0) {
            $this->addRoundRect($ix0, $iy0, $ix1, $iy1, array_map(fn (float $v): float => max(0.0, $v - $inset), $r));
        }

        return PLUTOVG_FILL_RULE_EVEN_ODD;
    }

    /** @param list<float> $r top-left, top-right, bottom-right, bottom-left: circular corners */
    private function addRoundRect(float $x0, float $y0, float $x1, float $y1, array $r): void
    {
        [$tl, $tr, $br, $bl] = $r;
        $k = self::KAPPA;
        $p = $this->path;
        plutovg_path_move_to($p, $x0 + $tl, $y0);
        plutovg_path_line_to($p, $x1 - $tr, $y0);
        if ($tr > 0) {
            plutovg_path_cubic_to($p, $x1 - $tr + $tr * $k, $y0, $x1, $y0 + $tr - $tr * $k, $x1, $y0 + $tr);
        }
        plutovg_path_line_to($p, $x1, $y1 - $br);
        if ($br > 0) {
            plutovg_path_cubic_to($p, $x1, $y1 - $br + $br * $k, $x1 - $br + $br * $k, $y1, $x1 - $br, $y1);
        }
        plutovg_path_line_to($p, $x0 + $bl, $y1);
        if ($bl > 0) {
            plutovg_path_cubic_to($p, $x0 + $bl - $bl * $k, $y1, $x0, $y1 - $bl + $bl * $k, $x0, $y1 - $bl);
        }
        plutovg_path_line_to($p, $x0, $y0 + $tl);
        if ($tl > 0) {
            plutovg_path_cubic_to($p, $x0, $y0 + $tl - $tl * $k, $x0 + $tl - $tl * $k, $y0, $x0 + $tl, $y0);
        }
        plutovg_path_close($p);
    }

    /**
     * $this->path filled in opaque white over the part of $cut its extents reach: the surface whose
     * alpha is the coverage, and where its top-left sits in the memory. Null when it reaches none.
     *
     * @return array{\plutovg_surface, int, int}|null
     */
    private function coverage(Region $cut, int $rule): ?array
    {
        plutovg_path_extents($this->path, $this->extents, false);
        $box = (new Region(
            (int) floor($this->extents->x) - 1,
            (int) floor($this->extents->y) - 1,
            (int) ceil($this->extents->w) + 3,
            (int) ceil($this->extents->h) + 3,
        ))->intersect($cut);
        if (is_null($box)) {
            return null;
        }

        $surface = plutovg_surface_create($box->width, $box->height) ?? throw new RenderingException("PlutoVG could not make a {$box->width}x{$box->height} surface.");
        $canvas = plutovg_canvas_create($surface);
        plutovg_canvas_translate($canvas, -$box->x, -$box->y);
        plutovg_canvas_set_rgba($canvas, 1.0, 1.0, 1.0, 1.0);
        plutovg_canvas_set_fill_rule($canvas, $rule);
        plutovg_canvas_fill_path($canvas, $this->path);

        return [$surface, $box->x, $box->y];
    }
}
