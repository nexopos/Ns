<script lang="ts">
import { BehaviorSubject } from 'rxjs';
import { nsHttpClient, nsSnackBar } from '../bootstrap';
import FormValidation from '../libraries/form-validation';
import { __  } from '~/libraries/lang';
import popupResolver from '~/libraries/popup-resolver';
import { shallowRef } from 'vue';

declare const nsExtraComponents;
declare const nsComponents;
declare const nsNotice;
declare const nsHooks;

export default {
    data: () => {
        return {
            form: {},
            globallyChecked: false,
            formValidation: new FormValidation,
            rows: [],
            extraComponents: () => nsExtraComponents,
            nsComponents: () => nsComponents
        }
    }, 
    emits: [ 'updated', 'saved' ],
    mounted() {
        this.loadForm();
        console.log( this.links );
    },
    props: [ 'src', 'createUrl', 'fieldClass', 'submitUrl', 'submitMethod', 'disableTabs', 'queryParams', 'popup', 'links', 'optionsAttributes' ],
    computed: {
        activeTabFields() {
            for( let identifier in this.form.tabs ) {
                if ( this.form.tabs[ identifier ].active ) {
                    return this.form.tabs[ identifier ].fields;
                }
            }
            return [];
        },
        activeTab() {
            for( let identifier in this.form.tabs ) {
                if ( this.form.tabs[ identifier ].active ) {
                    return this.form.tabs[ identifier ];
                }
            }
            return false;
        },
        activeTabIdentifier() {
            for( let identifier in this.form.tabs ) {
                if ( this.form.tabs[ identifier ].active ) {
                    return identifier;
                }
            }
            return {};
        },
        extractedForm() {
            return this.formValidation.extractForm( this.form );
        },
        extractedFormLabels() {
            return this.formValidation.extractFormLabels( this.form );
        },
    },
    methods: {
        __,
        popupResolver,
        toggle( identifier ) {
            for( let key in this.form.tabs ) {
                this.form.tabs[ key ].active    =   false;
            }
            this.form.tabs[ identifier ].active     =   true;
        },
        async handleSaved( event, activeTabIdentifier, field ) {
            this.form.tabs[ activeTabIdentifier ].fields.filter( __field => {
                if ( __field.name === field.name && event.data.entry ) {
                    __field.options.push({
                        label: event.data.entry[ this.optionAttributes.label ],
                        value: event.data.entry[ this.optionAttributes.value ]
                    });

                    __field.value = event.data.entry.id;
                }
            });
        },
        handleClose() {
            if ( this.popup ) {
                this.popupResolver( false );
            }
        },
        submit() {
            const validation  = this.formValidation.validateForm( this.form );

            if ( validation.length > 0 ) {
                return nsSnackBar.error( __( 'The form is not valid. Double check it or refer to the error dislayed above.' ), __( 'Close' ) );
            }

            this.formValidation.disableForm( this.form );

            if ( this.submitUrl === undefined ) {
                return nsSnackBar.error( __( 'No submit URL was provided' ), __( 'Okay' ) );
            }

            nsHttpClient[ this.submitMethod ? this.submitMethod.toLowerCase() : 'post' ]( this.appendQueryParamas( this.submitUrl ), this.formValidation.extractForm( this.form ) )
                .subscribe( result => {
                    if ( result.status === 'success' ) {
                        /**
                         * This wil allow any external to hook into saving process
                         */
                        if ( this.popup ) {
                            this.popupResolver( result );
                        } else {
                            if ( this.submitMethod && this.submitMethod.toLowerCase() === 'post' && this.links.list !== false ) {
                                return document.location   =   result.data.editUrl || this.links.list;
                            } else {
                                nsSnackBar.info( result.message, __( 'Okay' ), { duration: 3000 });
                            }

                            this.$emit( 'saved', result );
                        }
                    }
                    this.formValidation.enableForm( this.form );
                }, ( error ) => {
                    nsSnackBar.error( error.message, undefined, {
                        duration: 5000
                    });
                    
                    if ( error.data !== undefined ) {
                        this.formValidation.triggerError( this.form, error.data );
                    }

                    this.formValidation.enableForm( this.form );
                })
        },
        handleGlobalChange( event ) {
            this.globallyChecked    =   event;
            this.rows.forEach( r => r.$checked = event );
        },
        loadForm() {
            return new Promise( ( resolve, reject ) => {
                const request   =   nsHttpClient.get( `${this.appendQueryParamas( this.src ) }` );
                    request.subscribe({
                        next: (form) => {
                            resolve( form );
                            this.form    =   this.parseForm( form );
                            // this.links = f.links;
                            // this.optionAttributes = f.optionAttributes;
                            nsHooks.doAction( 'ns-crud-form-loaded', this );

                            /**
                             * We'll automatically add the mouse
                             * focus on the first field.
                             */
                            if ( this.form.main ) {
                                setTimeout(() => {
                                    this.$el.querySelector( '#crud-form input' ).focus();
                                }, 100 );
                            }

                            this.$emit( 'updated', this.form );
                        },
                        error: ( error ) => {
                            reject( error )
                            nsSnackBar.error( error.message, __( 'Okay' ), { duration: 0 });
                        }
                    });
            });
        },
        appendQueryParamas( url ) {
            if ( this.queryParams === undefined ) {
                return url;
            }

            const params    =   Object.keys(this.queryParams)
                .map(key => `${encodeURIComponent(key)}=${encodeURIComponent(this.queryParams[key])}`)
                .join('&');

            if ( url.includes( '?' ) ) {
                return `${url}&${params}`;
            }

            return `${url}?${params}`;
        },
        parseForm( form ) {

            /** 
             * The form might omit the main field.
             * Therefore we should ignore it.
             */
            if ( form.main && Object.keys( form.main ).length > 0 ) {
                form.main.value     =   form.main.value === undefined ? '' : form.main.value;
                form.main           =   this.formValidation.createFields([ form.main ])[0];
            }

            let index           =   0;

            for( let key in form.tabs ) {
                if ( index === 0 ) {
                    form.tabs[ key ].active  =   true;
                }

                form.tabs[ key ].active     =   form.tabs[ key ].active === undefined ? false : form.tabs[ key ].active;
                form.tabs[ key ].fields     =   this.formValidation.createFields( form.tabs[ key ].fields );

                /**
                 * Each tabs has a subject object defined, which will be transmitted to the fields
                 * so each field can listen to the changes of the other fields
                 */
                form.tabs[ key ].subject    =   new BehaviorSubject({});
                form.tabs[ key ].fields.forEach( field => {
                    field.subject   =   form.tabs[ key ].subject;
                });

                /**
                 * We'll resolve the component here and keep it
                 * to the object so we can easily access it. We should make 
                 * sure the instance doesnt' yet exists.
                 */
                if ( form.tabs[ key ].component && form.tabs[ key ].instance === undefined ) {
                    form.tabs[ key ].instance  =   {
                        object: shallowRef( this.extraComponents()[ form.tabs[ key ].component ] ),
                        errors: []
                    };
                }

                index++;
            }

            return form;
        },
        handleFieldChange( field, fields ) {
            if ( field.errors.length === 0 ) {
                field.subject.next({ field, fields });
            }
        },

        /**
         * This should ensure the component is able
         * to update the tab state and therefore control
         * it's validation.
         * @param {object} object
         */
        handleTabError( error ) {
            this.form.tabs[ this.activeTabIdentifier ].instance.errors  =   [];

            if ( error !== false ) {
                this.form.tabs[ this.activeTabIdentifier ].instance.errors.push( ...error );
            } else {
                this.form.tabs[ this.activeTabIdentifier ].instance.errors = [];
            }
        },

        handleTabChange( fields ) {
            this.form.tabs[ this.activeTabIdentifier ].fields = fields;
        }
    },
}
</script>
<template>
    <div class="form flex-auto" v-if="Object.values( form ).length > 0" :class="popup ? 'ns-box w-95vw md:w-2/3-screen max-h-[80vh] overflow-hidden flex flex-col' : ''" id="crud-form" >
        <div v-if="Object.values( form ).length === 0" class="flex items-center justify-center h-full">
            <ns-spinner />
        </div>
        <div class="box-header border-b border-box-edge box-border p-2 flex justify-between items-center" v-if="popup">
            <h2 class="text-fontcolor font-bold text-lg">{{ popup.params.title }}</h2>
            <div>
                <ns-close-button @click="handleClose()"></ns-close-button>
            </div>
        </div>
        <div v-if="Object.values( form ).length > 0" :class="popup ? 'p-2 overflow-y-auto' : ''">
            <div class="flex flex-col" v-if="form.main">
                <div class="flex justify-between items-center">
                    <label for="title" class="font-bold my-2">
                        <span v-if="form.main.name">{{ form.main.label }}</span>
                    </label>
                    <div for="title" class="text-sm my-2">
                        <a v-if="links.list && ! popup" :href="links.list" class="rounded-full border px-2 py-1 ns-inset-button error">{{ __( 'Go Back' ) }}</a>
                    </div>
                </div>
                <template v-if="form.main.name">
                    <div :class="form.main.disabled ? 'disabled' : form.main.errors.length > 0 ? 'error' : 'primary'" class="input-group flex border rounded overflow-hidden">
                        <input v-model="form.main.value" 
                            @keydown.enter="submit()"
                            @keypress="formValidation.checkField( form.main, extractedForm, extractedFormLabels )"
                            @blur="formValidation.checkField( form.main, extractedForm, extractedFormLabels )" 
                            @change="formValidation.checkField( form.main, extractedForm, extractedFormLabels )" 
                            :disabled="form.main.disabled"
                            type="text" 
                            class="flex-auto outline-hidden h-10 px-2">
                        <button :disabled="form.main.disabled" :class="form.main.disabled ? 'disabled' : form.main.errors.length > 0 ? 'error' : ''" @click="submit()" class="outline-hidden px-4 h-10 text-white">{{ __( 'Save' ) }}</button>
                    </div>
                    <p class="text-xs text-fontcolor py-1" v-if="form.main.description && form.main.errors.length === 0">{{ form.main.description }}</p>
                    <p :key="index" class="text-xs py-1 text-error-secondary" v-for="(error,index) of form.main.errors">
                        <span v-if="error.identifier=== 'required'"><slot name="error-required">{{ error.identifier }}</slot></span>
                        <span v-if="error.identifier=== 'invalid'"><slot name="error-invalid">{{ error.message }}</slot></span>
                    </p>
                </template>
            </div>
            <div id="tabs-container" :class="popup ? 'mt-5' : 'my-5'" class="ns-tab" v-if="disableTabs !== 'true'">
                <div class="header flex ml-4" style="margin-bottom: -1px;">
                    <div :key="identifier" v-for="( tab , identifier ) of form.tabs" 
                        @click="toggle( identifier )" 
                        :class="tab.active ? 'active border' : 'inactive border'" 
                        class="tab rounded-tl rounded-tr border px-3 py-2 cursor-pointer" style="margin-right: -1px">{{ tab.label }}</div>
                </div>
                <div class="ns-tab-item">
                    <div class="border p-4 rounded">
                        <!-- We can't display both fields and component at the same time. The component has the priority over fields -->
                        <div class="-mx-4 flex flex-wrap" v-if="( activeTabFields && activeTabFields.length ) > 0 && ! activeTab.component">
                            <div :key="`${activeTabIdentifier}-${key}`" :class="fieldClass || 'px-4 w-full md:w-1/2 lg:w-1/3'" v-for="(field,key) of activeTabFields">
                                <ns-field @saved="handleSaved( $event, activeTabIdentifier, field )" @blur="formValidation.checkField( field, extractedForm, extractedFormLabels )" @keyup="formValidation.checkField( field, extractedForm, extractedFormLabels ) && handleFieldChange( field, activeTabFields )" :field="field"/>
                            </div>
                        </div>
                        <div class="-mx-4 flex flex-wrap" v-if="activeTab && activeTab.component">
                            <div :key="`${activeTabIdentifier}`" v-if="activeTab.instance.object" :class="fieldClass || 'px-4 w-full'">
                                <keep-alive>
                                    <component @changed="handleTabChange( $event )" @invalid="handleTabError( $event )":is="activeTab.instance.object" :tab="activeTab"/>
                                </keep-alive>
                            </div>
                            <div v-else class="px-4 text-center">
                                <div class="text-error-tertiary border-dashed border-error-tertiary border p-4">
                                    {{ __( 'Failed to load the component: {component}'.replace( '{component}', activeTab.component ) ) }}
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-end -mx-2" v-if="! form.main">
                            <div class="ns-button default px-2">
                                <a v-if="links.list && ! popup" :href="links.list" class="block flex items-center justify-center outline-hidden px-4 h-10 border-l">{{ __( 'Return' ) }}</a>
                            </div>
                            <div class="ns-button px-2" :class="form.main?.disabled ? 'default' : ( form.main?.errors.length > 0 ? 'error' : 'info' )">
                                <button :disabled="form.main?.disabled" @click="submit()" class="outline-hidden px-4 h-10 border-l">{{ __( 'Save' ) }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>