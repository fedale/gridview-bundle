<?php

namespace Fedale\GridviewBundle\Tests\Column;

use Fedale\GridviewBundle\Column\ActionColumn;
use Fedale\GridviewBundle\Grid\Gridview;
use PHPUnit\Framework\TestCase;

class ActionColumnTest extends TestCase
{
    private function column(): ActionColumn
    {
        return new ActionColumn($this->createStub(Gridview::class), 'actions');
    }

    private function row(array $data): object
    {
        return new class($data) {
            public function __construct(public array $data)
            {
            }
        };
    }

    /**
     * Without explicit buttons the column renders nothing — the default token
     * layout has no mapped buttons, so there are no dead `href="#"` placeholders.
     */
    public function testRendersEmptyWhenNoButtons(): void
    {
        $out = $this->column()->render($this->row(['id' => 11]), 0);

        $this->assertSame('', $out);
    }

    /** Only tokens present in the layout AND mapped in buttons are rendered, in order. */
    public function testRendersMappedButtonsInLayoutOrder(): void
    {
        $column = $this->column();
        $column->setLayout('{edit} {delete}');
        $column->setButtons([
            'edit'   => fn(array $row) => sprintf('<a class="edit" href="/update/%d">e</a>', $row['id']),
            'delete' => fn(array $row) => sprintf('<a class="delete" href="/delete/%d">d</a>', $row['id']),
            // not in layout → must not appear
            'clone'  => '<a class="clone">c</a>',
        ]);

        $out = $column->render($this->row(['id' => 11]), 0);

        $this->assertStringContainsString('/update/11', $out);
        $this->assertStringContainsString('/delete/11', $out);
        $this->assertStringNotContainsString('clone', $out);
        // edit before delete
        $this->assertLessThan(strpos($out, 'delete'), strpos($out, 'edit'));
    }

    /** A token in the layout with no matching button is skipped (no placeholder). */
    public function testUnmappedLayoutTokenIsSkipped(): void
    {
        $column = $this->column();
        $column->setLayout('{view} {edit}');
        $column->setButtons([
            'edit' => '<a class="edit">e</a>',
        ]);

        $out = $column->render($this->row(['id' => 1]), 0);

        $this->assertStringContainsString('class="edit"', $out);
        $this->assertStringNotContainsString('view', $out);
        $this->assertStringNotContainsString('href="#"', $out);
    }
}
