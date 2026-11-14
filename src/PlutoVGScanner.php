<?php

namespace Jovian\Rendering\Pluto;

use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\ScanDirection;
use Surface\Contracts\Rendering\Rasterization\DirectScanner;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\Contracts\Rendering\Rasterization\MaskScanner;
use Surface\Contracts\Rendering\Rasterization\Scanner;
use Surface\VelvetGE\Framebuffers\Layout;

/**
 * Fills paths with PlutoVG's anti-aliased rasteriser. A path is filled in opaque white onto a scratch
 * surface the size of its extents inside the clip, and plutovg_surface_get_alpha() reads that back as
 * a8 coverage in one C call. mask() answers the a8 as it is (VelvetGE lands it with the store's
 * paintMasks()); path() run-length encodes the same bytes into spans. Both come from one render, so
 * they are the same coverage. The other tiers come from VelvetGE through the completing scanner.
 *
 * Into B G R A memory (PlutoVG's own pixel layout on a little-endian host) fill() draws the paths
 * in place instead. PlutoVG's premultiplied source-over on the stored bytes is VelvetGE's blend:
 * measured 2026-11-13 over 3,000 translucent polygons on pixels of every alpha, at most BLEND off
 * per byte from VelvetGE blending PlutoVG's own coverage (99.9% of differing bytes 1 off).
 */
class PlutoVGScanner implements DirectScanner, MaskScanner
{
    /**
     * Greatest coverage difference from VelvetGE's scanner at any pixel of a simple shape (measured
     * 2026-11-13 over 300 random rects, triangles, rect strokes and lines). Where a path's contours
     * overlap inside one pixel, FreeType's rasteriser sums their coverage and the difference at that
     * pixel can reach 220; those pixels are under 1% of a shape and its area stays within 0.5%.
     */
    public const int TOLERANCE = 11;

    /** Greatest per-byte distance between drawing in place and VelvetGE blending the same coverage. */
    public const int BLEND = 2;

    /** Scratch surfaces are at most this wide and high (PlutoVG refuses 2^15; 4096² is 64 MiB). */
    private const int TILE = 4096;

    public function __construct(
        private readonly Region $clip,
        private readonly Edges $edges,
    ) {}

    public function clip(): Region
    {
        return $this->clip;
    }

    public function edges(): Edges
    {
        return $this->edges;
    }

    public function path(array $contours, FillRule $rule): string
    {
        $coverage = $this->coverage($contours, $rule === FillRule::EVEN_ODD);
        if ($coverage === null) {
            return '';
        }
        [$a8, $area] = $coverage;
        $spans = '';
        for ($r = 0; $r < $area->height; $r++) {
            $row = substr($a8, $r * $area->width, $area->width);
            for ($o = 0; $o < $area->width;) {
                $byte = $row[$o];
                $run = strspn($row, $byte, $o);
                if ($byte !== "\0") {
                    $spans .= pack('vvvC', $area->y + $r, $area->x + $o, $run, ord($byte));
                }
                $o += $run;
            }
        }

        return $spans;
    }

    /** @param list<mixed> $op [Scanner::PATH, list of flat contours, 0 non-zero | 1 even-odd] */
    public function mask(array $op): ?array
    {
        if (($op[0] ?? null) !== Scanner::PATH) {
            return null;
        }
        $coverage = $this->coverage($op[1], (int) $op[2] === 1);
        if ($coverage === null) {
            return null;
        }
        [$a8, $area] = $coverage;

        return [$a8, $area->width, $area->x, $area->y];
    }

    /** B G R A rows, top row first, a whole number of pixels apart, on a little-endian host. */
    public function drawsInto(PixelMemory $memory): bool
    {
        return self::inPlace($memory);
    }

    /**
     * Each path drawn into $memory by PlutoVG, inside the clip. The region answered for an op is its
     * extents inside the clip: every pixel it changed is in there.
     *
     * @param  list<list<mixed>>  $ops  PATH ops
     * @param  list<int>  $colours  0xRRGGBBAA
     */
    public function fill(array $ops, array $colours, PixelMemory $memory): array
    {
        $clip = $this->clip->intersect(new Region(0, 0, $memory->width, $memory->height));
        if (is_null($clip) || $clip->isEmpty()) {
            return array_fill(0, count($ops), null);
        }
        $canvas = self::canvasOver($memory, $clip);
        $touched = [];
        foreach ($ops as $i => $op) {
            $path = self::path_of($op[1]);
            $area = ($colours[$i] & 0xFF) === 0 ? null : self::extents($path)?->intersect($clip);
            if (is_null($area) || $area->isEmpty()) {
                $touched[] = null;

                continue;
            }
            self::paint($canvas, $path, (int) $op[2] === 1 ? PLUTOVG_FILL_RULE_EVEN_ODD : PLUTOVG_FILL_RULE_NON_ZERO, $colours[$i]);
            $touched[] = $area;
        }

        return $touched;
    }

    /** Whether PlutoVG can draw into $memory as it is: B G R A rows, top first, little-endian host. */
    public static function inPlace(PixelMemory $memory): bool
    {
        try {
            return Layout::of($memory->format) === Layout::RGBA8888
                && Layout::channelOrder($memory->format) === ChannelOrder::BGRA
                && $memory->format->scan_direction === ScanDirection::TOP_TO_BOTTOM
                && $memory->stride % 4 === 0
                && $memory->stride >= $memory->width * 4
                && pack('V', 1) === pack('L', 1);
        } catch (FramebufferException) {
            return false;    // not a layout at all
        }
    }

    /** A canvas over $memory that draws nothing outside $clip. inPlace($memory) must hold. */
    public static function canvasOver(PixelMemory $memory, Region $clip): \plutovg_canvas
    {
        $surface = plutovg_surface_create_for_address($memory->address, $memory->width, $memory->height, $memory->stride, $memory->owner)
            ?? throw new \Surface\Contracts\Rendering\RenderingException('PlutoVG could not wrap the framebuffer memory.');
        $canvas = plutovg_canvas_create($surface);
        plutovg_canvas_clip_rect($canvas, $clip->x, $clip->y, $clip->width, $clip->height);

        return $canvas;
    }

    /** $path filled on $canvas in $rgba (0xRRGGBBAA, straight) by $winding. */
    public static function paint(\plutovg_canvas $canvas, \plutovg_path $path, int $winding, int $rgba): void
    {
        plutovg_canvas_set_rgba($canvas, (($rgba >> 24) & 0xFF) / 255, (($rgba >> 16) & 0xFF) / 255, (($rgba >> 8) & 0xFF) / 255, ($rgba & 0xFF) / 255);
        plutovg_canvas_set_fill_rule($canvas, $winding);
        plutovg_canvas_fill_path($canvas, $path);
    }

    /** The pixels a path's extents reach, or null for an empty path. */
    public static function extents(\plutovg_path $path): ?Region
    {
        $box = new \plutovg_rect();
        plutovg_path_extents($path, $box, true);
        $x0 = (int) floor($box->x);
        $y0 = (int) floor($box->y);
        $bounds = new Region($x0, $y0, (int) ceil($box->x + $box->w) - $x0, (int) ceil($box->y + $box->h) - $y0);

        return $bounds->isEmpty() ? null : $bounds;
    }

    /** @param list<list<float>> $contours flat x, y lists, closed implicitly */
    private static function path_of(array $contours): \plutovg_path
    {
        $path = plutovg_path_create();
        foreach ($contours as $contour) {
            $n = intdiv(count($contour), 2);
            if ($n < 2) {
                continue;
            }
            plutovg_path_move_to($path, $contour[0], $contour[1]);
            for ($i = 1; $i < $n; $i++) {
                plutovg_path_line_to($path, $contour[2 * $i], $contour[2 * $i + 1]);
            }
            plutovg_path_close($path);
        }

        return $path;
    }

    /**
     * The path's a8 coverage over its extents inside the clip, rows packed, or null when it covers
     * no pixel there.
     *
     * @param  list<list<float>>  $contours  flat x, y lists, closed implicitly
     * @return array{string, Region}|null
     */
    private function coverage(array $contours, bool $even_odd): ?array
    {
        $path = self::path_of($contours);
        $area = self::extents($path)?->intersect($this->clip);
        if ($area === null || $area->isEmpty()) {
            return null;
        }

        $winding = $even_odd ? PLUTOVG_FILL_RULE_EVEN_ODD : PLUTOVG_FILL_RULE_NON_ZERO;
        if ($area->width <= self::TILE && $area->height <= self::TILE) {
            $a8 = $this->tile($path, $winding, $area->x, $area->y, $area->width, $area->height);
        } else {
            // Larger than one scratch surface: tiles stitched row by row into one packed plane.
            $a8 = '';
            for ($y = $area->y; $y < $area->bottom(); $y += self::TILE) {
                $h = min(self::TILE, $area->bottom() - $y);
                $rows = array_fill(0, $h, '');
                for ($x = $area->x; $x < $area->right(); $x += self::TILE) {
                    $w = min(self::TILE, $area->right() - $x);
                    foreach (str_split($this->tile($path, $winding, $x, $y, $w, $h), $w) as $r => $row) {
                        $rows[$r] .= $row;
                    }
                }
                $a8 .= implode('', $rows);
            }
        }

        return strspn($a8, "\0") === strlen($a8) ? null : [$a8, $area];
    }

    /** One scratch surface's a8 coverage, $w × $h at ($x0, $y0), rows packed. */
    private function tile(\plutovg_path $path, int $winding, int $x0, int $y0, int $w, int $h): string
    {
        $surface = plutovg_surface_create($w, $h);
        $canvas = plutovg_canvas_create($surface);
        plutovg_canvas_set_rgb($canvas, 1, 1, 1);
        plutovg_canvas_set_fill_rule($canvas, $winding);
        plutovg_canvas_translate($canvas, -$x0, -$y0);
        plutovg_canvas_fill_path($canvas, $path);

        return plutovg_surface_get_alpha($surface);
    }
}
