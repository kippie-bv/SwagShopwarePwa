<?php declare(strict_types=1);

namespace SwagShopwarePwa\Pwa\Bundle;

use League\Flysystem\FileNotFoundException;
use League\Flysystem\FilesystemInterface;
use Shopware\Core\Framework\App\AppEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Event\PluginPostActivateEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostDeactivateEvent;
use Shopware\Core\Framework\Plugin\PluginCollection;
use Shopware\Core\Framework\Plugin\PluginEntity;
use Shopware\Core\Kernel;
use SplFileInfo;
use SwagShopwarePwa\Pwa\Bundle\Helper\FormattingHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;

class AssetService implements EventSubscriberInterface
{
    /**
     * @var string
     */
    private $assetArtifactDirectory = 'pwa-bundles-assets';

    /**
     * @var string
     */
    private $resourcesDirectory = '/src/Resources/app/pwa';

    /**
     * @var Kernel
     */
    private $kernel;

    /**
     * @var EntityRepositoryInterface
     */

    private $pluginRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $appRepository;

    /**
     * @var FormattingHelper
     */
    private $helper;

    /**
     * @var FilesystemInterface
     */
    private $fileSystem;

    public function __construct(
        Kernel $kernel,
        EntityRepositoryInterface $pluginRepository,
        EntityRepositoryInterface $appRepository,
        FormattingHelper $helper,
        FilesystemInterface $fileSystem)
    {
        $this->kernel = $kernel;
        $this->pluginRepository = $pluginRepository;
        $this->appRepository = $appRepository;
        $this->helper = $helper;
        $this->fileSystem = $fileSystem;
    }

    public static function getSubscribedEvents()
    {
        return [
            PluginPostActivateEvent::class => 'dumpBundles',
            PluginPostDeactivateEvent::class => 'dumpBundles'
        ];
    }

    public function dumpBundles(): string
    {
        // Create temporary directory
        $archivePath = $this->kernel->getCacheDir() . '/../../' . $this->assetArtifactDirectory . '.zip';

        // Look for assets
        list($bundles, $checksum) = $this->getExtensions();

        // Zip directory
        $this->createAssetsArchive($archivePath, $bundles);

        return $this->writeToPublicDirectory($archivePath, $checksum);
    }

    private function createAssetsArchive(string $archivePath, array $bundles)
    {
        $zip = new \ZipArchive();
        $zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach($bundles as $bundle)
        {
            $bundleAssetPath = $bundle['path'] . $this->resourcesDirectory;

            if(!is_dir($bundleAssetPath))
            {
                continue;
            }

            /** @var SplFileInfo[] $files */
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($bundleAssetPath));

            foreach($files as $name => $file)
            {

                if(is_dir($name))
                {
                    continue;
                }

                $localPath = $this->helper->convertToDashCase($bundle['name']) . '/' . substr($file->getRealPath(), strlen($bundleAssetPath) + 1);
                $zip->addFile($file->getRealPath(), $localPath);
            }
        }

        if($zip->count() <= 0)
        {
            $zip->addFromString('_placeholder_', '');
        }

        $zip->close();
    }

    private function getExtensions()
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));

        /** @var PluginCollection $plugins */
        $plugins = $this->pluginRepository->search($criteria, Context::createDefaultContext());
        $apps = $this->appRepository->search($criteria, Context::createDefaultContext());

        $kernelProjectDir = $this->kernel->getProjectDir();

        $extensionMetaData = [];

        /** @var $apps iterable<AppEntity> */
        foreach($apps as $app) {
            $extensionMetaData[] = [
                'name' => $app->getName(),
                'path' => implode([$kernelProjectDir, $app->getPath()], DIRECTORY_SEPARATOR)
            ];
        }

        /** @var $apps iterable<PluginEntity> */
        foreach($plugins as $plugin) {
            $extensionMetaData[] = [
                'name' => $plugin->getName(),
                'path' => implode([$kernelProjectDir, $plugin->getPath()], DIRECTORY_SEPARATOR)
            ];
        }

        $checksum = md5(\GuzzleHttp\json_encode($extensionMetaData));

        return [$extensionMetaData, $checksum];
    }

    private function writeToPublicDirectory(string $sourceArchive, string $checksum = null): string
    {
        $this->fileSystem->createDir('pwa');

        $output = $checksum ?? 'pwa_assets';

        $outputPath = 'pwa/' . $output  . '.zip';

        try {
            $this->fileSystem->delete($outputPath);
        } catch (FileNotFoundException $e)
        {
            // Catch gracefully
        }

        $this->fileSystem->writeStream($outputPath, fopen($sourceArchive, 'r'));

        return $outputPath;
    }
}
