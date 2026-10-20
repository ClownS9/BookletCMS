<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The book's email settings (one row). See App\Support\MailSettings.
 *
 * Its fields (from the database, with their casts):
 *
 * @property int $id
 * @property bool $enabled
 * @property string $service
 * @property string|null $host
 * @property int|null $port
 * @property string $security
 * @property string|null $username
 * @property string|null $password
 * @property string|null $from_address
 * @property string|null $from_name
 * @property \Illuminate\Support\Carbon|null $tested_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class MailSetting extends Model
{
    protected $fillable = ['enabled', 'service', 'host', 'port', 'security', 'username', 'password', 'from_address', 'from_name', 'tested_at'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port' => 'integer',
            'password' => \App\Casts\Secret::class,
            'tested_at' => 'datetime',
        ];
    }

    /** Ready to send: switched on with a server and a from address (and a password it can still read). */
    public function isReady(): bool
    {
        return $this->enabled && $this->host && $this->port && $this->from_address && ! $this->passwordLost();
    }

    /** Saved before APP_KEY changed, so it can't be read any more. */
    public function passwordLost(): bool
    {
        return \App\Casts\Secret::lost($this, 'password');
    }
}
