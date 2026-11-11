<?php

declare(strict_types=1);

namespace Company\AuditClient\Concerns;

use Company\AuditClient\Observers\AuditableObserver;
use Company\AuditClient\Support\EntityResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Logs created / updated / deleted (and restored with SoftDeletes) events
 * of an Eloquent model:
 *
 *   class Order extends Model
 *   {
 *       use Auditable;
 *
 *       // optional: never send these attributes ($hidden is always excluded)
 *       protected array $auditExclude = ['internal_notes'];
 *   }
 *
 * @mixin Model
 */
trait Auditable
{
    /**
     * Listens to the model events directly: Model::observe() instantiates the
     * model, which Laravel forbids while the model is booting.
     */
    public static function bootAuditable(): void
    {
        foreach (['created', 'updated', 'deleted', 'restored'] as $event) {
            static::registerModelEvent($event, static fn ($model) => app(AuditableObserver::class)->{$event}($model));
        }
    }

    /**
     * Event name for a model event, e.g. "order.created". Override to customize.
     */
    public function auditEventName(string $modelEvent): string
    {
        return EntityResolver::typeOf($this).'.'.$modelEvent;
    }

    /**
     * Attributes never included in old/new values: $hidden plus $auditExclude.
     *
     * @return list<string>
     */
    public function auditExcludedAttributes(): array
    {
        $excluded = property_exists($this, 'auditExclude') && is_array($this->auditExclude)
            ? $this->auditExclude
            : [];

        return array_values(array_unique(array_merge($this->getHidden(), $excluded)));
    }
}
