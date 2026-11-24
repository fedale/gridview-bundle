<?php

namespace Fedale\GridviewBundle\Tests\Form\Control;

use Fedale\GridviewBundle\Form\Control\ControlBuilder;
use Fedale\GridviewBundle\Form\Control\ControlTypeRegistry;
use Fedale\GridviewBundle\Form\Control\MediaFile;
use Fedale\GridviewBundle\Form\Control\MediaListType;
use Fedale\GridviewBundle\Form\Control\MediaType;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\FormExtension;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Bridge\Twig\Form\TwigRendererEngine;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormRenderer;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Translation\IdentityTranslator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;

/**
 * A media control showing its current file(s): what the host stores, drawn by the bundle,
 * bound back as ids and uploads.
 */
class MediaFilesTest extends TestCase
{
    private ControlBuilder $controls;

    protected function setUp(): void
    {
        $this->controls = new ControlBuilder(new ControlTypeRegistry());
    }

    /** Uploads come as UploadedFile objects, as from a Request. */
    private function factory(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()->addExtension(new HttpFoundationExtension())->getFormFactory();
    }

    /**
     * @param array<string, mixed> $control
     *
     * @return FormInterface<mixed>
     */
    private function form(array $control, mixed $data = null): FormInterface
    {
        $builder = $this->factory()->createNamedBuilder('record', FormType::class, ['manual' => $data]);
        $this->controls->add($builder, 'manual', ['type' => 'media'] + $control, 'Manual');

        return $builder->getForm();
    }

    private function upload(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'gv');
        file_put_contents((string) $path, 'content');

        return new UploadedFile((string) $path, $name, null, null, true);
    }

    private static function manual(): MediaFile
    {
        return new MediaFile('f1', 'manual.pdf', 2_400_000, '/files/f1');
    }

    public function testWithoutCurrentItStaysTheBareUploadInput(): void
    {
        [$type, $options] = $this->controls->field(['type' => 'media', 'accept' => 'image/*']);

        $this->assertSame(FileType::class, $type);
        $this->assertFalse($options['mapped']);
        $this->assertSame(['accept' => 'image/*'], $options['attr']);
    }

    public function testGivenItsCurrentFileItDrawsIt(): void
    {
        [$type, $options] = $this->controls->field(['type' => 'media', 'current' => null, 'accept' => 'application/pdf']);

        $this->assertSame(MediaType::class, $type);
        $this->assertNull($options['current']);
        $this->assertSame('application/pdf', $options['accept']);
        $this->assertArrayNotHasKey('mapped', $options);
    }

    public function testAnUntouchedFileKeepsItsId(): void
    {
        $form = $this->form(['current' => self::manual()], 'f1');

        $form->submit(['manual' => ['upload' => null]]);

        $this->assertSame('f1', $form->get('manual')->getData());
    }

    public function testANewFileReplacesTheCurrentOne(): void
    {
        $form = $this->form(['current' => self::manual()], 'f1');
        $upload = $this->upload('manual-v2.pdf');

        $form->submit(['manual' => ['upload' => $upload]]);

        $this->assertSame($upload, $form->get('manual')->getData());
    }

    public function testAnOptionalFileMayBeRemoved(): void
    {
        $form = $this->form(['current' => self::manual()], 'f1');

        $form->submit(['manual' => ['remove' => '1']]);

        $this->assertNull($form->get('manual')->getData());
    }

    public function testARequiredFileMayOnlyBeReplaced(): void
    {
        $form = $this->form(['current' => self::manual(), 'required' => true, 'validate' => false], 'f1');

        $this->assertFalse($form->get('manual')->has('remove'));
        // Something to keep: the input itself is optional.
        $this->assertFalse($form->get('manual')->get('upload')->getConfig()->getOption('required'));
    }

    public function testTheListKeepsItsFilesInTheOrderSentThenAddsTheNewOnes(): void
    {
        $current = [new MediaFile('a', 'a.jpg'), new MediaFile('b', 'b.jpg'), new MediaFile('c', 'c.jpg')];
        $form = $this->form(['multiple' => true, 'current' => $current], ['a', 'b', 'c']);
        $upload = $this->upload('d.jpg');

        // "x" is somebody else's file: not among the current ones, so not taken.
        $form->submit(['manual' => ['kept' => 'c,x,a', 'upload' => [$upload]]]);

        $this->assertSame(['c', 'a', $upload], $form->get('manual')->getData());
    }

    public function testEachNewUploadGoesToTheUploadCallable(): void
    {
        $received = [];
        $builder = $this->factory()->createNamedBuilder('record', FormType::class, []);
        $this->controls->add($builder, 'photos', [
            'type'   => 'media', 'multiple' => true, 'current' => [new MediaFile('a', 'a.jpg')],
            'upload' => static function (UploadedFile $file) use (&$received): void {
                $received[] = $file->getClientOriginalName();
            },
        ]);
        $form = $builder->getForm();

        $form->submit(['photos' => ['kept' => 'a', 'upload' => [$this->upload('b.jpg'), $this->upload('c.jpg')]]]);

        $this->assertSame(['b.jpg', 'c.jpg'], $received);
    }

    public function testAListIsAMediaListType(): void
    {
        $this->assertSame(MediaListType::class, $this->controls->field(['type' => 'media', 'multiple' => true])[0]);
    }

    public function testTheCurrentFileMustBeAMediaFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The "current" of a "media" control must be a');

        $this->controls->field(['type' => 'media', 'current' => 'f1']);
    }

    public function testTheSizeIsInDecimalUnits(): void
    {
        $this->assertSame('820 B', (new MediaFile('f', 'f', 820))->formattedSize());
        $this->assertSame('14 kB', (new MediaFile('f', 'f', 14_200))->formattedSize());
        $this->assertSame('2.4 MB', self::manual()->formattedSize());
        $this->assertNull((new MediaFile('f', 'f'))->formattedSize());
    }

    public function testTheThemeDrawsTheCurrentFileAndItsRemoval(): void
    {
        $html = $this->render($this->form(['current' => new MediaFile('f1', 'photo.jpg', 14_200, '/files/f1', '/thumbs/f1')], 'f1'));

        $this->assertStringContainsString('<div id="record_manual" class="gv-media">', $html);
        $this->assertStringContainsString('<img class="gv-media__preview" src="/thumbs/f1" alt="" loading="lazy">', $html);
        $this->assertStringContainsString('<a class="gv-media__name" href="/files/f1">photo.jpg</a><span class="gv-media__size">14 kB</span>', $html);
        $this->assertStringContainsString('media.remove_file', $html);
        $this->assertStringContainsString('<label for="record_manual_upload" class="gv-form-label">Manual</label>', $html);
    }

    public function testTheThemeDrawsTheListForItsController(): void
    {
        $html = $this->render($this->form(['multiple' => true, 'current' => [new MediaFile('a', 'a.pdf')]], ['a']));

        $this->assertStringContainsString('class="gv-media-list" data-controller="gridview-media-list"', $html);
        $this->assertStringContainsString('<li class="gv-media-list__item" data-gridview-media-list-target="item" data-id="a">', $html);
        $this->assertStringContainsString('<span class="gv-media-list__name">a.pdf</span>', $html);
        $this->assertStringContainsString('data-gridview-media-list-target="kept"', $html);
        $this->assertStringContainsString('value="a"', $html);
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function render(FormInterface $form): string
    {
        $bridge = \dirname((string) (new \ReflectionClass(FormExtension::class))->getFileName(), 2) . '/Resources/views/Form';
        $twig = new Environment(new FilesystemLoader([\dirname(__DIR__, 3) . '/templates/form', $bridge]));
        $engine = new TwigRendererEngine(['gv_form_theme.html.twig'], $twig);
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([FormRenderer::class => static fn() => new FormRenderer($engine)]));
        $twig->addExtension(new FormExtension());
        $twig->addExtension(new TranslationExtension(new IdentityTranslator()));

        return $twig->createTemplate('{{ form_row(form.manual) }}')->render(['form' => $form->createView()]);
    }
}
