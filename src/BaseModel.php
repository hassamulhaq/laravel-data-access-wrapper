<?php

namespace Hassam\DataAccessWrapper;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

abstract class BaseModel extends Model
{
    /**
     * Drop appended (accessor-driven) attributes before persistence so they
     * don't accidentally end up written as columns.
     */
    public function save(array $options = []): bool
    {
        foreach ($this->getArrayableAppends() as $append) {
            $this->offsetUnset($append);
        }

        return parent::save($options);
    }

    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d H:i:s');
    }

    public static function getTableName(): string
    {
        return (new static)->getTable();
    }

    /**
     * Real column names on the underlying table.
     *
     * @return string[]
     */
    public static function getTableColumns(?string $table = null): array
    {
        return Schema::getColumnListing($table ?: static::getTableName());
    }

    /**
     * Columns declared `$fillable` on the model.
     *
     * @return string[]
     */
    public static function getFillableColumns(): array
    {
        return (new static)->getFillable();
    }
}
