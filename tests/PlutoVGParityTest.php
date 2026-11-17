<?php

declare(strict_types=1);

/*
 * Runs inside jovian/venusian-velvet's test suite (it uses topside's SpanGrid and Lustrous's
 * PHP scanner as the reference): copy it into that package's tests/ with this package autoloaded.
 */

use Jovian\Rendering\Pluto\PlutoVGRasterizationDriver;
use Jovian\Rendering\Pluto\PlutoVGScanner;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\Spans;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\Contracts\Rendering\Rasterization\Rasterizer;
use Jovian\Engines\Velvet\Lustrous\Rasterization\PHPLandScanner;
use VelvetGE\Rasterization\ScannerRasterizer;
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
        expect((new PlutoVGScanner($clip))->path($squares, $rule))
            ->toBe((new PHPLandScanner($clip, Edges::ANTIALIASED))->path($squares, $rule));
    }
});

/** Each curved shape with random arguments, as one closure per kind. */
function pluto_curves(): array
{
    $radii = fn (): array => [mt_rand(0, 2000) / 100, mt_rand(0, 2000) / 100, mt_rand(0, 2000) / 100, mt_rand(0, 2000) / 100];

    return [
        'triangle' => fn (Rasterizer $z) => $z->fillTriangle(pluto_r(), pluto_r(), pluto_r(), pluto_r(), pluto_r(), pluto_r()),
        'ellipse' => fn (Rasterizer $z) => $z->fillEllipse(pluto_r(), pluto_r(), 1 + pluto_r() / 4, 1 + pluto_r() / 4),
        'ring' => fn (Rasterizer $z) => $z->strokeEllipse(pluto_r(), pluto_r(), 2 + pluto_r() / 4, 2 + pluto_r() / 4, 1 + mt_rand(0, 800) / 100),
        'roundrect' => fn (Rasterizer $z) => $z->fillRoundRect(pluto_r() / 2, pluto_r() / 2, 4 + pluto_r() / 2, 4 + pluto_r() / 2, $radii()),
        'roundrect, equal corners' => function (Rasterizer $z): string {
            $r = mt_rand(0, 2000) / 100;

            return $z->fillRoundRect(pluto_r() / 2, pluto_r() / 2, 4 + pluto_r() / 2, 4 + pluto_r() / 2, [$r, $r, $r, $r]);
        },
        'roundring' => fn (Rasterizer $z) => $z->strokeRoundRect(pluto_r() / 2, pluto_r() / 2, 6 + pluto_r() / 2, 6 + pluto_r() / 2, $radii(), 1 + mt_rand(0, 600) / 100),
    ];
}

/** Pixel => coverage. */
function pluto_cells(string $spans): array
{
    $g = [];
    foreach (Spans::unpack($spans) as [$y, $x, $length, $coverage]) {
        for ($i = 0; $i < $length; $i++) {
            $g[$y * 65536 + $x + $i] = $coverage;
        }
    }

    return $g;
}

/*
 * Curves are PlutoVG geometry (plutovg_path_add_ellipse, plutovg_path_add_round_rect and
 * plutovg_path_arc_to corners), not VelvetGE's outline. Measured 2026-11-15 over 200 of each
 * against VelvetGE's PHP scanner: the worst pixel 36 (ellipse), 45 (ring), 38 (round rect, round
 * ring); total coverage within 0.4% for every kind. Nothing was tuned toward VelvetGE.
 */
it('fills curves as PlutoVG geometry, at its measured distance from VelvetGE', function (string $kind, int $worst): void {
    [$most, $a, $b] = [0, 0, 0];
    foreach (pluto_pairs(pluto_curves()[$kind], 200) as [$pluto, $velvet]) {
        $p = pluto_cells($pluto);
        $v = pluto_cells($velvet);
        foreach ($p + $v as $k => $_) {
            $most = max($most, abs(($p[$k] ?? 0) - ($v[$k] ?? 0)));
        }
        [$a, $b] = [$a + array_sum($p), $b + array_sum($v)];
    }
    expect($most)->toBeLessThanOrEqual($worst)
        ->and(abs($a / $b - 1))->toBeLessThan(0.004);
})->with([['ellipse', 36], ['ring', 45], ['roundrect', 38], ['roundrect, equal corners', 38], ['roundring', 38]]);

/*
 * PlutoVG has no hard-edged mode. The edge mode is a preference: asked for hard edges, its scanner
 * says it draws anti-aliased and draws exactly what it draws when asked for that. A thin stroke is
 * then an outline like any other, not a one-pixel line.
 */
it('draws anti-aliased when asked for hard edges, and says so', function (string $kind): void {
    $clip = new Region(0, 0, 200, 150);
    $hard = (new PlutoVGRasterizationDriver())->rasterizer($clip, Edges::HARD);
    $soft = (new PlutoVGRasterizationDriver())->rasterizer($clip, Edges::ANTIALIASED);
    $shape = pluto_curves()[$kind];

    expect($hard->edges())->toBe(Edges::ANTIALIASED);
    $partial = 0;
    for ($n = 0; $n < 50; $n++) {
        mt_srand(1000 + $n);
        $asked = $shape($hard);
        mt_srand(1000 + $n);
        expect($asked)->toBe($shape($soft));
        $partial += count(array_filter(pluto_cells($asked), fn (int $c): bool => $c < 255));
    }
    expect($partial)->toBeGreaterThan(0)
        ->and($hard->line(5.0, 5.0, 150.0, 90.0))->toBe($soft->line(5.0, 5.0, 150.0, 90.0))->not->toBeNull()->not->toBe('');
})->with(['triangle', 'ellipse', 'ring', 'roundrect', 'roundring']);

it('answers nothing outside the clip, and spans across tiles in order', function (): void {
    $small = new PlutoVGScanner(new Region(10, 10, 5, 5));
    expect($small->path([[50, 50, 60, 50, 60, 60]], FillRule::NON_ZERO))->toBe('');

    $wide = new Region(0, 0, 9000, 3);
    $spans = (new PlutoVGScanner($wide))->path([[0, 0, 9000, 0, 9000, 3, 0, 3]], FillRule::NON_ZERO);
    SpanGrid::assertWellFormed($spans, $wide, Edges::ANTIALIASED);
    expect(SpanGrid::area($spans))->toBe(27000.0);
});

it('answers the same coverage as a mask as it does as spans, paths and curves', function (): void {
    $scanner = new PlutoVGScanner(new Region(0, 0, 200, 150));
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
    foreach ([[40.3, 30.7, 25.1, 12.6], [150.5, 75.2, 60.0, 90.4]] as [$cx, $cy, $rx, $ry]) {
        [$a8, $width, $left, $top] = $scanner->mask([\Surface\Contracts\Rendering\Rasterization\Scanner::ELLIPSE, $cx, $cy, $rx, $ry]);
        $grid = [];
        foreach (str_split($a8) as $k => $byte) {
            if ($byte !== "\0") {
                $grid[($top + intdiv($k, $width)) * 65536 + $left + $k % $width] = ord($byte);
            }
        }
        expect($grid)->toEqual(pluto_cells($scanner->ellipse($cx, $cy, $rx, $ry)));
    }
    expect($scanner->mask([\Surface\Contracts\Rendering\Rasterization\Scanner::PATH, [[500, 500, 510, 500, 510, 510]], 0]))->toBeNull();
});

it('is the plutovg driver', function (): void {
    expect((new PlutoVGRasterizationDriver())->driver())->toBe('plutovg');
});

it('draws in place into B G R A memory and no other', function (): void {
    $memory = fn (\Surface\Contracts\Rendering\Framebuffers\ChannelOrder $o) => (new \Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver())
        ->full(new \Surface\Contracts\Rendering\Framebuffers\FormatSpec(\Surface\Contracts\Rendering\Framebuffers\PixelFormat::ROW_MAJOR, \Surface\Contracts\Rendering\Framebuffers\BitDepth::B32, channel_order: $o), 8, 8)->memory();
    $scanner = new PlutoVGScanner(new Region(0, 0, 8, 8));

    expect($scanner->drawsInto($memory(\Surface\Contracts\Rendering\Framebuffers\ChannelOrder::BGRA)))->toBeTrue()
        ->and($scanner->drawsInto($memory(\Surface\Contracts\Rendering\Framebuffers\ChannelOrder::RGBA)))->toBeFalse()
        ->and($scanner->drawsInto($memory(\Surface\Contracts\Rendering\Framebuffers\ChannelOrder::ARGB)))->toBeFalse()
        ->and($scanner->drawsInto($memory(\Surface\Contracts\Rendering\Framebuffers\ChannelOrder::ABGR)))->toBeFalse();
})->skip(! extension_loaded('fb'), 'ext-fb is not loaded: no store with memory to draw into.');

it('blends in place within BLEND of VelvetGE blending the same coverage, over pixels of every alpha', function (): void {
    $frames = new \Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver();
    $clip = new Region(3, 2, 58, 44);
    $scanner = new PlutoVGScanner($clip);
    mt_srand(21);
    for ($i = 0; $i < 200; $i++) {
        $background = (new \Random\Randomizer(new \Random\Engine\Mt19937(8400 + $i)))->getBytes(64 * 48 * 4);   // seeded, so a failure repeats
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
