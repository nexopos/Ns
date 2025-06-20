<?php

namespace Ns\Services;

use Ns\Classes\Hook;
use Ns\Exceptions\NotEnoughPermissionException;
use Ns\Exceptions\NotFoundException;
use Ns\Jobs\CheckTaskSchedulingConfigurationJob;
use Ns\Models\Migration;
use Ns\Models\Notification;
use Ns\Models\Permission;
use Ns\Models\Role;
use Ns\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class CoreService
{
    /**
     * @var bool
     */
    public $isMultistore = false;

    public $storeID;

    public Options $rootOption;

    /**
     * @var \Modules\NsMultiStore\Services\StoresService
     */
    public $store;

    public $cachedPermissions = [];

    public function __construct(
        public CurrencyService $currency,
        public UpdateService $update,
        public DateService $date,
        public NotificationService $notification,
        public Options $option,
        public MathService $math,
        public EnvEditor $envEditor,
        public MediaService $mediaService,
    ) {
        // ...
    }

    /**
     * check if a use is allowed to
     * access a page or trigger an error. This should not be used
     * on middleware or controller constructor.
     */
    public function restrict( $permissions, $message = '' ): void
    {
        if ( is_array( $permissions ) ) {
            $permissions = collect( $permissions )->filter( function ( $permission ) {
                return is_string( $permission );
            } )->toArray();

            $passed = $this->allowedTo( $permissions );
        } elseif ( is_string( $permissions ) ) {
            $passed = $this->allowedTo( $permissions );
        } elseif ( is_bool( $permissions ) ) {
            $passed = $permissions;
        }

        if ( ! $passed ) {
            throw new NotEnoughPermissionException( $message ?:
                sprintf(
                    __( 'You do not have enough permissions to perform this action.' ) . '<br>' . __( 'Required permissions: %s' ),
                    is_string( $permissions ) ? $permissions : implode( ', ', $permissions )
                )
            );
        }
    }

    /**
     * Will return the logged user details
     * that are actually fillable to avoid exposing any sensitive information.
     */
    public function getUserDetails(): Collection
    {
        return collect( ( new User )->getFillable() )->mapWithKeys( fn( $key ) => [ $key => Auth::user()->$key ] );
    }

    /**
     * Will determine if a user is allowed
     * to perform a specific action (using a permission)
     */
    public function allowedTo( array|string $permissions ): bool
    {
        if ( is_array( $permissions ) ) {
            return Gate::any( $permissions );
        }

        return Gate::allows( $permissions );
    }

    /**
     * check if the logged user has a specific role.
     */
    public function hasRole( string $roleNamespace ): bool
    {
        return Auth::user()
            ->roles()
            ->get()
            ->filter( fn( $role ) => $role->namespace === $roleNamespace )->count() > 0;
    }

    /**
     * clear missing migration files
     * from migrated files.
     */
    public function purgeMissingMigrations(): void
    {
        $migrations = collect( Migration::get() )
            ->map( function ( $migration ) {
                return $migration->migration;
            } );

        $rawFiles = collect( Storage::disk( 'ns' )
            ->allFiles( 'database/migrations' ) );

        $files = $rawFiles->map( function ( $file ) {
            $details = pathinfo( $file );

            return $details[ 'filename' ];
        } );

        $difference = array_diff(
            $migrations->toArray(),
            $files->toArray()
        );

        foreach ( $difference as $diff ) {
            Migration::where( 'migration', $diff )->delete();
        }
    }

    /**
     * Returns a boolean if the environment is
     * on production mode
     */
    public function isProduction(): bool
    {
        return ! is_file( base_path( 'public/hot' ) );
    }

    /**
     * Simplify the manifest to return
     * only the files to use.
     */
    public function simplifyManifest(): Collection
    {
        $possiblePaths = [
            base_path( 'public/vendor/ns/build/.vite/manifest.json' ),
            base_path( 'public/vendor/ns/build/manifest.json' ),
        ];

        $manifestPath = collect( $possiblePaths )->filter( function ( $path ) {
            return file_exists( $path );
        } )->first();

        if ( ! $manifestPath ) {
            return collect();
        }
        
        $manifest = json_decode( file_get_contents( $manifestPath ), true );

        $files = collect( $manifest )
            ->mapWithKeys( fn( $value, $key ) => [ $key => asset( 'build/' . $value[ 'file' ] ) ] )
            ->filter( function ( $element ) {
                $info = pathinfo( $element );

                return $info[ 'extension' ] === 'css';
            } );

        return $files;
    }

    /**
     * Some features must be disabled
     * if the jobs aren't configured correctly.
     */
    public function canPerformAsynchronousOperations(): bool
    {
        $lastUpdate = Carbon::parse( ns()->option->get( 'ns_jobs_last_activity', false ) );

        if ( $lastUpdate->diffInMinutes( ns()->date->now() ) > 60 || ! ns()->option->get( 'ns_jobs_last_activity', false ) ) {
            return false;
        }

        return true;
    }

    /**
     * Check if the tasks scheduling is configured or
     * will emit a notification to help fixing it.
     */
    public function checkTaskSchedulingConfiguration(): void
    {
        if ( ns()->option->get( 'ns_jobs_last_activity', false ) === false ) {
            /**
             * @var Notification;
             */
            $this->emitNotificationForTaskSchedulingMisconfigured();

            /**
             * force dispatching the job
             * to force check the tasks status.
             */
            CheckTaskSchedulingConfigurationJob::dispatch();
        } else {
            /**
             * @var DateService
             */
            $date = app()->make( DateService::class );
            $lastUpdate = Carbon::parse( ns()->option->get( 'ns_jobs_last_activity' ) );

            if ( $lastUpdate->diffInMinutes( $date->now() ) > 60 ) {
                $this->emitNotificationForTaskSchedulingMisconfigured();

                /**
                 * force dispatching the job
                 * to force check the tasks status.
                 */
                CheckTaskSchedulingConfigurationJob::dispatch();
            }
        }
    }

    /**
     * Register the available permissions when
     * the app is installed as valid gates.
     */
    public function registerGatePermissions(): void
    {
        /**
         * We'll define gate by using all available permissions.
         * Those will be cached to avoid unecessary db calls when testing
         * wether the user has the permission or not.
         */
        if ( Helper::installed() ) {
            Permission::get()->each( function ( $permission ) {
                if ( ! Gate::has( $permission->namespace ) ) {
                    Gate::define( $permission->namespace, function ( User $user ) use ( $permission ) {
                        if ( ! isset( $this->cachedPermissions[ $user->id ] ) ) {
                            $this->cachedPermissions[ $user->id ] = $user->roles()
                                ->with( 'permissions' )
                                ->get()
                                ->map( fn( $role ) => $role->permissions->map( fn( $permission ) => $permission->namespace ) )
                                ->flatten();
                        }

                        return in_array( $permission->namespace, $this->cachedPermissions[ $user->id ]->toArray() );
                    } );
                }
            } );
        }
    }

    /**
     * This will update the last time
     * the cron has been active
     */
    public function setLastCronActivity(): void
    {
        /**
         * @var NotificationService
         */
        $notification = app()->make( NotificationService::class );
        $notification->deleteHavingIdentifier( Notification::NSCRONDISABLED );

        ns()->option->set( 'ns_cron_last_activity', ns()->date->toDateTimeString() );
    }

    /**
     * Will check if the cron has been active recently
     * and delete a ntoification that has been generated for that.
     */
    public function checkCronConfiguration(): void
    {
        if ( ns()->option->get( 'ns_cron_last_activity', false ) === false ) {
            $this->emitCronMisconfigurationNotification();
        } else {
            /**
             * @var DateService
             */
            $date = app()->make( DateService::class );
            $lastUpdate = Carbon::parse( ns()->option->get( 'ns_cron_last_activity' ) );

            if ( $lastUpdate->diffInMinutes( $date->now() ) > 60 ) {
                $this->emitCronMisconfigurationNotification();
            }
        }
    }

    public function checkSymbolicLinks(): void
    {
        if ( ! file_exists( public_path( 'storage' ) ) ) {
            $notification = Notification::where( 'identifier', Notification::NSSYMBOLICLINKSMISSING )
                ->first();

            if ( ! $notification instanceof Notification ) {
                ns()->option->set( 'ns_has_symbolic_links_missing_notifications', true );

                $notification = app()->make( NotificationService::class );
                $notification->create(
                    title: __( 'Symbolic Links Missing' ),
                    identifier: Notification::NSSYMBOLICLINKSMISSING,
                    source: 'system',
                    url: 'https://my.nexopos.com/en/documentation/troubleshooting/broken-media-images?utm_source=nexopos&utm_campaign=warning&utm_medium=app',
                    description: __( 'The Symbolic Links to the public directory is missing. Your medias might be broken and not display.' ),
                )->dispatchForGroup( Role::namespace( Role::ADMIN ) );
            }
        } else {
            /**
             * We should only perform this if we have reason to believe
             * there is some records, to avoid the request triggered for no reason.
             */
            if ( ns()->option->get( 'ns_has_symbolic_links_missing_notifications' ) ) {
                Notification::where( 'identifier', Notification::NSSYMBOLICLINKSMISSING )->delete();
            }
        }
    }

    /**
     * Emit a notification when Cron aren't
     * correctly configured.
     */
    private function emitCronMisconfigurationNotification(): void
    {
        $notification = app()->make( NotificationService::class );
        $notification->create(
            title: __( 'Cron Disabled' ),
            identifier: Notification::NSCRONDISABLED,
            source: 'system',
            url: 'https://my.nexopos.com/en/documentation/troubleshooting/workers-or-async-requests-disabled?utm_source=nexopos&utm_campaign=warning&utm_medium=app',
            description: __( "Cron jobs aren't configured correctly on NexoPOS. This might restrict necessary features. Click here to learn how to fix it." ),
        )->dispatchForGroup( Role::namespace( Role::ADMIN ) );
    }

    /**
     * Emit a notification when workers aren't
     * correctly configured.
     */
    private function emitNotificationForTaskSchedulingMisconfigured(): void
    {
        $notification = app()->make( NotificationService::class );
        $notification->create(
            title: __( 'Task Scheduling Disabled' ),
            identifier: Notification::NSWORKERDISABLED,
            source: 'system',
            url: 'https://my.nexopos.com/en/documentation/troubleshooting/workers-or-async-requests-disabled?utm_source=nexopos&utm_campaign=warning&utm_medium=app',
            description: __( 'NexoPOS is unable to schedule background tasks. This might restrict necessary features. Click here to learn how to fix it.' ),
        )->dispatchForGroup( Role::namespace( Role::ADMIN ) );
    }

    /**
     * Get a valid user for assigning resources
     * create by the system on behalf of the user.
     */
    public function getValidAuthor()
    {
        $firstAdministrator = User::where( 'active', true )->
            whereRelation( 'roles', 'namespace', Role::ADMIN )->first();

        if ( App::runningInConsole() ) {
            return $firstAdministrator->id;
        } else {
            if ( Auth::check() ) {
                return Auth::id();
            } else {
                return $firstAdministrator->id;
            }
        }
    }

    /**
     * Get the asset file name from the manifest.json file of a module in Laravel.
     *
     * @param  int         $moduleId
     * @return string|null
     *
     * @throws NotFoundException
     */
    public function moduleViteAssets( string $fileName, $moduleId ): string
    {
        $moduleService = app()->make( ModulesService::class );
        $module = $moduleService->get( $moduleId );

        if ( empty( $module ) ) {
            throw new NotFoundException(
                sprintf(
                    __( 'The requested module %s cannot be found.' ),
                    $moduleId
                )
            );
        }

        $viteConfigFile = $module[ 'path' ] . 'vite.config.js';

        if ( ! file_exists( $viteConfigFile ) ) {
            throw new NotFoundException(
                sprintf(
                    __( 'The vite.config.js file for the module %s cannot be found.' ),
                    $module[ 'name' ]
                )
            );
        }

        /**
         * let's read the outDir value from the vite.config.js file
         */
        $viteConfig = file_get_contents( $viteConfigFile );

        $assets = collect( [] );
        $errors = [];

        /**
         * We need to define the location of the hot file. We'll start by checking if the vite.config.js file
         * includes a hotFile variable. The location is it's value.
         */
        if ( preg_match( '/hotFile:\s*[\'"](.+?)[\'"]/', $viteConfig, $matches ) ) {
            $hotFilePath = $matches[1]; // Return the matched hotFile value
        } else {
            $hotFilePath = 'Public' . DIRECTORY_SEPARATOR . 'hot';
        }

        /**
         * If the hot file is not a file. When we'll load the assets directly from the
         * manifest.json file.
         */
        if ( file_exists( $module[ 'path' ] . $hotFilePath ) ) {
            $url    =   file_get_contents( $module[ 'path' ] . $hotFilePath );
            $pathinfo   =   pathinfo( $fileName );

            if ( in_array( $pathinfo[ 'extension' ], [ 'js', 'ts', 'tsx', 'jsx' ] ) ) {
                $assets->prepend( '<script type="module" src="' . $url . '/' . $fileName . '"></script>' );
            } else if ( in_array( $pathinfo[ 'extension'], [ 'css', 'scss' ] ) ) {
                $assets->push( '<link rel="stylesheet" href="' . $url . '/' . $fileName . '"/>' );
            } else {
                throw new NotFoundException(
                    sprintf(
                        __( 'The requested file %s is not a valid asset.' ),
                        $fileName
                    )
                );
            }
        } else {

            $ds = DIRECTORY_SEPARATOR;
    
            if ( preg_match( '/outDir:\s*[\'"](.+?)[\'"]/', $viteConfig, $matches ) ) {
                $buildDirectory = $matches[1]; // Return the matched outDir value
            } else {
                $buildDirectory = 'Public' . $ds . 'build'; // Default build directory
            }
    
            $possiblePaths = [
                rtrim( $module['path'], $ds ) . $ds . $buildDirectory . $ds . '.vite' . $ds . 'manifest.json',
                rtrim( $module['path'], $ds ) . $ds . $buildDirectory . $ds . 'manifest.json',
            ];
    
            $buildFolderName = last( preg_split( '/[\/\\\\]/', $buildDirectory ) );
    
            foreach ( $possiblePaths as $manifestPath ) {
                if ( ! file_exists( $manifestPath ) ) {
                    $errors[] = $manifestPath;
    
                    continue;
                }
    
                $manifestArray = json_decode( file_get_contents( $manifestPath ), true );
    
                if ( ! isset( $manifestArray[ $fileName ] ) ) {
                    throw new NotFoundException(
                        sprintf(
                            __( 'the requested file "%s" can\'t be located inside the manifest.json for the module %s.' ),
                            $fileName,
                            $module[ 'name' ]
                        )
                    );
                }
    
                /**
                 * checks if a css file is declared as well
                 */
                $assetURL = asset( 'modules/' . strtolower( $moduleId ) . '/' . $buildFolderName . '/' . $manifestArray[ $fileName ][ 'file' ] ) ?? null;
    
                if ( ! empty( $manifestArray[ $fileName ][ 'css' ] ) ) {
                    $assets = collect( $manifestArray[ $fileName ][ 'css' ] )->map( function ( $url ) use ( $moduleId, $buildFolderName ) {
                        return '<link rel="stylesheet" href="' . asset( 'modules/' . strtolower( $moduleId ) . '/' . $buildFolderName . '/' . $url ) . '"/>';
                    } );
                }

                $pathinfo   =   pathinfo( $assetURL );

                if ( in_array( $pathinfo[ 'extension' ], [ 'js', 'ts', 'tsx', 'jsx' ] ) ) {
                    $assets->prepend( '<script type="module" src="' . $assetURL . '"></script>' );
                } else if ( in_array( $pathinfo[ 'extension'], [ 'css', 'scss' ] ) ) {
                    $assets->push( '<link rel="stylesheet" href="' . $assetURL . '"/>' );
                } else {
                    throw new NotFoundException(
                        sprintf(
                            __( 'The requested file %s is not a valid asset.' ),
                            $fileName
                        )
                    );
                }
            }

            if ( count( $errors ) === count( $possiblePaths ) ) {
                throw new NotFoundException(
                    sprintf(
                        __( 'The manifest file for the module %s wasn\'t found on all possible directories: %s.' ),
                        $module[ 'name' ],
                        collect( $errors )->join( ', ' ),
                    )
                );
            }
        }

        return $assets->flatten()->join( '' );
    }
}
