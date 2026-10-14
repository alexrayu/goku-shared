<?php

declare(strict_types=1);

namespace App\Controller;

use App\Install\DatabaseSetup;
use App\Install\InstallState;
use App\Install\Requirements;
use App\Security\UserProvisioner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// One route walks through the steps InstallState reports as open: requirements, database,
// schema, first user. Open to whoever arrives first; the route closes itself once a user exists.
// Afterwards it only runs the migrations an uploaded release brings. That step asks for
// UPDATE_TOKEN instead of a login: the new release's entities may not load from the old schema.
final class InstallController extends AbstractController
{
    public function __construct(
        private readonly InstallState $state,
        private readonly DatabaseSetup $setup,
        #[Autowire('%env(UPDATE_TOKEN)%')]
        private readonly string $updateToken,
    ) {
    }

    #[Route('/install', name: 'app_install')]
    public function __invoke(Request $request, Requirements $requirements, UserProvisioner $provisioner, Security $security): Response
    {
        if ($this->state->isInstalled()) {
            return $this->state->hasPendingMigrations() ? $this->update($request) : $this->redirectToRoute('app_login');
        }

        $problems = $requirements->problems();
        if ([] !== $problems) {
            return $this->render('install/requirements.html.twig', ['problems' => $problems]);
        }
        if (!$this->state->isConfigured()) {
            return $this->database($request);
        }
        if (!$this->state->hasSchema()) {
            return $this->schema($request);
        }

        return $this->user($request, $provisioner, $security);
    }

    private function database(Request $request): Response
    {
        $form = ['host' => 'localhost', 'port' => '3306', 'name' => '', 'user' => ''];
        $error = null;

        if ($request->isMethod('POST')) {
            foreach (array_keys($form) as $field) {
                $form[$field] = trim((string) $request->request->get($field));
            }
            try {
                $dsn = $this->setup->dsn($form['host'], (int) $form['port'], $form['name'], $form['user'], (string) $request->request->get('password'));
                $manual = $this->setup->writeEnvLocal($dsn);
                if (null === $manual) {
                    return $this->redirectToRoute('app_install');
                }

                return $this->render('install/env_local.html.twig', ['contents' => $manual]);
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('install/database.html.twig', ['form' => $form, 'error' => $error]);
    }

    private function schema(Request $request): Response
    {
        $error = null;
        if ($request->isMethod('POST')) {
            try {
                $this->setup->migrate();

                return $this->redirectToRoute('app_install');
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('install/schema.html.twig', ['error' => $error]);
    }

    private function update(Request $request): Response
    {
        $error = null;
        if ('' !== $this->updateToken && $request->isMethod('POST')) {
            try {
                if (!hash_equals($this->updateToken, (string) $request->request->get('token'))) {
                    throw new \RuntimeException('Wrong update token. Copy UPDATE_TOKEN from .env.local.');
                }
                $this->setup->migrate();

                return $this->redirectToRoute('app_login');
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('install/update.html.twig', ['error' => $error, 'enabled' => '' !== $this->updateToken]);
    }

    private function user(Request $request, UserProvisioner $provisioner, Security $security): Response
    {
        $error = null;
        $email = (string) $request->request->get('email');

        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password');

            try {
                if ($password !== (string) $request->request->get('password_confirm')) {
                    throw new \InvalidArgumentException('Passwords do not match.');
                }

                $user = $provisioner->create($email, $password);
                $security->login($user, 'form_login', 'main');

                return $this->redirectToRoute('admin');
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('install/index.html.twig', [
            'error' => $error,
            'email' => $email,
        ]);
    }
}
