<?php

namespace Fedale\GridviewBundle\Contract;

/**
 * Opt-in capability for a data provider that can confirm a raw primary-key
 * value would actually appear in its current, filtered result set. Used to
 * guard direct-key lookups that bypass the grid's own paginated query — e.g.
 * the lazy grouping `?_children=<key>` endpoint, which receives a bare id
 * from the client — against reading rows the grid's own list wouldn't
 * otherwise show (a repository `search()` scoping rows to the current
 * user/tenant, for instance). A provider that doesn't implement this is
 * trusted to not need such scoping, or to enforce it elsewhere.
 */
interface ScopeVerifiableInterface
{
    public function isKeyInScope(int|string $key, string $keyField = 'id'): bool;
}
