<?php

declare(strict_types=1);

namespace DolibarrMcp\Support;

/**
 * Turn the `filters` argument of dolibarr_list into something Dolibarr's REST
 * API actually honours.
 *
 * The problem this solves
 * -----------------------
 * `filters` was documented to the model as "filter by field values", but its
 * decoded keys were merged straight into the HTTP query string. Dolibarr's
 * router (Restler) binds only the parameters an endpoint declares in its
 * `index()` signature and DISCARDS every other query parameter without a word.
 * A request like
 *
 *     GET /thirdparties?email=someone@example.com
 *
 * therefore ran as a plain unfiltered list, and the model — which had been told
 * the filter was applied — presented the first unrelated record as the answer.
 * The failure is silent and indistinguishable from a genuine match, which is
 * what makes it dangerous: a customer looking up a contact by e-mail was shown
 * a different company altogether.
 *
 * The contract implemented here
 * -----------------------------
 * Every key is resolved to exactly one of four outcomes, and never to silence:
 *
 *  1. **Native parameter** — the endpoint declares it (`mode`, `category`,
 *     `thirdparty_ids`, `status`, …). Passed through untouched, so the core's
 *     own semantics and permission handling keep applying.
 *  2. **Known alias** — a name the model reasonably guesses but the endpoint
 *     does not use (`socid` on invoices, `name` on thirdparties). Rewritten to
 *     the native parameter when one exists, otherwise to the real column.
 *  3. **Column filter** — anything else becomes a `sqlfilters` equality on
 *     `t.<key>`, which is the one mechanism every list endpoint supports.
 *     A column that does not exist makes the SQL fail loudly instead of
 *     returning an unfiltered list.
 *  4. **Refusal** — ambiguous or unrepresentable input is rejected before the
 *     request is sent, with the supported alternatives named.
 *
 * Values are never concatenated into SQL here: they are emitted inside the
 * universal filter syntax, and Dolibarr's `dolForgeSQLCriteriaCallback()` runs
 * `$db->escape()` on them. What this class must guarantee is that the *syntax*
 * survives — the core parses criteria with `\(([a-zA-Z0-9_\.]+:[<>!=insotlke]+:[^\(\)]+)\)`,
 * so a value containing a parenthesis would corrupt the expression. Those are
 * refused with an explanation rather than mangled.
 */
final class ListFilterTranslator
{
    /**
     * Query parameters dolibarr_list manages itself; a filter must never
     * overwrite them.
     */
    private const RESERVED = ['limit', 'page', 'sortfield', 'sortorder', 'sqlfilters', 'properties'];

    /**
     * Keys a model commonly uses to mean "this third party".
     */
    private const THIRDPARTY_KEYS = ['socid', 'fk_soc', 'thirdparty_id', 'thirdparty_ids', 'company_id', 'customer_id', 'supplier_id'];

    /**
     * Per-resource contract, transcribed from the `index()` signatures of the
     * core API classes (htdocs/**\/api_*.class.php).
     *
     * - native:      extra query parameters the endpoint declares
     * - thirdparty:  native parameter carrying the third-party restriction
     * - statusParam: native parameter carrying the status, when textual
     * - statusWords: accepted words => underlying numeric status
     * - statusColumn: column to use when the model passes a numeric status
     * - columns:     alias => real column name, for `sqlfilters`
     * - ambiguous:   key => explanation, refused instead of guessed
     */
    private const CONTRACTS = [
        'thirdparties' => [
            'native' => ['mode', 'category'],
            'thirdparty' => null,
            'columns' => ['name' => 'nom', 'company' => 'nom', 'label' => 'nom', 'socid' => 'rowid', 'thirdparty_id' => 'rowid', 'vat_number' => 'tva_intra'],
        ],
        'contacts' => [
            'native' => ['thirdparty_ids', 'category', 'includecount', 'includeroles'],
            'thirdparty' => 'thirdparty_ids',
            'columns' => ['phone' => 'phone_pro', 'job' => 'poste', 'function' => 'poste'],
            'ambiguous' => ['name' => 'Contacts have no "name" column. Use "lastname" or "firstname", or sqlfilters for a partial match, e.g. (t.lastname:like:\'%dupont%\').'],
        ],
        'invoices' => [
            'native' => ['thirdparty_ids', 'status'],
            'thirdparty' => 'thirdparty_ids',
            'statusParam' => 'status',
            'statusWords' => ['draft' => 0, 'unpaid' => 1, 'paid' => 2, 'cancelled' => 3],
            'statusColumn' => 'fk_statut',
        ],
        'supplierinvoices' => [
            'native' => ['thirdparty_ids', 'status'],
            'thirdparty' => 'thirdparty_ids',
            'statusParam' => 'status',
            'statusWords' => ['draft' => 0, 'unpaid' => 1, 'paid' => 2, 'cancelled' => 3],
            'statusColumn' => 'fk_statut',
        ],
        'supplierorders' => [
            'native' => ['thirdparty_ids', 'product_ids', 'status'],
            'thirdparty' => 'thirdparty_ids',
            'statusParam' => 'status',
            'statusWords' => ['draft' => 0, 'validated' => 1, 'approved' => 2, 'running' => 3, 'received_start' => 4, 'received_end' => 5, 'cancelled' => 6, 'refused' => 9],
            'statusColumn' => 'fk_statut',
        ],
        'orders' => [
            'native' => ['thirdparty_ids', 'sqlfilterlines'],
            'thirdparty' => 'thirdparty_ids',
            'columns' => ['status' => 'fk_statut'],
        ],
        'proposals' => [
            'native' => ['thirdparty_ids'],
            'thirdparty' => 'thirdparty_ids',
            'columns' => ['status' => 'fk_statut'],
        ],
        'supplierproposals' => [
            'native' => ['thirdparty_ids'],
            'thirdparty' => 'thirdparty_ids',
            'columns' => ['status' => 'fk_statut'],
        ],
        'products' => [
            'native' => ['mode', 'category', 'ids_only', 'variant_filter', 'includestockdata'],
            'thirdparty' => null,
            'columns' => ['name' => 'label', 'status' => 'tosell', 'type' => 'fk_product_type'],
        ],
        'projects' => [
            'native' => ['thirdparty_ids', 'category'],
            'thirdparty' => 'thirdparty_ids',
            'columns' => ['name' => 'title', 'label' => 'title', 'status' => 'fk_statut'],
        ],
        'tasks' => [
            'native' => [],
            'thirdparty' => null,
            'columns' => ['name' => 'label', 'project_id' => 'fk_projet', 'fk_project' => 'fk_projet'],
        ],
        'tickets' => [
            'native' => ['socid'],
            'thirdparty' => 'socid',
            'columns' => ['name' => 'subject', 'title' => 'subject', 'status' => 'fk_statut'],
        ],
        'users' => [
            'native' => ['user_ids', 'category'],
            'thirdparty' => null,
            'columns' => ['username' => 'login', 'status' => 'statut'],
            'ambiguous' => ['name' => 'Users have no "name" column. Use "login", "lastname" or "firstname".'],
        ],
        'members' => [
            'native' => ['typeid', 'category'],
            'thirdparty' => null,
            'columns' => ['status' => 'statut'],
            'ambiguous' => ['name' => 'Members have no "name" column. Use "lastname", "firstname" or "societe".'],
        ],
        'agendaevents' => [
            'native' => ['user_ids'],
            'thirdparty' => null,
            'columns' => ['name' => 'label', 'socid' => 'fk_soc', 'thirdparty_id' => 'fk_soc'],
        ],
        'expensereports' => [
            'native' => ['user_ids'],
            'thirdparty' => null,
            'columns' => ['status' => 'fk_statut'],
        ],
        'categories' => [
            'native' => ['type'],
            'thirdparty' => null,
            'columns' => ['name' => 'label'],
        ],
        'bankaccounts' => [
            'native' => ['category'],
            'thirdparty' => null,
            'columns' => ['name' => 'label', 'status' => 'clos'],
        ],
        'warehouses' => [
            'native' => ['category'],
            'thirdparty' => null,
            'columns' => ['name' => 'label', 'status' => 'statut'],
        ],
        'knowledgemanagement' => [
            'native' => ['category'],
            'thirdparty' => null,
        ],
        // Reference-data endpoints under /setup share one parameter vocabulary.
        // Their tables are aliased `t` like every other list endpoint, so the
        // generic column fallback works for their real columns (code, label,
        // module, type...). Only the selectors the endpoints declare natively
        // need listing here.
        'setup/extrafields' => [
            'native' => ['elementtype'],
            'thirdparty' => null,
        ],
        'setup' => [
            'native' => ['active', 'lang', 'module', 'type', 'country', 'filter', 'multicurrency', 'zipcode', 'town'],
            'thirdparty' => null,
        ],

        'contracts' => ['native' => ['thirdparty_ids'], 'thirdparty' => 'thirdparty_ids'],
        'donations' => ['native' => ['thirdparty_ids'], 'thirdparty' => 'thirdparty_ids'],
        'interventions' => ['native' => ['thirdparty_ids'], 'thirdparty' => 'thirdparty_ids'],
        'receptions' => ['native' => ['thirdparty_ids'], 'thirdparty' => 'thirdparty_ids'],
        'shipments' => ['native' => ['thirdparty_ids'], 'thirdparty' => 'thirdparty_ids'],
    ];

    /**
     * @param array<string, mixed> $filters decoded `filters` JSON object
     * @return array{params: array<string, string>, sqlfilters: list<string>, error: array<string, mixed>|null}
     */
    public function translate(string $resource, array $filters): array
    {
        $contract = self::CONTRACTS[$this->contractKey($resource)] ?? [];
        $params = [];
        $sqlfilters = [];

        foreach ($filters as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                return ['params' => [], 'sqlfilters' => [], 'error' => $this->error(
                    'INVALID_FILTER_KEY',
                    'Filter keys must be plain field names (letters, digits and underscores).',
                    ['key' => $key]
                )];
            }

            $lower = strtolower($key);

            if (in_array($lower, self::RESERVED, true)) {
                return ['params' => [], 'sqlfilters' => [], 'error' => $this->error(
                    'RESERVED_FILTER_KEY',
                    sprintf('"%s" is not a filter: it is a dedicated argument of dolibarr_list. Pass it as its own argument.', $lower),
                    ['key' => $lower]
                )];
            }

            if (isset($contract['ambiguous'][$lower])) {
                return ['params' => [], 'sqlfilters' => [], 'error' => $this->error(
                    'AMBIGUOUS_FILTER',
                    $contract['ambiguous'][$lower],
                    ['key' => $lower, 'resource' => $resource]
                )];
            }

            $scalar = $this->scalarize($value);
            if ($scalar === null) {
                return ['params' => [], 'sqlfilters' => [], 'error' => $this->error(
                    'INVALID_FILTER_VALUE',
                    sprintf('The value of "%s" must be a string, a number, a boolean, or a list of those.', $lower),
                    ['key' => $lower]
                )];
            }

            // 1. Third-party shorthands map onto the endpoint's own parameter,
            //    which keeps the core's permission handling in the loop.
            if (in_array($lower, self::THIRDPARTY_KEYS, true) && !empty($contract['thirdparty'])) {
                $params[$contract['thirdparty']] = $scalar;
                continue;
            }

            // 2. Status needs value-aware routing: the native parameter only
            //    understands words, so a numeric status must go to the column.
            if ($lower === ($contract['statusParam'] ?? null)) {
                $routed = $this->routeStatus($scalar, $contract);
                if (isset($routed['error'])) {
                    return ['params' => [], 'sqlfilters' => [], 'error' => $routed['error']];
                }
                if (isset($routed['param'])) {
                    $params[$contract['statusParam']] = $routed['param'];
                } else {
                    $sqlfilters[] = $this->criterion($routed['column'], $routed['value']);
                }
                continue;
            }

            // 3. Native parameters are passed through untouched.
            if (in_array($lower, $contract['native'] ?? [], true)) {
                $params[$lower] = $scalar;
                continue;
            }

            // 4. Everything else becomes a column equality.
            $column = $contract['columns'][$lower] ?? $lower;

            if (str_contains($scalar, '(') || str_contains($scalar, ')')) {
                return ['params' => [], 'sqlfilters' => [], 'error' => $this->error(
                    'UNSUPPORTED_FILTER_VALUE',
                    sprintf(
                        'The value of "%s" contains a parenthesis, which Dolibarr\'s filter syntax cannot carry. Use the sqlfilters argument with a partial match instead, e.g. (t.%s:like:\'%%fragment%%\').',
                        $lower,
                        $column
                    ),
                    ['key' => $lower, 'value' => $scalar]
                )];
            }

            $sqlfilters[] = $this->criterion($column, $scalar);
        }

        return ['params' => $params, 'sqlfilters' => $sqlfilters, 'error' => null];
    }

    /**
     * Resolve the contract for a resource, ignoring any sub-path
     * (thirdparties/42/contacts is governed by the contacts contract when
     * listing, but the sub-collection endpoints take no filters — falling back
     * to the generic behaviour is correct there).
     */
    private function contractKey(string $resource): string
    {
        $resource = strtolower(trim($resource, '/'));

        // Exact path first (setup/extrafields declares parameters its siblings
        // do not), then the collection it belongs to.
        if (isset(self::CONTRACTS[$resource])) {
            return $resource;
        }

        return explode('/', $resource)[0];
    }

    /**
     * @param array<string, mixed> $contract
     * @return array{param?: string, column?: string, value?: string, error?: array<string, mixed>}
     */
    private function routeStatus(string $value, array $contract): array
    {
        $words = $contract['statusWords'] ?? [];
        $lower = strtolower($value);

        if (array_key_exists($lower, $words)) {
            return ['param' => $lower];
        }

        if (ctype_digit($value)) {
            // The model speaks in the numeric codes it sees in the data. The
            // native parameter only understands words, so translate back to a
            // word when the code maps to one, and fall back to the column
            // otherwise — never drop the filter.
            $word = array_search((int) $value, $words, true);
            if ($word !== false) {
                return ['param' => $word];
            }

            return ['column' => $contract['statusColumn'] ?? 'fk_statut', 'value' => $value];
        }

        return ['error' => $this->error(
            'INVALID_STATUS_FILTER',
            sprintf(
                'Unknown status "%s". Accepted values: %s, or the matching numeric code.',
                $value,
                implode(', ', array_keys($words))
            ),
            ['received' => $value, 'accepted' => array_keys($words)]
        )];
    }

    private function criterion(string $column, string $value): string
    {
        return "(t." . $column . ":=:'" . $value . "')";
    }

    /**
     * Flatten a JSON value to the string form a query parameter carries.
     * Lists become comma-separated, which is what Dolibarr's `*_ids`
     * parameters expect.
     */
    private function scalarize(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $flat = $this->scalarize($item);
                if ($flat === null || is_array($item)) {
                    return null;
                }
                $parts[] = $flat;
            }

            return implode(',', $parts);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function error(string $code, string $message, array $context = []): array
    {
        return array_merge(['error' => true, 'code' => $code, 'message' => $message], $context);
    }
}
