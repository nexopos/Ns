<?php

namespace Ns\Services;

use Ns\Classes\Hook;
use Ns\Events\SettingsSavedEvent;
use Ns\Traits\NsForms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class SettingsPage
{
    use NsForms;

    protected $form = [];

    protected string $view;

    /**
     * Retrieves the form configuration, filtering tabs and fields based on their visibility.
     *
     * This method iterates over the tabs and fields in the form configuration. For each tab and field:
     * - If the 'show' key is set and is a callable, it evaluates the callable to determine visibility.
     * - If the 'show' key is not set, it defaults to true, making the tab or field visible.
     *
     * The filtered form configuration is then passed through a hook for further customization.
     *
     * @return array The filtered form configuration.
     */
    public function getForm(): array
    {
        $form = collect( $this->form )->mapWithKeys( function ( $tab, $key ) {
            if ( $key === 'tabs' ) {
                return [
                    $key => collect( $tab )->filter( function ( $tab ) {
                        if ( isset( $tab[ 'show' ] ) && is_callable( $tab[ 'show' ] ) ) {
                            return $tab[ 'show' ]();
                        }

                        return true;
                    } )->mapWithKeys( function ( $tab, $key ) {
                        /**
                         * in case not fields is provided
                         * let's save the tab with no fields.
                         */
                        if ( ! isset( $tab[ 'fields' ] ) ) {
                            $tab[ 'fields' ] = [];
                        }

                        $tab['fields'] = collect($tab['fields'])->filter(function ($field) {
                            if (isset($field['show']) && is_callable($field['show'])) {
                                return $field['show']();
                            }

                            return true;
                        })->toArray();

                        return [ $key => $tab ];
                    } ),
                ];
            }

            return [ $key => $tab ];
        } )->toArray();

        return Hook::filter( self::method( 'getForm' ), $form );
    }

    public function getIdentifier()
    {
        return get_called_class()::IDENTIFIER;
    }

    /**
     * In case the form is used as a resource,
     * "index" is used as a main method.
     */
    public static function index()
    {
        return self::renderForm();
    }

    public static function renderForm()
    {
        $className = get_called_class();
        $settings = new $className;

        /**
         * if something has to be made before a form
         * is renderer, we'll trigger the method here if
         * that exists.
         */
        if ( method_exists( $settings, 'beforeRenderForm' ) ) {
            $settings->beforeRenderForm();
        }

        /**
         * When the settingsPage class has the "getView" method,
         * we return it as it might provide a custom View page.
         */
        if ( method_exists( $settings, 'getView' ) ) {
            return $settings->getView();
        }

        $form = $settings->getForm();

        /**
         * if the form is an instance of a view
         * that view is rendered in place of the default form.
         */
        return View::make( 'ns::pages.dashboard.settings.form', [
            'title' => $form[ 'title' ] ?? __( 'Untitled Settings Page' ),

            /**
             * retrive the description provided on the SettingsPage instance.
             * Otherwhise a default settings is used .
             */
            'description' => $form[ 'description' ] ?? __( 'No description provided for this settings page.' ),

            /**
             * retrieve the identifier of the settings if it's defined.
             * this is used to load the settings asynchronously.
             */
            'identifier' => $settings->getIdentifier(),

            /**
             * We now pass the instance so it can be captured by modules on the footer. 
             * This is usefull to add settings specific behavior.
             */
            'instance' => $settings,
        ] );
    }

    /**
     * Validate a form using a provided
     * request. Based on the actual settings page rules
     *
     * @return array
     */
    public function validateForm( Request $request )
    {
        $arrayRules = $this->extractValidation();

        /**
         * As rules might contains complex array (with Rule class),
         * we don't want that array to be transformed using the dot key form.
         */
        $isolatedRules = $this->isolateArrayRules( $arrayRules );

        /**
         * Let's properly flat everything.
         */
        $flatRules = collect( $isolatedRules )->mapWithKeys( function ( $rule ) {
            return [ $rule[0] => $rule[1] ];
        } )->toArray();

        return $flatRules;
    }

    /**
     * Proceed to a saving using te provided
     * request along with the plain data
     *
     * @return array
     */
    public function saveForm( Request $request )
    {
        /**
         * @var Options
         */
        $options = app()->make( Options::class );
        $data = [];
        $inputs = Hook::filter( SettingsPage::method( 'saveForm' ), $this->getPlainData( $request ) );

        foreach ( $inputs as $key => $value ) {
            if ( $value === null ) {
                $options->delete( $key );
            } else {
                $options->set( $key, $value );
                $data[ $key ] = $value;
            }
        }

        event( new SettingsSavedEvent(
            data: $data,
            settingsClass: get_class( $this )
        ) );

        return [
            'status' => 'success',
            'message' => __( 'The form has been successfully saved.' ),
        ];
    }
}
