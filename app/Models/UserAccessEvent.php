<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One registration or successful login: where it came from and on what
 * device. Written only by App\Services\Auth\AccessLog, append-only, read on
 * admin user detail (AdminSignupInfoController).
 */
class UserAccessEvent extends Model
{
    protected $table = 'user_access_events';

    /** Append-only: a row is a fact about one moment, never edited. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'uni_id',
        'event',
        'method',
        'ip',
        'country',
        'device_type',
        'browser',
        'os',
        'user_agent',
        'timezone',
        'language',
        'screen',
        'consent',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** The AccessEvent shape the admin user-detail page renders. */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'method' => $this->method,
            'ip' => $this->ip,
            'country' => $this->country,
            'device_type' => $this->device_type,
            'browser' => $this->browser,
            'os' => $this->os,
            'user_agent' => $this->user_agent,
            'timezone' => $this->timezone,
            'language' => $this->language,
            'screen' => $this->screen,
            'consent' => $this->consent,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
