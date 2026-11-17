# jovian/venusian-pluto

The `plutovg` rasterization and painting drivers for [Venusian Topside](https://github.com/VenusianPHP/topside): paths filled by [PlutoVG](https://github.com/sammycage/plutovg) through [ext-plutovg](https://github.com/php-io-extensions/plutovg).

```bash
composer require jovian/venusian-pluto
```

The service provider registers `plutovg` on Velvet's framebuffer, rasterization and painting managers. Pick it like any other driver, or let `auto` try it; without ext-plutovg loaded the driver refuses to construct and `auto` moves on.

## What PlutoVG does here

- **Paths, ellipses, rings, round rects and round rings**, as PlutoVG geometry (`plutovg_path_add_ellipse`, `plutovg_path_add_round_rect`, `plutovg_path_arc_to` corners). Strokes and wide polylines reach it as their outlines.
- **Hard edges** PlutoVG does not have. The edge mode is a preference: asked for hard edges, the scanner answers `Edges::ANTIALIASED` from `edges()` and draws anti-aliased. A frame that cannot hold partial coverage takes it from 128 up when it lands.
- **Shadows** PlutoVG has no answer for; Velvet hands them back undrawn. One-pixel lines belong to hard edges, so a thin stroke is always an outline here. Nothing here uses Lustrous or any other module.

## Where it draws

- **In place**, straight into the framebuffer's memory, when that memory is B G R A rows (PlutoVG's own pixel layout on a little-endian host, and what windows use). This holds for a frame with any transparency: PlutoVG's premultiplied source-over on the stored bytes is the same arithmetic as VelvetGE's blend. Measured over 20,000 translucent polygons on pixels of every alpha: at most 3 off per byte from VelvetGE blending PlutoVG's own coverage (99.94% of differing bytes are 1 off, 0.06% are 2 off, and 2 bytes in 9 million are 3 off). 1.3× faster than the coverage route on small shapes, 2.5× on large ones.
- **As coverage**, for every other layout: PlutoVG fills the path in white on a scratch surface, `plutovg_surface_get_alpha()` reads it out in one call, and the framebuffer's own store blends it.

## How it differs from VelvetGE

Coverage at a shape's edge is PlutoVG's own arithmetic. On simple shapes every pixel is within 11 of VelvetGE's (out of 255). Where a path's contours overlap inside one pixel (a self-crossing polygon, the joins of a stroked polyline), PlutoVG adds the overlapping coverage, so that pixel can be up to 220 brighter. Under 1% of a shape's pixels are affected, and its total area stays within 0.5%.

## Images

`plutovg` on Images reads PNG and JPEG. stb has no call that reports a header without the pixels, so `info()` reads the file header in PHP. The decoded surface is premultiplied ARGB, and PlutoVG's own `plutovg_convert_argb_to_rgba()` writes it into the frame as straight R, G, B, A, address to address, a row at a time when the frame's rows are padded. An opaque pixel matches the file; a partial pixel is divided back as PlutoVG divides, `(channel × 255) ÷ alpha`. PlutoVG has no straight B, G, R, A output, so the decoder takes RGBA frames only, and Images refuses it for a BGRA frame by name. BMP, GIF, TGA, PSD, HDR, PIC and PNM are in this stb and are not formats Images names. TIFF is not read.

## Painting

`PlutoPaintingDriver` is VelvetGE's painter with `PlutoBrush`. The brush takes anti-aliased `path`, `gradpath`, `polyline`, `ellipse`, `ring`, `roundrect` and `roundring` commands drawn into 32-bit memory (any of the four channel orders, top row first). Each shape is lowered by PlutoVG's rasterizer, so curves are PlutoVG geometry. On B G R A memory a solid shape is drawn in place by PlutoVG; otherwise, and for every gradient, PlutoVG draws its coverage and ext-plutovg blends that with VelvetGE's arithmetic (`plutovg_surface_blend_colour()`, gradients through `plutovg_surface_blend_ramp()` sampling VelvetGE's ramp). Hard edges, clears, shadows, glass, layers, images, glyphs and spans go to VelvetGE's painter, which rasterizes with PlutoVG unless another rasterizer is given.

Measured against the native painter, one shape at a time: worst per-byte distance 8 on rects, 3 on lines, 27 on ellipses, 40 on rings, 32 on round rects (`PlutoBrush::TOLERANCE` = 40); areas within 0.6%. Paths, strokes and polylines pass 40 only where their contours cross inside a pixel, under 1% of differing pixels. Speed, 200 draws on a 1024×768 BGRA frame (topside eeed8ad): 1.05–1.7× the `ext` painter, 10–40× faster than `native`.

## Requirements

PHP 8.4+, ext-plutovg 0.11 with `plutovg_surface_blend_colour()` (php-io-extensions/plutovg 7121b96 or later), venusian/topside 0.11.5+ (painting: 38dffd0 or later).

## License

MIT.
