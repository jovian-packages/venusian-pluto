<?php

use Jovian\Engines\Velvet\Lustrous\Framebuffers\FullFramebuffer;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\CLandFramebufferDriver;
use Jovian\Engines\Velvet\Lustrous\Framebuffers\PHPLandFramebufferDriver;
use Jovian\Rendering\Pluto\Framebuffers\PlutoVGSurface;
use Jovian\Rendering\Pluto\Framebuffers\PlutoVGFramebufferDriver;
use Surface\Contracts\Rendering\Framebuffers\BitDepth;
use Surface\Contracts\Rendering\Framebuffers\ChannelOrder;
use Surface\Contracts\Rendering\Framebuffers\Endianness;
use Surface\Contracts\Rendering\Framebuffers\Filter;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\PixelFormat;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Framebuffers\Spans;
use Surface\NutsAndBolts\Affine;
use Surface\NutsAndBolts\GradientSpread;
use VelvetGE\Contracts\Framebuffers\CompositingFramebuffer;
use VelvetGE\Contracts\Framebuffers\EffectsFramebuffer;
use VelvetGE\Contracts\Framebuffers\ScrollingFramebuffer;

/*
 * A PlutoVG surface held to lustrous-php, the reference: the same calls on both, in BGRA8, the one
 * colour layout PlutoVG has. Moves, fills and conversions move bytes, so they match exactly;
 * blends are PlutoVG's arithmetic, measured against lustrous-php's.
 *
 * PlutoVG samples a transformed texture its own way, with no nearest or bilinear choice, so a
 * scaled or turned framebuffer is held to what PlutoVG does promise: nothing outside the clip, the
 * coverage or the footprint changes, opacity 0 changes nothing, and the footprint is drawn.
 */

const PW = 37;
const PH = 23;

dataset('plutovg formats', [
    'bgra8' => [FormatSpec::bgra8()],
]);

/** @return array{PlutoVGSurface, FullFramebuffer} */
function pvboth(FormatSpec $spec, int $w = PW, int $h = PH, ?string $rgba8 = null): array
{
    $pair = [new PlutoVGSurface($spec, $w, $h), new FullFramebuffer(new PHPLandFramebufferDriver(), $spec, $w, $h)];
    if (! is_null($rgba8)) {
        foreach ($pair as $frame) {
            $frame->writeRgba8($rgba8, $w, $h);
        }
    }

    return $pair;
}

/** The largest difference between two byte strings of one length. */
function pvfurthest(string $a, string $b): int
{
    expect(strlen($a))->toBe(strlen($b));
    $most = 0;
    for ($i = 0, $n = strlen($a); $i < $n; $i++) {
        $most = max($most, abs(ord($a[$i]) - ord($b[$i])));
    }

    return $most;
}

function pvValue(FormatSpec $spec): int
{
    return match ($spec->bit_depth) {
        BitDepth::B16 => mt_rand(0, 0xFFFF),
        BitDepth::B24 => mt_rand(0, 0xFFFFFF),
        default => mt_rand(0, 0xFFFFFFFF),
    };
}

/** How far a read-out may differ: 0, since BGRA8 to RGBA8 only moves bytes. */
function pvSlack(FormatSpec $spec): int
{
    return $spec->bit_depth === BitDepth::B16 ? 1 : 0;
}

beforeEach(fn () => mt_srand(7));

it('is the core plus compositing and scrolling, and no effects', function () {
    $image = new PlutoVGSurface(FormatSpec::bgra8(), 4, 4);

    expect($image)->toBeInstanceOf(InstanceThatManagesAFramebuffer::class)
        ->and($image)->toBeInstanceOf(CompositingFramebuffer::class)
        ->and($image)->toBeInstanceOf(ScrollingFramebuffer::class)
        ->and($image)->not->toBeInstanceOf(EffectsFramebuffer::class);
});

it('refuses every format but BGRA8', function (FormatSpec $spec) {
    new PlutoVGSurface($spec, 8, 2);
})->with([
    'rgba8' => [FormatSpec::rgba8()],
    'mono' => [new FormatSpec(PixelFormat::MONO_HORIZONTAL, BitDepth::B1)],
    'rgb888' => [new FormatSpec(PixelFormat::ROW_MAJOR, BitDepth::B24)],
])->throws(FramebufferException::class);

it('is made by the plutovg driver', function () {
    $driver = new PlutoVGFramebufferDriver();

    expect($driver->driver())->toBe('plutovg')
        ->and($driver->make(FormatSpec::bgra8(), 5, 3))->toBeInstanceOf(PlutoVGSurface::class);
});

it('holds pixel values as lustrous-php does', function (FormatSpec $spec) {
    [$p, $l] = pvboth($spec);
    for ($i = 0; $i < 50; $i++) {
        [$x, $y, $v] = [mt_rand(0, PW - 1), mt_rand(0, PH - 1), pvValue($spec)];
        $p->setPixel($x, $y, $v);
        $l->setPixel($x, $y, $v);
        expect($p->getPixel($x, $y))->toBe($l->getPixel($x, $y));
    }

    expect($p->dump())->toBe($l->dump());
})->with('plutovg formats');

it('fills, writes segments and clears to the same bytes', function (FormatSpec $spec) {
    [$p, $l] = pvboth($spec);
    foreach ([$p, $l] as $frame) {
        mt_srand(1);
        $frame->fill(pvValue($spec))->setSegment(30, 18, 20, 20, pvValue($spec))->setPixels([[0, 0, pvValue($spec)], [3, 4, pvValue($spec)]])->setRegion([[5, 5], [6, 7]], pvValue($spec));
    }
    expect($p->dump())->toBe($l->dump());

    $p->clear();
    $l->clear();
    expect($p->dump())->toBe($l->dump());
})->with('plutovg formats');

it('takes RGBA8 in, and reads it out, as lustrous-php does', function (FormatSpec $spec) {
    [$p, $l] = pvboth($spec, rgba8: random_bytes(PW * PH * 4));
    $region = new Region(3, 2, 11, 9);

    expect($p->dump())->toBe($l->dump())
        ->and(pvfurthest($p->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(pvSlack($spec))
        ->and(pvfurthest($p->flush(FormatSpec::bgra8()), $l->flush(FormatSpec::bgra8())))->toBeLessThanOrEqual(pvSlack($spec))
        ->and(pvfurthest($p->flushRegion($region, FormatSpec::rgba8()), $l->flushRegion($region, FormatSpec::rgba8())))->toBeLessThanOrEqual(pvSlack($spec))
        ->and($p->flush($spec))->toBe($l->flush($spec));
})->with('plutovg formats');

it('scrolls to the same bytes', function (FormatSpec $spec) {
    [$p, $l] = pvboth($spec, rgba8: random_bytes(PW * PH * 4));
    foreach ([[3, -2], [-5, 4], [0, 7]] as [$dx, $dy]) {
        $p->scroll(new Region(2, 2, 30, 18), $dx, $dy);
        $l->scroll(new Region(2, 2, 30, 18), $dx, $dy);
    }

    expect($p->dump())->toBe($l->dump());
})->with('plutovg formats');

it('blits between images, and to and from Lustrous, without changing a byte', function (FormatSpec $spec) {
    [$p, $l] = pvboth($spec, rgba8: random_bytes(PW * PH * 4));
    $copy = new PlutoVGSurface($spec, PW, PH);
    $lustrous = new FullFramebuffer(new PHPLandFramebufferDriver(), $spec, PW, PH);

    $p->blitTo($copy);
    $p->blitTo($lustrous);
    expect($copy->dump())->toBe($p->dump())
        ->and($lustrous->dump())->toBe($l->dump());

    $back = new PlutoVGSurface($spec, PW, PH);
    $back->blitFrom($lustrous);
    expect($back->dump())->toBe($l->dump());
})->with('plutovg formats');

it('copies a frame that lends BGRA8 memory straight from that memory', function () {
    $rgba8 = random_bytes(PW * PH * 4);
    $c = new FullFramebuffer(new CLandFramebufferDriver(), FormatSpec::bgra8(), PW, PH);
    $c->writeRgba8($rgba8, PW, PH);
    expect($c->memory())->not->toBeNull();

    $p = new PlutoVGSurface(FormatSpec::bgra8(), PW + 6, PH + 4);
    $p->blitFrom($c, 3, 2);

    expect($p->flushRegion(new Region(3, 2, PW, PH), FormatSpec::bgra8()))->toBe($c->dump())
        ->and($p->getPixel(0, 0))->toBe(0);
});

it('blends spans and masks within its tolerance of lustrous-php', function (FormatSpec $spec) {
    for ($t = 0; $t < 30; $t++) {
        $background = random_bytes(PW * PH * 4);
        $colour = mt_rand(0, 0xFFFFFFFF);

        $spans = '';
        for ($y = 0; $y < PH; $y += mt_rand(1, 2)) {
            for ($x = mt_rand(0, 3); $x < PW - 1; $x += mt_rand(2, 9)) {
                $length = mt_rand(1, min(6, PW - $x));
                $spans .= Spans::pack($y, $x, $length, mt_rand(1, 255));
                $x += $length;
            }
        }
        [$p, $l] = pvboth($spec, rgba8: $background);
        $p->paintSpans($spans, $colour);
        $l->paintSpans($spans, $colour);
        expect(pvfurthest($p->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(PlutoVGSurface::TOLERANCE);

        $masks = [[random_bytes(10 * 6), 10, mt_rand(-3, PW - 5), mt_rand(-3, PH - 3)], [random_bytes(4 * 9), 4, mt_rand(0, PW - 4), mt_rand(0, PH - 9)]];
        [$p, $l] = pvboth($spec, rgba8: $background);
        $p->paintMasks($masks, $colour, new Region(1, 1, PW - 2, PH - 2));
        $l->paintMasks($masks, $colour, new Region(1, 1, PW - 2, PH - 2));
        expect(pvfurthest($p->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(PlutoVGSurface::TOLERANCE);
    }
})->with('plutovg formats');

it('paints gradients within its tolerance of lustrous-php', function (FormatSpec $spec, string $kind, GradientSpread $spread) {
    $ramp = '';
    for ($i = 0; $i < 256; $i++) {
        $ramp .= pack('C4', $i, 255 - $i, (int) (128 + 127 * sin($i / 20)), (int) (60 + $i * 0.7));
    }
    $spans = '';
    for ($y = 0; $y < PH; $y++) {
        $spans .= Spans::pack($y, 1, PW - 2, mt_rand(1, 255));
    }
    [$p, $l] = pvboth($spec, rgba8: random_bytes(PW * PH * 4));
    $inverse = $kind === 'radial' ? [1 / 30, 0.0, 0.0, 1 / 25, -0.1, -0.2] : [1 / 30, 0.004, -0.01, 1 / 25, -0.1, -0.2];
    $p->paintGradient($spans, $ramp, $kind, $spread, $inverse);
    $l->paintGradient($spans, $ramp, $kind, $spread, $inverse);

    expect(pvfurthest($p->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(PlutoVGSurface::TOLERANCE);
})->with('plutovg formats')->with(['linear', 'radial'])->with([GradientSpread::PAD, GradientSpread::REPEAT, GradientSpread::REFLECT]);

it('paints another framebuffer, nearest, within its tolerance of lustrous-php', function (FormatSpec $spec, Affine $placement, int $opacity, int $tint, bool $coverage) {
    $W = 48;
    $H = 40;
    for ($t = 0; $t < 10; $t++) {
        [$p, $l] = pvboth($spec, $W, $H, random_bytes($W * $H * 4));
        [$ps, $ls] = pvboth(FormatSpec::bgra8(), 9, 7, random_bytes(9 * 7 * 4));
        $spans = null;
        if ($coverage) {
            $spans = '';
            for ($y = 0; $y < $H; $y++) {
                if ($y % 3 !== 0) {
                    $spans .= Spans::pack($y, 2 + $y % 5, 20, mt_rand(1, 255));
                }
            }
        }
        $p->paintImage($ps, $placement, $opacity, Filter::NEAREST, null, $tint, null, $spans);
        $l->paintImage($ls, $placement, $opacity, Filter::NEAREST, null, $tint, null, $spans);

        expect(pvfurthest($p->toRgba8(), $l->toRgba8()))->toBeLessThanOrEqual(PlutoVGSurface::TOLERANCE);
    }
})->with('plutovg formats')->with([
    'moved' => [Affine::translation(11, 7), 255, 0xFFFFFFFF, false],
    'moved, translucent, tinted' => [Affine::translation(3, 9), 180, 0xFF8040C0, false],
    'turned' => [Affine::translation(24, 6)->multiply(Affine::rotation(M_PI / 6))->multiply(Affine::scaling(2, 2)), 255, 0xFFFFFFFF, false],
    'scaled, through coverage' => [Affine::translation(3.25, 2.25)->multiply(Affine::scaling(3, 2.5)), 200, 0xFFFFFFFF, true],
]);

/** RGBA8 pixel indices whose bytes differ between two read-outs. */
function pvchanged(string $before, string $after): array
{
    $out = [];
    for ($i = 0, $n = intdiv(strlen($before), 4); $i < $n; $i++) {
        if (substr($before, $i * 4, 4) !== substr($after, $i * 4, 4)) {
            $out[] = $i;
        }
    }

    return $out;
}

it('draws a framebuffer asked for bilinear by its own sampling, only inside its footprint, clip and coverage', function (FormatSpec $spec, Affine $placement) {
    $W = 48;
    $H = 40;
    $background = random_bytes($W * $H * 4);
    $clip = new Region(6, 4, 30, 26);
    $coverage = '';
    for ($y = 0; $y < $H; $y++) {
        if ($y % 3 !== 0) {
            $coverage .= Spans::pack($y, 2, 40, 255);
        }
    }
    [$p] = pvboth($spec, $W, $H, $background);
    [$ps] = pvboth($spec, 9, 7, random_bytes(9 * 7 * 4));
    $before = $p->toRgba8();
    $p->paintImage($ps, $placement, 255, Filter::LINEAR, $clip, 0xFFFFFFFF, null, $coverage);
    $changed = pvchanged($before, $p->toRgba8());

    $corners = array_map(fn ($c) => $placement->apply($c[0], $c[1]), [[0, 0], [9, 0], [9, 7], [0, 7]]);
    $xs = array_column($corners, 0);
    $ys = array_column($corners, 1);
    expect($changed)->not->toBeEmpty();
    foreach ($changed as $i) {
        [$x, $y] = [$i % $W, intdiv($i, $W)];
        expect($x >= 6 && $x < 36 && $y >= 4 && $y < 30)->toBeTrue("changed ($x, $y) outside the clip")
            ->and($y % 3)->not->toBe(0, "changed ($x, $y) on a row with no coverage")
            ->and($x >= floor(min($xs)) - 1 && $x <= ceil(max($xs)) && $y >= floor(min($ys)) - 1 && $y <= ceil(max($ys)))->toBeTrue("changed ($x, $y) outside the footprint");
    }

    $still = new PlutoVGSurface($spec, $W, $H);
    $still->writeRgba8($background, $W, $H);
    $still->paintImage($ps, $placement, 0, Filter::LINEAR);
    expect($still->toRgba8())->toBe($background);
})->with('plutovg formats')->with([
    'scaled' => [Affine::translation(4.3, 3.1)->multiply(Affine::scaling(2.7, 2.3))],
    'turned' => [Affine::translation(24, 6)->multiply(Affine::rotation(M_PI / 6))->multiply(Affine::scaling(2, 2))],
]);

it('offers its pixels as memory for drawing in place', function () {
    $image = new PlutoVGSurface(FormatSpec::bgra8(), 8, 4);
    $memory = $image->memory();

    expect($memory)->not->toBeNull()
        ->and($image->pointer())->not->toBe(0)
        ->and($memory->width)->toBe(8)
        ->and($memory->height)->toBe(4)
        ->and($image->drewInto(new Region(0, 0, 2, 2), null))->toBe($image);

    // something else drawing into that memory: the surface reads what it drew
    $other = plutovg_surface_create_for_address($memory->address, 8, 4, 32, $memory->owner);
    $canvas = plutovg_canvas_create($other);
    plutovg_canvas_set_rgba($canvas, 1.0, 0.0, 0.0, 1.0);
    plutovg_canvas_fill_rect($canvas, 2, 1, 3, 2);

    expect($image->getPixel(3, 2))->toBe(0xFF0000FF)
        ->and($image->getPixel(0, 0))->toBe(0);
});

it('keeps its own pixels when cloned', function () {
    $a = new PlutoVGSurface(FormatSpec::bgra8(), 4, 2);
    $a->fill(0x11223344);
    $b = clone $a;
    $b->fill(0xAABBCCDD);

    expect($a->getPixel(0, 0))->toBe(0x11223344)
        ->and($b->getPixel(0, 0))->toBe(0xAABBCCDD);
});

it('hands out reads that later drawing does not change', function () {
    $image = new PlutoVGSurface(FormatSpec::bgra8(), 4, 2);
    $image->fill(0x11223344);
    $dump = $image->dump();
    $image->fill(0xAABBCCDD);

    expect($dump)->toBe(str_repeat("\x33\x22\x11\x44", 8));
});

// Library to library is a pointer: blitFrom copies a BGRA8 source from its memory, paintImage
// premultiplies an RGBA8 source from its memory (venusian-velvet/tests/Support).
moduleTicketChecks('plutovg blit', fn (FormatSpec $f, int $w, int $h) => new PlutoVGSurface($f, $w, $h), FormatSpec::bgra8(), ['bgra8' => FormatSpec::bgra8()], ['blitFrom']);
moduleTicketChecks('plutovg paint', fn (FormatSpec $f, int $w, int $h) => new PlutoVGSurface($f, $w, $h), FormatSpec::bgra8(), ['rgba8' => FormatSpec::rgba8()], ['paintImage']);
