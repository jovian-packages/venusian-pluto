<?php

declare(strict_types=1);

use Jovian\Engines\Velvet\Lustrous\Rasterization\PHPLandScanner;
use Jovian\Engines\Velvet\Lustrous\Stroking\PHPStroker;
use Jovian\Rendering\Pluto\Stroking\PlutoVGStroker;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\Spans;
use Surface\Contracts\Rendering\Rasterization\DashGeometry;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\Contracts\Rendering\Rasterization\StrokeCap;
use Surface\Contracts\Rendering\Rasterization\StrokeJoin;

/*
 * PlutoVG's stroker against Lustrous's PHP stroker: the outline filled non-zero with Lustrous's
 * scanner, both ways. Measured 2026-11-15 over 300 random polylines (2-6 points, 0.5-12 px wide,
 * every cap and join, open and closed): 0.24% of pixels beyond 13 of Lustrous's, area within 0.31% (PlutoVG keeps 1/64 px). Where they part most is a two-point polyline
 * marked closed, which turns back on itself: each library joins that reversal its own way. Runs
 * inside venusian-velvet's suite with this package autoloaded.
 */

function pluto_stroker(): PlutoVGStroker
{
    if (! function_exists('plutovg_path_stroke_outline')) {
        test()->markTestSkipped('ext-plutovg with plutovg_path_stroke_outline() is not loaded.');
    }

    return new PlutoVGStroker();
}

/** @return array{float, float, float, float} min x, min y, max x, max y */
function pluto_box(array $contours): array
{
    $xs = $ys = [];
    foreach ($contours as $flat) {
        foreach (array_chunk($flat, 2) as [$x, $y]) {
            [$xs[], $ys[]] = [$x, $y];
        }
    }

    return [round(min($xs), 3) + 0.0, round(min($ys), 3) + 0.0, round(max($xs), 3) + 0.0, round(max($ys), 3) + 0.0];
}

/** The outline's coverage, filled non-zero by Lustrous's scanner. */
function pluto_outline_cells(array $contours): array
{
    $cells = [];
    foreach (Spans::unpack((new PHPLandScanner(new Region(0, 0, 200, 150), Edges::ANTIALIASED))->path($contours, FillRule::NON_ZERO)) as [$y, $x, $length, $coverage]) {
        for ($i = 0; $i < $length; $i++) {
            $cells[$y * 65536 + $x + $i] = $coverage;
        }
    }

    return $cells;
}

it('outlines a butt, a square and a round cap and a miter corner where the stroke is', function (): void {
    $s = pluto_stroker();
    $round = pluto_outline_cells($s->outline([[10, 10], [50, 10]], 8.0, false, StrokeCap::ROUND));

    expect(pluto_box($s->outline([[10, 10], [50, 10]], 8.0, false)))->toBe([10.0, 6.0, 50.0, 14.0])
        ->and(array_sum(pluto_outline_cells($s->outline([[10, 10], [50, 10]], 8.0, false))) / 255)->toEqual(320.0)
        ->and(pluto_box($s->outline([[10, 10], [50, 10]], 8.0, false, StrokeCap::SQUARE)))->toBe([6.0, 6.0, 54.0, 14.0])
        ->and(pluto_box($s->outline([[0, 0], [40, 0], [40, 40]], 10.0, false, StrokeCap::BUTT, StrokeJoin::MITER)))->toBe([0.0, -5.0, 45.0, 40.0])
        // a 40 x 8 band and two half discs of radius 4
        ->and(abs(array_sum($round) / 255 - (320 + M_PI * 16)))->toBeLessThan(1.5)
        ->and($s->outline([[10, 10], [50, 10]], 0.0, false))->toBe([]);
});

it('fills within its measured distance of Lustrous\'s stroker', function (): void {
    $s = pluto_stroker();
    $lustrous = new PHPStroker();
    [$beyond, $all, $a, $b] = [0, 0, 0, 0];
    for ($i = 0; $i < 300; $i++) {
        mt_srand(4000 + $i);
        $points = [];
        for ($k = mt_rand(2, 6); $k > 0; $k--) {
            $points[] = [mt_rand(1000, 19000) / 100, mt_rand(1000, 14000) / 100];
        }
        $width = mt_rand(50, 1200) / 100;
        $closed = (bool) mt_rand(0, 1);
        $cap = [StrokeCap::BUTT, StrokeCap::ROUND, StrokeCap::SQUARE][mt_rand(0, 2)];
        $join = [StrokeJoin::MITER, StrokeJoin::ROUND, StrokeJoin::BEVEL][mt_rand(0, 2)];
        $p = pluto_outline_cells($lustrous->outline($points, $width, $closed, $cap, $join));
        $q = pluto_outline_cells($s->outline($points, $width, $closed, $cap, $join));
        foreach ($p + $q as $key => $_) {
            $beyond += abs(($p[$key] ?? 0) - ($q[$key] ?? 0)) > 13 ? 1 : 0;
            $all++;
        }
        [$a, $b] = [$a + array_sum($p), $b + array_sum($q)];
    }

    expect($beyond / $all)->toBeLessThanOrEqual(0.003)
        ->and(abs($b / $a - 1))->toBeLessThanOrEqual(0.004);
});

it('cuts dashes as PlutoVG does: on, off, on, along the line', function (): void {
    $s = pluto_stroker();
    $pieces = $s->dashed([[0, 0], [100, 0]], false, [10, 5], 0.0);

    expect($s)->toBeInstanceOf(DashGeometry::class)
        ->and(count($pieces))->toBe(7)
        ->and(array_map(fn (array $piece): array => [round($piece['points'][0][0], 2) + 0.0, round(end($piece['points'])[0], 2) + 0.0], $pieces))
        ->toBe([[0.0, 10.0], [15.0, 25.0], [30.0, 40.0], [45.0, 55.0], [60.0, 70.0], [75.0, 85.0], [90.0, 100.0]]);
});
