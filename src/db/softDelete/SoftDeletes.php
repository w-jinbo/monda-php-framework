<?php

namespace framework\db\softDelete;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Str;

/**
 * @method static static|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder onlyTrashed()
 * @method static static|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder withoutTrashed()
 * @method static static|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder withTrashed()
 */
trait SoftDeletes
{
    /**
     * @var bool
     */
    public $canDelete = false;
    protected $originalTable;

    protected $trashedTable;

    protected $trashedModel;

    /**
     * Indicates if the model is currently force deleting.
     *
     * @var bool
     */
    protected $forceDeleting = false;

    /**
     * Boot the soft deleting trait for a model.
     *
     * @return void
     */
    public static function bootSoftDeletes(): void
    {
        static::addGlobalScope(new SoftDeletingScope());
    }

    /**
     * Initialize the soft deleting trait for an instance.
     *
     * @return void
     */
    public function initializeSoftDeletes(): void
    {
        if (isset($this->casts)) {
            $this->casts[$this->getDeletedAtColumn()] = 'date';
        } else {
            $this->dates[] = $this->getDeletedAtColumn();
        }

        $this->setOriginalTable($this->getTable());
    }

    /**
     * Force a hard delete on a soft deleted model. 硬删除，不做软备份
     *
     * @return mixed
     * @throws \Throwable
     */
    public function forceDelete()
    {
        $this->forceDeleting = true;

        $this->canDelete = true;

        $deleted = null;

        $this->transaction(function () use (&$deleted) {
            $trashedModel = clone $this;
            $trashedModel->withTrashedTable();
            $trashedModel->delete();
            $deleted = $this->delete();
        });

        return tap($deleted, function ($deleted) {
            $this->forceDeleting = false;

            if ($deleted) {
                $this->fireModelEvent('forceDeleted', false);
            }
        });
    }

    /**
     * 开启事务
     *
     * @param \Closure $callback
     * @return mixed
     * @throws \Throwable
     */
    public function transaction(\Closure $callback)
    {
        $connection = Manager::connection($this->getConnectionName());

        if ($connection->getPdo()->inTransaction()) {
            return $callback();
        }

        return $connection->transaction($callback);
    }

    /**
     * Restore a soft-deleted model instance.
     *
     * @return bool|null
     * @throws \Throwable
     */
    public function restore()
    {
        // If the restoring event does not return false, we will proceed with this
        // restore operation. Otherwise, we bail out so the developer will stop
        // the restore totally. We will clear the deleted timestamp and save.
        if ($this->fireModelEvent('restoring') === false) {
            return false;
        }

        $result = null;

        $this->transaction(function () use (&$result) {
            $trashedModel = clone $this;

            $trashedModel->canDelete = true;

            $trashedModel->delete();

            $this->withoutTrashedTable();

            $this->{$this->getDeletedAtColumn()} = null;
            // Once we have saved the model, we will fire the "restored" event so this
            // developer will do anything they need to after a restore operation is
            // totally finished. Then we will return the result of the save call.
            $this->exists = false;

            $result = $this->save();

            $this->canDelete = false;
        });

        $this->fireModelEvent('restored', false);

        return $result;
    }

    /**
     * Determine if the model instance has been soft-deleted.
     *
     * @return bool
     */
    public function trashed()
    {
        return ! is_null($this->{$this->getDeletedAtColumn()});
    }

    /**
     * Register a restoring model event with the dispatcher.
     *
     * @param  \Closure|string  $callback
     * @return void
     */
    public static function restoring($callback)
    {
        static::registerModelEvent('restoring', $callback);
    }

    /**
     * Register a restored model event with the dispatcher.
     *
     * @param  \Closure|string  $callback
     * @return void
     */
    public static function restored($callback)
    {
        static::registerModelEvent('restored', $callback);
    }

    /**
     * Get the name of the "deleted at" column.
     *
     * @return string
     */
    public function getDeletedAtColumn()
    {
        return defined('static::DELETED_AT') ? static::DELETED_AT : 'deleted_at';
    }

    /**
     * Get the fully qualified "deleted at" column.
     *
     * @return string
     */
    public function getQualifiedDeletedAtColumn()
    {
        return $this->qualifyTrashedColumn($this->getDeletedAtColumn());
    }

    /**
     * Qualify the given column name by the model's table.
     *
     * @param  string  $column
     * @return string
     */
    public function qualifyTrashedColumn($column)
    {
        if (Str::contains($column, '.')) {
            return $column;
        }

        return $this->getTrashedTable() . '.' . $column;
    }

    /**
     * Determine if the model is currently force deleting.
     *
     * @return bool
     */
    public function isForceDeleting()
    {
        return $this->forceDeleting;
    }

    /**
     * @return string
     */
    public function getTrashedTable()
    {
        if (empty($this->trashedTable)) {
            $this->trashedTable = $this->getOriginalTable() . '_trash';
        }

        return $this->trashedTable;
    }

    /**
     * @param string $table
     *
     * @return $this
     */
    public function setOriginalTable(string $table)
    {
        if ($table === $this->getTrashedTable() || $this->originalTable) {
            return $this;
        }

        $this->originalTable = $table;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getOriginalTable()
    {
        return $this->originalTable ?: $this->getTable();
    }

    /**
     * @return $this
     */
    public function withTrashedTable()
    {
        $this->setOriginalTable($this->getTable());

        return $this->setTable($this->getTrashedTable());
    }

    /**
     * @return $this
     */
    public function withoutTrashedTable()
    {
        return $this->setTable($this->getOriginalTable());
    }

    /**
     * Perform the actual delete query on this model instance.
     *
     * @return mixed
     */
    protected function performDeleteOnModel()
    {
        if ($this->forceDeleting || $this->getTable() === $this->getTrashedTable()) {
            $this->exists = false;

            return $this->setKeysForSaveQuery($this->newModelQuery())->forceDelete();
        }

        if ($this->canDelete) {
            $this->exists = false;

            return $this->setKeysForSaveQuery($this->newModelQuery())->delete();
        }

        return $this->runSoftDelete();
    }

    /**
     * Perform the actual delete query on this model instance.
     *
     * @return void
     * @throws \Throwable
     */
    protected function runSoftDelete(): void
    {
        $this->transaction(function () {
            $originalModel = clone $this;
            $originalModel->canDelete = true;
            $originalModel->delete();

            $time = $this->freshTimestamp();
            $this->{$this->getDeletedAtColumn()} = $time;
            if ($this->timestamps && ! is_null($this->getUpdatedAtColumn())) {
                $this->{$this->getUpdatedAtColumn()} = $time;
            }
            $this->withTrashedTable();
            $this->exists = false;
            $this->save();
            // 已软删除，需要把 canDelete 设置为 true
            $this->canDelete = true;
        });
    }
}
