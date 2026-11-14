<?php

namespace Jovian\Rendering\Pluto\Providers;

use Jovian\Rendering\Pluto\PlutoPaintingDriver;
use Jovian\Rendering\Pluto\PlutoVGRasterizationDriver;
use ReflectionException;
use Surface\Rendering\Painters;
use Surface\Rendering\Rasterization;
use Voyager\NutsAndBolts\ServiceProvider;

class VenusianPlutoServiceProvider extends ServiceProvider
{
    /**
     * Registers 'plutovg' on Topside's rasterization and painting managers.
     *
     * @throws ReflectionException
     */
    public function boot(): void
    {
        Rasterization::manager()->extend('plutovg', fn () => new PlutoVGRasterizationDriver());
        Painters::manager()->extend('plutovg', fn () => new PlutoPaintingDriver());
    }
}
