<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Done-state + note for one Admin → To be Done item. The items themselves are
 * authored in the frontend (`src/lib/adminTodos.ts`); this is only what the
 * owner did about one, keyed by the item's slug.
 */
class AdminTodoState extends Model
{
    protected $table = 'admin_todo_states';

    protected $primaryKey = 'slug';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['slug', 'done_at', 'done_by', 'note'];

    protected function casts(): array
    {
        return [
            'done_at' => 'datetime',
        ];
    }

    public function doneBy()
    {
        return $this->belongsTo(UserCredential::class, 'done_by', 'uni_id');
    }
}
