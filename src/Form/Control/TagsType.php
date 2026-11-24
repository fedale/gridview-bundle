<?php

namespace Fedale\GridviewBundle\Form\Control;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The `tags` control: a short list of free text labels. Its data is a list of strings.
 *
 * The input itself is a text field of labels separated by commas, so the form works without
 * JavaScript and in tests; the gridview-tags controller draws it as chips, with an input
 * adding them and the suggestions. A label therefore holds no comma.
 *
 * Labels are kept as typed and compared case-insensitively: "Urgent" and "urgent" are one
 * label, the first spelling wins. A label matching a suggestion takes its spelling.
 */
final class TagsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $suggestions */
        $suggestions = $options['suggestions'];
        $builder->addModelTransformer(new CallbackTransformer(
            static fn(mixed $tags): string => implode(', ', \is_array($tags) ? array_filter($tags, \is_string(...)) : []),
            static fn(mixed $text): array => self::parse(\is_string($text) ? $text : '', $suggestions),
        ));
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $attr = \is_array($view->vars['attr'] ?? null) ? $view->vars['attr'] : [];
        $controllers = \is_string($attr['data-controller'] ?? null) ? $attr['data-controller'] : '';
        $view->vars['attr'] = array_filter([
            'data-gridview-tags-suggestions-value' => json_encode(array_values($options['suggestions']), \JSON_THROW_ON_ERROR),
            'data-gridview-tags-restrict-value'    => $options['restrict'] ? 'true' : null,
            'data-gridview-tags-max-value'         => $options['max'],
            'data-gridview-tags-max-length-value'  => $options['max_length'],
            'data-gridview-tags-url-value'         => $options['suggestions_url'],
        ], static fn(mixed $value): bool => $value !== null) + $attr;
        $view->vars['attr']['data-controller'] = trim($controllers . ' gridview-tags');
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                // Labels the input suggests, in this order.
                'suggestions'     => [],
                // Only the suggestions are accepted (in the page; on the server, a Choice
                // constraint when the control validates).
                'restrict'        => false,
                // The most labels, and the longest one; null: no limit.
                'max'             => null,
                'max_length'      => null,
                // A URL suggesting more labels: `?query=<typed text>` in, a JSON list of
                // strings out.
                'suggestions_url' => null,
            ])
            ->setAllowedTypes('suggestions', 'string[]')
            ->setAllowedTypes('restrict', 'bool')
            ->setAllowedTypes('max', ['null', 'int'])
            ->setAllowedTypes('max_length', ['null', 'int'])
            ->setAllowedTypes('suggestions_url', ['null', 'string']);
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'gv_tags';
    }

    /**
     * The labels of a comma-separated text: trimmed, inner whitespace collapsed, empty ones
     * dropped, case-insensitive duplicates removed (the first spelling wins), in their order.
     * A label matching a suggestion case-insensitively takes the suggestion's spelling.
     *
     * @param list<string> $suggestions
     *
     * @return list<string>
     */
    public static function parse(string $text, array $suggestions = []): array
    {
        $spellings = [];
        foreach ($suggestions as $suggestion) {
            $spellings[mb_strtolower($suggestion)] ??= $suggestion;
        }
        $tags = [];
        foreach (explode(',', $text) as $label) {
            $label = trim((string) preg_replace('/\s+/u', ' ', $label));
            if ($label === '') {
                continue;
            }
            $key = mb_strtolower($label);
            $tags[$key] ??= $spellings[$key] ?? $label;
        }

        return array_values($tags);
    }
}
