<?php
use Ns\Classes\Hook;
?>
<div>
    @include( Hook::filter( 'ns-dashboard-header-file', 'ns::common/dashboard-header' ) )
    <div id="dashboard-content" class="px-4">
        @yield( 'layout.dashboard.body.with-header' )
        @yield( 'layout.dashboard.with-header' )
    </div>
</div>