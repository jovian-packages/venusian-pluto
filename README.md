# jovian/venusian-pluto

The `plutovg` rasterization and painting drivers for [Venusian Topside](https://github.com/VenusianPHP/topside): paths filled by [PlutoVG](https://github.com/sammycage/plutovg) through [ext-plutovg](https://github.com/php-io-extensions/plutovg).

```bash
composer require jovian/venusian-pluto
```

The service provider registers `plutovg` on Topside's rasterization and painting managers. Pick it like any other driver, or let `auto` try it; without ext-plutovg loaded the driver refuses to construct and `auto` moves on.

## What PlutoVG does here

- **Anti-aliased path fills**, which is everything Topside's shared rasterizer lowers shapes and strokes to.
- **Everything else comes from VelvetGE:** hard edges, ellipses and round rects, shadows and polylines, batches.

## Where it draws

- **In place**, straight into the framebuffer's memory, when that memory is B G R A rows (PlutoVG's own pixel layout on a little-endian host, and what windows use). This holds for a frame with any transparency: PlutoVG's premultiplied source-over on the stored bytes is the same arithmetic as VelvetGE's blend. Measured over 3,000 translucent polygons on pixels of every alpha: at most 2 off per byte from VelvetGE blending PlutoVG's own coverage, 99.9% of differing bytes 1 off (`PlutoVGScanner::BLEND`). 1.3× faster than the coverage route on small shapes, 2.5× on large ones.
- **As coverage**, for every other layout: PlutoVG fills the path in white on a scratch surface, `plutovg_surface_get_alpha()` reads it out in one call, and the framebuffer's own store blends it.

## How it differs from VelvetGE

Coverage at a shape's edge is PlutoVG's own arithmetic. On simple shapes every pixel is within 11 of VelvetGE's (out of 255). Where a path's contours overlap inside one pixel (a self-crossing polygon, the joins of a stroked polyline), PlutoVG adds the overlapping coverage, so that pixel can be up to 220 brighter. Under 1% of a shape's pixels are affected, and its total area stays within 0.5%.

## Painting

`PlutoPaintingDriver` is VelvetGE's painter with `PlutoBrush`. The brush takes anti-aliased `path`, `gradpath`, `polyline`, `ellipse`, `ring`, `roundrect` and `roundring` commands drawn into 32-bit memory (any of the four channel orders, top row first). Each shape is lowered exactly as VelvetGE lowers it. On B G R A memory a solid shape is drawn in place by PlutoVG; otherwise, and for every gradient, PlutoVG draws its coverage and ext-plutovg blends that with VelvetGE's arithmetic (`plutovg_surface_blend_colour()`, gradients through `plutovg_surface_blend_ramp()` sampling VelvetGE's ramp). Hard edges, clears, shadows, glass, layers, images, glyphs and spans stay VelvetGE's, so a frame with hard edges is VelvetGE's byte for byte.

Measured against the native painter, one shape at a time: worst per-byte distance 8 on rects, 3 on lines, 27 on ellipses, 40 on rings, 32 on round rects (`PlutoBrush::TOLERANCE` = 40); areas within 0.6%. Paths, strokes and polylines pass 40 only where their contours cross inside a pixel, under 1% of differing pixels. Speed, 200 draws on a 1024×768 BGRA frame (topside eeed8ad): 1.05–1.7× the `ext` painter, 10–40× faster than `native`.

## Requirements

PHP 8.4+, ext-plutovg 0.11 with `plutovg_surface_blend_colour()` (php-io-extensions/plutovg 7121b96 or later), venusian/topside 0.11.5+ (painting: 38dffd0 or later).

## License

MIT.
