<?php

namespace Ns\Traits;

trait NsFlashData
{
    protected $flashData = [];

    public function setData( $data )
    {
        $this->flashData = $data;
    }

    public function getData()
    {
        return $this->flashData;
    }
}
