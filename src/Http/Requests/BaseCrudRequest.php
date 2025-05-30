<?php

namespace Ns\Http\Requests;

use Ns\Services\CrudService;
use Illuminate\Foundation\Http\FormRequest;

class BaseCrudRequest extends FormRequest
{
    public function getPlainData( $namespace, $entry = null )
    {
        $service = new CrudService;
        $resource = $service->getCrudInstance( $this->route( 'namespace' ) );

        return $resource->getPlainData( $this, $entry );
    }
}
