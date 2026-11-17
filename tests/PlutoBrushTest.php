<?php

declare(strict_types=1);

use Jovian\Rendering\Pluto\PlutoBrush;
use Jovian\Rendering\Pluto\PlutoPaintingDriver;
use Jovian\Rendering\Pluto\PlutoVGRasterizationDriver;
use Surface\Contracts\Graphics\StrokeStyle;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Painting\Painter;
use Surface\Contracts\Rendering\Rasterization\Edges;
use Surface\Contracts\Rendering\Rasterization\FillRule;
use Surface\NutsAndBolts\Color;
use Surface\NutsAndBolts\GradientSpread;
use Surface\NutsAndBolts\LinearGradient;
use Surface\NutsAndBolts\Path;
use Surface\NutsAndBolts\RadialGradient;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Painting\PHPLandPaintbrushDriver;
use Jovian\Engines\Velvet\Lustrous\Rasterization\PHPLandRasterizationDriver;

/*
 * PlutoVG's brush against VelvetGE's native painter. PlutoVG supplies coverage, ext-plutovg blends
 * with VelvetGE's arithmetic, so frames differ only at shape edges: by PlutoBrush::TOLERANCE where
 * an outline never crosses itself inside a pixel, beyond it only where contours cross inside one.
 * Runs inside topside's suite (AGENTS.md): needs ext-plutovg and ext-fb.
 */

const PLUTO_W = 120;
const PLUTO_H = 90;

/** One draw call of $kind, seeded; $count of them over a seeded clear. */
function plutoScene(string $kind, int $seed, int $count = 1): Closure
{
    return function (Painter $g) use ($kind, $seed, $count): void {
        mt_srand($seed);
        $n = fn (float $lo, float $hi): float => $lo + ($hi - $lo) * mt_rand() / mt_getrandmax();
        $colour = fn (): Color => Color::rgba(mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 2) ? $n(0.2, 1.0) : 1.0);
        $path = function () use ($n): Path {
            $p = (new Path())->moveTo($n(0, PLUTO_W), $n(0, PLUTO_H));
            for ($i = mt_rand(2, 6); $i > 0; $i--) {
                mt_rand(0, 3) ? $p->lineTo($n(-10, PLUTO_W + 10), $n(-10, PLUTO_H + 10)) : $p->cubicTo($n(0, PLUTO_W), $n(0, PLUTO_H), $n(0, PLUTO_W), $n(0, PLUTO_H), $n(0, PLUTO_W), $n(0, PLUTO_H));
            }

            return $p->close();
        };
        $stops = fn (): array => [[0.0, $colour()], [$n(0.3, 0.7), $colour()], [1.0, $colour()]];
        $spread = fn (): GradientSpread => [GradientSpread::PAD, GradientSpread::REPEAT, GradientSpread::REFLECT][mt_rand(0, 2)];
        $g->clear(Color::rgb(mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)));
        for ($i = 0; $i < $count; $i++) {
            match ($kind) {
                'fillRect' => $g->fillRect($n(-5, PLUTO_W), $n(-5, PLUTO_H), $n(1, 40), $n(1, 40), $colour()),
                'line' => $g->line($n(0, PLUTO_W), $n(0, PLUTO_H), $n(0, PLUTO_W), $n(0, PLUTO_H), $colour(), $n(1, 7)),
                'fillEllipse' => $g->fillEllipse($n(0, PLUTO_W), $n(0, PLUTO_H), $n(1, 40), $n(1, 30), $colour()),
                'strokeEllipse' => $g->strokeEllipse($n(0, PLUTO_W), $n(0, PLUTO_H), $n(2, 40), $n(2, 30), $colour(), $n(0.5, 8)),
                'roundRect' => $g->roundRect($n(0, 80), $n(0, 50), $n(4, 60), $n(4, 50), [$n(0, 16), $n(0, 16), $n(0, 16), $n(0, 16)], $colour(), mt_rand(0, 1) ? $colour() : null, $n(0.5, 5)),
                'fill path' => $g->fill($path(), $colour(), mt_rand(0, 1) ? FillRule::NON_ZERO : FillRule::EVEN_ODD),
                'stroke path' => $g->stroke($path(), $colour(), new StrokeStyle(width: $n(0.5, 6))),
                'polyline' => $g->polyline([[$n(0, PLUTO_W), $n(0, PLUTO_H)], [$n(0, PLUTO_W), $n(0, PLUTO_H)], [$n(0, PLUTO_W), $n(0, PLUTO_H)]], $colour(), $n(1, 6), (bool) mt_rand(0, 1)),
                'linear gradient' => $g->fill($path(), new LinearGradient($n(0, PLUTO_W), $n(0, PLUTO_H), $n(0, PLUTO_W), $n(0, PLUTO_H), $stops(), $spread())),
                'radial gradient' => $g->fill($path(), new RadialGradient($n(0, PLUTO_W), $n(0, PLUTO_H), $n(5, 50), $stops(), $spread())),
                'shadow' => $g->shadow($n(0, 80), $n(0, 50), $n(4, 60), $n(4, 40), $n(0, 10), $n(0, 9), $colour(), $n(-3, 3), $n(-3, 3)),
            };
        }
    };
}

/**
 * The scene painted on a fresh 32-bit frame. $pluto: through 'plutovg' painting (PlutoBrush, and
 * PlutoVG's rasterizer for what the brush does not take); false: through Lustrous's PHP painter;
 * 'raster': through VelvetGE's painter with no brush and PlutoVG's rasterizer.
 *
 * @return array{string, list<Region>, list<array<int, mixed>>} the frame as RGBA8, its damage, and the commands not drawn
 */
function plutoFrame(bool|string $pluto, Closure $scene, ChannelOrder $order, Edges $edges): array
{
    $record = new \Surface\Rendering\Recording\Drivers\PHPLandRecordingDriver();
    $driver = match ($pluto) {
        true => new PlutoPaintingDriver(null, true, $record),
        false => new PHPLandPaintbrushDriver(new PHPLandRasterizationDriver(), true, $record),
        'raster' => new PHPLandPaintbrushDriver(new PlutoVGRasterizationDriver(), true, $record),
    };
    $target = (new CLandFramebufferDriver())->dirty(new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B32, channel_order: $order), PLUTO_W, PLUTO_H);
    $target->beginEpoch();
    $painter = $driver->painter($target, $edges);
    $painter->frame($scene);

    return [$target->flushRegion(new Region(0, 0, PLUTO_W, PLUTO_H), FormatSpec::rgba8()), $target->damage(), $painter->undrawn()];
}

/** @return array{int, int, int} worst per-byte distance, pixels that differ, pixels beyond the tolerance */
function plutoDistance(string $a, string $b): array
{
    [$worst, $differ, $beyond] = [0, 0, 0];
    for ($i = 0, $len = strlen($a); $i < $len; $i += 4) {
        $d = 0;
        for ($k = 0; $k < 4; $k++) {
            $d = max($d, abs(ord($a[$i + $k]) - ord($b[$i + $k])));
        }
        $worst = max($worst, $d);
        $differ += $d > 0 ? 1 : 0;
        $beyond += $d > PlutoBrush::TOLERANCE ? 1 : 0;
    }

    return [$worst, $differ, $beyond];
}

function plutoNeeds(): void
{
    if (! function_exists('plutovg_surface_blend_colour') || ! extension_loaded('fb')) {
        test()->markTestSkipped('ext-plutovg (with plutovg_surface_blend_colour) and ext-fb are not both loaded.');
    }
}

dataset('pluto orders', [ChannelOrder::RGBA, ChannelOrder::BGRA, ChannelOrder::ARGB, ChannelOrder::ABGR]);

it('paints shapes whose outline never crosses itself within the tolerance of the native painter', function (string $kind, ChannelOrder $order): void {
    plutoNeeds();
    foreach (range(1, 12) as $seed) {
        [$a] = plutoFrame(false, plutoScene($kind, $seed), $order, Edges::ANTIALIASED);
        [$b] = plutoFrame(true, plutoScene($kind, $seed), $order, Edges::ANTIALIASED);
        expect(plutoDistance($a, $b)[0])->toBeLessThanOrEqual(PlutoBrush::TOLERANCE);
    }
})->with(['fillRect', 'line', 'fillEllipse', 'strokeEllipse', 'roundRect'])->with('pluto orders');

it('passes the tolerance only where a path crosses itself inside a pixel', function (string $kind, ChannelOrder $order): void {
    plutoNeeds();
    [$differ, $beyond] = [0, 0];
    foreach (range(1, 12) as $seed) {
        [$a] = plutoFrame(false, plutoScene($kind, $seed), $order, Edges::ANTIALIASED);
        [$b] = plutoFrame(true, plutoScene($kind, $seed), $order, Edges::ANTIALIASED);
        [, $d, $o] = plutoDistance($a, $b);
        [$differ, $beyond] = [$differ + $d, $beyond + $o];
    }
    expect($beyond)->toBeLessThan(max(1, $differ) * 0.02);
})->with(['fill path', 'stroke path', 'polyline', 'linear gradient', 'radial gradient'])->with('pluto orders');

it('damages every pixel it changes', function (string $kind): void {
    plutoNeeds();
    foreach (range(1, 6) as $seed) {
        [$clear] = plutoFrame(true, plutoScene($kind, $seed, 0), ChannelOrder::BGRA, Edges::ANTIALIASED);
        [$frame, $damage] = plutoFrame(true, plutoScene($kind, $seed, 8), ChannelOrder::BGRA, Edges::ANTIALIASED);
        for ($y = 0; $y < PLUTO_H; $y++) {
            for ($x = 0; $x < PLUTO_W; $x++) {
                $at = ($y * PLUTO_W + $x) * 4;
                if (substr($frame, $at, 4) !== substr($clear, $at, 4)) {
                    expect(array_filter($damage, fn (Region $r): bool => $r->contains($x, $y)))->not->toBeEmpty();
                }
            }
        }
    }
})->with(['fillRect', 'fillEllipse', 'roundRect', 'fill path', 'stroke path', 'linear gradient', 'radial gradient']);

it('leaves hard edges and the commands it does not take to the painter, which rasterizes them with PlutoVG', function (string $kind, Edges $edges): void {
    plutoNeeds();
    foreach (range(1, 4) as $seed) {
        $raster = plutoFrame('raster', plutoScene($kind, $seed, 6), ChannelOrder::BGRA, $edges);
        $pluto = plutoFrame(true, plutoScene($kind, $seed, 6), ChannelOrder::BGRA, $edges);
        expect($pluto[0] === $raster[0])->toBeTrue()
            ->and(array_map(fn (Region $r) => [$r->x, $r->y, $r->width, $r->height], $pluto[1]))
            ->toBe(array_map(fn (Region $r) => [$r->x, $r->y, $r->width, $r->height], $raster[1]))
            ->and(count($pluto[2]))->toBe(count($raster[2]));
    }
})->with([
    'hard fill path' => ['fill path', Edges::HARD],
    'hard ellipse' => ['fillEllipse', Edges::HARD],
    'hard gradient' => ['linear gradient', Edges::HARD],
    'shadow' => ['shadow', Edges::ANTIALIASED],
]);

it('hands back the shadows PlutoVG has no answer for, drawing none of them', function (): void {
    plutoNeeds();
    foreach (range(1, 4) as $seed) {
        [$blank] = plutoFrame(true, plutoScene('shadow', $seed, 0), ChannelOrder::BGRA, Edges::ANTIALIASED);
        [$frame, , $undrawn] = plutoFrame(true, plutoScene('shadow', $seed, 6), ChannelOrder::BGRA, Edges::ANTIALIASED);
        expect($frame === $blank)->toBeTrue()
            ->and($undrawn)->not->toBeEmpty()
            ->and(array_unique(array_column($undrawn, 0)))->toBe(['shadow']);
    }
});

it('takes only the anti-aliased shapes it draws', function (): void {
    plutoNeeds();
    $brush = new PlutoBrush();
    $clip = new Region(0, 0, 10, 10);

    expect($brush->takes(['path', [], FillRule::NON_ZERO, 0, $clip], Edges::ANTIALIASED))->toBeTrue()
        ->and($brush->takes(['path', [], FillRule::NON_ZERO, 0, $clip], Edges::HARD))->toBeFalse()
        ->and($brush->takes(['shadow', 0, 0, 1, 1, 0, 0, 0, $clip], Edges::ANTIALIASED))->toBeFalse()
        ->and($brush->takes(['clear', 0], Edges::ANTIALIASED))->toBeFalse();
});
