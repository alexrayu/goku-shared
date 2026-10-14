<?php

declare(strict_types=1);

namespace App\Tests\Image;

use App\Image\ImageProcessor;
use App\Image\GdImageProcessor;

final class GdImageProcessorTest extends ImageProcessorTestCase
{
    protected function processor(): ImageProcessor
    {
        return new GdImageProcessor();
    }
}
