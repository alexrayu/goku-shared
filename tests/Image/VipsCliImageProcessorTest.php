<?php

declare(strict_types=1);

namespace App\Tests\Image;

use App\Image\ImageProcessor;
use App\Image\ImageProcessorFactory;
use App\Image\VipsCliImageProcessor;

final class VipsCliImageProcessorTest extends ImageProcessorTestCase
{
    protected function setUp(): void
    {
        if (!ImageProcessorFactory::available()['vips']) {
            self::markTestSkipped('Needs the vips CLI.');
        }
        parent::setUp();
    }

    protected function processor(): ImageProcessor
    {
        return new VipsCliImageProcessor();
    }
}
