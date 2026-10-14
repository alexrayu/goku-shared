<?php

declare(strict_types=1);

namespace App\Install;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// Until the first user exists, every page leads to the installer; after an upgrade with pending
// migrations, to the updater.
final class InstallRedirectSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly InstallState $state,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 64: before the router (32) and the firewall (8), so unmatched paths redirect too.
        return [KernelEvents::REQUEST => ['onRequest', 64]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // The installer itself and the dev tools (/_profiler, /_wdt) stay reachable.
        $path = $event->getRequest()->getPathInfo();
        if ('/install' === $path || str_starts_with($path, '/_')) {
            return;
        }

        if (!$this->state->isInstalled() || $this->state->hasPendingMigrations()) {
            // The router has not seen the request yet: without its base path, a site in a
            // subfolder would redirect to the domain root.
            $this->urlGenerator->getContext()->fromRequest($event->getRequest());
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_install')));
        }
    }
}
