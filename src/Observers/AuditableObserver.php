<?php

declare(strict_types=1);

namespace Company\AuditClient\Observers;

use Company\AuditClient\AuditManager;
use Company\AuditClient\Enums\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Registered by the Auditable trait. Updates send only the changed
 * attributes; $hidden and $auditExclude attributes are never sent, and the
 * mask list is applied by the manager. A failure here is reported and never
 * interrupts the model operation.
 */
final class AuditableObserver
{
    public function __construct(private readonly AuditManager $audit) {}

    public function created(Model $model): void
    {
        $this->record($model, 'created', Action::Create, null, $this->snapshot($model, $model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changed = array_keys($model->getChanges());

        if (array_diff($changed, $this->excluded($model)) === [] || $this->isRestoring($model)) {
            return;
        }

        $this->record(
            $model,
            'updated',
            Action::Update,
            $this->snapshot($model, $model->getRawOriginal(), $changed),
            $this->snapshot($model, $model->getAttributes(), $changed),
        );
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted', Action::Delete, $this->snapshot($model, $model->getRawOriginal()), null);
    }

    public function restored(Model $model): void
    {
        $this->record($model, 'restored', Action::Restore, null, $this->snapshot($model, $model->getAttributes()));
    }

    /**
     * @param  array<array-key, mixed>|null  $old
     * @param  array<array-key, mixed>|null  $new
     */
    private function record(Model $model, string $modelEvent, Action $action, ?array $old, ?array $new): void
    {
        if (! $this->audit->enabled()) {
            return;
        }

        rescue(fn () => $this->audit
            ->event(method_exists($model, 'auditEventName') ? $model->auditEventName($modelEvent) : $modelEvent)
            ->action($action)
            ->on($model)
            ->changes($old, $new)
            ->send());
    }

    /**
     * Serialize raw attributes the way the model itself does (casts, dates,
     * enums), without appends, then keep only $only and drop excluded ones.
     *
     * @param  array<string, mixed>  $raw
     * @param  list<string>|null  $only
     * @return array<string, mixed>
     */
    private function snapshot(Model $model, array $raw, ?array $only = null): array
    {
        $copy = clone $model;
        $copy->setRawAttributes($raw);
        $copy->setAppends([]);

        $values = $copy->attributesToArray();

        if ($only !== null) {
            $values = Arr::only($values, $only);
        }

        return Arr::except($values, $this->excluded($model));
    }

    /**
     * SoftDeletes::restore() saves the model (firing "updated") before it
     * fires "restored"; only the latter is logged.
     */
    private function isRestoring(Model $model): bool
    {
        if (! method_exists($model, 'getDeletedAtColumn')) {
            return false;
        }

        $column = $model->getDeletedAtColumn();

        return array_key_exists($column, $model->getChanges()) && $model->getAttribute($column) === null;
    }

    /**
     * @return list<string>
     */
    private function excluded(Model $model): array
    {
        return method_exists($model, 'auditExcludedAttributes') ? $model->auditExcludedAttributes() : $model->getHidden();
    }
}
