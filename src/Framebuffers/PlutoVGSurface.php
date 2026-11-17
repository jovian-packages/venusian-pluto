<?php

namespace Jovian\Rendering\Pluto\Framebuffers;

use plutovg_canvas;
use plutovg_color;
use plutovg_gradient_stop;
use plutovg_matrix;
use plutovg_surface;
use Surface\Contracts\Rendering\Framebuffers\DamageGranularity;
use Surface\Contracts\Rendering\Framebuffers\Filter;
use Surface\Contracts\Rendering\Framebuffers\FormatSpec;
use Surface\Contracts\Rendering\Framebuffers\FramebufferException;
use Surface\Contracts\Rendering\Framebuffers\InstanceThatManagesAFramebuffer;
use Surface\Contracts\Rendering\Framebuffers\PixelMemory;
use Surface\Contracts\Rendering\Framebuffers\Region;
use Surface\Contracts\Rendering\Painting\PaintTarget;
use Surface\NutsAndBolts\Affine;
use Surface\NutsAndBolts\GradientSpread;
use Surface\Rendering\Framebuffers\FramebufferDelegate;
use VelvetGE\Contracts\Framebuffers\CompositingFramebuffer;
use VelvetGE\Contracts\Framebuffers\GradientFramebuffer;
use VelvetGE\Contracts\Framebuffers\ScrollingFramebuffer;
use VelvetGE\Framebuffers\ImagePlacement;
use VelvetGE\Framebuffers\Layout;
use VelvetGE\Framebuffers\MaskList;
use VelvetGE\Contracts\Framebuffers\PagedFramebuffer as PagedFramebufferContract;
use VelvetGE\Framebuffers\ReadsBlitSources;
use VelvetGE\Framebuffers\SpanList;

/**
 * One PlutoVG surface, PlutoVG's way. PlutoVG's surface is ARGB32 in native order, which on a
 * little-endian host lies in memory as B G R A bytes, so the surface is a BGRA8 framebuffer and
 * takes no other format. It holds the format's bytes: straight colours, as the framework defines them.
 *
 * Every write is a PlutoVG fill: bytes land by SRC through a plain texture (a copy) or a tiled
 * 1x1 texture (a fill), which move them as they are. PlutoVG has no mask fill, so a blend is
 * three PlutoVG operations on a scratch surface the size of the area: the paint (a solid, a
 * gradient or another framebuffer's pixels through the placement) by SRC, the coverage as alpha
 * by DST_IN, then the result SRC_OVER onto the surface. PlutoVG samples a transformed texture its
 * own way (no nearest or bilinear choice), so paintImage()'s filter is PlutoVG's, not the caller's.
 *
 * PlutoVG blends as if the pixels were premultiplied, so over a translucent destination pixel its
 * result is not Lustrous's straight-alpha lerp: blended bytes are measured against lustrous-php.
 * No effects (no filter chains, Kawase levels or glass) and no kinds.
 */
final class PlutoVGSurface extends FramebufferDelegate implements CompositingFramebuffer, GradientFramebuffer, ScrollingFramebuffer, PaintTarget
{
    use ReadsBlitSources;

    /** How far a blended byte may land from lustrous-php's: 5% of 255, as ruled for library framebuffers. */
    public const int TOLERANCE = 13;

    private plutovg_surface $surface;

    private plutovg_canvas $canvas;

    /** @throws FramebufferException For any format but BGRA8, or on a big-endian host. */
    public function __construct(
        private readonly FormatSpec $format,
        private readonly int $width,
        private readonly int $height,
    ) {
        if (! $format->equals(FormatSpec::bgra8())) {
            throw FramebufferException::unsupportedFormat($format, 'PlutoVG holds colour as native-order ARGB32: BGRA8 only.');
        }
        if (pack('V', 1) !== pack('L', 1)) {
            throw FramebufferException::unsupportedFormat($format, 'PlutoVG writes host-order words; this host is big-endian.');
        }
        Layout::size($width, $height);
        $this->adopt(str_repeat("\0", $width * $height * 4));
    }

    /** A clone gets its own surface: PlutoVG draws into it, so two frames must not share one. */
    public function __clone()
    {
        $this->adopt($this->bytes());
    }

    public function viewportWidth(): int
    {
        return $this->width;
    }

    public function viewportHeight(): int
    {
        return $this->height;
    }

    public function hostFormat(): FormatSpec
    {
        return $this->format;
    }

    /** The memory PlutoVG draws into: BGRA8 rows, stride width × 4. The surface owns it. */
    public function memory(): ?PixelMemory
    {
        return new PixelMemory($this->pointer(), $this->width, $this->height, $this->width * 4, $this->format, $this->surface);
    }

    public function pointer(): int
    {
        return plutovg_surface_get_data($this->surface);
    }

    public function drewInto(?Region ...$regions): static
    {
        foreach ($regions as $region) {
            $this->wrote($region);
        }

        return $this;
    }

    public function getPixel(int $x, int $y): int
    {
        $this->guard($x, $y);
        [, $b, $g, $r, $a] = unpack('C4', $this->bytes(), ($y * $this->width + $x) * 4);

        return ($r << 24) | ($g << 16) | ($b << 8) | $a;
    }

    public function setPixel(int $x, int $y, int $value): static
    {
        $this->guard($x, $y);
        $this->put(new Region($x, $y, 1, 1), self::encode($value));

        return $this->wrote(new Region($x, $y, 1, 1));
    }

    public function setPixels(array $pixels): static
    {
        $bounds = $this->bounds($pixels, 3, '[x, y, value]');
        foreach ($pixels as [$x, $y, $value]) {
            $this->put(new Region($x, $y, 1, 1), self::encode($value));
        }

        return $this->wrote($bounds);
    }

    public function setRegion(array $coordinates, int $value): static
    {
        $bounds = $this->bounds($coordinates, 2, '[x, y]');
        $cell = self::encode($value);
        foreach ($coordinates as [$x, $y]) {
            $this->put(new Region($x, $y, 1, 1), $cell);
        }

        return $this->wrote($bounds);
    }

    /** A rect of one value, clipped to the surface: a rect straddling the edge is ordinary. */
    public function setSegment(int $x, int $y, int $width, int $height, int $color): static
    {
        $region = (new Region($x, $y, max(0, $width), max(0, $height)))->intersect($this->whole());
        if (is_null($region)) {
            return $this;
        }
        $this->tile($region, self::encode($color));

        return $this->wrote($region);
    }

    public function clear(): static
    {
        return $this->fill(0);
    }

    public function fill(int $color): static
    {
        $this->tile($this->whole(), self::encode($color));

        return $this->wrote($this->whole());
    }

    public function writeRgba8(string $rgba8, int $width, int $height, int $x = 0, int $y = 0): static
    {
        if ($width < 1 || $height < 1 || strlen($rgba8) !== $width * $height * 4) {
            throw new FramebufferException("writeRgba8() takes {$width}x{$height} RGBA8 pixels (".(max(0, $width) * max(0, $height) * 4).' bytes), got '.strlen($rgba8).'.');
        }
        $target = (new Region($x, $y, $width, $height))->intersect($this->whole());
        if (is_null($target)) {
            return $this;
        }
        $this->blit(self::swapped($rgba8), $width, $height, $target, $target->x - $x, $target->y - $y);

        return $this->wrote($target);
    }

    public function blitTo(InstanceThatManagesAFramebuffer $target, int $offset_x = 0, int $offset_y = 0): InstanceThatManagesAFramebuffer
    {
        return $target->blitFrom($this, $offset_x, $offset_y);
    }

    public function blitFrom(InstanceThatManagesAFramebuffer $source, int $offset_x = 0, int $offset_y = 0): InstanceThatManagesAFramebuffer
    {
        if ($source instanceof self) {
            $target = (new Region($offset_x, $offset_y, $source->width, $source->height))->intersect($this->whole());
            if (is_null($target)) {
                return $this;
            }
            $this->texture($source->surface, $target, $target->x - $offset_x, $target->y - $offset_y);

            return $this->wrote($target);
        }
        // A BGRA8 frame's ticket: PlutoVG copies from it, the bytes are never read in PHP.
        $memory = $this->sourceMemory($source);
        if (! is_null($memory) && $memory->format->equals(FormatSpec::bgra8())) {
            $target = (new Region($offset_x, $offset_y, $memory->width, $memory->height))->intersect($this->whole());
            if (is_null($target)) {
                return $this;
            }
            $over = plutovg_surface_create_for_address($memory->address, $memory->width, $memory->height, $memory->stride, $memory->owner)
                ?? throw new FramebufferException('PlutoVG could not take the source memory.');
            $this->texture($over, $target, $target->x - $offset_x, $target->y - $offset_y);

            return $this->wrote($target);
        }
        // Memory in another layout, or none: the source gives its bytes in this surface's own
        // format, converting in its own library, so nothing is converted in PHP.
        if (! ($source instanceof PagedFramebufferContract)) {
            $target = (new Region($offset_x, $offset_y, $source->viewportWidth(), $source->viewportHeight()))->intersect($this->whole());
            if (is_null($target)) {
                return $this;
            }
            $bgra8 = $source->flushRegion(new Region($target->x - $offset_x, $target->y - $offset_y, $target->width, $target->height), $this->format);
            $this->put($target, $bgra8);

            return $this->wrote($target);
        }
        [$rgba8, $width, $height, $top] = $this->blitSource($source);

        return $this->writeRgba8($rgba8, $width, $height, $offset_x, $offset_y + $top);
    }

    public function toRgba8(): string
    {
        return self::swapped($this->bytes());
    }

    public function dump(?int $layer = null): string
    {
        if (! is_null($layer) && $layer !== 0) {
            throw new FramebufferException("A PlutoVG surface has one layer, 0; got {$layer}.");
        }

        return $this->bytes();
    }

    public function flush(FormatSpec $spec, bool $as_array = false): string|array
    {
        return self::answer($this->in($this->whole(), $spec), $as_array);
    }

    public function flushRegion(Region $region, FormatSpec $spec, bool $as_array = false): string|array
    {
        if ($region->isEmpty() || $region->x < 0 || $region->y < 0 || $region->right() > $this->width || $region->bottom() > $this->height) {
            throw FramebufferException::outsideSurface($region, $this->width, $this->height);
        }

        return self::answer($this->in($region, $spec), $as_array);
    }

    /** A blank frame of $width x $height. PlutoVG holds BGRA8 only, so the scratch frame is BGRA8, not RGBA8. */
    public function scratch(int $width, int $height): InstanceThatManagesAFramebuffer
    {
        return new self(FormatSpec::bgra8(), $width, $height);
    }

    public function damageGranularity(): DamageGranularity
    {
        return DamageGranularity::pixel($this->width, $this->height);
    }

    public function preservesContentsOnPresent(): bool
    {
        return true;
    }

    public function scroll(Region $region, int $dx, int $dy): void
    {
        $clip = $region->intersect($this->whole());
        $source = is_null($clip) ? null : self::scrollSource($clip, $dx, $dy);
        if (is_null($source)) {
            return;
        }
        // A texture of the surface onto itself reads what it is writing: PlutoVG copies the rect
        // to a scratch surface first, then back, both by SRC.
        $scratch = plutovg_surface_create($source->width, $source->height) ?? throw new FramebufferException('PlutoVG could not make a scratch surface.');
        $canvas = plutovg_canvas_create($scratch);
        plutovg_canvas_set_operator($canvas, PLUTOVG_OPERATOR_SRC);
        plutovg_canvas_set_texture($canvas, $this->surface, PLUTOVG_TEXTURE_TYPE_PLAIN, 1.0, self::matrix(Affine::translation(-$source->x, -$source->y)));
        plutovg_canvas_fill_rect($canvas, 0, 0, $source->width, $source->height);
        $this->texture($scratch, new Region($source->x + $dx, $source->y + $dy, $source->width, $source->height), 0, 0);
        $this->touched($clip);
    }

    public function paintSpans(string $spans, int $rgba8): static
    {
        [$list, $bounds] = SpanList::read($spans, $rgba8, $this->width, $this->height);
        if (is_null($bounds) || ($rgba8 & 0xFF) === 0) {
            return is_null($bounds) ? $this : $this->wrote($bounds);
        }
        // One mask per pass. Spans paint in list order, so a span overlapping one already in the
        // mask blends a second time: the mask so far is painted first.
        $blank = str_repeat("\0", $bounds->width * $bounds->height);
        $mask = $blank;
        $taken = [];
        foreach ($list as [$y, $x, $length, $coverage]) {
            foreach ($taken[$y] ?? [] as [$x0, $x1]) {
                if ($x < $x1 && $x + $length > $x0) {
                    $this->solidThrough($rgba8, $mask, $bounds);
                    $mask = $blank;
                    $taken = [];
                    break;
                }
            }
            $taken[$y][] = [$x, $x + $length];
            $mask = substr_replace($mask, str_repeat(chr($coverage), $length), ($y - $bounds->y) * $bounds->width + $x - $bounds->x, $length);
        }
        $this->solidThrough($rgba8, $mask, $bounds);

        return $this->wrote($bounds);
    }

    public function paintMasks(array $masks, int $rgba8, Region $clip): static
    {
        MaskList::check($masks, $rgba8, $clip, $this->width, $this->height);
        $left = $top = PHP_INT_MAX;
        $right = $bottom = PHP_INT_MIN;
        foreach ($masks as [$coverage, $width, $mx, $my]) {
            $y0 = max($my, $clip->y);
            $y1 = min($my + intdiv(strlen($coverage), $width), $clip->bottom());
            $x0 = max($mx, $clip->x);
            $x1 = min($mx + $width, $clip->right());
            if ($y0 >= $y1 || $x0 >= $x1) {
                continue;
            }
            $w = $x1 - $x0;
            $rows = '';
            for ($y = $y0; $y < $y1; $y++) {
                $row = substr($coverage, ($y - $my) * $width + $x0 - $mx, $w);
                $lead = strspn($row, "\0");
                if ($lead < $w) {
                    $left = min($left, $x0 + $lead);
                    $right = max($right, $x0 + strlen(rtrim($row, "\0")));
                    $top = min($top, $y);
                    $bottom = max($bottom, $y + 1);
                }
                $rows .= $row;
            }
            if (($rgba8 & 0xFF) !== 0) {
                $this->solidThrough($rgba8, $rows, new Region($x0, $y0, $w, $y1 - $y0));
            }
        }

        return $right === PHP_INT_MIN ? $this : $this->wrote(new Region($left, $top, $right - $left, $bottom - $top));
    }

    /**
     * Another framebuffer's pixels through $placement, PlutoVG's way: the source premultiplied by
     * PlutoVG's converter (tinted first, when tinted), as a plain texture with the placement as its
     * matrix, sampled as PlutoVG samples, cut by one A8 mask: the pixels whose centre maps inside
     * the source (or $window), times $opacity and $coverage. $filter is not PlutoVG's to take.
     */
    public function paintImage(InstanceThatManagesAFramebuffer $source, Affine $placement, int $opacity = 255, Filter $filter = Filter::NEAREST, ?Region $clip = null, int $tint = 0xFFFFFFFF, ?array $window = null, ?string $coverage = null): static
    {
        // PlutoVG premultiplies from RGBA order only (plutovg_convert_rgba_to_argb), so an untinted
        // RGBA8 source with packed rows is converted from its memory into the picture, in C. A tint
        // (a per-channel multiply PlutoVG has no operator for), a BGRA8 source, or one in another
        // layout or with none, is read back.
        $memory = $tint === 0xFFFFFFFF ? $this->sourceMemory($source) : null;
        $direct = ! is_null($memory) && $memory->format->equals(FormatSpec::rgba8()) && $memory->stride === $memory->width * 4;
        if ($direct) {
            [$sw, $sh, $top] = [$memory->width, $memory->height, 0];
        } else {
            [$rgba8, $sw, $sh, $top] = $this->blitSource($source);
        }
        $plan = ImagePlacement::plan($placement, $sw, $sh, $top, $this->whole(), $clip, $opacity, $window);
        if (is_null($plan) || $opacity === 0) {
            return $this;
        }
        [$target, $inverse, $held] = $plan;
        [$a, $b, $c, $d, $e, $f] = $inverse;
        $forward = (new Affine($a, $b, $c, $d, $e, $f))->inverse();
        if (is_null($forward)) {
            return $this;
        }
        if ($direct) {
            $picture = plutovg_surface_create($sw, $sh) ?? throw new FramebufferException('PlutoVG could not make the picture.');
            $into = plutovg_surface_get_data($picture);
            plutovg_convert_rgba_to_argb($into, $memory->address, $sw, $sh, $sw * 4);
        } else {
            if ($tint !== 0xFFFFFFFF) {
                $rgba8 = self::tint($rgba8, $tint);
            }
            $premultiplied = null;
            plutovg_convert_rgba_to_argb($premultiplied, $rgba8, $sw, $sh, $sw * 4);
            $picture = plutovg_surface_create_for_data($premultiplied, $sw, $sh, $sw * 4) ?? throw new FramebufferException('PlutoVG could not take the source pixels.');
        }

        $mask = $this->footprint($target, $inverse, $held ?? [0.0, 0.0, (float) $sw, (float) $sh], $opacity, is_null($coverage) ? null : $this->coverageRows($coverage, $target));
        $this->through($target, $mask, function (plutovg_canvas $canvas) use ($picture, $forward, $target): void {
            plutovg_canvas_set_texture($canvas, $picture, PLUTOVG_TEXTURE_TYPE_PLAIN, 1.0, self::matrix(Affine::translation(-$target->x, -$target->y)->multiply($forward)));
        });

        return $this->wrote($target);
    }

    /**
     * A gradient through spans, PlutoVG's way: a PlutoVG linear (t = u) or radial (t = hypot(u, v))
     * gradient over the ramp's 256 colours as stops, its matrix the placement the inverse undoes,
     * its spread the spread, cut by the spans' coverage.
     */
    public function paintGradient(string $spans, string $ramp, string $kind, GradientSpread $spread, array $inverse): static
    {
        if (strlen($ramp) !== 1024) {
            throw new FramebufferException('paintGradient() takes a ramp of 256 RGBA colours (1024 bytes), got '.strlen($ramp).'.');
        }
        if (count($inverse) !== 6 || array_filter($inverse, fn (mixed $n): bool => (is_int($n) || is_float($n)) && is_finite($n)) !== $inverse) {
            throw new FramebufferException('paintGradient() takes an inverse of six finite numbers.');
        }
        if ($kind !== 'linear' && $kind !== 'radial') {
            throw new FramebufferException("paintGradient() takes 'linear' or 'radial', got '{$kind}'.");
        }
        [$list, $bounds] = SpanList::read($spans, 0xFFFFFFFF, $this->width, $this->height);
        if (is_null($bounds)) {
            return $this;
        }
        [$a, $b, $c, $d, $e, $f] = array_values($inverse);
        $forward = (new Affine((float) $a, (float) $b, (float) $c, (float) $d, (float) $e, (float) $f))->inverse();
        if (is_null($forward)) {
            return $this;
        }
        $stops = [];
        for ($i = 0; $i < 256; $i++) {
            [, $r, $g, $bl, $al] = unpack('C4', $ramp, $i * 4);
            $stops[] = new plutovg_gradient_stop($i / 255, new plutovg_color($r / 255, $g / 255, $bl / 255, $al / 255));
        }
        $method = match ($spread) {
            GradientSpread::PAD => PLUTOVG_SPREAD_METHOD_PAD,
            GradientSpread::REPEAT => PLUTOVG_SPREAD_METHOD_REPEAT,
            GradientSpread::REFLECT => PLUTOVG_SPREAD_METHOD_REFLECT,
        };
        $matrix = self::matrix(Affine::translation(-$bounds->x, -$bounds->y)->multiply($forward));

        $mask = str_repeat("\0", $bounds->width * $bounds->height);
        foreach ($list as [$y, $x, $length, $coverage]) {
            $mask = substr_replace($mask, str_repeat(chr($coverage), $length), ($y - $bounds->y) * $bounds->width + $x - $bounds->x, $length);
        }
        $this->through($bounds, $mask, function (plutovg_canvas $canvas) use ($kind, $method, $stops, $matrix): void {
            if ($kind === 'linear') {
                plutovg_canvas_set_linear_gradient($canvas, 0.0, 0.0, 1.0, 0.0, $method, $stops, 256, $matrix);
            } else {
                plutovg_canvas_set_radial_gradient($canvas, 0.0, 0.0, 1.0, 0.0, 0.0, 0.0, $method, $stops, 256, $matrix);
            }
        });

        return $this->wrote($bounds);
    }

    /** The pixels as PlutoVG's surface, and a canvas over it. */
    private function adopt(string $bgra8): void
    {
        $this->surface = plutovg_surface_create_for_data($bgra8, $this->width, $this->height, $this->width * 4)
            ?? throw new FramebufferException("PlutoVG could not take a {$this->width}x{$this->height} surface.");
        $this->canvas = plutovg_canvas_create($this->surface);
    }

    /** The surface's bytes, a copy: BGRA8 rows, top row first. */
    private function bytes(): string
    {
        return plutovg_surface_get_data($this->surface, true);
    }

    /** $texture's ($sx, $sy) onward landed over $target by SRC through a plain texture: the bytes as they are. */
    private function texture(plutovg_surface $texture, Region $target, int $sx, int $sy): void
    {
        plutovg_canvas_save($this->canvas);
        plutovg_canvas_set_operator($this->canvas, PLUTOVG_OPERATOR_SRC);
        plutovg_canvas_set_texture($this->canvas, $texture, PLUTOVG_TEXTURE_TYPE_PLAIN, 1.0, self::matrix(Affine::translation($target->x - $sx, $target->y - $sy)));
        plutovg_canvas_fill_rect($this->canvas, $target->x, $target->y, $target->width, $target->height);
        plutovg_canvas_restore($this->canvas);
    }

    /** BGRA8 rows of $width x $height, from ($sx, $sy) onward, landed over $target: the bytes as they are. */
    private function blit(string $bgra8, int $width, int $height, Region $target, int $sx, int $sy): void
    {
        $source = plutovg_surface_create_for_data($bgra8, $width, $height, $width * 4) ?? throw new FramebufferException('PlutoVG could not take the pixels to copy.');
        $this->texture($source, $target, $sx, $sy);
    }

    /** Packed BGRA8 rows into a rect. */
    private function put(Region $region, string $bgra8): void
    {
        $this->blit($bgra8, $region->width, $region->height, $region, 0, 0);
    }

    /** One BGRA8 cell over a rect: a tiled 1x1 texture, SRC. */
    private function tile(Region $region, string $cell): void
    {
        $one = plutovg_surface_create_for_data($cell, 1, 1, 4) ?? throw new FramebufferException('PlutoVG could not take the fill colour.');
        plutovg_canvas_save($this->canvas);
        plutovg_canvas_set_operator($this->canvas, PLUTOVG_OPERATOR_SRC);
        plutovg_canvas_set_texture($this->canvas, $one, PLUTOVG_TEXTURE_TYPE_TILED, 1.0, null);
        plutovg_canvas_fill_rect($this->canvas, $region->x, $region->y, $region->width, $region->height);
        plutovg_canvas_restore($this->canvas);
    }

    /** 0xRRGGBBAA, straight, through an A8 mask over $at: PlutoVG premultiplies the solid. */
    private function solidThrough(int $rgba8, string $mask, Region $at): void
    {
        $this->through($at, $mask, function (plutovg_canvas $canvas) use ($rgba8): void {
            plutovg_canvas_set_rgba($canvas, (($rgba8 >> 24) & 0xFF) / 255, (($rgba8 >> 16) & 0xFF) / 255, (($rgba8 >> 8) & 0xFF) / 255, ($rgba8 & 0xFF) / 255);
        });
    }

    /**
     * A paint over $at cut by the A8 $mask, PlutoVG's Porter-Duff way: on a scratch surface the
     * size of $at, $paint is filled by SRC, the mask (as alpha) applied by DST_IN, and the result
     * filled SRC_OVER onto the surface.
     */
    private function through(Region $at, string $mask, \Closure $paint): void
    {
        $scratch = plutovg_surface_create($at->width, $at->height) ?? throw new FramebufferException('PlutoVG could not make a scratch surface.');
        $canvas = plutovg_canvas_create($scratch);
        plutovg_canvas_set_operator($canvas, PLUTOVG_OPERATOR_SRC);
        $paint($canvas);
        plutovg_canvas_fill_rect($canvas, 0, 0, $at->width, $at->height);

        // The coverage as alpha: each byte c becomes the word 0xCC000000, black at alpha c, in C.
        $alpha = plutovg_surface_create($at->width, $at->height) ?? throw new FramebufferException('PlutoVG could not make a coverage mask.');
        plutovg_surface_put_alpha($alpha, $mask);
        plutovg_canvas_set_operator($canvas, PLUTOVG_OPERATOR_DST_IN);
        plutovg_canvas_set_texture($canvas, $alpha, PLUTOVG_TEXTURE_TYPE_PLAIN, 1.0, null);
        plutovg_canvas_fill_rect($canvas, 0, 0, $at->width, $at->height);

        plutovg_canvas_save($this->canvas);
        plutovg_canvas_set_operator($this->canvas, PLUTOVG_OPERATOR_SRC_OVER);
        plutovg_canvas_set_texture($this->canvas, $scratch, PLUTOVG_TEXTURE_TYPE_PLAIN, 1.0, self::matrix(Affine::translation($at->x, $at->y)));
        plutovg_canvas_fill_rect($this->canvas, $at->x, $at->y, $at->width, $at->height);
        plutovg_canvas_restore($this->canvas);
    }

    /** A rect's BGRA8 bytes, rows packed, top row first. */
    private function read(Region $region): string
    {
        $bytes = $this->bytes();
        if ($region->x === 0 && $region->width === $this->width) {
            return substr($bytes, $region->y * $this->width * 4, $region->height * $this->width * 4);
        }
        $rows = '';
        for ($y = $region->y; $y < $region->bottom(); $y++) {
            $rows .= substr($bytes, ($y * $this->width + $region->x) * 4, $region->width * 4);
        }

        return $rows;
    }

    /** A rect's bytes in $spec: BGRA8 as they are, RGBA8 with bytes 0 and 2 swapped; nothing else. */
    private function in(Region $region, FormatSpec $spec): string
    {
        return match (true) {
            $spec->equals(FormatSpec::bgra8()) => $this->read($region),
            $spec->equals(FormatSpec::rgba8()) => self::swapped($this->read($region)),
            default => throw FramebufferException::unsupportedFormat($spec, 'a PlutoVG surface converts to BGRA8 and RGBA8 only.'),
        };
    }

    /**
     * Per row of $target, the pixels whose centre maps (through $inverse) inside $held, as A8 at
     * $opacity, times the coverage of the span holding each pixel when $coverage rows are given.
     *
     * @param  array{float, float, float, float, float, float}  $inverse
     * @param  array{float, float, float, float}  $held
     * @param  list<string>|null  $coverage
     */
    private function footprint(Region $target, array $inverse, array $held, int $opacity, ?array $coverage): string
    {
        [$a, $b, $c, $d, $e, $f] = $inverse;
        [$u0, $v0, $u1, $v1] = $held;
        $level = chr($opacity);
        $mask = '';
        for ($row = 0; $row < $target->height; $row++) {
            $cy = $target->y + $row + 0.5;
            // u = a·x + (c·cy + e), v = b·x + (d·cy + f): each bound holds x in an interval.
            [$lo, $hi] = [-INF, INF];
            foreach ([[$a, $c * $cy + $e, $u0, $u1], [$b, $d * $cy + $f, $v0, $v1]] as [$k, $m, $low, $high]) {
                if ($k == 0.0) {
                    if (! ($m >= $low && $m < $high)) {
                        [$lo, $hi] = [INF, -INF];
                    }
                    continue;
                }
                [$p, $q] = [($low - $m) / $k, ($high - $m) / $k];
                if ($k > 0) {
                    $lo = max($lo, $p);
                    $hi = min($hi, $q);
                } else {
                    $lo = max($lo, $q);
                    $hi = min($hi, $p);
                }
            }
            $first = max($target->x, (int) ceil($lo - 0.5));
            $last = min($target->right(), (int) ceil($hi - 0.5));
            $line = str_repeat("\0", $target->width);
            if ($first < $last) {
                $line = substr_replace($line, str_repeat($level, $last - $first), $first - $target->x, $last - $first);
            }
            if (! is_null($coverage)) {
                $line = self::scaled($line, $coverage[$row]);
            }
            $mask .= $line;
        }

        return $mask;
    }

    /**
     * Spans as one coverage row per row of $target (0 where no span holds a pixel).
     *
     * @return list<string>
     */
    private function coverageRows(string $spans, Region $target): array
    {
        [$list] = SpanList::read($spans, 0xFFFFFFFF, $this->width, $this->height);
        $rows = array_fill(0, $target->height, str_repeat("\0", $target->width));
        foreach ($list as [$y, $x, $length, $coverage]) {
            $x0 = max($x, $target->x);
            $x1 = min($x + $length, $target->right());
            if ($y < $target->y || $y >= $target->bottom() || $x0 >= $x1) {
                continue;
            }
            $rows[$y - $target->y] = substr_replace($rows[$y - $target->y], str_repeat(chr($coverage), $x1 - $x0), $x0 - $target->x, $x1 - $x0);
        }

        return $rows;
    }

    /** Two A8 rows multiplied byte by byte, (a × b + 127) / 255. */
    private static function scaled(string $a, string $b): string
    {
        $out = '';
        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            $x = ord($a[$i]);
            $out .= $x === 0 ? "\0" : chr(intdiv($x * ord($b[$i]) + 127, 255));
        }

        return $out;
    }

    /** Straight RGBA8 times a 0xRRGGBBAA tint per channel, alpha included: c = (c × t + 127) / 255. */
    private static function tint(string $rgba8, int $tint): string
    {
        $t = [($tint >> 24) & 0xFF, ($tint >> 16) & 0xFF, ($tint >> 8) & 0xFF, $tint & 0xFF];
        $out = '';
        for ($i = 0, $n = strlen($rgba8); $i < $n; $i++) {
            $out .= chr(intdiv(ord($rgba8[$i]) * $t[$i & 3] + 127, 255));
        }

        return $out;
    }

    /**
     * RGBA8 to BGRA8 or back: bytes 0 and 2 of every pixel swap.
     * chisle: a PHP pass over the words; PlutoVG's converters premultiply, so they cannot just move bytes.
     */
    private static function swapped(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        return pack('V*', ...array_map(
            static fn (int $w): int => ($w & 0xFF00FF00) | (($w >> 16) & 0xFF) | (($w & 0xFF) << 16),
            unpack('V*', $bytes),
        ));
    }

    /** A pixel value (0xRRGGBBAA) as BGRA8 bytes. */
    private static function encode(int $value): string
    {
        return pack('C4', ($value >> 8) & 0xFF, ($value >> 16) & 0xFF, ($value >> 24) & 0xFF, $value & 0xFF);
    }

    private static function matrix(Affine $m): plutovg_matrix
    {
        return new plutovg_matrix($m->a, $m->b, $m->c, $m->d, $m->e, $m->f);
    }

    private function whole(): Region
    {
        return Region::wholeSurface($this->width, $this->height);
    }

    /**
     * The whole list checked before anything is written, and its bounding box.
     *
     * @param  array<int, array<int, int>>  $pixels
     */
    private function bounds(array $pixels, int $arity, string $shape): ?Region
    {
        $left = $top = PHP_INT_MAX;
        $right = $bottom = PHP_INT_MIN;
        foreach (array_values($pixels) as $position => $pixel) {
            if (! is_array($pixel) || count($pixel) !== $arity || ! is_int($pixel[0] ?? null) || ! is_int($pixel[1] ?? null) || ($arity === 3 && ! is_int($pixel[2] ?? null))) {
                throw new FramebufferException("Pixel {$position} is not {$shape}.");
            }
            $this->guard($pixel[0], $pixel[1]);
            $left = min($left, $pixel[0]);
            $top = min($top, $pixel[1]);
            $right = max($right, $pixel[0]);
            $bottom = max($bottom, $pixel[1]);
        }

        return $pixels === [] ? null : new Region($left, $top, $right - $left + 1, $bottom - $top + 1);
    }
}
