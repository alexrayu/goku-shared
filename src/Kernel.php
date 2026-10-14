<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private ?string $release = null;

    // A release zip unpacks over the previous install and keeps var/: one cache dir per release
    // (config/release.php, written by bin/release) stops the old compiled container from running new code.
    public function getCacheDir(): string
    {
        $file = $this->getProjectDir().'/config/release.php';
        $this->release ??= is_file($file) ? (string) require $file : '';

        return parent::getCacheDir().('' === $this->release ? '' : '-'.$this->release);
    }
}
