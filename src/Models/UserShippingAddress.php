<?php

namespace Ns\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * @property int            $id
 * @property int            $author
 * @property string         $uuid
 * @property \Carbon\Carbon $updated_at
 */
class UserShippingAddress extends UserAddress
{
    use HasFactory;

    protected static function booted()
    {
        static::addGlobalScope( 'type', function ( Builder $builder ) {
            $builder->where( 'type', 'shipping' );
        } );

        static::creating( function ( $address ) {
            $address->type = 'shipping';
        } );

        static::updating( function ( $address ) {
            $address->type = 'shipping';
        } );
    }
}
