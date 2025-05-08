<?php
use Illuminate\Support\Str;
?>
<{{ '?php' }}
/**
 * {{ $module[ 'name' ] }} Settings
 * @since {{ $module[ 'version' ] }}
**/
namespace Modules\{{ $module[ 'namespace' ] }}\Settings;

use Ns\Services\SettingsPage;
use Ns\Classes\SettingForm;
use Ns\Classes\FormInput;
use Ns\Services\Helper;

class {{ ucwords( $name ) }} extends SettingsPage
{
    const AUTOLOAD = true;
    const IDENTIFIER = '{{ Str::slug( $module[ 'namespace' ] . '-' . $name ) }}';

    public function __construct()
    {
        /**
         * Settings Form definition.
         */
        $this->form     =   SettingForm::form(
            title: __m( 'Settings', '{{ $module[ 'namespace' ] }}' ),
            description: __m( 'No description has been provided.', '{{ $module[ 'namespace' ] }}' ),
            tabs: SettingForm::tabs(
                SettingForm::tab(
                    label: __m( 'General Settings', '{{ $module[ 'namespace' ] }}' ),
                    identifier: 'general',
                    fields: SettingForm::fields(
                        // your fields here
                    )
                )
            )
        );
    }
}