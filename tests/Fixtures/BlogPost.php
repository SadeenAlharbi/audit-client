<?php

declare(strict_types=1);

namespace Company\AuditClient\Tests\Fixtures;

use Company\AuditClient\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BlogPost extends Model
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'blog_posts';

    protected $guarded = [];

    protected $hidden = ['draft_notes'];

    /** @var list<string> */
    protected array $auditExclude = ['views'];

    protected $casts = ['published' => 'boolean', 'settings' => 'array'];
}
