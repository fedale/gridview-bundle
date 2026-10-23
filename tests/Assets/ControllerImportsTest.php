<?php

namespace Fedale\GridviewBundle\Tests\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Every package the bundle's JavaScript imports statically must be an
 * importmap peer in assets/package.json: an AssetMapper host only maps what
 * is listed there, and an unmapped static import fails the whole Stimulus
 * controller — silently, from the user's point of view.
 *
 * Dynamic import() calls are left out on purpose: they load optional modules
 * (the date picker's locales) and handle their absence.
 */
class ControllerImportsTest extends TestCase
{
    private const ASSETS = __DIR__ . '/../../assets';

    public function testEveryStaticBareImportIsAnImportmapPeer(): void
    {
        $package = json_decode((string) file_get_contents(self::ASSETS . '/package.json'), true, 512, \JSON_THROW_ON_ERROR);
        $peers = array_keys($package['symfony']['importmap'] ?? []);

        $files = [...glob(self::ASSETS . '/*.js'), ...glob(self::ASSETS . '/controllers/*.js')];
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            preg_match_all('/^\s*import\s+(?:[^;]*?\s+from\s+)?[\'"]([^\'"]+)[\'"]/m', (string) file_get_contents($file), $matches);

            foreach ($matches[1] as $specifier) {
                if (str_starts_with($specifier, '.') || str_starts_with($specifier, '/')) {
                    continue;
                }

                $this->assertContains($specifier, $peers, sprintf('%s imports "%s", which assets/package.json does not list in symfony.importmap.', basename($file), $specifier));
            }
        }
    }

    /**
     * A controller declared in assets/package.json but missing on disk breaks the host's
     * importmap build, not this repository's tests.
     */
    public function testEveryDeclaredControllerExists(): void
    {
        $package = json_decode((string) file_get_contents(self::ASSETS . '/package.json'), true, 512, \JSON_THROW_ON_ERROR);
        $controllers = $package['symfony']['controllers'] ?? [];
        $this->assertNotEmpty($controllers);

        foreach ($controllers as $key => $controller) {
            $this->assertFileExists(self::ASSETS . '/' . $controller['main'], sprintf('Controller "%s" points at a missing file.', $key));
            $this->assertSame('gridview-' . $key, $controller['name'], sprintf('Controller "%s" should be named "gridview-%s".', $key, $key));
        }
    }
}
