<?php

namespace Fedale\GridviewBundle\Tests\Form\Control;

use Fedale\GridviewBundle\Column\DataColumn;
use Fedale\GridviewBundle\Form\Control\ControlResolver;
use Fedale\GridviewBundle\Form\Control\ControlTypeRegistry;
use Fedale\GridviewBundle\Grid\Gridview;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Forms;

/**
 * The whole point of mapping a control to a native FormType is inheriting its
 * data transformer "for free": the view value round-trips back into the entity
 * as the right PHP type on submit, with no custom code on our side.
 */
class ControlTransformerTest extends TestCase
{
    private function builder(object $entity): FormBuilderInterface
    {
        return Forms::createFormFactoryBuilder()
            ->getFormFactory()
            ->createNamedBuilder('gridform', FormType::class, $entity, ['data_class' => $entity::class]);
    }

    private function column(string $attribute, array $control, string $dataType): DataColumn
    {
        $column = new DataColumn($this->createStub(Gridview::class), $attribute);
        $column->setControl((new ControlResolver())->resolve($control, $dataType));

        return $column;
    }

    public function testEnumControlBindsEnumInstance(): void
    {
        $entity  = new class {
            public ?Priority $priority = null;
        };
        $builder = $this->builder($entity);

        $this->column('priority', ['type' => 'enum', 'options' => ['class' => Priority::class]], 'enum')
            ->buildControl($builder, new ControlTypeRegistry());

        $form = $builder->getForm();
        $form->submit(['priority' => 'high']);

        $this->assertTrue($form->isSynchronized());
        $this->assertSame(Priority::High, $entity->priority, 'enum control must bind the enum case, not a string');
    }

    public function testEmailControlRendersAsEmailInput(): void
    {
        $entity  = new class {
            public ?string $email = null;
        };
        $builder = $this->builder($entity);

        $this->column('email', ['type' => 'email'], 'email')
            ->buildControl($builder, new ControlTypeRegistry());

        $form = $builder->getForm();
        $view = $form->createView();

        // EmailType in the block-prefix chain → the form theme renders <input type="email">.
        $this->assertContains('email', $view->children['email']->vars['block_prefixes']);

        $form->submit(['email' => 'a@b.com']);
        $this->assertSame('a@b.com', $entity->email);
    }
}
