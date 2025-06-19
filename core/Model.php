<?php
declare(strict_types=1);

/**
 * File: core/Model.php
 * Location: core/Model.php
 *
 * WinABN Base Model Class
 *
 * Provides database interaction functionality including CRUD operations,
 * query building, relationships, validation, and caching for all models.
 *
 * @package WinABN\Core
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Core;

use Exception;

abstract class Model
{
    /**
     * Database table name
     *
     * @var string
     */
    protected string $table = '';

    /**
     * Primary key column
     *
     * @var string
     */
    protected string $primaryKey = 'id';

    /**
     * Fillable attributes for mass assignment
     *
     * @var array<string>
     */
    protected array $fillable = [];

    /**
     * Guarded attributes (cannot be mass assigned)
     *
     * @var array<string>
     */
    protected array $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Hidden attributes (not included in toArray/toJson)
     *
     * @var array<string>
     */
    protected array $hidden = [];

    /**
     * Attributes that should be cast to specific types
     *
     * @var array<string, string>
     */
    protected array $casts = [];

    /**
     * Validation rules
     *
     * @var array<string, string>
     */
    protected array $rules = [];

    /**
     * Enable timestamps (created_at, updated_at)
     *
     * @var bool
     */
    protected bool $timestamps = true;

    /**
     * Model data
     *
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * Original model data (for dirty checking)
     *
     * @var array<string, mixed>
     */
    protected array $original = [];

    /**
     * Model exists in database
     *
     * @var bool
     */
    protected bool $exists = false;

    /**
     * Database instance
     *
     * @var Database
     */
    protected static Database $db;

    /**
     * Constructor
     *
     * @param array<string, mixed> $attributes Initial attributes
     */
    public function __construct(array $attributes = [])
    {
        if (!isset(self::$db)) {
            self::$db = new Database();
        }

        if (empty($this->table)) {
            $this->table = $this->getTableName();
        }

        $this->fill($attributes);
    }

    /**
     * Find model by primary key
     *
     * @param int $id Primary key value
     * @return static|null
     */
    public static function find(int $id): ?static
    {
        $instance = new static();
        $data = Database::fetchOne(
            "SELECT * FROM `{$instance->table}` WHERE `{$instance->primaryKey}` = ?",
            [$id]
        );

        if (!$data) {
            return null;
        }

        return $instance->newFromBuilder($data);
    }

    /**
     * Find model by primary key or throw exception
     *
     * @param int $id Primary key value
     * @return static
     * @throws Exception
     */
    public static function findOrFail(int $id): static
    {
        $model = static::find($id);

        if (!$model) {
            throw new Exception("Model not found with ID: $id");
        }

        return $model;
    }

    /**
     * Find first model matching conditions
     *
     * @param array<string, mixed> $conditions Where conditions
     * @return static|null
     */
    public static function where(array $conditions): ?static
    {
        $instance = new static();
        $whereClause = [];
        $params = [];

        foreach ($conditions as $column => $value) {
            $whereClause[] = "`$column` = ?";
            $params[] = $value;
        }

        $sql = "SELECT * FROM `{$instance->table}` WHERE " . implode(' AND ', $whereClause) . " LIMIT 1";
        $data = Database::fetchOne($sql, $params);

        if (!$data) {
            return null;
        }

        return $instance->newFromBuilder($data);
    }

    /**
     * Get all models matching conditions
     *
     * @param array<string, mixed> $conditions Where conditions
     * @param string $orderBy Order by clause
     * @param int|null $limit Limit results
     * @return array<static>
     */
    public static function all(array $conditions = [], string $orderBy = '', ?int $limit = null): array
    {
        $instance = new static();
        $sql = "SELECT * FROM `{$instance->table}`";
        $params = [];

        if (!empty($conditions)) {
            $whereClause = [];
            foreach ($conditions as $column => $value) {
                $whereClause[] = "`$column` = ?";
                $params[] = $value;
            }
            $sql .= " WHERE " . implode(' AND ', $whereClause);
        }

        if (!empty($orderBy)) {
            $sql .= " ORDER BY $orderBy";
        }

        if ($limit !== null) {
            $sql .= " LIMIT $limit";
        }

        $results = Database::fetchAll($sql, $params);
        $models = [];

        foreach ($results as $data) {
            $models[] = $instance->newFromBuilder($data);
        }

        return $models;
    }

    /**
     * Create new model instance
     *
     * @param array<string, mixed> $attributes Model attributes
     * @return static
     */
    public static function create(array $attributes): static
    {
        $instance = new static($attributes);
        $instance->save();

        return $instance;
    }

    /**
     * Update or create model
     *
     * @param array<string, mixed> $conditions Search conditions
     * @param array<string, mixed> $attributes Update attributes
     * @return static
     */
    public static function updateOrCreate(array $conditions, array $attributes): static
    {
        $model = static::where($conditions);

        if ($model) {
            $model->fill($attributes);
            $model->save();
        } else {
            $model = static::create(array_merge($conditions, $attributes));
        }

        return $model;
    }

    /**
     * Save model to database
     *
     * @return bool
     * @throws Exception
     */
    public function save(): bool
    {
        // Validate before saving
        $this->validate();

        // Set timestamps
        if ($this->timestamps) {
            $now = date('Y-m-d H:i:s');

            if (!$this->exists) {
                $this->setAttribute('created_at', $now);
            }

            $this->setAttribute('updated_at', $now);
        }

        if ($this->exists) {
            return $this->performUpdate();
        } else {
            return $this->performInsert();
        }
    }

    /**
     * Delete model from database
     *
     * @return bool
     * @throws Exception
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        $sql = "DELETE FROM `{$this->table}` WHERE `{$this->primaryKey}` = ?";
        Database::execute($sql, [$this->getAttribute($this->primaryKey)]);

        $this->exists = false;
        return true;
    }

    /**
     * Fill model with attributes
     *
     * @param array<string, mixed> $attributes Attributes to fill
     * @return static
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }

        return $this;
    }

    /**
     * Set attribute value
     *
     * @param string $key Attribute key
     * @param mixed $value Attribute value
     * @return void
     */
    public function setAttribute(string $key, $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Get attribute value
     *
     * @param string $key Attribute key
     * @param mixed $default Default value
     * @return mixed
     */
    public function getAttribute(string $key, $default = null)
    {
        $value = $this->attributes[$key] ?? $default;

        // Apply casts
        if (isset($this->casts[$key])) {
            $value = $this->castAttribute($key, $value);
        }

        return $value;
    }

    /**
     * Check if attribute exists
     *
     * @param string $key Attribute key
     * @return bool
     */
    public function hasAttribute(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    /**
     * Get all attributes
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        $attributes = [];

        foreach ($this->attributes as $key => $value) {
            if (!in_array($key, $this->hidden)) {
                $attributes[$key] = $this->getAttribute($key);
            }
        }

        return $attributes;
    }

    /**
     * Get dirty (changed) attributes
     *
     * @return array<string, mixed>
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    /**
     * Check if model is dirty
     *
     * @return bool
     */
    public function isDirty(): bool
    {
        return !empty($this->getDirty());
    }

    /**
     * Convert model to array
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->getAttributes();
    }

    /**
     * Convert model to JSON
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Validate model attributes
     *
     * @return void
     * @throws Exception
     */
    protected function validate(): void
    {
        if (empty($this->rules)) {
            return;
        }

        $errors = [];

        foreach ($this->rules as $field => $rule) {
            $value = $this->getAttribute($field);
            $fieldRules = explode('|', $rule);

            foreach ($fieldRules as $fieldRule) {
                $result = $this->validateRule($field, $value, $fieldRule);

                if ($result !== true) {
                    $errors[$field] = $result;
                    break;
                }
            }
        }

        if (!empty($errors)) {
            throw new Exception('Validation failed: ' . json_encode($errors));
        }
    }

    /**
     * Validate single rule
     *
     * @param string $field Field name
     * @param mixed $value Field value
     * @param string $rule Validation rule
     * @return string|bool Error message or true if valid
     */
    protected function validateRule(string $field, $value, string $rule)
    {
        $ruleParts = explode(':', $rule, 2);
        $ruleName = $ruleParts[0];
        $ruleParam = $ruleParts[1] ?? null;

        switch ($ruleName) {
            case 'required':
                return !empty($value) || "The {$field} field is required";

            case 'unique':
                if ($this->exists) {
                    // Skip unique validation for existing records if value hasn't changed
                    if ($this->original[$field] ?? null === $value) {
                        return true;
                    }
                }

                $column = $ruleParam ?? $field;
                $existing = Database::fetchColumn(
                    "SELECT COUNT(*) FROM `{$this->table}` WHERE `{$column}` = ?",
                    [$value]
                );
                return $existing == 0 || "The {$field} is already taken";

            case 'email':
                return filter_var($value, FILTER_VALIDATE_EMAIL) !== false || "The {$field} must be a valid email";

            case 'numeric':
                return is_numeric($value) || "The {$field} must be numeric";

            case 'min':
                if (is_string($value)) {
                    return strlen($value) >= (int)$ruleParam || "The {$field} must be at least {$ruleParam} characters";
                }
                return $value >= (int)$ruleParam || "The {$field} must be at least {$ruleParam}";

            case 'max':
                if (is_string($value)) {
                    return strlen($value) <= (int)$ruleParam || "The {$field} must not exceed {$ruleParam} characters";
                }
                return $value <= (int)$ruleParam || "The {$field} must not exceed {$ruleParam}";

            default:
                return true;
        }
    }

    /**
     * Cast attribute to specified type
     *
     * @param string $key Attribute key
     * @param mixed $value Attribute value
     * @return mixed
     */
    protected function castAttribute(string $key, $value)
    {
        $castType = $this->casts[$key];

        switch ($castType) {
            case 'integer':
            case 'int':
                return (int) $value;

            case 'float':
            case 'double':
                return (float) $value;

            case 'string':
                return (string) $value;

            case 'boolean':
            case 'bool':
                return (bool) $value;

            case 'array':
                return is_string($value) ? json_decode($value, true) : (array) $value;

            case 'json':
                return is_string($value) ? json_decode($value, true) : $value;

            case 'datetime':
                return $value instanceof \DateTime ? $value : new \DateTime($value);

            default:
                return $value;
        }
    }

    /**
     * Check if attribute is fillable
     *
     * @param string $key Attribute key
     * @return bool
     */
    protected function isFillable(string $key): bool
    {
        // If fillable is defined, only allow those attributes
        if (!empty($this->fillable)) {
            return in_array($key, $this->fillable);
        }

        // Otherwise, allow all except guarded
        return !in_array($key, $this->guarded);
    }

    /**
     * Perform database insert
     *
     * @return bool
     * @throws Exception
     */
    protected function performInsert(): bool
    {
        $attributes = $this->getDirty();

        if (empty($attributes)) {
            return true;
        }

        $columns = array_keys($attributes);
        $values = array_values($attributes);
        $placeholders = str_repeat('?,', count($columns) - 1) . '?';

        $sql = "INSERT INTO `{$this->table}` (`" . implode('`, `', $columns) . "`) VALUES ($placeholders)";

        Database::execute($sql, $values);

        // Set the primary key for new records
        if (empty($this->getAttribute($this->primaryKey))) {
            $this->setAttribute($this->primaryKey, Database::lastInsertId());
        }

        $this->exists = true;
        $this->syncOriginal();

        return true;
    }

    /**
     * Perform database update
     *
     * @return bool
     * @throws Exception
     */
    protected function performUpdate(): bool
    {
        $dirty = $this->getDirty();

        if (empty($dirty)) {
            return true;
        }

        $setParts = [];
        $values = [];

        foreach ($dirty as $column => $value) {
            $setParts[] = "`$column` = ?";
            $values[] = $value;
        }

        $values[] = $this->getAttribute($this->primaryKey);

        $sql = "UPDATE `{$this->table}` SET " . implode(', ', $setParts) . " WHERE `{$this->primaryKey}` = ?";

        Database::execute($sql, $values);
        $this->syncOriginal();

        return true;
    }

    /**
     * Create new model instance from database result
     *
     * @param array<string, mixed> $data Database row data
     * @return static
     */
    protected function newFromBuilder(array $data): static
    {
        $instance = new static();
        $instance->attributes = $data;
        $instance->original = $data;
        $instance->exists = true;

        return $instance;
    }

    /**
     * Sync original attributes
     *
     * @return void
     */
    protected function syncOriginal(): void
    {
        $this->original = $this->attributes;
    }

    /**
     * Get table name from class name
     *
     * @return string
     */
    protected function getTableName(): string
    {
        $className = (new \ReflectionClass($this))->getShortName();

        // Convert PascalCase to snake_case and pluralize
        $tableName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $className));

        // Simple pluralization
        if (str_ends_with($tableName, 'y')) {
            $tableName = substr($tableName, 0, -1) . 'ies';
        } elseif (str_ends_with($tableName, 's')) {
            $tableName .= 'es';
        } else {
            $tableName .= 's';
        }

        return $tableName;
    }

    /**
     * Magic getter for attributes
     *
     * @param string $key Attribute key
     * @return mixed
     */
    public function __get(string $key)
    {
        return $this->getAttribute($key);
    }

    /**
     * Magic setter for attributes
     *
     * @param string $key Attribute key
     * @param mixed $value Attribute value
     * @return void
     */
    public function __set(string $key, $value): void
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Magic isset for attributes
     *
     * @param string $key Attribute key
     * @return bool
     */
    public function __isset(string $key): bool
    {
        return $this->hasAttribute($key);
    }

    /**
     * Magic unset for attributes
     *
     * @param string $key Attribute key
     * @return void
     */
    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }

    /**
     * Convert to string
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->toJson();
    }
}
