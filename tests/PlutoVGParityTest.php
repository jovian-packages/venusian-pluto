<?php

declare(strict_types=1);

/*
 * Runs inside venusian/topside's test suite (it uses topside's SpanGrid): copy this file into
 * topside's tests/Rasterization/ in a workbench with this package autoloaded. See AGENTS.md.
 */

use Jovian\Rendering\Pluto\PlutoVGRasterizationDriver;
use Jovian\Rendering\Pluto\PlutoVGScanner;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\Spans;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\Contracts\Rendering\Rasterization\Rasterizer;
use Surface\VelvetGE\Rasterize\PHPLandScanner;
use Surface\VelvetGE\Rasterize\ScannerRasterizer;
use Venusian\Topside\Tests\Support\Rasterization\SpanGrid;

/** The same shape through PlutoVG and through VelvetGE's PHP scanner, $n times with random arguments. */
function pluto_pairs(Closure $shape, int $n, Edges $edges = Edges::ANTIALIASED): array
{
    $clip = new Region(0, 0, 200, 150);
    $pluto = (new PlutoVGRasterizationDriver())->rasterizer($clip, $edges);
    $velvet = new ScannerRasterizer('native', fn ($c, $e) => new PHPLandScanner($c, $e), $clip, $edges);
    $pairs = [];
    for ($i = 0; $i < $n; $i++) {
        $seed = 1000 + $i;
        mt_srand($seed);
        $a = $shape($pluto);
        mt_srand($seed);
        $b = $shape($velvet);
        SpanGrid::assertWellFormed($a, $clip, $edges);
        $pairs[] = [$a, $b];
    }

    return $pairs;
}

function pluto_r(): float
{
    return mt_rand(0, 19000) / 100;
}

/** Share of pixels further apart than the tolerance, over every pair. */
function pluto_outliers(array $pairs): float
{
    $over = $all = 0;
    foreach ($pairs as [$a, $b]) {
        $g = [];
        foreach ([[$a, 0], [$b, 1]] as [$spans, $side]) {
            foreach (Spans::unpack($spans) as [$y, $x, $length, $coverage]) {
                for ($i = 0; $i < $length; $i++) {
                    $g[$y * 65536 + $x + $i][$side] = $coverage;
                }
            }
        }
        foreach ($g as $pair) {
            $all++;
            $over += abs(($pair[0] ?? 0) - ($pair[1] ?? 0)) > PlutoVGScanner::TOLERANCE ? 1 : 0;
        }
    }

    return $over / max(1, $all);
}

it('fills simple shapes within the tolerance of VelvetGE', function (Closure $shape): void {
    foreach (pluto_pairs($shape, 100) as [$a, $b]) {
        expect(SpanGrid::distance($a, $b))->toBeLessThanOrEqual(PlutoVGScanner::TOLERANCE);
    }
})->with([
    'fillRect' => [fn (Rasterizer $z) => $z->fillRect(pluto_r(), pluto_r(), pluto_r() / 2, pluto_r() / 2)],
    'fillTriangle' => [fn (Rasterizer $z) => $z->fillTriangle(pluto_r(), pluto_r(), pluto_r(), pluto_r(), pluto_r(), pluto_r())],
    'strokeRect' => [fn (Rasterizer $z) => $z->strokeRect(pluto_r(), pluto_r(), pluto_r() / 2, pluto_r() / 2, 1 + pluto_r() / 40)],
    'line' => [fn (Rasterizer $z) => $z->line(pluto_r(), pluto_r(), pluto_r(), pluto_r(), 0.5 + pluto_r() / 40)],
]);

it('differs only where contours overlap inside a pixel, with the same area', function (Closure $shape): void {
    $pairs = pluto_pairs($shape, 100);
    $area = fn (int $side) => array_sum(array_map(fn ($p) => SpanGrid::area($p[$side]), $pairs));

    expect(pluto_outliers($pairs))->toBeLessThan(0.01)
        ->and(abs($area(0) - $area(1)) / $area(1))->toBeLessThan(0.005);
})->with([
    'self-crossing polygon, non-zero' => [fn (Rasterizer $z) => $z->fillPolygon([[pluto_r(), pluto_r()], [pluto_r(), pluto_r()], [pluto_r(), pluto_r()], [pluto_r(), pluto_r()], [pluto_r(), pluto_r()]])],
    'self-crossing polygon, even-odd' => [fn (Rasterizer $z) => $z->fillPolygon([[pluto_r(), pluto_r()], [pluto_r(), pluto_r()], [pluto_r(), pluto_r()], [pluto_r(), pluto_r()], [pluto_r(), pluto_r()]], FillRule::EVEN_ODD)],
    'two overlapping contours' => [fn (Rasterizer $z) => $z->fillPath([[[pluto_r(), pluto_r()], [pluto_r(), pluto_r()], [pluto_r(), pluto_r()]], [[pluto_r(), pluto_r()], [pluto_r(), pluto_r()], [pluto_r(), pluto_r()]]])],
    'polyline joins' => [fn (Rasterizer $z) => $z->polyline([[pluto_r(), pluto_r()], [pluto_r(), pluto_r()], [pluto_r(), pluto_r()]], 1 + pluto_r() / 40)],
]);

it('matches exactly where the shape is exact: whole pixels, both fill rules', function (): void {
    $clip = new Region(0, 0, 40, 40);
    $squares = [[0, 0, 20, 0, 20, 20, 0, 20], [10, 10, 30, 10, 30, 30, 10, 30]];
    foreach ([FillRule::NON_ZERO, FillRule::EVEN_ODD] as $rule) {
        expect((new PlutoVGScanner($clip, Edges::ANTIALIASED))->path($squares, $rule))
            ->toBe((new PHPLandScanner($clip, Edges::ANTIALIASED))->path($squares, $rule));
    }
});

it('leaves hard edges and curves to VelvetGE, byte for byte', function (Closure $shape, Edges $edges): void {
    foreach (pluto_pairs($shape, 20, $edges) as [$a, $b]) {
        expect($a)->toBe($b);
    }
})->with([
    'hard polygon' => [fn (Rasterizer $z) => $z->fillTriangle(pluto_r(), pluto_r(), pluto_r(), pluto_r(), pluto_r(), pluto_r()), Edges::HARD],
    'antialiased ellipse' => [fn (Rasterizer $z) => $z->fillEllipse(pluto_r(), pluto_r(), 1 + pluto_r() / 4, 1 + pluto_r() / 4), Edges::ANTIALIASED],
]);

it('answers nothing outside the clip, and spans across tiles in order', function (): void {
    $small = new PlutoVGScanner(new Region(10, 10, 5, 5), Edges::ANTIALIASED);
    expect($small->path([[50, 50, 60, 50, 60, 60]], FillRule::NON_ZERO))->toBe('');

    $wide = new Region(0, 0, 9000, 3);
    $spans = (new PlutoVGScanner($wide, Edges::ANTIALIASED))->path([[0, 0, 9000, 0, 9000, 3, 0, 3]], FillRule::NON_ZERO);
    SpanGrid::assertWellFormed($spans, $wide, Edges::ANTIALIASED);
    expect(SpanGrid::area($spans))->toBe(27000.0);
});

it('answers the same coverage as a mask as it does as spans', function (): void {
    $scanner = new PlutoVGScanner(new Region(0, 0, 200, 150), Edges::ANTIALIASED);
    mt_srand(9);
    for ($i = 0; $i < 200; $i++) {
        $contours = [array_map(fn () => pluto_r(), range(1, 2 * mt_rand(3, 8))), array_map(fn () => mt_rand(-2000, 22000) / 100, range(1, 6))];
        $mask = $scanner->mask([\Surface\Contracts\Rendering\Rasterization\Scanner::PATH, $contours, $i % 2]);
        $grid = [];
        if ($mask !== null) {
            [$a8, $width, $left, $top] = $mask;
            expect(strlen($a8) % $width)->toBe(0);
            foreach (str_split($a8) as $k => $byte) {
                if ($byte !== "\0") {
                    $grid[($top + intdiv($k, $width)) * 65536 + $left + $k % $width] = ord($byte);
                }
            }
        }
        $spans = [];
        foreach (Spans::unpack($scanner->path($contours, $i % 2 ? FillRule::EVEN_ODD : FillRule::NON_ZERO)) as [$y, $x, $length, $coverage]) {
            for ($j = 0; $j < $length; $j++) {
                $spans[$y * 65536 + $x + $j] = $coverage;
            }
        }
        expect($grid)->toEqual($spans);
    }
    expect($scanner->mask([\Surface\Contracts\Rendering\Rasterization\Scanner::ELLIPSE, 1, 1, 1, 1]))->toBeNull()
        ->and($scanner->mask([\Surface\Contracts\Rendering\Rasterization\Scanner::PATH, [[500, 500, 510, 500, 510, 510]], 0]))->toBeNull();
});

it('is the plutovg driver', function (): void {
    expect((new PlutoVGRasterizationDriver())->driver())->toBe('plutovg');
});

it('draws in place into B G R A memory and no other', function (): void {
    $memory = fn (\Surface\Contracts\Rendering\Framebuffers\ChannelOrder $o) => (new \Surface\VelvetGE\Drivers\Framebuffers\CLandFramebufferDriver())
        ->full(new \Surface\Contracts\Rendering\Framebuffers\FormatSpec(\Surface\Contracts\Rendering\Framebuffers\PixelFormat::ROW_MAJOR, \Surface\Contracts\Rendering\Framebuffers\BitDepth::B32, channel_order: $o), 8, 8)->memory();
    $scanner = new PlutoVGScanner(new Region(0, 0, 8, 8), Edges::ANTIALIASED);

    expect($scanner->drawsInto($memory(\Surface\Contracts\Rendering\Framebuffers\ChannelOrder::BGRA)))->toBeTrue()
        ->and($scanner->drawsInto($memory(\Surface\Contracts\Rendering\Framebuffers\ChannelOrder::RGBA)))->toBeFalse()
        ->and($scanner->drawsInto($memory(\Surface\Contracts\Rendering\Framebuffers\ChannelOrder::ARGB)))->toBeFalse()
        ->and($scanner->drawsInto($memory(\Surface\Contracts\Rendering\Framebuffers\ChannelOrder::ABGR)))->toBeFalse();
})->skip(! extension_loaded('fb'), 'ext-fb is not loaded: no store with memory to draw into.');

it('blends in place within BLEND of VelvetGE blending the same coverage, over pixels of every alpha', function (): void {
    $frames = new \Surface\VelvetGE\Drivers\Framebuffers\CLandFramebufferDriver();
    $clip = new Region(3, 2, 58, 44);
    $scanner = new PlutoVGScanner($clip, Edges::ANTIALIASED);
    mt_srand(21);
    for ($i = 0; $i < 200; $i++) {
        $background = random_bytes(64 * 48 * 4);
        [$direct, $masked] = [$frames->full(\Surface\Contracts\Rendering\Framebuffers\FormatSpec::bgra8(), 64, 48), $frames->full(\Surface\Contracts\Rendering\Framebuffers\FormatSpec::bgra8(), 64, 48)];
        $direct->writeRgba8($background, 64, 48, 0, 0);
        $masked->writeRgba8($background, 64, 48, 0, 0);
        $flat = [];
        foreach (range(1, mt_rand(3, 6)) as $_) {
            $flat[] = mt_rand(-80, 720) / 10;
            $flat[] = mt_rand(-80, 560) / 10;
        }
        $op = [\Surface\Contracts\Rendering\Rasterization\Scanner::PATH, [$flat], $i % 2];
        $rgba = (mt_rand(0, 0xFFFFFF) << 8) | mt_rand(1, 255);

        $scanner->fill([$op], [$rgba], $direct->memory());
        $mask = $scanner->mask($op);
        if ($mask !== null) {
            $masked->paintMasks([$mask], $rgba, $clip);
        }

        [$a, $b, $worst] = [$direct->toRgba8(), $masked->toRgba8(), 0];
        for ($k = 0, $n = strlen($a); $k < $n; $k++) {
            $worst = max($worst, abs(ord($a[$k]) - ord($b[$k])));
        }
        expect($worst)->toBeLessThanOrEqual(PlutoVGScanner::BLEND)
            ->and(substr($a, 0, 64 * 2 * 4))->toBe(substr($background, 0, 64 * 2 * 4));   // nothing above the clip changed
    }
})->skip(! extension_loaded('fb'), 'ext-fb is not loaded: no store with memory to draw into.');
