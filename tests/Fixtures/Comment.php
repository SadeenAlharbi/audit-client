<?php

declare(strict_types=1);

namespace Company\AuditClient\Tests\Fixtures;

use Company\AuditClient\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Auditable model without $auditExclude and without SoftDeletes.
 */
class Comment extends Model
{
    use Auditable;

    protected $guarded = [];
}
