<?php 
namespace Fedale\GridviewBundle\Column;


class SerialColumn extends AbstractColumn {

    // public function __construct(
    //     private Gridview $gridview,
    //     private ?string $attribute, 
    //     private string $format, 
    //     private ?string $label,
    //     private ?array $options = []
    // ) {
    //     $this->initContent();
    // }

    // private function initContent()
    // {
    //     $this->content = '1';
    // }

    public function render(mixed $_model, int $index): mixed
    {
        return $index + 1;
    }
}