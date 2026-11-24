<?php

namespace Fedale\GridviewBundle\Form\Control;

/**
 * A file a `media` control shows as its current one: what the host tells the bundle
 * about a file it stores. The bundle never stores files; it draws this, and gives back
 * the id when the file is kept.
 *
 * A file with a thumbnail is drawn as an image (its preview, or a small thumbnail in
 * a list); any other file with an icon.
 */
final class MediaFile
{
    /**
     * @param string      $id           what the control's data holds for this file while it is kept
     * @param string      $name         the name shown, usually the name it was uploaded under
     * @param int|null    $size         in bytes, shown in decimal units (1 kB = 1000 bytes)
     * @param string|null $url          where the name links to (a download), none without it
     * @param string|null $thumbnailUrl a preview of an image, none for other files
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?int $size = null,
        public readonly ?string $url = null,
        public readonly ?string $thumbnailUrl = null,
    ) {
    }

    /**
     * The size in decimal units, as upload limits are usually counted: "820 B", "14 kB",
     * "2.5 MB". Null without a size.
     */
    public function formattedSize(): ?string
    {
        return match (true) {
            $this->size === null     => null,
            $this->size < 1000       => sprintf('%d B', $this->size),
            $this->size < 1_000_000  => sprintf('%d kB', (int) round($this->size / 1000)),
            default                  => sprintf('%.1f MB', $this->size / 1_000_000),
        };
    }
}
