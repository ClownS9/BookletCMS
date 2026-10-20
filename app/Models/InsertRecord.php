<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One thing an insert keeps: an album, an event… Its fields are in `data`;
 * read them with $record->field('title').
 *
 * Its fields (from the database, with their casts):
 *
 * @property int $id
 * @property string $insert
 * @property string $kind
 * @property string|null $slug
 * @property array $data
 * @property int $sort
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class InsertRecord extends Model
{
    protected $fillable = ['insert', 'kind', 'slug', 'data', 'sort'];

    protected function casts(): array
    {
        return ['data' => 'array', 'sort' => 'integer'];
    }

    public function field(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }
}
