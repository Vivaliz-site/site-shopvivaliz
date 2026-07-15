<?php
declare(strict_types=1);

/**
 * Simple Query Builder
 *
 * Helper class for building parameterized SQL queries safely.
 * Prevents SQL injection through prepared statements.
 */

class QueryBuilder
{
    private mysqli $connection;
    private string $table = '';
    private array $select = ['*'];
    private array $joins = [];
    private array $where = [];
    private array $bindings = [];
    private array $orderBy = [];
    private ?int $limit = null;
    private ?int $offset = null;

    public function __construct(mysqli $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Set table name
     */
    public function from(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    /**
     * Set SELECT columns
     */
    public function select(string|array $columns = '*'): self
    {
        if (is_string($columns)) {
            $this->select = [$columns];
        } else {
            $this->select = $columns;
        }

        return $this;
    }

    /**
     * Add WHERE condition
     */
    public function where(string $column, string $operator, mixed $value): self
    {
        $placeholder = '?';
        $this->where[] = "{$column} {$operator} {$placeholder}";
        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add AND WHERE condition
     */
    public function andWhere(string $column, string $operator, mixed $value): self
    {
        return $this->where($column, $operator, $value);
    }

    /**
     * Add OR WHERE condition
     */
    public function orWhere(string $column, string $operator, mixed $value): self
    {
        if (!empty($this->where)) {
            $this->where[] = 'OR';
        }

        return $this->where($column, $operator, $value);
    }

    /**
     * Add ORDER BY
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'ASC';
        }

        $this->orderBy[] = "{$column} {$direction}";

        return $this;
    }

    /**
     * Set LIMIT
     */
    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set OFFSET
     */
    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Build query string
     */
    private function buildQuery(): string
    {
        $query = 'SELECT ' . implode(', ', $this->select) . ' FROM ' . $this->table;

        if (!empty($this->joins)) {
            $query .= ' ' . implode(' ', $this->joins);
        }

        if (!empty($this->where)) {
            $query .= ' WHERE ' . implode(' AND ', $this->where);
        }

        if (!empty($this->orderBy)) {
            $query .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        if ($this->limit !== null) {
            $query .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            $query .= ' OFFSET ' . $this->offset;
        }

        return $query;
    }

    /**
     * Execute query and get results
     */
    public function get(): array
    {
        $query = $this->buildQuery();
        $stmt = $this->connection->prepare($query);

        if ($stmt === false) {
            error_log('Query preparation failed: ' . $this->connection->error);
            return [];
        }

        // Bind parameters
        if (!empty($this->bindings)) {
            $types = $this->getBindingTypes($this->bindings);
            $stmt->bind_param($types, ...$this->bindings);
        }

        if (!$stmt->execute()) {
            error_log('Query execution failed: ' . $stmt->error);
            $stmt->close();
            return [];
        }

        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC) ?: [];

        $stmt->close();

        return $rows;
    }

    /**
     * Execute query and get first result
     */
    public function first(): ?array
    {
        $this->limit(1);
        $results = $this->get();

        return !empty($results) ? $results[0] : null;
    }

    /**
     * Count results
     */
    public function count(): int
    {
        $currentSelect = $this->select;
        $currentLimit = $this->limit;
        $currentOffset = $this->offset;

        // Temporarily change select to count
        $this->select = ['COUNT(*) as count'];
        $this->limit = null;
        $this->offset = null;

        $result = $this->first();
        $count = (int)($result['count'] ?? 0);

        // Restore original values
        $this->select = $currentSelect;
        $this->limit = $currentLimit;
        $this->offset = $currentOffset;

        return $count;
    }

    /**
     * Execute INSERT
     */
    public function insert(array $data): bool
    {
        $columns = array_keys($data);
        $values = array_values($data);
        $placeholders = array_fill(0, count($columns), '?');

        $query = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $stmt = $this->connection->prepare($query);
        if ($stmt === false) {
            return false;
        }

        $types = $this->getBindingTypes($values);
        if (!$stmt->bind_param($types, ...$values)) {
            $stmt->close();
            return false;
        }

        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Execute UPDATE
     */
    public function update(array $data): bool
    {
        if (empty($this->where)) {
            error_log('UPDATE without WHERE clause rejected for safety');
            return false;
        }

        $sets = [];
        $values = [];

        foreach ($data as $column => $value) {
            $sets[] = "{$column} = ?";
            $values[] = $value;
        }

        // Add WHERE bindings
        $values = array_merge($values, $this->bindings);

        $query = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $sets) . ' WHERE ' . implode(' AND ', $this->where);

        $stmt = $this->connection->prepare($query);
        if ($stmt === false) {
            return false;
        }

        $types = $this->getBindingTypes($values);
        if (!$stmt->bind_param($types, ...$values)) {
            $stmt->close();
            return false;
        }

        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Execute DELETE
     */
    public function delete(): bool
    {
        if (empty($this->where)) {
            error_log('DELETE without WHERE clause rejected for safety');
            return false;
        }

        $query = 'DELETE FROM ' . $this->table . ' WHERE ' . implode(' AND ', $this->where);

        $stmt = $this->connection->prepare($query);
        if ($stmt === false) {
            return false;
        }

        if (!empty($this->bindings)) {
            $types = $this->getBindingTypes($this->bindings);
            if (!$stmt->bind_param($types, ...$this->bindings)) {
                $stmt->close();
                return false;
            }
        }

        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Get binding type string for parameters
     */
    private function getBindingTypes(array $values): string
    {
        $types = '';
        foreach ($values as $value) {
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }

        return $types;
    }

    /**
     * Get last insert ID
     */
    public function lastInsertId(): int
    {
        return (int)$this->connection->insert_id;
    }

    /**
     * Reset builder
     */
    public function reset(): self
    {
        $this->table = '';
        $this->select = ['*'];
        $this->joins = [];
        $this->where = [];
        $this->bindings = [];
        $this->orderBy = [];
        $this->limit = null;
        $this->offset = null;

        return $this;
    }
}

/**
 * Helper function to create query builder
 */
function query(mysqli $connection, string $table = ''): QueryBuilder
{
    $builder = new QueryBuilder($connection);
    if ($table !== '') {
        $builder->from($table);
    }

    return $builder;
}
