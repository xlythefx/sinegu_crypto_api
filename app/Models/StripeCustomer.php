<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A user's Stripe Customer, per key mode. See the migration for why the mode is
 * part of the key: a test-mode cus_… is not valid against live keys.
 */
class StripeCustomer extends Model
{
    protected $table = 'stripe_customers';

    protected $fillable = ['uni_id', 'mode', 'stripe_customer_id'];
}
