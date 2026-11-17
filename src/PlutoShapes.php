<?php

namespace Jovian\Rendering\Pluto;

use plutovg_path;
use Surface\Contracts\Rendering\Rasterization\Scanner;

/**
 * Velvet's shape questions as PlutoVG geometry, shared by the scanner and the brush. Each op is
 * added to a PlutoVG path with PlutoVG's own calls: ellipses with plutovg_path_add_ellipse(),
 * round corners with plutovg_path_add_round_rect() when all four radii are equal and
 * plutovg_path_arc_to() each corner otherwise. A band (ring, round ring) is its outer and inner
 * shape filled even-odd.
 */
final class PlutoShapes
{
    /**
     * $op added to $path. Answers the fill rule to fill it with.
     *
     * @param  list<mixed>  $op  a PATH, ELLIPSE, RING, ROUND_RECT or ROUND_RING op
     */
    public static function add(plutovg_path $path, array $op): int
    {
        return match ($op[0]) {
            Scanner::PATH => self::contours($path, $op[1], (int) $op[2]),
            Scanner::ELLIPSE => self::ellipse($path, $op[1], $op[2], $op[3], $op[4]),
            Scanner::RING => self::ring($path, $op[1], $op[2], $op[3], $op[4], $op[5]),
            Scanner::ROUND_RECT => self::roundRect($path, $op[1], $op[2], $op[3], $op[4], array_values($op[5])),
            Scanner::ROUND_RING => self::roundRing($path, $op[1], $op[2], $op[3], $op[4], array_values($op[5]), $op[6]),
        };
    }

    /** @param list<list<float>> $contours flat x, y lists, closed implicitly */
    private static function contours(plutovg_path $path, array $contours, int $evenOdd): int
    {
        foreach ($contours as $flat) {
            $n = intdiv(count($flat), 2);
            if ($n < 2) {
                continue;
            }
            plutovg_path_move_to($path, $flat[0], $flat[1]);
            for ($i = 1; $i < $n; $i++) {
                plutovg_path_line_to($path, $flat[2 * $i], $flat[2 * $i + 1]);
            }
            plutovg_path_close($path);
        }

        return $evenOdd === 1 ? PLUTOVG_FILL_RULE_EVEN_ODD : PLUTOVG_FILL_RULE_NON_ZERO;
    }

    private static function ellipse(plutovg_path $path, float $cx, float $cy, float $rx, float $ry): int
    {
        plutovg_path_add_ellipse($path, $cx, $cy, $rx, $ry);

        return PLUTOVG_FILL_RULE_NON_ZERO;
    }

    /** The band between radii r ± stroke / 2; all of the outer ellipse when the inner one vanishes. */
    private static function ring(plutovg_path $path, float $cx, float $cy, float $rx, float $ry, float $stroke): int
    {
        $half = $stroke / 2;
        plutovg_path_add_ellipse($path, $cx, $cy, $rx + $half, $ry + $half);
        if ($rx - $half > 0 && $ry - $half > 0) {
            plutovg_path_add_ellipse($path, $cx, $cy, $rx - $half, $ry - $half);
        }

        return PLUTOVG_FILL_RULE_EVEN_ODD;
    }

    /** @param list<float> $radii top-left, top-right, bottom-right, bottom-left, already held to the box */
    private static function roundRect(plutovg_path $path, float $x0, float $y0, float $x1, float $y1, array $radii): int
    {
        self::box($path, $x0, $y0, $x1, $y1, $radii);

        return PLUTOVG_FILL_RULE_NON_ZERO;
    }

    /** The band inside the box, $inset wide: the inner box's radii are max(0, r − inset). */
    private static function roundRing(plutovg_path $path, float $x0, float $y0, float $x1, float $y1, array $radii, float $inset): int
    {
        self::box($path, $x0, $y0, $x1, $y1, $radii);
        [$ix0, $iy0, $ix1, $iy1] = [$x0 + $inset, $y0 + $inset, $x1 - $inset, $y1 - $inset];
        if ($ix1 > $ix0 && $iy1 > $iy0) {
            self::box($path, $ix0, $iy0, $ix1, $iy1, array_map(fn (float $v): float => max(0.0, $v - $inset), $radii));
        }

        return PLUTOVG_FILL_RULE_EVEN_ODD;
    }

    /** @param list<float> $r top-left, top-right, bottom-right, bottom-left: circular corners */
    private static function box(plutovg_path $p, float $x0, float $y0, float $x1, float $y1, array $r): void
    {
        [$tl, $tr, $br, $bl] = $r;
        if ($tl === $tr && $tr === $br && $br === $bl) {
            plutovg_path_add_round_rect($p, $x0, $y0, $x1 - $x0, $y1 - $y0, $tl, $tl);

            return;
        }
        plutovg_path_move_to($p, $x0 + $tl, $y0);
        plutovg_path_line_to($p, $x1 - $tr, $y0);
        if ($tr > 0) {
            plutovg_path_arc_to($p, $tr, $tr, 0, false, true, $x1, $y0 + $tr);
        }
        plutovg_path_line_to($p, $x1, $y1 - $br);
        if ($br > 0) {
            plutovg_path_arc_to($p, $br, $br, 0, false, true, $x1 - $br, $y1);
        }
        plutovg_path_line_to($p, $x0 + $bl, $y1);
        if ($bl > 0) {
            plutovg_path_arc_to($p, $bl, $bl, 0, false, true, $x0, $y1 - $bl);
        }
        plutovg_path_line_to($p, $x0, $y0 + $tl);
        if ($tl > 0) {
            plutovg_path_arc_to($p, $tl, $tl, 0, false, true, $x0 + $tl, $y0);
        }
        plutovg_path_close($p);
    }
}
