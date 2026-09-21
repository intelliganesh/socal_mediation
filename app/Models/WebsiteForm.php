<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteForm extends Model
{
    protected $table = 'website_form';

    protected $fillable = [
        'application',
        'name',
        'email',
        'phone',
        'message',
        'extra_fields',
    ];

    protected function casts(): array
    {
        return [
            'extra_fields' => 'array',
        ];
    }
}
