<?php

namespace Jovian\Rendering\Pluto\Stroking;

use plutovg_path;
use plutovg_path_iterator;
use Surface\Contracts\Rendering\Rasterization\DashGeometry;
use Surface\Contracts\Rendering\Rasterization\StrokeCap;
use Surface\Contracts\Rendering\Rasterization\StrokeJoin;
use Surface\Contracts\Rendering\RenderingException;
use Surface\NutsAndBolts\Path;

/**
 * PlutoVG's stroker: plutovg_path_stroke_outline() (an ext-plutovg addition) runs the stroker
 * PlutoVG fills its own strokes with, a copy of FreeType's. Its round joins and caps are curves,
 * flattened to contours within 0.1 px, at PlutoVG's 1/64 px precision.
 *
 * Dashes are PlutoVG's too: plutovg_path_clone_dashed() cuts the polyline into pieces. A zero-length
 * stroke is PlutoVG's, so the direction outline() is given is not used.
 */
final class PlutoVGStroker implements DashGeometry
{
    /** How far a flattened round join or cap strays from its arc, in pixels. */
    private const float FLATNESS = 0.1;

    public function __construct()
    {
        function_exists('plutovg_path_stroke_outline') || throw new RenderingException('ext-plutovg with plutovg_path_stroke_outline() is not loaded in this PHP ('.PHP_BINARY.').');
    }

    public function outline(array $points, float $stroke, bool $closed, StrokeCap $cap = StrokeCap::BUTT, StrokeJoin $join = StrokeJoin::MITER, float $miterLimit = self::MITER_LIMIT, ?array $direction = null): array
    {
        if (! ($stroke > 0) || $points === []) {
            return [];
        }
        $outline = plutovg_path_stroke_outline(self::polyline($points, $closed), $stroke, match ($cap) {
            StrokeCap::BUTT => PLUTOVG_LINE_CAP_BUTT,
            StrokeCap::ROUND => PLUTOVG_LINE_CAP_ROUND,
            StrokeCap::SQUARE => PLUTOVG_LINE_CAP_SQUARE,
        }, match ($join) {
            StrokeJoin::MITER => PLUTOVG_LINE_JOIN_MITER,
            StrokeJoin::ROUND => PLUTOVG_LINE_JOIN_ROUND,
            StrokeJoin::BEVEL => PLUTOVG_LINE_JOIN_BEVEL,
        }, $miterLimit);

        $contours = [];
        foreach (self::read($outline)->flatten(self::FLATNESS) as ['points' => $ring]) {
            if (count($ring) >= 3) {
                $contours[] = array_merge(...$ring);
            }
        }

        return $contours;
    }

    public function dashed(array $points, bool $closed, array $dash, float $offset): array
    {
        $pattern = array_values(array_map(floatval(...), $dash));
        if ($points === [] || $pattern === [] || max($pattern) <= 1e-12) {
            return [];
        }
        $pieces = [];
        foreach (self::read(plutovg_path_clone_dashed(self::polyline($points, $closed), $offset, $pattern, count($pattern)))->flatten(self::FLATNESS) as ['points' => $run, 'closed' => $shut]) {
            if ($run === []) {
                continue;
            }
            $last = count($run) - 1;
            $dx = $last > 0 ? $run[$last][0] - $run[$last - 1][0] : 1.0;
            $dy = $last > 0 ? $run[$last][1] - $run[$last - 1][1] : 0.0;
            $length = hypot($dx, $dy);
            $pieces[] = ['points' => $run, 'closed' => $shut, 'direction' => $length > 0 ? [$dx / $length, $dy / $length] : [1.0, 0.0]];
        }

        return $pieces;
    }

    /** @param list<array{float, float}> $points */
    private static function polyline(array $points, bool $closed): plutovg_path
    {
        $path = plutovg_path_create();
        foreach (array_values($points) as $i => [$x, $y]) {
            $i === 0 ? plutovg_path_move_to($path, (float) $x, (float) $y) : plutovg_path_line_to($path, (float) $x, (float) $y);
        }
        if ($closed) {
            plutovg_path_close($path);
        }

        return $path;
    }

    /** A PlutoVG path as Velvet's Path. */
    private static function read(plutovg_path $source): Path
    {
        $path = new Path();
        $it = new plutovg_path_iterator();
        plutovg_path_iterator_init($it, $source);
        while (plutovg_path_iterator_has_next($it)) {
            $command = plutovg_path_iterator_next($it, $p);
            match ($command) {
                PLUTOVG_PATH_COMMAND_MOVE_TO => $path->moveTo($p[0]->x, $p[0]->y),
                PLUTOVG_PATH_COMMAND_LINE_TO => $path->lineTo($p[0]->x, $p[0]->y),
                PLUTOVG_PATH_COMMAND_CUBIC_TO => $path->cubicTo($p[0]->x, $p[0]->y, $p[1]->x, $p[1]->y, $p[2]->x, $p[2]->y),
                PLUTOVG_PATH_COMMAND_CLOSE => $path->close(),
            };
        }

        return $path;
    }
}
