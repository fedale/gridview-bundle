<?php

namespace Fedale\GridviewBundle\Form\Control;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A `media` control with its current file: the file shown (a preview for an image), an
 * input to upload another one, and "Remove" when the field may be empty.
 *
 * Its data is the UploadedFile of a new file, the id of the current file when nothing
 * changes, null once removed. The current id comes from the `current` option, never
 * from the request, so a form cannot point the data at somebody else's file.
 */
final class MediaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var MediaFile|null $current */
        $current = $options['current'];
        $builder->add('upload', FileType::class, [
            'label'    => false,
            // Required only while there is nothing to keep.
            'required' => $options['required'] && $current === null,
            'attr'     => array_filter(['accept' => $options['accept']]),
        ]);
        // A required file may be replaced, never removed.
        if ($current !== null && !$options['required']) {
            $builder->add('remove', CheckboxType::class, [
                'label'              => 'media.remove_file',
                'translation_domain' => 'GridviewBundle',
                'required'           => false,
            ]);
        }

        $builder->addModelTransformer(new CallbackTransformer(
            static fn(): array => ['upload' => null, 'remove' => false],
            static function (mixed $data) use ($current): UploadedFile|string|null {
                $upload = \is_array($data) ? ($data['upload'] ?? null) : null;
                if ($upload instanceof UploadedFile) {
                    return $upload;
                }

                return \is_array($data) && ($data['remove'] ?? false) === true ? null : $current?->id;
            },
        ));
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $attr = \is_array($view->vars['attr'] ?? null) ? $view->vars['attr'] : [];
        $view->vars['attr'] = ['class' => trim((\is_string($attr['class'] ?? null) ? $attr['class'] : '') . ' gv-media')] + $attr;
        $view->vars['current_file'] = $options['current'];
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        // The label of the field names the file input, as it would for a simple control.
        $labelAttr = \is_array($view->vars['label_attr'] ?? null) ? $view->vars['label_attr'] : [];
        $view->vars['label_attr'] = ['for' => $view['upload']->vars['id']] + $labelAttr;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'current'        => null,
            'accept'         => '',
            // The errors of the file (type, size) stay on the field, not on the whole form.
            'error_bubbling' => false,
        ]);
        $resolver->setAllowedTypes('current', ['null', MediaFile::class]);
        $resolver->setAllowedTypes('accept', 'string');
    }

    public function getBlockPrefix(): string
    {
        return 'gv_media';
    }
}
