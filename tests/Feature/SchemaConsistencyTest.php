<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Permanent guard against model↔schema drift — the class of bug SQLite hides but
 * MySQL/prod hard-errors on: a `$fillable` entry or a `belongsTo` foreign key that
 * has no backing column (mass-assign error / always-null relation). Runs on the
 * migrated test schema, so it guards on any driver, including CI's SQLite.
 */
class SchemaConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_declared_relationship_keys_exist_in_the_migrated_schema(): void
    {
        $problems = [];
        $checked = 0;
        $columns = [];
        $check = function (string $table, string $key, string $source) use (&$columns, &$problems): void {
            $columns[$table] ??= Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
            $key = substr($key, (int) strrpos('.'.$key, '.'));
            if (! in_array($key, $columns[$table], true)) {
                $problems[] = "{$source}: relation key '{$table}.{$key}' has no schema column";
            }
        };

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            $ref = new ReflectionClass($class);
            if ($ref->isAbstract() || ! $ref->isSubclassOf(Model::class)) {
                continue;
            }
            $model = new $class();
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || $method->isStatic() || $method->getNumberOfParameters() !== 0) {
                    continue;
                }
                $source = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
                if (! preg_match('/\$this->(?:belongsTo|belongsToMany|hasOne|hasMany|morphTo|morphOne|morphMany|morphToMany|morphedByMany)\(/', $source)) {
                    continue;
                }
                $name = $class.'::'.$method->getName().'()';
                try {
                    $relation = $model->{$method->getName()}();
                    $this->assertInstanceOf(Relation::class, $relation, $name);
                    $checked++;
                    if ($relation instanceof MorphTo) {
                        $check($model->getTable(), $relation->getForeignKeyName(), $name);
                        $check($model->getTable(), $relation->getMorphType(), $name);
                    } elseif ($relation instanceof BelongsTo) {
                        $check($model->getTable(), $relation->getForeignKeyName(), $name);
                        $check($relation->getRelated()->getTable(), $relation->getOwnerKeyName(), $name);
                    } elseif ($relation instanceof HasOneOrMany) {
                        $check($relation->getRelated()->getTable(), $relation->getForeignKeyName(), $name);
                        $check($model->getTable(), $relation->getLocalKeyName(), $name);
                        if ($relation instanceof MorphOneOrMany) {
                            $check($relation->getRelated()->getTable(), $relation->getMorphType(), $name);
                        }
                    } elseif ($relation instanceof BelongsToMany) {
                        $check($relation->getTable(), $relation->getForeignPivotKeyName(), $name);
                        $check($relation->getTable(), $relation->getRelatedPivotKeyName(), $name);
                        $check($model->getTable(), $relation->getParentKeyName(), $name);
                        $check($relation->getRelated()->getTable(), $relation->getRelatedKeyName(), $name);
                    }
                } catch (\Throwable $e) {
                    $problems[] = $name.': '.$e->getMessage();
                }
            }
        }

        $this->assertGreaterThan(100, $checked, 'The migrated host relationship graph must be inspected.');
        $this->assertSame([], $problems, "Relationship/schema drift detected:\n".implode("\n", $problems));
    }

    public function test_every_model_fillable_and_belongsto_fk_map_to_real_columns(): void
    {
        $problems = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if (! class_exists($class)) {
                continue;
            }

            $ref = new ReflectionClass($class);
            if ($ref->isAbstract() || ! $ref->isSubclassOf(Model::class)) {
                continue;
            }

            try {
                $model = new $class();
                $table = $model->getTable();

                if (! Schema::hasTable($table)) {
                    $problems[] = "{$class}: table '{$table}' does not exist";

                    continue;
                }

                $columns = Schema::getColumnListing($table);

                foreach (array_diff($model->getFillable(), $columns) as $missing) {
                    $problems[] = "{$class}: \$fillable '{$missing}' is not a column of '{$table}'";
                }

                foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    if ($method->getDeclaringClass()->getName() !== $class
                        || $method->isStatic()
                        || $method->getNumberOfRequiredParameters() > 0) {
                        continue;
                    }

                    $returnType = $method->getReturnType();
                    if (! $returnType instanceof ReflectionNamedType
                        || ! str_contains($returnType->getName(), 'BelongsTo')) {
                        continue;
                    }

                    try {
                        $relation = $model->{$method->getName()}();
                        if ($relation instanceof BelongsTo
                            && ! in_array($relation->getForeignKeyName(), $columns, true)) {
                            $problems[] = "{$class}::{$method->getName()}() foreign key '"
                                .$relation->getForeignKeyName()."' is not a column of '{$table}'";
                        }
                    } catch (\Throwable) {
                        // Relation that needs state to resolve — out of scope for a static column check.
                    }
                }
            } catch (\Throwable $e) {
                $problems[] = "{$class}: ".$e->getMessage();
            }
        }

        $this->assertSame([], $problems, "Model/schema drift detected:\n".implode("\n", $problems));
    }
}
