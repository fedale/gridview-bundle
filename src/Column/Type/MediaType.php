<?php

namespace Fedale\GridviewBundle\Column\Type;

use Fedale\GridviewBundle\Contract\ColumnInterface;

/**
 * Media — generic binary file (image, pdf, svg, …). Renders the file inline as an
 * <img> when it is an image, otherwise a download link. Replaces the former
 * ImageType.
 *
 * The displayed value is a URL/path to the file (typically supplied by a `value`
 * closure on the column). Options:
 *  - `display`: 'auto' (default, decide by extension/mime), 'image', or 'download';
 *  - `mimeType`: explicit mime used by the 'auto' heuristic when the URL has no
 *    telling extension;
 *  - `imageExtensions`: extensions treated as images in 'auto' mode;
 *  - `alt`, `width`, `height`, `fallback`: image rendering (as the old ImageType);
 *  - `downloadLabel`: link text for non-image files (defaults to the basename).
 *
 * The write side (upload button) is a separate `media` control type mapped to a
 * Symfony FileType; see ControlTypeRegistry and AbstractColumn::buildControl().
 */
class MediaType extends AbstractColumnType
{
    public function getName(): string
    {
        return 'media';
    }

    public function getParent(): ?string
    {
        return null;
    }

    public function getDefaultOptions(): array
    {
        return [
            'display'         => 'auto',
            'alt'             => '',
            'width'           => null,
            'height'          => null,
            'fallback'        => null,
            'downloadLabel'   => null,
            'mimeType'        => null,
            'imageExtensions' => ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'avif'],
        ];
    }

    public function render(mixed $value, array $options, ColumnInterface $column): mixed
    {
        $src = ($value === null || $value === '') ? ($options['fallback'] ?? null) : $value;
        if ($src === null || $src === '') {
            return '';
        }

        return $this->isImage((string) $src, $options)
            ? $this->renderImage((string) $src, $options)
            : $this->renderDownload((string) $value, $options);
    }

    /** The `media` type intentionally has no filter (out of scope). */
    public function inferFilterType(): ?string
    {
        return null;
    }

    public function inferControlType(): ?string
    {
        return 'media';
    }

    /**
     * Whether to render as an inline image. `display` forces the mode; in 'auto'
     * an explicit `mimeType` of image/* or a known image extension on the URL wins.
     */
    private function isImage(string $src, array $options): bool
    {
        $display = $options['display'] ?? 'auto';
        if ($display === 'image') {
            return true;
        }
        if ($display === 'download') {
            return false;
        }

        $mime = $options['mimeType'] ?? null;
        if (\is_string($mime) && $mime !== '') {
            return \str_starts_with($mime, 'image/');
        }

        $extension = \strtolower(\pathinfo(\parse_url($src, \PHP_URL_PATH) ?? $src, \PATHINFO_EXTENSION));

        return $extension !== '' && \in_array($extension, $options['imageExtensions'] ?? [], true);
    }

    private function renderImage(string $src, array $options): mixed
    {
        $attrs = '';
        foreach (['width', 'height'] as $dim) {
            if (isset($options[$dim]) && $options[$dim] !== null && $options[$dim] !== '') {
                $attrs .= sprintf(' %s="%s"', $dim, $this->esc($options[$dim]));
            }
        }

        return $this->markup(sprintf(
            '<img src="%s" class="gv-img" loading="lazy" alt="%s"%s>',
            $this->esc($src),
            $this->esc($options['alt'] ?? ''),
            $attrs
        ));
    }

    private function renderDownload(string $src, array $options): mixed
    {
        $label = $options['downloadLabel'] ?? \basename(\parse_url($src, \PHP_URL_PATH) ?? $src);

        return $this->markup(sprintf(
            '<a href="%s" class="gv-file" download>%s</a>',
            $this->esc($src),
            $this->esc($label)
        ));
    }
}
