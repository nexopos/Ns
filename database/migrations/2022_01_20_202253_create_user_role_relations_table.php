<?php

use Ns\Models\Role;
use Ns\Models\User;
use Ns\Models\UserRoleRelation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Determine whether the migration
     * should execute when we're accessing
     * a multistore instance.
     */
    public function runOnMultiStore()
    {
        return false;
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if ( ! Schema::hasTable( 'users_roles_relations' ) ) {
            Schema::create( 'users_roles_relations', function ( Blueprint $table ) {
                $table->id();
                $table->integer( 'role_id' );
                $table->integer( 'user_id' );
                $table->timestamps();
            } );
        }

        if ( Schema::hasColumn( 'users', 'role_id' ) ) {
            Role::get()->each( function ( $role ) {
                User::where( 'role_id', $role->id )
                    ->get()
                    ->each( function ( $user ) use ( $role ) {
                        $relation = UserRoleRelation::where( 'user_id', $user->id )
                            ->where( 'role_id', $role->id )
                            ->firstOrNew();
                            
                        $relation->user_id = $user->id;
                        $relation->role_id = $role->id;
                        $relation->save();
                    } );
            } );
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists( 'users_roles_relations' );
    }
};
