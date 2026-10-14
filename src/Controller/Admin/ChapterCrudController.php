<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Chapter;
use App\Entity\Work;
use App\Enum\WorkType;
use App\Ingest\ArchiveIngestor;
use App\Ingest\ChapterPages;
use App\Ingest\ChunkedUpload;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractCrudController<Chapter>
 */
final class ChapterCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Chapter::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Chapter')
            ->setEntityLabelInPlural('Chapters')
            ->setPageTitle(Crud::PAGE_DETAIL, static fn (Chapter $chapter): string => (string) $chapter)
            ->setDefaultSort(['work' => 'ASC', 'number' => 'ASC']);
    }

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChapterPages $chapterPages,
        private readonly AdminUrlGenerator $urlGenerator,
    ) {
    }

    // Chapters are added from their work's page (?work=id), numbered after the last one.
    public function createEntity(string $entityFqcn): Chapter
    {
        $work = $this->em->find(Work::class, $this->getContext()?->getRequest()->query->getInt('work'))
            ?? throw new NotFoundHttpException('Add chapters from their work\'s page.');
        $last = $work->getChapters()->last();

        return new Chapter($work, false === $last ? '1' : (string) (floor((float) $last->getNumber()) + 1));
    }

    public function configureFields(string $pageName): iterable
    {
        // Fixed by the work page when creating; editing can still move a chapter.
        yield AssociationField::new('work')->setFormTypeOption('constraints', [new NotBlank()])->hideWhenCreating();
        // DECIMAL arrives as a string; sprintf formatting avoids the int|float-only Intl formatter.
        // Lists show the public label ("12", "12.5"); the form keeps one decimal.
        yield NumberField::new('number')->setNumDecimals(1)->setStoredAsString()->setNumberFormat('%.1f')
            ->formatValue(static fn (mixed $value, Chapter $chapter): string => $chapter->getNumberLabel());
        yield TextField::new('title');
        yield TextareaField::new('summary')->hideOnIndex()
            ->setHelp('Optional. Shown above the pages and used as the description in search results and link previews.');
        yield BooleanField::new('published');
        // Set on first publication, never edited by hand.
        yield DateTimeField::new('publishedAt', 'First published')->hideOnForm();
        yield CollectionField::new('pages')->setLabel(false)->onlyOnDetail()->setTemplatePath('admin/chapter/pages.html.twig');
    }

    // The page grid's Stimulus controller.
    public function configureAssets(Assets $assets): Assets
    {
        return $assets->addAssetMapperEntry('app');
    }

    public function configureActions(Actions $actions): Actions
    {
        $upload = Action::new('uploadPages', 'Upload pages', 'fa fa-file-zipper')->linkToCrudAction('uploadPages');
        // The real reader, drafts included for logged-in users: pages are scrambled, so this is the only view of them.
        $preview = Action::new('preview', 'Preview', 'fa fa-eye')
            ->linkToUrl(fn (Chapter $chapter): string => $this->readerUrl($chapter))
            ->setHtmlAttributes(['target' => '_blank']);
        $deletePages = Action::new('deletePages', 'Delete pages', 'fa fa-trash-can')->linkToCrudAction('deletePages')
            ->displayIf(static fn (Chapter $chapter): bool => !$chapter->getPages()->isEmpty());

        return $actions
            ->remove(Crud::PAGE_INDEX, Action::NEW)
            ->update(Crud::PAGE_DETAIL, Action::INDEX, fn (Action $action): Action => $action->setLabel('Back to work')
                ->linkToUrl(fn (Chapter $chapter): string => $this->workUrl($chapter->getWork())))
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $preview)
            ->add(Crud::PAGE_INDEX, $upload)
            ->add(Crud::PAGE_DETAIL, $preview)
            ->add(Crud::PAGE_DETAIL, $upload)
            ->add(Crud::PAGE_DETAIL, $deletePages);
    }

    // Save and return / add another go back to the chapter's work, not the flat chapter list.
    protected function getRedirectResponseAfterSave(AdminContext $context, string $action): RedirectResponse
    {
        /** @var Chapter $chapter */
        $chapter = $context->getEntity()->getInstance();

        return match ($context->getRequest()->request->all()['ea']['newForm']['btn'] ?? null) {
            Action::SAVE_AND_RETURN => $this->redirect($this->workUrl($chapter->getWork())),
            Action::SAVE_AND_ADD_ANOTHER => $this->redirect($this->urlGenerator->setController(self::class)->setAction(Action::NEW)
                ->unset(EA::ENTITY_ID)->set('work', $chapter->getWork()->getId())->generateUrl()),
            default => parent::getRedirectResponseAfterSave($context, $action),
        };
    }

    public function delete(AdminContext $context): KeyValueStore|Response
    {
        /** @var Chapter $chapter */
        $chapter = $context->getEntity()->getInstance();
        $response = parent::delete($context);

        return $response instanceof RedirectResponse ? $this->redirect($this->workUrl($chapter->getWork())) : $response;
    }

    // Stored files go with the chapter; a chapter still processing is kept, with the reason shown.
    public function deleteEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        try {
            $this->chapterPages->deleteChapter($entityInstance);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }
    }

    // Confirm page, then a CSRF-checked POST: frees the chapter for a new upload.
    #[AdminRoute('/{id}/delete-pages', 'delete_pages', options: ['methods' => ['GET', 'POST']])]
    public function deletePages(#[MapEntity] Chapter $chapter, Request $request, AdminUrlGenerator $urlGenerator): Response
    {
        if ($request->isMethod('POST')) {
            try {
                if (!$this->isCsrfTokenValid('chapter_delete_pages', (string) $request->request->get('_token'))) {
                    throw new \InvalidArgumentException('Invalid CSRF token. Reload the page and try again.');
                }
                $this->chapterPages->clear($chapter);
                $this->addFlash('success', 'Pages deleted. Upload a new archive when ready.');

                return $this->redirect($urlGenerator->setController(self::class)->setAction(Action::DETAIL)
                    ->setEntityId($chapter->getId())->generateUrl());
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('admin/chapter/delete_pages.html.twig', ['chapter' => $chapter]);
    }

    // One drag-and-drop move from the page grid: the page goes right after "after", or first without it.
    // A refusal is flashed for the reload the grid does on any error.
    #[AdminRoute('/{id}/move-page', 'move_page', options: ['methods' => ['POST']])]
    public function movePage(#[MapEntity] Chapter $chapter, Request $request): Response
    {
        try {
            if (!$this->isCsrfTokenValid('chapter_move_page', (string) $request->request->get('_token'))) {
                throw new \InvalidArgumentException('Invalid CSRF token. Reload the page and try again.');
            }
            $after = $request->request->has('after') ? $request->request->getInt('after') : null;
            $this->chapterPages->move($chapter, $request->request->getInt('page'), $after);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return new Response($e->getMessage(), Response::HTTP_CONFLICT);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function workUrl(Work $work): string
    {
        return $this->urlGenerator->setController(WorkCrudController::class)->setAction(Action::DETAIL)
            ->setEntityId($work->getId())->unset('work')->generateUrl();
    }

    private function readerUrl(Chapter $chapter): string
    {
        $work = $chapter->getWork();

        return WorkType::Oneshot === $work->getType()
            ? $this->generateUrl('work_show', ['slug' => $work->getSlug()])
            : $this->generateUrl('chapter_read', ['slug' => $work->getSlug(), 'number' => $chapter->getNumberLabel()]);
    }

    #[AdminRoute('/{id}/upload', 'upload', options: ['methods' => ['GET', 'POST']])]
    public function uploadPages(
        #[MapEntity] Chapter $chapter,
        Request $request,
        ArchiveIngestor $ingestor,
        AdminUrlGenerator $urlGenerator,
    ): Response {
        if ($request->isMethod('POST')) {
            try {
                // PHP drops the whole body, token included, when it exceeds post_max_size.
                if (0 === $request->request->count() && 0 === $request->files->count()) {
                    throw new \InvalidArgumentException('The upload exceeds the server size limit.');
                }
                if (!$this->isCsrfTokenValid('chapter_upload', (string) $request->request->get('_token'))) {
                    throw new \InvalidArgumentException('Invalid CSRF token. Reload the page and try again.');
                }
                $file = $request->files->get('archive');
                if (!$file instanceof UploadedFile) {
                    throw new \InvalidArgumentException('Choose an archive to upload.');
                }
                if (!$file->isValid()) {
                    throw new \InvalidArgumentException($file->getErrorMessage());
                }

                $ingestor->accept($chapter, $file, $file->getClientOriginalName());
                $this->addFlash('success', 'Archive queued. Page status updates as the worker processes it.');

                return $this->redirect($urlGenerator->setController(self::class)->setAction(Action::DETAIL)
                    ->setEntityId($chapter->getId())->generateUrl());
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->render('admin/chapter/upload.html.twig', ['chapter' => $chapter, 'chunk_size' => ChunkedUpload::chunkSize()]);
    }

    // One slice of a chunked upload (chapter_upload_controller.js); the last one queues the archive.
    #[AdminRoute('/{id}/upload-chunk', 'upload_chunk', options: ['methods' => ['POST']])]
    public function uploadChunk(
        #[MapEntity] Chapter $chapter,
        Request $request,
        ChunkedUpload $chunks,
        ArchiveIngestor $ingestor,
        AdminUrlGenerator $urlGenerator,
    ): JsonResponse {
        try {
            if (!$this->isCsrfTokenValid('chapter_upload', (string) $request->request->get('_token'))) {
                throw new \InvalidArgumentException('Invalid CSRF token. Reload the page and try again.');
            }
            $chunk = $request->files->get('chunk');
            if (!$chunk instanceof UploadedFile || !$chunk->isValid()) {
                throw new \InvalidArgumentException('A part of the upload did not arrive. Start it again.');
            }

            $archive = $chunks->append(sprintf('chapter-%d', $chapter->getId()), $chunk, $request->request->getInt('offset'), $request->request->getInt('size'));
            if (null === $archive) {
                return $this->json(['done' => false]);
            }
            try {
                $ingestor->accept($chapter, $archive, (string) $request->request->get('name'));
            } finally {
                unlink($archive->getPathname());
            }
            $this->addFlash('success', 'Archive queued. Page status updates as the worker processes it.');

            return $this->json(['redirect' => $urlGenerator->setController(self::class)->setAction(Action::DETAIL)
                ->setEntityId($chapter->getId())->generateUrl()]);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
