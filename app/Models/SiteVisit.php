<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One counted page view of the public website. Written only by
 * App\Services\Visits\VisitRecorder, read by Admin → Visitors
 * (App\Services\Visits\VisitorStats). Append-only, except that a row can be
 * flagged suspicious after the fact and loses its IP and User-Agent after
 * VisitRecorder::IP_DAYS.
 */
class SiteVisit extends Model
{
    protected $table = 'site_visits';

    public const UPDATED_AT = null;

    protected $fillable = [
        'path',
        'visitor_hash',
        'is_entry',
        'ip',
        'user_agent',
        'country',
        'device_type',
        'browser',
        'os',
        'referrer_host',
        'ref_code',
        'utm_source',
        'utm_campaign',
        'signed_in',
        'suspicious',
        'suspicious_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_entry' => 'boolean',
            'signed_in' => 'boolean',
            'suspicious' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
