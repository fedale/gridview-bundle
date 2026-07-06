<?php

namespace Fedale\GridviewBundle\Tests\Serializer;

use Fedale\GridviewBundle\Serializer\AccessorPrefixNameConverter;
use Fedale\GridviewBundle\Serializer\LazyAwareObjectNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Serializer;

class AccessorPrefixNameConverterTest extends TestCase
{
    public function testBooleanGetterKeepsThePropertyName(): void
    {
        $converter = new AccessorPrefixNameConverter();

        $this->assertSame('isVerified', $converter->normalize('verified', PrefixedModel::class));
    }

    public function testUnprefixedPropertyIsUntouched(): void
    {
        $converter = new AccessorPrefixNameConverter();

        $this->assertSame('email', $converter->normalize('email', PrefixedModel::class));
    }

    public function testDerivedNameCollidingWithARealPropertyIsUntouched(): void
    {
        $converter = new AccessorPrefixNameConverter();

        // Both an `isEnabled` and an `enabled` property exist: the real `enabled`
        // key must win, so the derived name is left mapping to nothing.
        $this->assertSame('enabled', $converter->normalize('enabled', CollidingModel::class));
    }

    public function testDenormalizeRestoresTheDerivedName(): void
    {
        $converter = new AccessorPrefixNameConverter();

        $this->assertSame('verified', $converter->denormalize('isVerified', PrefixedModel::class));
    }

    public function testSerializedRowUsesTheDoctrineFieldName(): void
    {
        $normalizer = new LazyAwareObjectNormalizer(null, new AccessorPrefixNameConverter());
        new Serializer([$normalizer]);

        $data = $normalizer->normalize(new PrefixedModel());

        $this->assertArrayHasKey('isVerified', $data, 'Boolean field must keep its property name.');
        $this->assertTrue($data['isVerified']);
        $this->assertArrayNotHasKey('verified', $data);
        $this->assertSame('a@b.test', $data['email']);
    }
}

class PrefixedModel
{
    private bool $isVerified = true;

    private string $email = 'a@b.test';

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function getEmail(): string
    {
        return $this->email;
    }
}

class CollidingModel
{
    private bool $isEnabled = true;

    private bool $enabled = false;

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function getEnabled(): bool
    {
        return $this->enabled;
    }
}
