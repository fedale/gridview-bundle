<?php

namespace Fedale\GridviewBundle\Form\Control;

use Symfony\Component\Form\ChoiceList\ArrayChoiceList;
use Symfony\Component\Form\ChoiceList\ChoiceListInterface;
use Symfony\Component\Form\ChoiceList\Loader\ChoiceLoaderInterface;

/**
 * The choices of a remote `relation` control: scalar ids, none of them known in
 * advance. The browser searches the rest at the control's URL, so the list holds
 * only the ids the form has met, the current ones when it is drawn and the submitted
 * ones when it is bound, each looked up once through the host's `labels` callable.
 *
 * An id the callable gives no label for is not a choice: a submitted id that names no
 * record makes the field invalid, as a choice outside the list does.
 *
 * One instance per field and per form: it remembers what it has looked up, so it must
 * never be shared across forms or requests.
 */
final class RemoteChoiceLoader implements ChoiceLoaderInterface
{
    /** @var array<string, string> id => label, in the order the ids were met */
    private array $labels = [];

    /** @var array<string, true> the ids already looked up, found or not */
    private array $lookedUp = [];

    /**
     * @param \Closure(list<string>): array<array-key, string> $lookup the labels of the ids it is given, keyed by id
     */
    public function __construct(private readonly \Closure $lookup)
    {
    }

    public function loadChoiceList(?callable $value = null): ChoiceListInterface
    {
        return new ArrayChoiceList(array_map('strval', array_keys($this->labels)), $value);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, string>
     */
    public function loadChoicesForValues(array $values, ?callable $value = null): array
    {
        return $this->known($values);
    }

    /**
     * @param array<array-key, mixed> $choices
     *
     * @return array<array-key, string>
     */
    public function loadValuesForChoices(array $choices, ?callable $value = null): array
    {
        return $this->known($choices);
    }

    /**
     * The label of an id met so far, the id itself otherwise.
     */
    public function label(string $id): string
    {
        return $this->labels[$id] ?? $id;
    }

    /**
     * The ids among the given ones that name a record, keys kept (the choice and its
     * value are the same string), looking up those not met yet.
     *
     * @param array<array-key, mixed> $ids
     *
     * @return array<array-key, string>
     */
    private function known(array $ids): array
    {
        $ids = array_filter(
            array_map(static fn(mixed $id): ?string => \is_string($id) || \is_int($id) ? (string) $id : null, $ids),
            static fn(?string $id): bool => $id !== null && $id !== '',
        );
        $missing = array_values(array_unique(array_filter($ids, fn(string $id): bool => !isset($this->lookedUp[$id]))));
        if ($missing !== []) {
            $found = ($this->lookup)($missing);
            // In the order of the ids, not of the callable's answer: a multiple
            // relation lists its records in the order they were linked.
            foreach ($missing as $id) {
                $this->lookedUp[$id] = true;
                if (isset($found[$id])) {
                    $this->labels[$id] = (string) $found[$id];
                }
            }
        }

        return array_filter($ids, fn(string $id): bool => isset($this->labels[$id]));
    }
}
