<?php

namespace Fedale\GridviewBundle\Tests\Form\Control;

use Fedale\GridviewBundle\Column\DataColumn;
use Fedale\GridviewBundle\Form\Control\ControlResolver;
use Fedale\GridviewBundle\Form\Control\ControlTypeRegistry;
use Fedale\GridviewBundle\Grid\Gridview;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * The `media` control is virtual (mapped=false): phase 1 (receiving the upload)
 * lives in the bundle, phase 2 (storing + populating the entity) is delegated to
 * the host app's `upload` callable, invoked on POST_SUBMIT.
 */
class MediaControlTest extends TestCase
{
    private function mediaColumn(?callable $upload): DataColumn
    {
        $column  = new DataColumn($this->createStub(Gridview::class), 'upload');
        $control = ['type' => 'media'];
        if ($upload !== null) {
            $control['upload'] = $upload;
        }
        $column->setControl((new ControlResolver())->resolve($control, 'media'));

        return $column;
    }

    /** Form factory wired like the real app: HttpFoundation handles file uploads. */
    private function builder(object $entity): FormBuilderInterface
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->getFormFactory();

        return $factory->createNamedBuilder('gridform', FormType::class, $entity, ['data_class' => $entity::class]);
    }

    /** POST request carrying $file as gridform[upload] (or no file when null). */
    private function uploadRequest(?UploadedFile $file): Request
    {
        $files = $file !== null ? ['gridform' => ['upload' => $file]] : [];

        return Request::create('/', 'POST', [], [], $files);
    }

    public function testMediaFieldIsUnmapped(): void
    {
        $entity  = new class {
            public ?string $stored = null;
        };
        $builder = $this->builder($entity);

        $this->mediaColumn(null)->buildControl($builder, new ControlTypeRegistry());
        $form = $builder->getForm();

        $this->assertTrue($form->has('upload'));
        $this->assertFalse($form->get('upload')->getConfig()->getMapped());
    }

    public function testUploadCallbackReceivesFileAndEntity(): void
    {
        $entity  = new class {
            public ?string $stored = null;
        };
        $builder = $this->builder($entity);

        $received = null;
        $column   = $this->mediaColumn(function (UploadedFile $file, object $e) use (&$received): void {
            $received = $file;
            $e->stored = $file->getClientOriginalName();
        });
        $column->buildControl($builder, new ControlTypeRegistry());

        $tmp = tempnam(sys_get_temp_dir(), 'gv_media_');
        file_put_contents($tmp, '%PDF-1.4');
        $uploaded = new UploadedFile($tmp, 'manual.pdf', 'application/pdf', null, true);

        $form = $builder->getForm();
        $form->handleRequest($this->uploadRequest($uploaded));

        $this->assertTrue($form->isValid());
        $this->assertInstanceOf(UploadedFile::class, $received);
        $this->assertSame('manual.pdf', $entity->stored);

        @unlink($tmp);
    }

    public function testNoUploadKeepsEntityUntouched(): void
    {
        $entity  = new class {
            public ?string $stored = 'unchanged';
        };
        $builder = $this->builder($entity);

        $called = false;
        $column = $this->mediaColumn(function () use (&$called): void {
            $called = true;
        });
        $column->buildControl($builder, new ControlTypeRegistry());

        $form = $builder->getForm();
        $form->handleRequest($this->uploadRequest(null));

        $this->assertFalse($called, 'upload callback must not run when no file is submitted');
        $this->assertSame('unchanged', $entity->stored);
    }
}
