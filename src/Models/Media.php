<?php

namespace Ns\Models;

use Ns\Casts\DateCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int            $user_id
 * @property string         $slug
 * @property \Carbon\Carbon $updated_at
 */
class Media extends NsModel
{
    use HasFactory;

    protected $table = 'medias';

    protected $casts = [
        'created_at' => DateCast::class,
        'updated_at' => DateCast::class,
    ];

    public function user()
    {
        return $this->belongsTo( User::class );
    }
}
