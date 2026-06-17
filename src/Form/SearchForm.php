<?php

namespace Fedale\GridviewBundle\Form;

use Fedale\GridviewBundle\Contract\SearchFormInterface;
use Fedale\GridviewBundle\Filter\Applier\FilterApplierRegistry;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Form\FormFactoryInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpFoundation\RequestStack;


class SearchForm implements SearchFormInterface
{

    private Form $modelType;

    private Criteria $criteria;

    private ArrayCollection $filters;

    public function __construct(
        private FormFactoryInterface $formFactory,
        private RequestStack $requestStack,
        private ?FilterApplierRegistry $applierRegistry = null
    ) {
        $this->filters = new ArrayCollection();

        // name, method, action and so on must be passed as argument!
        $formBuilder = $this->formFactory->createNamedBuilder(
            'myform',
            FormType::class,
            null,
            [
                'method' => 'get',
                'action' => '',
                'required' => false
            ]
        );
        $this->modelType = $formBuilder->getForm();
        $this->modelType->add('save', SubmitType::class, ['label' => 'Filter', 'attr' => ['class' => 'gv-btn gv-btn-primary']]);
    }

    public function getFilters()
    {
        return $this->filters;
    }

    public function addGlobalSearch(): void
    {
        if (!$this->modelType->has('_q')) {
            $this->modelType->add('_q', \Symfony\Component\Form\Extension\Core\Type\TextType::class, [
                'required' => false,
                'label'    => false,
            ]);
        }
    }

    public function addFilter(string $name, string $type, array $options)
    {
        $name = str_replace('.', '_', $name);
        $class = "Fedale\\GridviewBundle\\Filter\\Filter" . ucfirst($type) . 'Type';
        $this->modelType->add($name, $class, $options);
    }

    /**
     * Applies a set of filter params to the QueryBuilder, delegating the
     * per-type logic (date ranges, boolean cast, IN, ...) to the registered
     * filter appliers. Blank values are skipped silently.
     *
     * Map keys are the submitted param keys (column attributes with dots
     * replaced by underscores, as produced by addFilter()). Each entry is
     * [type, dqlField] with an optional third element of applier options:
     *
     *   $searchForm->applyFilters($qb, $params, [
     *       'code'      => ['text',     'c.code'],
     *       'active'    => ['boolean',  'c.active'],
     *       'createdAt' => ['date',     'c.createdAt'],
     *       'locations' => ['relation', 'l.id'],
     *   ]);
     */
    public function applyFilters(QueryBuilder $qb, array $params, array $map): void
    {
        $this->applierRegistry ??= new FilterApplierRegistry();

        foreach ($map as $paramKey => $spec) {
            [$type, $dqlField] = $spec;

            $this->applierRegistry
                ->get($type)
                ->apply($qb, $dqlField, $params[$paramKey] ?? null, $spec[2] ?? []);
        }
    }

    public function getApplierRegistry(): FilterApplierRegistry
    {
        return $this->applierRegistry ??= new FilterApplierRegistry();
    }

    public function getCriteria(): Criteria|null
    {
        return $this->criteria ?? null;
    }

    public function setCriteria(Criteria $criteria)
    {
        $this->criteria = $criteria;
    }

    public function setFormFactory(FormFactoryInterface $formFactory)
    {
        $this->formFactory = $formFactory;
    }

    public function getModelType()
    {
        return $this->modelType;
    }

    public function andFilterWhere()
    {
        $condition = false;
        $args = func_get_args();
        $qb = $args[0];
        unset($args[0]);
        $baseParam = \uniqid();

        if ( in_array($args[1], ['or', 'and']) ) {
            $condition = $args[1];
            unset($args[1]);
        }

        $newArgs = [];
        $c = 0;
        foreach ($args as $arg) {
            $operator  = $arg[0];
            $attribute = $arg[1];
            $param     = is_string($arg[2]) ? trim($arg[2]) : $arg[2];
            $result    = $this->searchWithOperator($qb, $operator, $attribute, $param);
            if ($result !== null) {
                $newArgs[] = $result;
            }
            $c++;
        }

        if (empty($newArgs)) {
            return;
        }

        if ($condition == 'or') {
            $qb->andWhere(
                \call_user_func_array([$qb->expr(), 'orX'], $newArgs)
            );

        } else {
            \call_user_func_array([$qb, 'andWhere'], $newArgs);
        }
    }

    public function orFilterWhere()
    {
        $condition = false;
        $args = func_get_args();
        $qb = $args[0];
        unset($args[0]);
        $baseParam = \uniqid();

        if ( in_array($args[1], ['or', 'and']) ) {
            $condition = $args[1];
            unset($args[1]);
        }

        $newArgs = [];
        $c = 0;
        foreach ($args as $arg) {
            $operator  = $arg[0];
            $attribute = $arg[1];
            $param     = is_string($arg[2]) ? trim($arg[2]) : $arg[2];
            $result    = $this->searchWithOperator($qb, $operator, $attribute, $param);
            if ($result !== null) {
                $newArgs[] = $result;
            }
            $c++;
        }

        if (empty($newArgs)) {
            return;
        }

        if ($condition == 'and') {
            $qb->andWhere(
                \call_user_func_array([$qb->expr(), 'andX'], $newArgs)
            );

        } else {
            \call_user_func_array([$qb, 'orWhere'], $newArgs);
        }
        $newArgs = [];
    }

    public function searchWithOperator(QueryBuilder $qb, string $operator, string $attribute, string|array|\DateTimeInterface|null $param): mixed {
        if ($param === null || $param === '' || $param === []) {
            return null;
        }

        // Array values are only valid for 'in' — skip gracefully for other operators
        if (is_array($param) && $operator !== 'in') {
            return null;
        }
        $search = match($operator) {
            'eq', '==' => $this->eq($qb, $attribute, $param),
            'ieq', '=' => $this->ieq($qb, $attribute, $param),
            'neq', 'not', '!==', '<>' => $this->neq($qb, $attribute, $param),
            'ineq', '!=' => $this->ineq($qb, $attribute, $param),
            'gt', '>' => $this->gt($qb, $attribute, $param),
            'gte', '>=' => $this->gte($qb, $attribute, $param),
            'lt', '<' => $this->lt($qb, $attribute, $param),
            'lte', '<=' => $this->lte($qb, $attribute, $param),
            'btw', 'between' => $this->between($qb, $attribute, $param),
            'like', '%' => $this->like($qb, $attribute, $param),
            'ilike' => $this->ilike($qb, $attribute, $param),
            'notLike', 'nlike', '!%' => $this->notLike($qb, $attribute, $param),
            'notIlike', 'nilike' => $this->notIlike($qb, $attribute, $param),
            'startwith', '-%' => $this->startWith($qb, $attribute, $param),
            'istartwith', '-%' => $this->iStartWith($qb, $attribute, $param),
            'endWith', '%-' => $this->endWith($qb, $attribute, $param),
            'in' => $this->inOp($qb, $attribute, $param),
            default => $this->default($qb, $attribute, $param),
        };
        return $search;
    }

    public function search(QueryBuilder $qb, string $attribute, string $param)
    {
        $token = strtok(strtolower($param), " ");

        if ( $this->isOperator($token) ) {
            $operator = $token;
            $searchTerm = trim(str_ireplace($token, '', $param));

        } else {
            $operator = 'ilike'; // Default operator
            $searchTerm = $param;
        }

        $search = match($operator) {
            'eq', '==' => $this->eq($qb, $attribute, $searchTerm),
            'ieq', '=' => $this->ieq($qb, $attribute, $searchTerm),
            'neq', 'not', '!==', '<>' => $this->neq($qb, $attribute, $searchTerm),
            'ineq', '!=' => $this->ineq($qb, $attribute, $searchTerm),
            'gt', '>' => $this->gt($qb, $attribute, $searchTerm),
            'gte', '>=' => $this->gte($qb, $attribute, $searchTerm),
            'lt', '<' => $this->lt($qb, $attribute, $searchTerm),
            'lte', '<=' => $this->lte($qb, $attribute, $searchTerm),
            'btw', 'between' => $this->between($qb, $attribute, $searchTerm),
            'like', '%' => $this->like($qb, $attribute, $searchTerm),
            'ilike' => $this->ilike($qb, $attribute, $searchTerm),
            'notLike', 'nlike', '!%' => $this->notLike($qb, $attribute, $searchTerm),
            'notIlike', 'nilike' => $this->notIlike($qb, $attribute, $searchTerm),
            'startwith', '-%' => $this->startWith($qb, $attribute, $searchTerm),
            'istartwith', '-%' => $this->iStartWith($qb, $attribute, $searchTerm),
            'endWith', '%-' => $this->endWith($qb, $attribute, $searchTerm),
            default => $this->default($qb, $attribute, $searchTerm),
        };
    }

    private function getOperators()
    {
        $operators = [
            'eq', '==',
            'ieq', '=',
            'neq', 'not', '!==', '<>',
            'ineq', '!=',
            'gt', '>',
            'gte', '>=',
            'lt', '<',
            'lte', '<=',
            'btw', 'between',
            'btwe',
            'like', '%',
            'notlike', 'nlike', '!%',
            'ilike',
            'notilike', 'nilike', '!ilike',
            'startwith', '-%',
            'notstartwith', '!-%',
            'istartwith',
            'notistartwith',
            'endwith', '%-',
            'slike', '',
            'nslike', '',
            'rslike', '',
            'lslike', '',
            'isnull',
            'isnotnull',
            'in',
            'notin'
        ];

        return $operators;
    }

    private function isOperator($token) {
        if (in_array(strtolower($token), $this->getOperators())) {
            return true;
        }

        return false;
    }


    private function eq(QueryBuilder $qb, string $attribute, string $searchTerm)
    {
        $qb->andWhere($qb->expr()->eq($attribute, ':param'));
        $qb->setParameter(':param', $searchTerm);
    }

    private function ieq(QueryBuilder $qb, string $attribute, string $searchTerm)
    {
        $qb->andWhere($qb->expr()->eq(
            $qb->expr()->lower($attribute), ':param')
        );
        $qb->setParameter(':param', strtolower($searchTerm));
    }

    private function neq(QueryBuilder $qb, string $attribute, string $searchTerm)
    {
        $qb->andWhere($qb->expr()->neq($attribute, ':param'));
        $qb->setParameter(':param', $searchTerm);
    }

    private function ineq(QueryBuilder $qb, string $attribute, string $searchTerm)
    {
        $qb->andWhere($qb->expr()->neq(
            $qb->expr()->lower($attribute), ':param')
        );
        $qb->setParameter(':param', strtolower($searchTerm));
    }

    private function gt(QueryBuilder $qb, string $attribute, string|\DateTimeInterface $searchTerm): void
    {
        $p = 'p_' . uniqid();
        $qb->andWhere($qb->expr()->gt($attribute, ':' . $p));
        $qb->setParameter($p, $searchTerm);
    }

    private function gte(QueryBuilder $qb, string $attribute, string|\DateTimeInterface $searchTerm): void
    {
        $p = 'p_' . uniqid();
        $qb->andWhere($qb->expr()->gte($attribute, ':' . $p));
        $qb->setParameter($p, $searchTerm);
    }

    private function lt(QueryBuilder $qb, string $attribute, string|\DateTimeInterface $searchTerm): void
    {
        $p = 'p_' . uniqid();
        $qb->andWhere($qb->expr()->lt($attribute, ':' . $p));
        $qb->setParameter($p, $searchTerm);
    }

    private function lte(QueryBuilder $qb, string $attribute, string|\DateTimeInterface $searchTerm): void
    {
        $p = 'p_' . uniqid();
        $qb->andWhere($qb->expr()->lte($attribute, ':' . $p));
        $qb->setParameter($p, $searchTerm);
    }

    private function between(QueryBuilder $qb, string $attribute, string $searchTerm)
    {
        $terms = \explode(' AND ', $searchTerm);
        $qb->andWhere($qb->expr()->between($attribute, '\'' . $terms[0] . '\'', '\'' . $terms[1] . '\''));
    }

    private function like(QueryBuilder $qb, string $attribute, string $searchTerm)
    {
        return $qb->expr()->like($attribute, $qb->expr()->literal('%' . $searchTerm . '%'));
    }

    private function ilike(QueryBuilder $qb, string $attribute, string $searchTerm)
    {
        return $qb->expr()->like(
            $qb->expr()->lower($attribute),
            $qb->expr()->literal('%' . strtolower($searchTerm) . '%')
        );
    }

    private function notLike(QueryBuilder $qb, string $attribute, string $searchTerm)
    {
        return $qb->andWhere($qb->expr()->notLike(
            $attribute,
            $qb->expr()->literal('%' . $searchTerm . '%')
        ));
    }

    private function notIlike(QueryBuilder $qb, string $attribute, string $searchTerm)
    {
        $qb->andWhere(
            $qb->expr()->notLike(
                $qb->expr()->lower($attribute),
                ':param'
            )
            );
        $qb->setParameter(':param', '%' . strtolower($searchTerm) . '%');
    }

    private function startWith(QueryBuilder $qb, string $attribute, string $searchTerm)
    {
        $qb->andWhere($qb->expr()->like($attribute, ':param'));
        $qb->setParameter(':param', $searchTerm . '%');
    }

    private function iStartWith(QueryBuilder $qb, string $attribute, string $searchTerm)
    {
        $qb->andWhere($qb->expr()->like(
                $qb->expr()->lower($attribute),
                ':param'
            )
        );
        $qb->setParameter(':param', strtolower($searchTerm) . '%');
    }

    private function endWith(QueryBuilder $qb, string $attribute, string $searchTerm)
    {
        $qb->andWhere($qb->expr()->like($attribute, ':param'));
        $qb->setParameter(':param', '%' . $searchTerm);
    }


    private function default(QueryBuilder $qb, string $attribute, string $searchTerm)
    {
        $this->ilike($qb, $attribute, $searchTerm);
    }

    private function inOp(QueryBuilder $qb, string $attribute, string|array $param): mixed
    {
        $values = is_array($param)
            ? $param
            : array_filter(array_map('trim', explode(',', $param)));

        if (empty($values)) {
            return null;
        }

        $paramName = 'in_' . uniqid();
        $qb->setParameter($paramName, $values);

        return $qb->expr()->in($attribute, ':' . $paramName);
    }
}
