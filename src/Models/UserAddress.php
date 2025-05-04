<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int            $id
 * @property int            $author
 * @property string         $uuid
 * @property \Carbon\Carbon $updated_at
 */
class UserAddress extends NsModel
{
    use HasFactory;

    protected $table = 'nexopos_' . 'users_addresses';

    /**
     * define the relationship
     *
     * @return Model\RelationShip
     */
    public function groups()
    {
        return $this->belongsTo( User::class, 'user_id' );
    }

    public function scopeFrom( $query, $id, $type )
    {
        return $query->where( 'user_id', $id )
            ->where( 'type', $type );
    }
}
