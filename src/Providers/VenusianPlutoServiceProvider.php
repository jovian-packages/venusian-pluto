<?php

namespace Jovian\Rendering\Pluto\Providers;

use Jovian\Rendering\Pluto\Framebuffers\PlutoVGFramebufferDriver;
use Jovian\Rendering\Pluto\PlutoPaintingDriver;
use Jovian\Rendering\Pluto\PlutoVGRasterizationDriver;
use Jovian\Rendering\Pluto\Stroking\PlutoVGStrokingDriver;
use ReflectionException;
use Voyager\NutsAndBolts\ServiceProvider;

class VenusianPlutoServiceProvider extends ServiceProvider
{
    /**
     * Registers 'plutovg' on Velvet's painters, framebuffers and rasterizers.
     *
     * @throws ReflectionException
     */
    public function boot(): void
    {
        $this->registerVelvetDrivers();
    }

    /**
     * @throws ReflectionException
     */
    protected function registerVelvetDrivers(): void
    {

        $this->registerVelvetFramebufferDriver();
        $this->registerVelvetRasterizationDriver();
        $this->registerVelvetPaintbrushDriver();
        $this->registerStrokingDriver();
    }

    /** 'plutovg' on Velvet's framebuffers.
     * @throws ReflectionException
     */
    protected function registerVelvetFramebufferDriver(): void
    {
        app('velvet.framebuffers')->extend('plutovg', fn () => new PlutoVGFramebufferDriver());
    }

    /** 'plutovg' on Velvet's rasterizers.
     * @throws ReflectionException
     */
    protected function registerVelvetRasterizationDriver(): void
    {
        app('velvet.rasterizers')->extend('plutovg', fn () => new PlutoVGRasterizationDriver());
    }

    /**
     * @throws ReflectionException
     */
    protected function registerVelvetPaintbrushDriver(): void
    {
        app('velvet.painters')->extend('plutovg', fn () => new PlutoPaintingDriver());
    }

    /**
     * 'plutovg' on Topside's strokers: stroking is Rendering's, for every engine, not Velvet's.
     *
     * @throws ReflectionException
     */
    protected function registerStrokingDriver(): void
    {
        app('strokers')->extend('plutovg', fn () => new PlutoVGStrokingDriver());
    }
}
