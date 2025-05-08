<?php

namespace Ns\Models;

use Ns\Events\UserRoleRelationAfterCreatedEvent;
use Ns\Events\UserRoleRelationAfterUpdatedEvent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @method combinaison( User $user, Role $role )
 */
class UserRoleRelation extends Model
{
    protected $table = 'users_roles_relations';

    use HasFactory;

    protected $dispatchesEvents = [
        'created' => UserRoleRelationAfterCreatedEvent::class,
        'updated' => UserRoleRelationAfterUpdatedEvent::class,
    ];

    public function scopeCombinaison( $query, $user, $role )
    {
        return $query->where( 'user_id', $user->id )
            ->where( 'role_id', $role->id );
    }

    public function user()
    {
        return $this->belongsTo( User::class );
    }

    public function role()
    {
        return $this->belongsTo( Role::class );
    }
}
