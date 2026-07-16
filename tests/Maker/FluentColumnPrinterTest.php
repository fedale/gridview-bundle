<?php

namespace Fedale\GridviewBundle\Tests\Maker;

use Fedale\GridviewBundle\Maker\Util\FluentColumnPrinter;
use Fedale\GridviewBundle\Maker\Util\RawPhp;
use PHPUnit\Framework\TestCase;

class FluentColumnPrinterTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function plans(): array
    {
        return [
            [
                'attribute' => 'title', 'label' => 'Title', 'type' => 'text', 'sortable' => true,
                'filter' => ['type' => 'text'], 'control' => ['type' => 'text', 'required' => true], 'value' => null,
            ],
            [
                'attribute' => 'amount', 'label' => 'Amount', 'type' => 'number', 'sortable' => true,
                'filter' => ['type' => 'number'], 'control' => ['type' => 'number', 'required' => false], 'value' => null,
            ],
            [
                'attribute' => 'status', 'label' => 'Status', 'type' => 'select', 'sortable' => true,
                'filter' => ['type' => 'choice'],
                'control' => ['type' => 'enum', 'required' => true, 'options' => ['class' => 'App\\Enum\\PostStatus']],
                'value' => null,
            ],
            [
                'attribute' => 'author', 'label' => 'Author', 'type' => null, 'sortable' => false,
                'filter' => ['type' => 'relation'],
                'control' => ['type' => 'relation', 'required' => false, 'options' => ['class' => new RawPhp('\\App\\Entity\\User::class')]],
                'value' => new RawPhp("fn(array \$data): mixed => \$data['author']['id'] ?? null"),
            ],
        ];
    }

    public function testEmitsFluentChains(): void
    {
        $printed = (new FluentColumnPrinter())->print($this->plans(), true);
        $code = $printed['code'];

        $this->assertStringContainsString('CheckboxColumn::new(),', $code);
        $this->assertStringContainsString("TextColumn::new('title')->label('Title')->sortable()->filterText()->required(),", $code);
        $this->assertStringContainsString("NumberColumn::new('amount')->label('Amount')->sortable()->filterNumber()->control(true),", $code);
        $this->assertStringContainsString("SelectColumn::new('status')->label('Status')->sortable()->enum(\\App\\Enum\\PostStatus::class, required: true),", $code);
        $this->assertStringContainsString("RelationColumn::new('author')->label('Author')->relation(\\App\\Entity\\User::class),", $code);
        $this->assertStringContainsString('ActionColumn::new()->label(false),', $code);
    }

    public function testCollectsSortedImports(): void
    {
        $printed = (new FluentColumnPrinter())->print($this->plans(), true);

        $this->assertSame([
            'Fedale\\GridviewBundle\\Column\\Config\\ActionColumn',
            'Fedale\\GridviewBundle\\Column\\Config\\CheckboxColumn',
            'Fedale\\GridviewBundle\\Column\\Config\\NumberColumn',
            'Fedale\\GridviewBundle\\Column\\Config\\RelationColumn',
            'Fedale\\GridviewBundle\\Column\\Config\\SelectColumn',
            'Fedale\\GridviewBundle\\Column\\Config\\TextColumn',
        ], $printed['imports']);
    }

    public function testGeneratedCodeIsValidPhpExpression(): void
    {
        $printed = (new FluentColumnPrinter())->print($this->plans(), false);

        $this->assertSame(0, $this->lintExpression($printed['code']));
    }

    private function lintExpression(string $code): int
    {
        $php = "<?php\n\$columns = {$code};\n";
        $tmp = tempnam(sys_get_temp_dir(), 'fluent_') . '.php';
        file_put_contents($tmp, $php);
        exec(\sprintf('php -l %s 2>&1', escapeshellarg($tmp)), $out, $exit);
        unlink($tmp);

        return $exit;
    }
}
