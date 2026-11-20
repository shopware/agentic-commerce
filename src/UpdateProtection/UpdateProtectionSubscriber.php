<?php

declare(strict_types=1);

namespace Swag\AgenticCommerce\UpdateProtection;

use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Routing\KernelListenerPriorities;
use Swag\AgenticCommerce\SdkAvailability;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;

/**
 * Prepares the process for core replacing the plugin's files: the Administration download and
 * upload routes, `plugin:zip-import` and `store:download`.
 *
 * It resolves everything its response and terminate handlers use while the old files are still
 * there, because the container cache is cleared after the swap.
 *
 * @internal
 */
#[Package('framework')]
final class UpdateProtectionSubscriber implements EventSubscriberInterface
{
    public const PLUGIN_TECHNICAL_NAME = 'SwagAgenticCommerce';

    private const DOWNLOAD_ROUTE = 'api.extension.download';

    private const UPLOAD_ROUTE = 'api.extension.upload';

    private const ZIP_IMPORT_COMMAND = 'plugin:zip-import';

    private const STORE_DOWNLOAD_COMMAND = 'store:download';

    private bool $isRestartSignalPending = false;

    private bool $hasOpenedUpdateWindow = false;

    private ?CacheItemPoolInterface $restartSignalPool = null;

    /**
     * @param (\Closure(): ?CacheItemPoolInterface)|null $restartSignalPoolProvider
     */
    public function __construct(
        private readonly ClassPreloader $classPreloader,
        private readonly string $pluginRoot,
        private readonly ?\Closure $restartSignalPoolProvider = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => ['onController', KernelListenerPriorities::KERNEL_CONTROLLER_EVENT_SCOPE_VALIDATE_POST],
            KernelEvents::RESPONSE => 'onResponse',
            ConsoleEvents::COMMAND => 'onCommand',
            ConsoleEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $replacesPluginFiles = match ($request->attributes->get('_route')) {
            self::DOWNLOAD_ROUTE => self::PLUGIN_TECHNICAL_NAME === $request->attributes->get('technicalName'),
            self::UPLOAD_ROUTE => $this->isOwnArchive($request->files->get('file')),
            default => false,
        };

        if ($replacesPluginFiles) {
            $this->protect(false);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->signalWorkerRestart();
            $this->closeUpdateWindow();
        }
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $input = $event->getInput();

        switch ($event->getCommand()?->getName()) {
            case self::ZIP_IMPORT_COMMAND:
                if ($this->isOwnArchivePath($this->readInput($input, 'zip-file', true))) {
                    $this->protect(false);
                }

                break;
            case self::STORE_DOWNLOAD_COMMAND:
                // The command runs the update in this process once the files are replaced, so the
                // plugin's listeners have to keep working.
                if (self::PLUGIN_TECHNICAL_NAME === $this->readInput($input, 'pluginName', false)) {
                    $this->protect(true);
                }

                break;
        }
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $this->signalWorkerRestart();
        $this->closeUpdateWindow();
    }

    private function protect(bool $runsUpdateAfterwards): void
    {
        $this->classPreloader->preload($this->isSdkBundled());

        $this->restartSignalPool = null !== $this->restartSignalPoolProvider ? ($this->restartSignalPoolProvider)() : null;
        $this->isRestartSignalPending = true;

        if (!$runsUpdateAfterwards) {
            UpdateWindow::open();
            $this->hasOpenedUpdateWindow = true;
        }
    }

    private function isSdkBundled(): bool
    {
        return SdkAvailability::isBundled($this->pluginRoot);
    }

    private function isOwnArchive(mixed $uploadedFile): bool
    {
        return $uploadedFile instanceof UploadedFile && $this->isOwnArchivePath($uploadedFile->getPathname());
    }

    private function isOwnArchivePath(mixed $path): bool
    {
        return \is_string($path) && '' !== $path && ArchiveFirstEntry::isInDirectory($path, self::PLUGIN_TECHNICAL_NAME);
    }

    private function readInput(InputInterface $input, string $name, bool $isArgument): mixed
    {
        try {
            return $isArgument ? $input->getArgument($name) : $input->getOption($name);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function closeUpdateWindow(): void
    {
        if ($this->hasOpenedUpdateWindow) {
            UpdateWindow::close();
            $this->hasOpenedUpdateWindow = false;
        }
    }

    private function signalWorkerRestart(): void
    {
        if (!$this->isRestartSignalPending || null === $this->restartSignalPool) {
            return;
        }

        $this->isRestartSignalPending = false;

        $signal = $this->restartSignalPool->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY);
        $signal->set(microtime(true));
        $this->restartSignalPool->save($signal);
    }
}
