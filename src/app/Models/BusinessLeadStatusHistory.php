<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessLeadStatusHistory extends Model
{
    protected $fillable = ['business_lead_id', 'changed_by_user_id', 'from_status', 'to_status', 'note'];
}
