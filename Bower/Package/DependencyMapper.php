<?php

/*
 * This file is part of the SpBowerBundle package.
 *
 * (c) Martin Parsiegla <martin.parsiegla@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sp\BowerBundle\Bower\Package;

use Doctrine\Common\Collections\ArrayCollection;
use Sp\BowerBundle\Bower\ConfigurationInterface;
use Sp\BowerBundle\Bower\Exception\FileNotFoundException;

/**
 * @author Martin Parsiegla <martin.parsiegla@gmail.com>
 */
class DependencyMapper implements DependencyMapperInterface
{
    const SCRIPTS_TYPE = 'scripts';
    const STYLES_TYPE = 'styles';
    const IMAGES_TYPE = 'images';

    const DEPENDENCIES_KEY = 'dependencies';

    /**
     * @var string KernelRootDir
     */
    private $kernelRootDir;

    /**
     * @var array
     */
    private $requiredExtensions = array('js', 'css');

    /**
     * @var array
     */
    private $fileMapping = array(
        self::SCRIPTS_TYPE => array(
            'keys' => array(
                'main',
                'script',
                'scripts'
            ),
            'extensions' => array('js')
        ),
        self::STYLES_TYPE => array(
            'keys' => array(
                'main',
                'styles',
                'stylesheets'
            ),
            'extensions' => array('css'),
        ),
        self::IMAGES_TYPE => array(
            'keys' => array(
                'main'
            ),
            'extensions' => array('png', 'gif', 'jpg', 'jpeg', 'bmp')
        ),
    );

    /**
     * @var \Sp\BowerBundle\Bower\Configuration
     */
    private $config;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $packages;

    /**
     * @param $kernelRootDir
     */
    public function __construct($kernelRootDir)
    {
        $this->packages = new ArrayCollection();
        $this->kernelRootDir = $kernelRootDir;

        if (empty($kernelRootDir)) {
            throw new \RuntimeException('Kernel directory cannot be empty');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function map(array $packagesInfo, ConfigurationInterface $config)
    {
        $packagesDependencyInfo = [];

        $search = function ($packageInfo) use (&$packagesDependencyInfo, &$search) {
            foreach ($packageInfo['dependencies'] as $name => $dependency) {
                $search($dependency);
                $packagesDependencyInfo[$name] = $dependency;
            }
        };

        $search($packagesInfo);
        $packagesDependencyInfo[$packagesInfo['endpoint']['name']] = $packagesInfo;

        foreach ($packagesDependencyInfo as $name => $packageInfo) {
            $this->mapPackage($packageInfo, $config);
        }

        return new ArrayCollection(array_reverse($this->packages->toArray()));
    }

    /**
     * @see parent::map
     *
     * @param array $packagesInfo
     * @param ConfigurationInterface $config
     * @return ArrayCollection|\Doctrine\Common\Collections\Collection3
     */
    private function mapPackage(array $packagesInfo, ConfigurationInterface $config)
    {
        $this->config = $config;
        $packagesInfo = $this->orderPackages($packagesInfo);

        $packageName = $packagesInfo['endpoint']['name'];

        if ($this->packages->contains($packageName)) {
            return $this->packages;
        }

        $package = $this->createPackage($packageName, $packagesInfo);
        $this->packages->set($packageName, $package);

        foreach ($packagesInfo[self::DEPENDENCIES_KEY] as $packageName => $packageInfo) {
            $package = $this->createPackage($packageName, $packageInfo);
            $this->packages->set($packageName, $package);
        }

        return $this->packages;
    }

    /**
     * @param string $name
     * @param array  $packageInfo
     *
     * @return \Sp\BowerBundle\Bower\Package\Package
     */
    private function createPackage($name, array $packageInfo)
    {
        $package = new Package($name);
        $package->addScripts($this->getFiles($packageInfo, self::SCRIPTS_TYPE));
        $package->addStyles($this->getFiles($packageInfo, self::STYLES_TYPE));
        $package->addImages($this->getFiles($packageInfo, self::IMAGES_TYPE));

        $this->resolveDependencies($package, $packageInfo);

        return $package;
    }

    /**
     * @param array  $packageInfo
     * @param string $type
     *
     * @return array
     */
    private function getFiles(array $packageInfo, $type)
    {
        $files = array();
        foreach ($this->fileMapping[$type]['keys'] as $key) {
            if (isset($packageInfo['pkgMeta'][$key])) {
                $extractedFiles = $this->extractFiles($packageInfo['canonicalDir'], $packageInfo['pkgMeta'][$key], $this->fileMapping[$type]['extensions']);
                $files = array_merge($files, $extractedFiles);
            }
        }

        return $files;
    }

    /**
     * @param string       $canonicalDir
     * @param string|array $files
     * @param array        $extensions
     *
     * @return array
     */
    private function extractFiles($canonicalDir, $files, array $extensions)
    {
        $appDir = realpath($this->kernelRootDir . '/../');
        $canonicalDir = $appDir . '/' . $canonicalDir;

        if (!is_array($files)) {
            $files = array($files);
        }

        $matchedFiles = array();
        foreach ($files as $file) {
            if (in_array(pathinfo($file, PATHINFO_EXTENSION), $extensions)) {
                $matchedFiles[] = $this->resolvePath($canonicalDir . DIRECTORY_SEPARATOR . $file);
            }
        }

        return $matchedFiles;
    }

    /**
     * @param string $file
     *
     * @return string
     * @throws FileNotFoundException
     */
    private function resolvePath($file)
    {
        $resetDir = getcwd();
        chdir($this->config->getDirectory());
        if (strpos($file, '@') === 0) {
            return $file;
        }

        $extension = pathinfo($file, PATHINFO_EXTENSION);
        if (!file_exists($file) && in_array($extension, $this->requiredExtensions)) {
            throw new FileNotFoundException(
                sprintf('The required file "%s" could not be found. Did you accidentally deleted the "components" directory?', $file)
            );
        }

        $path = realpath($file) ? : "";

        chdir($resetDir);

        return $path;
    }

    /**
     * @param Package $package
     * @param array   $packageInfo
     */
    private function resolveDependencies(Package $package, array $packageInfo)
    {
        if (!isset($packageInfo[self::DEPENDENCIES_KEY])) {
            return;
        }

        foreach ($packageInfo[self::DEPENDENCIES_KEY] as $dependencyName => $dependencyInfo) {
            $dependencyPackage = $this->packages->get($dependencyName);
            if (null !== $dependencyPackage) {
                $package->addDependency($dependencyPackage);
            } else {
                throw new \RuntimeException("Dependency {$dependencyName} not found.");
            }
        }
    }

    /**
     * Orders the packages by the number of dependencies they have.
     *
     * @param array $packagesInfo
     *
     * @return array
     */
    private function orderPackages(array $packagesInfo)
    {
        $dependenciesKey = self::DEPENDENCIES_KEY;
        uasort($packagesInfo[self::DEPENDENCIES_KEY], function($first, $second) use($dependenciesKey) {
            $firstCount = isset($first[$dependenciesKey]) ? count($first[$dependenciesKey]) : 0;
            $secondCount = isset($second[$dependenciesKey]) ? count($second[$dependenciesKey]) : 0;

            if ($firstCount > $secondCount) {
                return 1;
            } else if ($firstCount < $secondCount) {
                return -1;
            }

            return 0;
        });

        return $packagesInfo;
    }
}
