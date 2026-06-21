<?php

namespace Fedale\GridviewBundle\Form\Control;

use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\CurrencyType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\LanguageType;
use Symfony\Component\Form\Extension\Core\Type\LocaleType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;

/**
 * Maps a column `control.type` to the Symfony FormType class used to render the
 * write-side field. Mirrors {@see \Fedale\GridviewBundle\Filter\Applier\FilterApplierRegistry}
 * but resolves FormType classes (resolved later by the form factory) instead of
 * applier instances.
 *
 * Note the intentional divergence from the filter side: a `relation` control
 * uses EntityType (binding managed entities) whereas a `relation` filter uses a
 * scalar ChoiceType. They are kept as separate entries on purpose.
 */
class ControlTypeRegistry
{
    /**
     * @var array<string, class-string>
     */
    private array $types = [
        // Text-family
        'text'     => TextType::class,
        'html'     => TextareaType::class,
        'email'    => EmailType::class,
        'url'      => UrlType::class,
        'password' => PasswordType::class,
        'color'    => ColorType::class,
        // Numeric-family
        'number'   => NumberType::class,
        'integer'  => IntegerType::class,
        'money'    => MoneyType::class,
        'percent'  => PercentType::class,
        'range'    => RangeType::class,
        // Date & time
        'date'     => DateType::class,
        'datetime' => DateTimeType::class,
        'time'     => TimeType::class,
        // Choice-family. `currency` is the ISO currency-code picker (CurrencyType),
        // intentionally distinct from `money` (MoneyType, an amount) above.
        'boolean'  => CheckboxType::class,
        'choice'   => ChoiceType::class,
        'enum'     => EnumType::class,
        'relation' => EntityType::class,
        'country'  => CountryType::class,
        'language' => LanguageType::class,
        'locale'   => LocaleType::class,
        'timezone' => TimezoneType::class,
        'currency' => CurrencyType::class,
        // Special
        'hidden'   => HiddenType::class,
        'media'    => FileType::class,
    ];

    /** Register or override a control type. */
    public function register(string $type, string $formTypeClass): void
    {
        $this->types[$type] = $formTypeClass;
    }

    /**
     * @return class-string
     */
    public function get(string $type): string
    {
        if (!isset($this->types[$type])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown control type "%s". Known types: %s.',
                $type,
                implode(', ', array_keys($this->types))
            ));
        }

        return $this->types[$type];
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }
}
