<?php

namespace Fedale\GridviewBundle\Filter\Criteria;

use Fedale\GridviewBundle\Contract\FilterCriteriaBuilderInterface;
use Fedale\GridviewBundle\DataSource\FilterCriterion;
use Fedale\GridviewBundle\DataSource\FilterOperator;
use Fedale\GridviewBundle\Filter\BlankValueTrait;

/**
 * Text filter: an optional operator prefix ("eq foo", ">= 10", "in a,b"), or
 * the default operator; or, with no prefix, a wildcard typed by the user on
 * an edge of the term ("%foo%", "foo%", "%foo").
 *
 * Options: default_operator (default 'ilike'), trim (default true), wildcard
 * (default '%', '' disables it).
 */
class TextCriteriaBuilder implements FilterCriteriaBuilderInterface
{
    use BlankValueTrait;

    private const OPERATOR_ALIASES = [
        'eq' => 'eq', '==' => 'eq',
        'ieq' => 'ieq', '=' => 'ieq',
        'neq' => 'neq', 'not' => 'neq', '!==' => 'neq', '<>' => 'neq',
        'ineq' => 'ineq', '!=' => 'ineq',
        'gt' => 'gt', '>' => 'gt',
        'gte' => 'gte', '>=' => 'gte',
        'lt' => 'lt', '<' => 'lt',
        'lte' => 'lte', '<=' => 'lte',
        'like' => 'like', '%' => 'like',
        'ilike' => 'ilike',
        'notlike' => 'nlike', 'nlike' => 'nlike', '!%' => 'nlike',
        'notilike' => 'nilike', 'nilike' => 'nilike',
        'startwith' => 'startwith', '-%' => 'startwith',
        'istartwith' => 'istartwith',
        'endwith' => 'endwith', '%-' => 'endwith',
        'in' => 'in',
        'btw' => 'between', 'between' => 'between',
    ];

    /** Normalized operator => [criterion operator, case-insensitive]. */
    private const OPERATORS = [
        'eq' => [FilterOperator::Eq, false],
        'ieq' => [FilterOperator::Eq, true],
        'neq' => [FilterOperator::Neq, false],
        'ineq' => [FilterOperator::Neq, true],
        'gt' => [FilterOperator::Gt, false],
        'gte' => [FilterOperator::Gte, false],
        'lt' => [FilterOperator::Lt, false],
        'lte' => [FilterOperator::Lte, false],
        'like' => [FilterOperator::Contains, false],
        'ilike' => [FilterOperator::Contains, true],
        'nlike' => [FilterOperator::NotContains, false],
        'nilike' => [FilterOperator::NotContains, true],
        'startwith' => [FilterOperator::StartsWith, false],
        'istartwith' => [FilterOperator::StartsWith, true],
        'endwith' => [FilterOperator::EndsWith, false],
    ];

    public function build(string $attribute, mixed $rawValue, array $options = []): array
    {
        if ($this->isBlank($rawValue) || !is_scalar($rawValue)) {
            return [];
        }

        $defaultOperator = $options['default_operator'] ?? 'ilike';
        // Mirror the NG TextFilter 'trim' / 'wildcard' args (defaults: true / '%').
        // 'wildcard' is the char the END USER types; its position drives the match:
        //   "%foo%" → contains, "foo%" → starts-with, "%foo" → ends-with.
        $trim     = $options['trim'] ?? true;
        $wildcard = (string) ($options['wildcard'] ?? '%');

        $value = (string) $rawValue;
        [$operator, $term, $explicit] = $this->parse($trim ? trim($value) : $value, $defaultOperator);

        if ($term === '') {
            return [];
        }

        // Client-driven wildcard: only when the user did NOT type an explicit
        // operator prefix and the term carries the wildcard char on an edge.
        if (!$explicit && $wildcard !== '' && $this->hasEdgeWildcard($term, $wildcard)) {
            return $this->wildcard($attribute, $term, $wildcard);
        }

        if ($operator === 'in') {
            $values = array_values(array_filter(array_map('trim', explode(',', $term)), fn($v) => $v !== ''));

            return $values === [] ? [] : [new FilterCriterion($attribute, FilterOperator::In, $values)];
        }

        if ($operator === 'between') {
            $bounds = preg_split('/\s+and\s+/i', $term);
            if (!is_array($bounds) || count($bounds) !== 2 || trim($bounds[0]) === '' || trim($bounds[1]) === '') {
                // Malformed range: fall back to treating the whole input as a plain term.
                return [new FilterCriterion($attribute, FilterOperator::Contains, $term, true)];
            }

            return [new FilterCriterion($attribute, FilterOperator::Between, [trim($bounds[0]), trim($bounds[1])])];
        }

        [$criterionOperator, $caseInsensitive] = self::OPERATORS[$operator] ?? self::OPERATORS['ilike'];

        return [new FilterCriterion($attribute, $criterionOperator, $term, $caseInsensitive)];
    }

    /**
     * Splits "<operator> <term>" on the first whitespace only, so terms that
     * contain an operator substring (e.g. "sequence") are never mangled.
     *
     * @return array{0: string, 1: string, 2: bool} [operator, term, explicit]
     *                                              'explicit' is true when an operator prefix was actually matched
     */
    private function parse(string $input, string $defaultOperator): array
    {
        $spacePos = strpos($input, ' ');

        if ($spacePos !== false) {
            $token = strtolower(substr($input, 0, $spacePos));

            if (isset(self::OPERATOR_ALIASES[$token])) {
                return [self::OPERATOR_ALIASES[$token], trim(substr($input, $spacePos + 1)), true];
            }
        }

        $normalizedDefault = self::OPERATOR_ALIASES[strtolower($defaultOperator)] ?? 'ilike';

        return [$normalizedDefault, $input, false];
    }

    private function hasEdgeWildcard(string $term, string $wildcard): bool
    {
        return str_starts_with($term, $wildcard) || str_ends_with($term, $wildcard);
    }

    /**
     * Translates the END USER's wildcard position into a case-insensitive
     * match, mirroring the NG TextFilter:
     *   "%foo%" → contains, "foo%" → starts-with, "%foo" → ends-with.
     * The wildcard char(s) are stripped. A term made only of wildcards yields
     * no constraint (matches everything).
     *
     * @return list<FilterCriterion>
     */
    private function wildcard(string $attribute, string $term, string $wildcard): array
    {
        $wlen   = strlen($wildcard);
        $starts = str_starts_with($term, $wildcard);
        $ends   = str_ends_with($term, $wildcard);

        if ($starts && $ends && strlen($term) >= 2 * $wlen) {
            $core     = substr($term, $wlen, strlen($term) - 2 * $wlen);
            $operator = FilterOperator::Contains;
        } elseif ($starts) {
            $core     = substr($term, $wlen);
            $operator = FilterOperator::EndsWith;
        } else { // $ends
            $core     = substr($term, 0, -$wlen);
            $operator = FilterOperator::StartsWith;
        }

        if ($core === '') {
            return [];
        }

        return [new FilterCriterion($attribute, $operator, $core, true)];
    }
}
