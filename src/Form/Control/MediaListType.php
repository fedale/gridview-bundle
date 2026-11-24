<?php

namespace Fedale\GridviewBundle\Form\Control;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A `media` control holding several files: the current files in their order, each with
 * "Remove" and buttons to move it up or down (the gridview-media-list controller), and
 * one input adding files at the end of the list.
 *
 * The controller writes the ids kept, in order, into a hidden input; without JavaScript
 * it holds every current file, unchanged. The data is the ids kept, then the
 * UploadedFile of each new file. Only the ids of the `current` option are taken from the
 * hidden input, so a form cannot attach somebody else's file.
 */
final class MediaListType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<MediaFile> $current */
        $current = $options['current'];
        $currentIds = array_map(static fn(MediaFile $file): string => $file->id, $current);
        $builder->add('kept', HiddenType::class);
        $builder->add('upload', FileType::class, [
            'label'    => false,
            'multiple' => true,
            // Required only while there is nothing to keep.
            'required' => $options['required'] && $current === [],
            'attr'     => array_filter(['accept' => $options['accept']]),
        ]);

        $builder->addModelTransformer(new CallbackTransformer(
            static fn(): array => ['kept' => implode(',', $currentIds), 'upload' => []],
            static function (mixed $data) use ($currentIds): array {
                $kept = \is_array($data) && \is_string($data['kept'] ?? null) ? explode(',', $data['kept']) : [];
                $files = array_values(array_unique(array_intersect($kept, $currentIds)));
                $uploads = \is_array($data) && \is_array($data['upload'] ?? null) ? $data['upload'] : [];
                foreach ($uploads as $upload) {
                    if ($upload instanceof UploadedFile) {
                        $files[] = $upload;
                    }
                }

                return $files;
            },
        ));
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $attr = \is_array($view->vars['attr'] ?? null) ? $view->vars['attr'] : [];
        $view->vars['attr'] = [
            'class'           => trim((\is_string($attr['class'] ?? null) ? $attr['class'] : '') . ' gv-media-list'),
            'data-controller' => trim('gridview-media-list ' . (\is_string($attr['data-controller'] ?? null) ? $attr['data-controller'] : '')),
        ] + $attr;
        $view->vars['current_files'] = $options['current'];
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
            'current'        => [],
            'accept'         => '',
            // The errors of the files (type, size, count) stay on the field.
            'error_bubbling' => false,
        ]);
        $resolver->setAllowedTypes('current', MediaFile::class . '[]');
        $resolver->setAllowedTypes('accept', 'string');
    }

    public function getBlockPrefix(): string
    {
        return 'gv_media_list';
    }
}
